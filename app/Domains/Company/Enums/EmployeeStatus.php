<?php

namespace App\Domains\Company\Enums;

/**
 * Mirrors the CHECK constraint on employees.status. Adding a case
 * REQUIRES a migration widening it.
 *
 * - `Active`: on the roster and eligible. The only status that will be
 *   able to receive and spend allowance.
 * - `Inactive`: a PAUSE (leave, suspension). The record and whatever it
 *   holds are kept; nothing moves until it is active again.
 * - `Separated`: the employee has left. Always carries a `separated_at`
 *   date (UpdateEmployeeAction keeps the two in step). This is the hook
 *   the allowance module will use to stop grants and expire what is left.
 *
 * An employee removed from the roster entirely is soft-deleted instead,
 * which is a different fact from any of these.
 */
enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Separated = 'separated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
