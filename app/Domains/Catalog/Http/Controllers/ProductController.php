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
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

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

    public function store(StoreProductRequest $request, CreateProductAction $action, RecordMerchantAuditLogAction $recordAuditLog): JsonResponse
    {
        $this->authorize('create', Product::class);

        /** @var User $user */
        $user = $request->user();

        $merchant = $user->merchant();

        $product = $action->execute($merchant, $request->validated());

        $recordAuditLog->execute(
            actor: $user,
            merchant: $merchant,
            action: MerchantAuditAction::ProductCreated,
            subject: $product,
            newValues: $request->validated(),
        );

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load('recipeItems.ingredient'));
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductAction $action, RecordMerchantAuditLogAction $recordAuditLog): ProductResource
    {
        $this->authorize('update', $product);

        /** @var User $user */
        $user = $request->user();

        $before = $product->getOriginal();

        $updated = $action->execute($product, $request->validated());

        $recordAuditLog->execute(
            actor: $user,
            merchant: $updated->merchant,
            action: MerchantAuditAction::ProductUpdated,
            subject: $updated,
            oldValues: Arr::only($before, array_keys($request->validated())),
            newValues: $request->validated(),
        );

        return new ProductResource($updated);
    }

    public function destroy(Request $request, Product $product, DeleteProductAction $action, RecordMerchantAuditLogAction $recordAuditLog): JsonResponse
    {
        $this->authorize('delete', $product);

        /** @var User $user */
        $user = $request->user();

        $merchant = $product->merchant;
        $productId = $product->getKey();
        $productName = $product->name;

        $action->execute($product);

        $recordAuditLog->execute(
            actor: $user,
            merchant: $merchant,
            action: MerchantAuditAction::ProductDeleted,
            subject: $product,
            oldValues: ['id' => $productId, 'name' => $productName],
        );

        return response()->json(null, 204);
    }
}
