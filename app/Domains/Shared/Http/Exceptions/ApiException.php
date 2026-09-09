<?php

namespace App\Domains\Shared\Http\Exceptions;

use Exception;

/**
 * Base class for domain-raised API errors that need a specific message,
 * machine-readable code, and status — e.g. "wrong portal for this role"
 * or "invalid login credentials". Rendered by ApiExceptionRenderer, which
 * is the only place API errors get shaped into JSON; never add a second
 * error-rendering path alongside this one.
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status = 400,
        private readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }
}
