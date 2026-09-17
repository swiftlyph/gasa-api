<?php

namespace App\Domains\Allowance\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/** An inactive or separated employee cannot receive a new grant. */
class EmployeeNotEligible extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'Only active employees can receive an allowance grant.',
            'employee_not_eligible',
            422,
        );
    }
}
