<?php

namespace App\Domains\Company\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by CreateEmployeeAction / UpdateEmployeeAction when the email is
 * already on ANOTHER non-deleted employee of the SAME company.
 *
 * Unlike the merchant domain's EmailUnavailable this is not an
 * enumeration risk: the roster is the caller's own, so naming the
 * collision (and the field, in `errors`, so a form can attach the
 * message to the right input) is the helpful answer, not a leak.
 * Uniqueness is per company, so the same person may sit on two
 * companies' rosters, and it ignores soft-deleted rows, so a rehire can
 * reuse the address.
 */
class EmployeeEmailTaken extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'An employee with this email address already exists.',
            'employee_email_taken',
            422,
            ['email' => ['An employee with this email address already exists.']],
        );
    }
}
