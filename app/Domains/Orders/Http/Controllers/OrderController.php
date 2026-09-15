<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Orders\Http\Requests\IndexOrdersRequest;
use App\Domains\Orders\Http\Resources\OrderResource;
use App\Domains\Orders\Http\Resources\ReceiptResource;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\MerchantDay;
use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read side of the merchant order list. Thin by construction: no business
 * logic, no writes — transitions live in OrderTransitionController and
 * creation in CheckoutController.
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
            ->with(['items.addOns', 'beneficiaries'])

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
            // Day boundaries live in MerchantDay, not here: the kitchen
            // queue asks the same question, and the two must agree about
            // where midnight is.
            MerchantDay::constrain($query, MerchantDay::startOf($date));
        }

        $orders = $query->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load(['items.addOns', 'beneficiaries']));
    }

    /**
     * GET /merchant/orders/{order}/receipt — printable receipt data (P9).
     * Gated by the SAME permission as `show` (orders.view): a receipt is a
     * read over this order, not a distinct capability. Always 200, even
     * for a VOIDED order — never a 404 — see ReceiptResource's docblock
     * for why a reprint of a voided slip must still say so rather than the
     * endpoint refusing to answer.
     */
    public function receipt(Order $order): ReceiptResource
    {
        $this->authorize('view', $order);

        return new ReceiptResource($order->load(['items.addOns', 'beneficiaries', 'createdBy', 'merchant']));
    }
}
