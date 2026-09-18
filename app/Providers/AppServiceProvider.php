<?php

namespace App\Providers;

use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Observers\IngredientObserver;
use App\Domains\Catalog\Observers\ProductObserver;
use App\Domains\Shared\Concerns\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Request-scoped tenancy state. Singleton so the admin-bypass flag
        // set by AllowsAdminContext is visible to every global scope in the
        // same request; the container is rebuilt per request in production,
        // so it does not leak between them. (Tests share one app across
        // sequential calls — see TenantContext's note in the test suite.)
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mass assignment that would silently DROP an attribute now
        // throws instead. Enabled in every environment, including
        // production: the failure mode it replaces is a write that looks
        // like it succeeded and lost a column, which is far worse than a
        // 500 on the one request that had the bug. The audit found two of
        // exactly that shape.
        //
        // This is a typo guard, not a security boundary — it makes a
        // misspelled key ("total_cent") explode instead of vanishing. It
        // does NOT make $fillable lists safe to feed raw request input:
        // that rule is unchanged (Actions build payloads themselves; see
        // the note on Order::$fillable).
        Model::preventSilentlyDiscardingAttributes();

        // API Resources return flat shapes (no implicit "data" wrapper) so
        // every response matches the documented shapes exactly. Note this
        // only flattens SINGLE resources — a paginated ResourceCollection
        // still wraps as { data, links, meta } regardless of this call,
        // because Laravel forces that wrapper whenever pagination info is
        // merged in. See README § Response shapes before "fixing" that.
        JsonResource::withoutWrapping();

        // 5 attempts/minute keyed on email+IP so one leaked password can't
        // be brute-forced from a single client, but distinct emails from
        // the same office IP don't throttle each other out.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // The catalog module's half of MenuCache's cross-lane contract
        // (see that class's docblock and README § "The POS menu, and its
        // cache"): any create/update/delete on a product invalidates the
        // POS till's cached menu for that merchant.
        Product::observe(ProductObserver::class);

        // Same contract, the ingredient side: a stock change can flip
        // whether a product that uses it is sellable, so it invalidates
        // the menu too — see IngredientObserver's docblock.
        Ingredient::observe(IngredientObserver::class);
    }
}
