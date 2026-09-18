<?php

namespace App\Domains\Orders\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Actions\RestoreIngredientsForOrderAction;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Voids an order: stamps `voided_at` and, crucially, `voided_by_user_id`.
 *
 * Voiding IS the reversal mechanism — orders are never deleted, and
 * `voided` is terminal, so this is the last thing that ever happens to
 * the record. That makes "who did it" part of the record rather than a
 * nice-to-have: a voided sale is money that left the till, and the audit
 * trail is the only thing that distinguishes a mis-keyed order from
 * theft. The acting user is a required argument for that reason; there is
 * no path that voids anonymously.
 *
 * Also restores any ingredient stock the order's checkout deducted (see
 * RestoreIngredientsForOrderAction) — a void means the sale didn't
 * happen, so neither should its effect on stock. Restoration reads
 * exactly what THIS order deducted, never the product's current recipe.
 *
 * Same transition guard and same lock rationale as CompleteOrderAction.
 */
class VoidOrderAction
{
    public function __construct(
        private readonly RestoreIngredientsForOrderAction $restoreIngredients,
        private readonly RecordMerchantAuditLogAction $recordAuditLog,
    ) {}

    public function execute(Order $order, User $voidedBy): Order
    {
        return DB::transaction(function () use ($order, $voidedBy): Order {
            /** @var Order $locked */
            $locked = $order->newQuery()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->status->assertCanTransitionTo(OrderStatus::Voided);

            $locked->status = OrderStatus::Voided;
            $locked->voided_at = now();
            $locked->voided_by_user_id = $voidedBy->getKey();
            $locked->save();

            $this->restoreIngredients->execute($locked);

            $this->recordAuditLog->execute(
                actor: $voidedBy,
                merchant: $locked->merchant,
                action: MerchantAuditAction::OrderVoided,
                subject: $locked,
                newValues: ['status' => OrderStatus::Voided->value],
            );

            return $locked;
        });
    }
}
