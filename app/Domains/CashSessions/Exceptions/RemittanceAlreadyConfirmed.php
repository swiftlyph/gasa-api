<?php

namespace App\Domains\CashSessions\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A remittance that was already confirmed is being confirmed again.
 *
 * 422, matching SessionClosed's reasoning: the request is well-formed but
 * no longer applies to this resource's current state, and confirmation is
 * terminal (see RemittanceStatus) — there is no un-confirm, so this can
 * never be resolved by retrying.
 */
class RemittanceAlreadyConfirmed extends ApiException
{
    public function __construct(public readonly int $remittanceId)
    {
        parent::__construct(
            'This remittance has already been confirmed.',
            'remittance_already_confirmed',
            422,
        );
    }
}
