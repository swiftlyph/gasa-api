<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Http\Requests\IndexKitchenQueueRequest;
use App\Domains\Orders\Http\Resources\KitchenOrderResource;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\MerchantDay;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The kitchen queue: a VIEW OVER ORDERS, not a thing of its own.
 *
 * There is no kitchen_queue table and no second status column. "In the
 * queue" means `status = pending` and nothing else, so an order cannot be
 * done on the till and still open in the kitchen — the failure mode of
 * every design that duplicates state. Completion goes through the
 * EXISTING POST /merchant/orders/{order}/complete, whose transition
 * guards already own what a legal status change is; this controller adds
 * no way to change an order at all.
 *
 * The flow is deliberately binary — pending, then done — because that is
 * what the audited shop actually ran. OrderStatus was built so
 * `preparing` and `ready` can be INSERTED later if a real kitchen asks
 * for them, which is a different thing from adding them speculatively now.
 *
 * BUILT TO BE POLLED. There are no websockets yet, so every kitchen
 * screen in the shop hits these endpoints on a timer, forever. That
 * shapes three decisions:
 *
 *  - Both endpoints are pure reads. No writes, no cache mutation, nothing
 *    that a poll every few seconds could accumulate.
 *  - Items and add-ons are eager loaded, so the query count is flat in
 *    the size of the queue rather than 1 + 2N.
 *  - Ordering is total (created_at, then id) and items are ordered too,
 *    so two polls a second apart return the same tickets in the same
 *    order and the screen doesn't reshuffle under the staff's hands.
 */
class KitchenQueueController extends Controller
{
    /**
     * The most tickets one response will ever carry.
     *
     * A kitchen screen shows the whole queue, so this endpoint is not
     * paginated — a barista cannot page through drinks. But "not
     * paginated" must not mean "unbounded": a merchant whose staff never
     * complete anything would otherwise build a response that grows all
     * day and is fetched every 15 seconds.
     *
     * 200 is far beyond any real counter-service backlog, so in practice
     * it never truncates; it exists so the worst case is a large response
     * rather than an unbounded one. Because the queue is oldest-first,
     * truncation drops the NEWEST tickets — the ones nobody is working on
     * yet — and keeps the front of the queue, which is the half that
     * matters. The summary endpoint's count is uncapped, so a screen can
     * always tell it is seeing a truncated view.
     */
    public const MAX_QUEUE_SIZE = 200;

    /**
     * GET /merchant/kitchen-queue
     */
    public function index(IndexKitchenQueueRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        // One instant for the whole response, so every ticket's
        // waiting_seconds is measured from the same clock reading.
        $now = CarbonImmutable::now();

        $orders = $this->queue($request)
            // Eager loaded, and ordered: without the orderBy the database
            // is free to return an order's lines in any order it likes,
            // and a polled screen would reshuffle a ticket's contents
            // between refreshes for no reason.
            ->with([
                'items' => fn ($query) => $query->orderBy('id'),
                'items.addOns' => fn ($query) => $query->orderBy('id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_QUEUE_SIZE)
            ->get();

        return response()->json([
            'data' => $orders
                ->map(fn (Order $order): array => (new KitchenOrderResource($order, $now))->toArray($request))
                ->values(),
        ]);
    }

    /**
     * GET /merchant/kitchen-queue/summary
     *
     * What a sidebar badge polls, more often than anyone polls the queue
     * itself. It must never build the full payload to answer "how many?"
     * — so this is a single aggregate query that touches no rows beyond
     * the index, loads no models, and hydrates no resources.
     */
    public function summary(IndexKitchenQueueRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        // toBase(): the tenant global scope is applied, then the query
        // drops to the base builder so the aggregate comes back as a plain
        // row instead of being hydrated into an Order carrying a bogus
        // `pending_count` attribute.
        // An aggregate with no GROUP BY always returns exactly one row —
        // zero and a null minimum on an empty queue — so there is no
        // "no result" case to handle.
        $stats = $this->queue($request)
            ->toBase()
            ->selectRaw('count(*) as pending_count, min(created_at) as oldest_created_at')
            ->first();

        $oldest = $stats->oldest_created_at;

        return response()->json([
            'pending_count' => (int) $stats->pending_count,

            // Deliberately null, not 0, on an empty queue: "nothing has
            // been waiting" and "something has been waiting no time at
            // all" are different facts, and a badge that turns red past a
            // threshold must not treat an empty kitchen as a fresh order.
            'oldest_waiting_seconds' => $oldest === null
                ? null
                : max(0, CarbonImmutable::now()->getTimestamp() - CarbonImmutable::parse($oldest)->getTimestamp()),
        ]);
    }

    /**
     * The queue itself: this merchant's pending orders, today unless the
     * caller asks for everything.
     *
     * Shared by both endpoints so the badge and the screen can never
     * disagree about what is in the queue. Tenancy is not applied here and
     * must not be — BelongsToMerchant's global scope already restricts
     * every query to the caller's merchant.
     *
     * @return Builder<Order>
     */
    private function queue(IndexKitchenQueueRequest $request): Builder
    {
        $query = Order::query()->where('status', OrderStatus::Pending->value);

        if (! $request->boolean('all')) {
            MerchantDay::constrain($query, MerchantDay::startOfToday());
        }

        return $query;
    }
}
