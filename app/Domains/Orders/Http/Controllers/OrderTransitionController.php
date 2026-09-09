<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Orders\Actions\CompleteOrderAction;
use App\Domains\Orders\Actions\VoidOrderAction;
use App\Domains\Orders\Http\Resources\OrderResource;
use App\Domains\Orders\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The two status transitions, kept in their own controller so
 * OrderController stays a pure read surface.
 *
 * There is no FormRequest on either: neither transition takes a body.
 * Whether the move is legal is a question about the ORDER, not about the
 * request, so it belongs to OrderStatus' transition map (via the Actions)
 * and surfaces as 422 `invalid_transition` — not to a validation rule
 * that would have to re-derive the same knowledge from the payload.
 *
 * Both return the updated order in the single-resource shape, so a POS
 * can render the new state without a follow-up GET.
 */
class OrderTransitionController extends Controller
{
    public function complete(Order $order, CompleteOrderAction $action): OrderResource
    {
        $this->authorize('complete', $order);

        $completed = $action->execute($order);

        return new OrderResource($completed->load(['items.addOns']));
    }

    public function void(Request $request, Order $order, VoidOrderAction $action): OrderResource
    {
        $this->authorize('void', $order);

        /** @var User $user */
        $user = $request->user();

        // The acting user is passed explicitly rather than read inside the
        // Action: an Action that reaches for Auth::user() can't be called
        // from a console command, a job, or a test without faking a
        // request. Who voided the order is an input to the operation.
        $voided = $action->execute($order, $user);

        return new OrderResource($voided->load(['items.addOns']));
    }
}
