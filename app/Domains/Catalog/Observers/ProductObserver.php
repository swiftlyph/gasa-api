<?php

namespace App\Domains\Catalog\Observers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Shared\Support\MenuCache;

/**
 * THE catalog-module half of the cross-lane contract MenuCache's
 * docblock describes: the POS menu endpoint (App\Domains\Orders) reads
 * through that cache, and this class is what invalidates it — a create,
 * update, or delete through the catalog module's own endpoints drops
 * every cached menu variant for that merchant, exactly as README §
 * "The POS menu, and its cache" requires.
 *
 * SCOPED TO CATALOG-MANAGED ROWS ONLY (`code !== null` — a code is
 * issued exclusively by ProductCodeGenerator, so its presence IS the
 * "came through the catalog module" signal). A product from ProductSeeder
 * or another domain's factory/test has no code and is deliberately left
 * alone: tests/Feature/Orders/MenuTest.php's "a stale menu is served
 * until the cache is invalidated" case asserts that a raw Eloquent
 * ->update() on such a product does NOT auto-invalidate — proving the
 * cache genuinely needs an explicit forget() rather than accidentally
 * passing because something else cleared it. A blanket observer on
 * every save would make that assertion false and defeat its purpose.
 *
 * `saved` fires for both created and updated, so a single hook covers
 * both without duplicating the call. The TTL (60s) is a safety net for a
 * missed invalidation, never a substitute for this observer.
 */
class ProductObserver
{
    public function saved(Product $product): void
    {
        if ($product->code !== null) {
            MenuCache::forget($product->merchant_id);
        }
    }

    public function deleted(Product $product): void
    {
        if ($product->code !== null) {
            MenuCache::forget($product->merchant_id);
        }
    }
}
