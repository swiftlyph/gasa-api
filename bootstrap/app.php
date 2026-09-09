<?php

use App\Domains\Shared\Http\Exceptions\ApiExceptionRenderer;
use App\Domains\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('api/v1')
                ->middleware(['api', 'admin.api'])
                ->group(base_path('routes/api/v1/admin.php'));

            Route::prefix('api/v1')
                ->middleware(['api', 'company.api'])
                ->group(base_path('routes/api/v1/company.php'));

            Route::prefix('api/v1')
                ->middleware(['api', 'employee.api'])
                ->group(base_path('routes/api/v1/employee.php'));

            Route::prefix('api/v1')
                ->middleware(['api', 'merchant.api'])
                ->group(base_path('routes/api/v1/merchant.php'));

            Route::prefix('api/v1')
                ->middleware(['api', 'public.api'])
                ->group(base_path('routes/api/v1/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('admin.api', []);
        $middleware->group('company.api', []);
        $middleware->group('employee.api', []);
        $middleware->group('merchant.api', []);
        $middleware->group('public.api', []);

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $renderer = new ApiExceptionRenderer;

        $exceptions->shouldRenderJsonWhen(fn ($request, Throwable $e) => $renderer->handles($request, $e));

        $exceptions->render(fn (Throwable $e, $request) => $renderer->handles($request, $e)
            ? $renderer->render($e)
            : null);
    })->create();
