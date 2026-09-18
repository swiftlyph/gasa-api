<?php

namespace App\Domains\Catalog\Observers;

use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Shared\Support\MenuCache;

/**
 * The catalog module's OTHER half of MenuCache's cross-lane contract
 * (see ProductObserver's docblock for the first half). Any change to an
 * ingredient's stock — a manual correction, a checkout deduction, a void
 * restoration — can flip whether a product that uses it is sellable, so
 * the POS till's cached menu for this merchant is dropped on every
 * `saved`/`deleted` event.
 *
 * UNSCOPED, unlike ProductObserver: that one only fires for
 * catalog-managed products (`code !== null`) because plain Eloquent
 * ->update() calls on non-catalog products are exercised by pre-existing
 * tests that deliberately expect NO auto-invalidation. Ingredient is a
 * brand new table with no such precedent, so every save/delete
 * invalidates unconditionally — simpler, and correct: there is no
 * "non-catalog ingredient" the way there's a "non-catalog product".
 */
class IngredientObserver
{
    public function saved(Ingredient $ingredient): void
    {
        MenuCache::forget($ingredient->merchant_id);
    }

    public function deleted(Ingredient $ingredient): void
    {
        MenuCache::forget($ingredient->merchant_id);
    }
}
