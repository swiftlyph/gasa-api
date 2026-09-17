<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Product;

/**
 * Hard delete. The inventory row goes with it via the FK cascade. Any
 * order_items referencing this product keep working — that FK is
 * nullOnDelete and order lines snapshot their own name/price (see the
 * original products migration's docblock), so this never rewrites
 * history.
 */
class DeleteProductAction
{
    public function execute(Product $product): void
    {
        $product->delete();
    }
}
