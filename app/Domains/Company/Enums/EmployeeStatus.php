<?php

namespace App\Domains\Company\Enums;

/**
 * Mirrors the CHECK constraint on employees.status. An `Inactive`
 * employee has left, or been paused by the company, but the record is
 * kept: a future wallet ledger will reference it. An employee removed
 * from the roster entirely is soft-deleted instead, which is a different
 * fact from "inactive".
 */
enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
