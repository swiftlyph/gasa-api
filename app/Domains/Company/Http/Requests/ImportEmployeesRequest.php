<?php

namespace App\Domains\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /company/employees/import: a CSV upload plus the mode. `preview`
 * (the default) reports what WOULD happen and writes nothing; `commit`
 * does it. Defaulting to the harmless one means a client that forgets
 * the field can never import by accident.
 *
 * Only the envelope is validated here (it is a file, it is small, it
 * looks like CSV). What is inside it, row by row, is
 * ImportEmployeesAction's job.
 */
class ImportEmployeesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 1 MB: far more than ImportEmployeesAction::MAX_ROWS rows need.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
            'mode' => ['sometimes', 'string', Rule::in(['preview', 'commit'])],
        ];
    }

    public function csv(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    public function shouldCommit(): bool
    {
        return $this->validated('mode', 'preview') === 'commit';
    }
}
