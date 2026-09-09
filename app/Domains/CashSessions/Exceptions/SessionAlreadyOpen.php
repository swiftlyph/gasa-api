<?php

namespace App\Domains\CashSessions\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The register already has an open session; opening another would mean
 * two shifts on one till simultaneously, with no way to say which cash
 * movement belongs to which.
 *
 * 409, not 422: the request is perfectly well-formed — "open a session on
 * register 1" is a valid instruction in general — it just conflicts with
 * the register's *current state*, which is exactly what 409 Conflict
 * means. The PARTIAL UNIQUE INDEX on cash_sessions is the real guard (see
 * that migration); this exception is what turns a lost race into a clean
 * 409 instead of a 500 from an uncaught constraint violation.
 */
class SessionAlreadyOpen extends ApiException
{
    public function __construct(public readonly int $registerId)
    {
        parent::__construct(
            'This register already has an open cash session.',
            'session_already_open',
            409,
        );
    }
}
