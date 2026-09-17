<?php

namespace App\Domains\Company\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The company already has a department with this name, compared
 * case-insensitively ("Finance" and "finance" are one department, which
 * is the whole reason departments stopped being free text). Names the
 * field in `errors` so a form can attach the message to the input.
 */
class DepartmentNameTaken extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'A department with this name already exists.',
            'department_name_taken',
            422,
            ['name' => ['A department with this name already exists.']],
        );
    }
}
