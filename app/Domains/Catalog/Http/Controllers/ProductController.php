<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Actions\CreateProductAction;
use App\Domains\Catalog\Actions\DeleteProductAction;
use App\Domains\Catalog\Actions\UpdateProductAction;
use App\Domains\Catalog\Http\Requests\ListProductsRequest;
use App\Domains\Catalog\Http\Requests\StoreProductRequest;
use App\Domains\Catalog\Http\Requests\UpdateProductRequest;
use App\Domains\Catalog\Http\Resources\ProductResource;
use App\Domains\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin by convention: validate (FormRequest), authorize
 * ($this->authorize()), delegate writes to an Action, return a Resource.
 *
 * Tenancy is not handled here and must not be: BelongsToMerchant's global
 * scope already restricts both the list query and route-model binding to
 * the caller's merchant, so another merchant's {product} id is a 404
 * before this class runs — matching OrderController's docblock. The
 * authorize() calls are the second, independent check (see ProductPolicy).
 *
 * A product's recipe is edited on its own endpoint — see RecipeController
 * — but is always eager-loaded here (`recipeItems.ingredient`) so
 * ProductResource can embed it and report sellability without a second
 * request.
 */
class ProductController extends Controller
{
    public function index(ListProductsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->with('recipeItems.ingredient')
            ->when(
                $request->validated('status'),
                fn ($query, string $status) => $query->where('is_available', $status !== 'inactive'),
            )
            ->when(
                $request->validated('category'),
                fn ($query, string $category) => $query->where('category', $category),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request, CreateProductAction $action): JsonResponse
    {
        $this->authorize('create', Product::class);

        /** @var User $user */
        $user = $request->user();

        $product = $action->execute($user->merchant(), $request->validated());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load('recipeItems.ingredient'));
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductAction $action): ProductResource
    {
        $this->authorize('update', $product);

        return new ProductResource($action->execute($product, $request->validated()));
    }

    public function destroy(Product $product, DeleteProductAction $action): JsonResponse
    {
        $this->authorize('delete', $product);

        $action->execute($product);

        return response()->json(null, 204);
    }
}
