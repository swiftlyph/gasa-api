<?php

namespace App\Domains\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces every API request to be treated as expecting a JSON response,
 * so validation failures, auth failures, and unhandled exceptions are
 * always rendered as JSON instead of redirecting to a login route.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
