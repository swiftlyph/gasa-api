<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\ProductCodeGenerator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The fixed category list (config/catalog.php) with each one's code
 * prefix, so the frontend's category dropdown and the codes it previews
 * come from the same source as validation. Not paginated: it's a handful
 * of config entries, returned as a plain array.
 *
 * Gated by the same permission as browsing the catalog itself
 * (catalog.view) — there's no {product} to authorize against, so this
 * asks ProductPolicy::viewAny the same way IndexMenuRequest's controller
 * asks its own "can this user see this list at all" question.
 */
class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $categories = collect(ProductCodeGenerator::categories())
            ->map(fn (string $prefix, string $name) => ['name' => $name, 'prefix' => $prefix])
            ->values();

        return response()->json($categories);
    }
}
