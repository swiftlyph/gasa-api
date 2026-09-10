<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Actions\RecordAuditLogAction;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /admin/merchants/{merchant}/status. Asks MerchantStatus's own
 * transition map whether the move is legal BEFORE any write, so an
 * illegal transition throws InvalidMerchantTransition and writes NO
 * audit entry at all — the map is the single authority on legality, this
 * Action never branches on status pairs itself.
 */
class ChangeMerchantStatusAction
{
    public function __construct(private readonly RecordAuditLogAction $recordAuditLog) {}

    public function execute(Merchant $merchant, MerchantStatus $newStatus, ?string $reason, User $actor): Merchant
    {
        $merchant->status->assertCanTransitionTo($newStatus);

        return DB::transaction(function () use ($merchant, $newStatus, $reason, $actor): Merchant {
            $oldStatus = $merchant->status;

            $merchant->update(['status' => $newStatus]);

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'merchant.status_changed',
                subject: $merchant,
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => $newStatus->value],
                context: $reason !== null ? ['reason' => $reason] : null,
            );

            return $merchant->refresh();
        });
    }
}
