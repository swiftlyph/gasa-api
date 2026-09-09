<?php

namespace App\Domains\CashSessions\Enums;

/**
 * A remittance's lifecycle: pending, then confirmed. Mirrors the CHECK
 * constraint on cash_remittances.status — adding a case here REQUIRES a
 * migration widening that constraint.
 *
 * There is no `rejected` case this phase. A remittance recorded in error
 * is a correction for a later phase, not a status this one defines —
 * inventing a rejection flow without a documented use for it would be
 * built for a rule nobody asked for.
 */
enum RemittanceStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
