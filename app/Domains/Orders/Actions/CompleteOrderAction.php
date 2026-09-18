<?php

namespace App\Domains\Orders\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order to `completed` and stamps `completed_at`.
 *
 * The legality of the move is not decided here — OrderStatus is the
 * single authority and this action just asks it, which is what keeps
 * complete and void from drifting into two different sets of rules.
 *
 * The re-read under lockForUpdate is not ceremony. Two taps on a POS
 * "complete" button, or a completing terminal racing a voiding manager,
 * both read `pending` and both pass the guard; without the lock the
 * second write silently overwrites the first's timestamp (or flips a
 * voided order to completed). Re-reading inside the transaction makes the
 * loser see the winner's status and get a 422 like any other invalid
 * transition.
 *
 * newQuery() keeps the merchant global scope applied on that re-read, so
 * the lock can never land on another tenant's row even if a caller passed
 * an unscoped model.
 */
class CompleteOrderAction
{
    public function __construct(
        private readonly RecordMerchantAuditLogAction $recordAuditLog,
    ) {}

    public function execute(Order $order, User $completedBy): Order
    {
        return DB::transaction(function () use ($order, $completedBy): Order {
            /** @var Order $locked */
            $locked = $order->newQuery()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->status->assertCanTransitionTo(OrderStatus::Completed);

            $locked->status = OrderStatus::Completed;
            $locked->completed_at = now();
            $locked->save();

            $this->recordAuditLog->execute(
                actor: $completedBy,
                merchant: $locked->merchant,
                action: MerchantAuditAction::OrderCompleted,
                subject: $locked,
                newValues: ['status' => OrderStatus::Completed->value],
            );

            return $locked;
        });
    }
}
