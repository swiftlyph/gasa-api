<?php

namespace App\Domains\CashSessions\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A remittance for more cash than the session currently has on hand.
 *
 * Checked against the LIVE reconciliation figure (see
 * ReconcileCashSessionAction), not a stored balance — a remittance is
 * itself one of the terms that figure subtracts, so this has to ask "what
 * is expected right now, before this remittance," not read a cached
 * number that could already be stale from an intervening cash_in/cash_out.
 *
 * 422, not 409: nothing else is racing this request for the same money in
 * the way two opens race for one register — the request is simply asking
 * to remit an amount its own session doesn't support, which is a
 * validation failure against server-computed state rather than a
 * conflict with a concurrent actor.
 */
class RemittanceExceedsCash extends ApiException
{
    public function __construct(
        public readonly int $amountCents,
        public readonly int $expectedCashCents,
    ) {
        parent::__construct(
            'The remittance amount exceeds the cash currently expected on hand.',
            'remittance_exceeds_cash',
            422,
        );
    }
}
