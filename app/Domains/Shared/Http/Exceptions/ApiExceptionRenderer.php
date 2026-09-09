<?php

namespace App\Domains\Shared\Http\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Normalizes every API error response to the project-wide shape:
 *
 *     { "message": string, "code": string, "errors"?: object }
 *
 * Unauthenticated requests always render as 401 JSON here — never a
 * redirect to a login route.
 */
class ApiExceptionRenderer
{
    public function handles(Request $request, Throwable $e): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => $this->json($e->getMessage(), $e->errorCode(), $e->status(), $e->errors()),
            $e instanceof AuthenticationException => $this->json('Unauthenticated.', 'unauthenticated', 401),
            $e instanceof AuthorizationException => $this->json('This action is unauthorized.', 'forbidden', 403),
            $e instanceof ValidationException => $this->json(
                $e->getMessage(),
                'validation_failed',
                $e->status,
                $e->errors(),
            ),
            // Both the raw exception and the NotFoundHttpException that
            // Laravel wraps it in on the route-model-binding path. Without
            // the getPrevious() arm, a missing bound model falls through to
            // the HttpExceptionInterface case below and renders Laravel's
            // internal message verbatim — "No query results for model
            // [App\Domains\Orders\Models\Order] 7", which publishes our
            // namespace layout to anyone who guesses a URL and contradicts
            // the documented 404 body.
            $e instanceof ModelNotFoundException,
            $e->getPrevious() instanceof ModelNotFoundException => $this->json(
                'Resource not found.',
                'not_found',
                404,
            ),
            $e instanceof ThrottleRequestsException => $this->json(
                $e->getMessage() ?: 'Too many attempts.',
                'too_many_attempts',
                429,
            ),
            $e instanceof HttpExceptionInterface => $this->json(
                $e->getMessage() ?: $this->defaultMessageFor($e->getStatusCode()),
                $this->codeFor($e->getStatusCode()),
                $e->getStatusCode(),
            ),
            default => $this->json(
                config('app.debug') ? $e->getMessage() : 'Server error.',
                'server_error',
                500,
            ),
        };
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    private function json(string $message, string $code, int $status, ?array $errors = null): JsonResponse
    {
        $payload = ['message' => $message, 'code' => $code];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    private function codeFor(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            422 => 'validation_failed',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'server_error' : 'error',
        };
    }

    private function defaultMessageFor(int $status): string
    {
        return match ($status) {
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            429 => 'Too many requests.',
            default => $status >= 500 ? 'Server error.' : 'Error.',
        };
    }
}
