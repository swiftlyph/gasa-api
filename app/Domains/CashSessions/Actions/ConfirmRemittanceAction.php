<?php

namespace App\Domains\CashSessions\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\RemittanceStatus;
use App\Domains\CashSessions\Exceptions\ConfirmationRequiresSecondUser;
use App\Domains\CashSessions\Exceptions\RemittanceAlreadyConfirmed;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;

/**
 * Confirms a pending remittance — the entire segregation-of-duties control
 * this feature exists to provide, so this is the one place it can be
 * checked, and it is checked BEFORE the already-confirmed check: a second
 * confirmation attempt by the creator should still be told "you can't do
 * this" rather than "it's already done," since telling them the ladder
 * order would let the creator learn whether someone else already
 * confirmed it.
 */
class ConfirmRemittanceAction
{
    public function __construct(
        private readonly RecordMerchantAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @throws ConfirmationRequiresSecondUser
     * @throws RemittanceAlreadyConfirmed
     */
    public function execute(CashRemittance $remittance, User $confirmer): CashRemittance
    {
        if ($remittance->created_by_user_id === $confirmer->getKey()) {
            throw new ConfirmationRequiresSecondUser;
        }

        if ($remittance->isConfirmed()) {
            throw new RemittanceAlreadyConfirmed($remittance->getKey());
        }

        $remittance->status = RemittanceStatus::Confirmed;
        $remittance->confirmed_by_user_id = $confirmer->getKey();
        $remittance->confirmed_at = now();
        $remittance->save();

        $this->recordAuditLog->execute(
            actor: $confirmer,
            merchant: $remittance->merchant,
            action: MerchantAuditAction::RemittanceConfirmed,
            subject: $remittance,
        );

        return $remittance;
    }
}
