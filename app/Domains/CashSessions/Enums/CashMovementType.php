<?php

namespace App\Domains\CashSessions\Enums;

/**
 * The direction of a cash movement. Mirrors the CHECK constraint on
 * cash_movements.type — adding a case here REQUIRES a migration widening
 * that constraint.
 *
 * `amount_cents` is always stored positive; the type alone carries
 * direction (see the cash_movements migration). A movement is never
 * signed in the column, so a report can't accidentally double-negate one
 * by misreading a sign that was never there.
 */
enum CashMovementType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';

    /**
     * The signed contribution of one unit of `amount_cents` toward
     * expected cash — the only place this class knows about direction.
     */
    public function sign(): int
    {
        return match ($this) {
            self::CashIn => 1,
            self::CashOut => -1,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
