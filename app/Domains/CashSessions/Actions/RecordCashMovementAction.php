<?php

namespace App\Domains\CashSessions\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Exceptions\SessionClosed;
use App\Domains\CashSessions\Models\CashMovement;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;

/**
 * Records one cash-in or cash-out event against an open session.
 *
 * Rejects outright on a closed session (SessionClosed, 422): a movement
 * against a session whose expected/counted/variance figures are already
 * snapshotted would silently make that snapshot wrong the moment it was
 * taken, with nothing to say so.
 */
class RecordCashMovementAction
{
    public function __construct(
        private readonly RecordMerchantAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array{type: string, amount_cents: int, reason: string}  $payload
     *
     * @throws SessionClosed
     */
    public function execute(CashSession $cashSession, array $payload, User $recordedBy): CashMovement
    {
        if (! $cashSession->isOpen()) {
            throw new SessionClosed($cashSession->getKey());
        }

        $movement = $cashSession->movements()->create([
            'type' => $payload['type'],
            'amount_cents' => $payload['amount_cents'],
            'reason' => $payload['reason'],
            'created_by_user_id' => $recordedBy->getKey(),
        ]);

        $this->recordAuditLog->execute(
            actor: $recordedBy,
            merchant: $cashSession->merchant,
            action: MerchantAuditAction::CashMovementRecorded,
            subject: $movement,
            newValues: [
                'type' => $movement->type->value,
                'amount_cents' => $movement->amount_cents,
                'reason' => $movement->reason,
            ],
        );

        return $movement;
    }
}
