<?php

namespace App\Domains\Company\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The employee_no twin of EmployeeEmailTaken: the number is already on
 * another non-deleted employee of the same company. See that class for
 * why this names the collision rather than hiding it.
 */
class EmployeeNumberTaken extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'An employee with this employee number already exists.',
            'employee_number_taken',
            422,
            ['employee_no' => ['An employee with this employee number already exists.']],
        );
    }
}
