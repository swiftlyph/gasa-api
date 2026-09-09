<?php

namespace App\Domains\CashSessions\Enums;

/**
 * A cash session's lifecycle: open, then closed. Mirrors the CHECK
 * constraint on cash_sessions.status — adding a case here REQUIRES a
 * migration widening that constraint.
 *
 * Two states only, one transition, and it never reverses: closing is not
 * "paused," and there is no reopen. A cashier who closed too early rings a
 * new session rather than editing history — the same reasoning that makes
 * a voided order the reversal mechanism rather than an edit (see
 * OrderStatus).
 */
enum CashSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
