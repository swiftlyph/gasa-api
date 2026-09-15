<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Orders\Actions\IdempotentCheckoutAction;
use App\Domains\Orders\Http\Requests\CheckoutRequest;
use App\Domains\Orders\Http\Resources\OrderResource;
use App\Domains\Orders\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * POST /merchant/orders — the POS checkout.
 *
 * Its own controller rather than an OrderController::store, so the read
 * surface stays a read surface (OrderController's docblock says as much)
 * and the one endpoint that creates money-bearing records is impossible
 * to miss when reading the routes file.
 *
 * Thin, like every controller here: validate (CheckoutRequest), authorize
 * (OrderPolicy), delegate (IdempotentCheckoutAction), serialise
 * (OrderResource). The only decision made here is which status code and
 * header describe the result, which is a transport concern and so belongs
 * at this layer rather than in an Action.
 */
class CheckoutController extends Controller
{
    /**
     * Marks a response that returned an EXISTING order rather than
     * creating one. Absent means the order was created by this request.
     * The 200-vs-201 status says the same thing, for clients that find a
     * status code easier to branch on than a header.
     */
    private const REPLAY_HEADER = 'Idempotent-Replayed';

    public function __invoke(CheckoutRequest $request, IdempotentCheckoutAction $action): JsonResponse
    {
        $this->authorize('create', Order::class);

        /** @var User $cashier */
        $cashier = $request->user();

        // checkoutPayload(), never all() or even validated(): the request's
        // rules define the entire accepted surface, so a price a client
        // tries to smuggle in alongside a product_id never reaches the
        // Action — and the idempotency key is stripped, so it stays out of
        // both the order and its fingerprint.
        $result = $action->execute(
            $request->idempotencyKey(),
            $request->checkoutPayload(),
            $cashier,
        );

        $response = OrderResource::make($result->order->load(['items.addOns', 'beneficiaries']))
            ->response()
            // 201 for a sale that happened, 200 for one that already had.
            // A replay must not claim to have created anything: a POS that
            // retries after a lost response needs to be able to tell the
            // difference between "your order went through the first time"
            // and "you have just made a second one".
            ->setStatusCode($result->replayed
                ? JsonResponse::HTTP_OK
                : JsonResponse::HTTP_CREATED);

        if ($result->replayed) {
            $response->headers->set(self::REPLAY_HEADER, 'true');
        }

        return $response;
    }
}
