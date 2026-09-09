<?php

namespace App\Domains\CashSessions\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A movement, or a second close, was attempted against a session that has
 * already been closed.
 *
 * 422, matching InvalidOrderTransition's reasoning: the request is
 * well-formed, but the operation no longer applies to this resource's
 * current state — the same category as a failed validation rule, not a
 * conflict with something else trying to happen concurrently (that would
 * be SessionAlreadyOpen's 409). A closed session is terminal — see
 * CashSessionStatus — so this can never be resolved by retrying, only by
 * opening a new session.
 */
class SessionClosed extends ApiException
{
    public function __construct(public readonly int $cashSessionId)
    {
        parent::__construct(
            'This cash session is already closed.',
            'session_closed',
            422,
        );
    }
}
