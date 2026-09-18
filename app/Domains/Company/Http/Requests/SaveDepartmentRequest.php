<?php

namespace App\Domains\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for both POST /company/departments and
 * PATCH /company/departments/{department}: a department is just its
 * name. Whether the name is already taken in this company is the
 * Actions' question (DepartmentNameTaken), not a rule's.
 */
class SaveDepartmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function name(): string
    {
        /** @var string $name */
        $name = $this->validated('name');

        return $name;
    }
}
