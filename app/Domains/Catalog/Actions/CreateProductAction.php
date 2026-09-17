<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\ProductCodeGenerator;
use App\Domains\Merchant\Models\Merchant;
use Illuminate\Support\Facades\DB;

/**
 * Creates a product for the given merchant, issuing its code from the
 * category prefix. No recipe is attached here — a new product starts
 * stock-unconstrained; see RecipeController/UpdateRecipeAction for
 * attaching one afterwards.
 *
 * The code is issued and the row inserted in ONE transaction so the
 * sequence row lock (see ProductCodeGenerator) is held until the product
 * exists — a concurrent create for the same category waits, then gets the
 * next number.
 *
 * merchant_id is set explicitly from $merchant, via setAttribute (it
 * isn't fillable, matching `code`) rather than left to BelongsToMerchant's
 * `creating` hook: the hook only stamps a tenant from the AUTHENTICATED
 * user, which is correct from a controller but absent for a seeder — see
 * CatalogDemoSeeder, which calls this same Action for each merchant with
 * no authenticated user at all, inside admin context (where the hook is
 * bypassed entirely, "trust whatever was explicitly set").
 */
class CreateProductAction
{
    public function __construct(private readonly ProductCodeGenerator $codes) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated StoreProductRequest data.
     */
    public function execute(Merchant $merchant, array $attributes): Product
    {
        return DB::transaction(function () use ($merchant, $attributes): Product {
            /** @var string $category */
            $category = $attributes['category'];

            $modelAttributes = $attributes;
            unset($modelAttributes['status']);
            $modelAttributes['is_available'] = ($attributes['status'] ?? 'active') !== 'inactive';

            // The products table defaults `currency` to 'PHP' at the DB
            // level, but a just-built (unrefreshed) model doesn't know
            // that yet — the 201 response would otherwise render `null`
            // for a create that omitted it. Match the column default
            // explicitly rather than a silent refresh() round-trip.
            $modelAttributes['currency'] ??= 'PHP';

            $product = new Product($modelAttributes);
            $product->setAttribute('merchant_id', $merchant->getKey());
            $product->setAttribute('code', $this->codes->next($merchant->getKey(), $category));
            $product->save();

            return $product->load('recipeItems.ingredient');
        });
    }
}
