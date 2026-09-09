<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Orders\Http\Requests\IndexOrdersRequest;
use App\Domains\Orders\Http\Resources\OrderResource;
use App\Domains\Orders\Models\Order;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read side of the merchant order list. Thin by construction: no business
 * logic, no writes — transitions live in OrderTransitionController and
 * creation arrives with P2's checkout.
 *
 * Tenancy is not handled here and must not be: BelongsToMerchant's global
 * scope already restricts both the list query and route-model binding to
 * the caller's merchant, so another merchant's order id is a 404 before
 * this class runs. The authorize() calls are the second, independent
 * check (see OrderPolicy).
 */
class OrderController extends Controller
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Order>>
     */
    public function index(IndexOrdersRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);

        $query = Order::query()
            // Eager loaded rather than lazy: an order list of 25 rows with
            // items and add-ons is 3 queries this way and 51 without.
            ->with(['items.addOns'])

            // Newest first. The tiebreak on id is not cosmetic — two
            // orders rung up in the same second would otherwise come back
            // in an arbitrary order, and a paginated list with an unstable
            // sort can show the same row on two pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($date = $request->validated('date')) {
            $this->scopeToDay($query, $date);
        }

        $orders = $query->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load(['items.addOns']));
    }

    /**
     * Restricts to a single calendar day.
     *
     * A half-open range (>= start, < next day) rather than whereDate():
     * whereDate wraps the column in a function, which discards the
     * (merchant_id, created_at) index, and a BETWEEN with an inclusive end
     * would double-count anything landing exactly at midnight.
     *
     * "Merchant-local" is the app timezone for now. When merchants get
     * their own timezone column this is the one place that changes —
     * which is why the boundaries are built here rather than inlined.
     *
     * @param  Builder<Order>  $query
     */
    private function scopeToDay(Builder $query, string $date): void
    {
        $timezone = config('app.timezone');

        $start = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();

        $query->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addDay());
    }
}
