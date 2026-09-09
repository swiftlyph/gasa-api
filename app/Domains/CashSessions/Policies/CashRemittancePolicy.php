<?php

namespace App\Domains\CashSessions\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\CashSessions\Models\CashSession;

/**
 * DEFENCE IN DEPTH, not the primary control — see OrderPolicy's docblock.
 *
 * `confirm` only checks tenancy — same-tenant, active-merchant — exactly
 * like `view`/`complete` elsewhere in this codebase. Segregation of duties
 * (the confirming user must differ from the creator) is a BUSINESS rule
 * about who specifically may act, not a tenancy question, so it is
 * enforced by ConfirmRemittanceAction and surfaced as
 * ConfirmationRequiresSecondUser — not folded into this policy, the same
 * way OrderPolicy never asks about roles that route middleware already
 * enforces.
 */
class CashRemittancePolicy
{
    public function create(User $user, CashSession $cashSession): bool
    {
        $merchant = $user->merchant();

        return $merchant !== null && $cashSession->merchant_id === $merchant->getKey();
    }

    public function confirm(User $user, CashRemittance $cashRemittance): bool
    {
        $merchant = $user->merchant();

        return $merchant !== null && $cashRemittance->merchant_id === $merchant->getKey();
    }
}
