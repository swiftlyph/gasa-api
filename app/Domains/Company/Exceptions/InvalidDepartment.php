<?php

namespace App\Domains\Company\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The department_id on an employee write is not one of THIS company's
 * departments. Whether the id belongs to another company or to nobody
 * is never distinguished: both are equally "not yours", and telling them
 * apart would let a caller probe which department ids exist elsewhere.
 *
 * Checked in the Actions rather than with an `exists:` rule for that
 * reason: `exists:departments,id` is not tenant-scoped.
 */
class InvalidDepartment extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'The selected department is invalid.',
            'invalid_department',
            422,
            ['department_id' => ['The selected department is invalid.']],
        );
    }
}
