<?php

use App\Domains\Merchant\Http\Middleware\EnsureMerchantActive;
use App\Domains\Shared\Http\Exceptions\ApiExceptionRenderer;
use App\Domains\Shared\Http\Middleware\AllowsAdminContext;
use App\Domains\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('api/v1/admin')
                ->middleware(['api', 'admin.api'])
                ->group(base_path('routes/api/v1/admin.php'));

            Route::prefix('api/v1/company')
                ->middleware(['api', 'company.api'])
                ->group(base_path('routes/api/v1/company.php'));

            Route::prefix('api/v1/employee')
                ->middleware(['api', 'employee.api'])
                ->group(base_path('routes/api/v1/employee.php'));

            Route::prefix('api/v1/merchant')
                ->middleware(['api', 'merchant.api'])
                ->group(base_path('routes/api/v1/merchant.php'));

            Route::prefix('api/v1')
                ->middleware(['api', 'public.api'])
                ->group(base_path('routes/api/v1/public.php'));

            // Shared by every authenticated role — never duplicated per
            // portal file.
            Route::prefix('api/v1')
                ->middleware(['api', 'auth:sanctum'])
                ->group(base_path('routes/api/v1/auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // Order matters in both tenant-aware groups below.
        //
        // admin.api: AllowsAdminContext runs AFTER role:platform_admin, so
        // the tenancy bypass is only ever granted to a request that has
        // already proven it is an admin on an admin route.
        //
        // merchant.api: EnsureMerchantActive runs AFTER role:merchant, so a
        // non-merchant gets the role middleware's "forbidden" rather than
        // "merchant_inactive" — the latter would leak that the route exists
        // for merchants and invite probing.
        $middleware->group('admin.api', ['auth:sanctum', 'role:platform_admin', AllowsAdminContext::class]);
        $middleware->group('company.api', ['auth:sanctum', 'role:company_admin']);
        $middleware->group('employee.api', ['auth:sanctum', 'role:employee']);
        $middleware->group('merchant.api', ['auth:sanctum', 'role:merchant', EnsureMerchantActive::class]);
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
