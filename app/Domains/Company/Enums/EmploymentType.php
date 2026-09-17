<?php

namespace App\Domains\Company\Enums;

/**
 * Mirrors the CHECK constraint on employees.employment_type. Recorded
 * now because the allowance module will target employees by it (a
 * probationary employee and a regular one often get different amounts),
 * the same way it will target departments. It is NOT an authorization
 * boundary of any kind.
 *
 * Adding a case REQUIRES a migration widening the constraint.
 */
enum EmploymentType: string
{
    case Regular = 'regular';
    case Probationary = 'probationary';
    case Contractual = 'contractual';
    case PartTime = 'part_time';
    case Intern = 'intern';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
