<?php

namespace App\Domains\CashSessions\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;

/**
 * DEFENCE IN DEPTH, not the primary control — see OrderPolicy's docblock.
 *
 * `confirm` checks tenancy, then remittances.confirm — same-tenant,
 * active-merchant, right role — exactly like `view`/`complete` elsewhere
 * in this codebase (P8). Segregation of duties (the confirming user must
 * differ from the creator) is a SEPARATE, ADDITIONAL business rule about
 * who specifically may act, not a tenancy or role question, so it stays
 * enforced by ConfirmRemittanceAction and surfaced as
 * ConfirmationRequiresSecondUser — never folded into this policy. A
 * manager who holds remittances.confirm still cannot confirm their OWN
 * remittance: this policy only answers "is a manager of this merchant
 * allowed to confirm remittances at all," ConfirmRemittanceAction
 * separately answers "not this specific one, you made it."
 */
class CashRemittancePolicy
{
    public function create(User $user, CashSession $cashSession): bool
    {
        $merchant = $user->merchant();

        if ($merchant === null || $cashSession->merchant_id !== $merchant->getKey()) {
            return false;
        }

        if (! $user->hasMerchantPermission(MerchantPermission::RemittancesCreate)) {
            throw new PermissionDenied(MerchantPermission::RemittancesCreate);
        }

        return true;
    }

    public function confirm(User $user, CashRemittance $cashRemittance): bool
    {
        $merchant = $user->merchant();

        if ($merchant === null || $cashRemittance->merchant_id !== $merchant->getKey()) {
            return false;
        }

        if (! $user->hasMerchantPermission(MerchantPermission::RemittancesConfirm)) {
            throw new PermissionDenied(MerchantPermission::RemittancesConfirm);
        }

        return true;
    }
}
