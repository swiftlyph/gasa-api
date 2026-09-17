<?php

namespace App\Domains\Company\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The uploaded CSV can't be imported AT ALL: no header row, a required
 * column missing, or too many rows. Distinct from a file whose header is
 * fine but whose rows have problems; that is a normal 200 report with
 * those rows marked invalid, never this.
 */
class InvalidImportFile extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'invalid_import_file', 422, ['file' => [$message]]);
    }
}
