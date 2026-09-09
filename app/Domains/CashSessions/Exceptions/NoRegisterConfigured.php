<?php

namespace App\Domains\CashSessions\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A merchant with no active register at all — unreachable once DevSeeder
 * or merchant onboarding has run (every merchant gets exactly one register
 * on creation), but not a state checkout or the register endpoints should
 * silently paper over if it is ever reached, e.g. from a future onboarding
 * path that forgets the step.
 *
 * 500-adjacent by nature (it describes a setup gap, not a caller mistake),
 * but returned as 422 rather than crashing: a client can at least render
 * "ask your admin to configure a register" instead of a bare 500 page.
 */
class NoRegisterConfigured extends ApiException
{
    public function __construct(public readonly int $merchantId)
    {
        parent::__construct(
            'This merchant has no active register configured.',
            'no_register_configured',
            422,
        );
    }
}
