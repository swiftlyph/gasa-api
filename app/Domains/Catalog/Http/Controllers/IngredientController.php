<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Actions\CreateIngredientAction;
use App\Domains\Catalog\Actions\DeleteIngredientAction;
use App\Domains\Catalog\Actions\UpdateIngredientAction;
use App\Domains\Catalog\Enums\StockStatus;
use App\Domains\Catalog\Http\Requests\ListIngredientsRequest;
use App\Domains\Catalog\Http\Requests\StoreIngredientRequest;
use App\Domains\Catalog\Http\Requests\UpdateIngredientRequest;
use App\Domains\Catalog\Http\Resources\IngredientResource;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

/**
 * Full CRUD, mirroring ProductController's shape exactly: validate
 * (FormRequest), authorize ($this->authorize(), see IngredientPolicy),
 * delegate writes to an Action, return a Resource. BelongsToMerchant
 * scopes every query and route-model binding, so a foreign {ingredient}
 * id is a 404 on every verb, never a 403.
 */
class IngredientController extends Controller
{
    public function index(ListIngredientsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Ingredient::class);

        $ingredients = Ingredient::query()
            ->when(
                $request->validated('status'),
                fn ($query, string $status) => $query->withStockStatus(StockStatus::from($status)),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return IngredientResource::collection($ingredients);
    }

    public function store(StoreIngredientRequest $request, CreateIngredientAction $action, RecordMerchantAuditLogAction $recordAuditLog): JsonResponse
    {
        $this->authorize('create', Ingredient::class);

        /** @var User $user */
        $user = $request->user();

        $merchant = $user->merchant();

        $ingredient = $action->execute($merchant, $request->validated());

        $recordAuditLog->execute(
            actor: $user,
            merchant: $merchant,
            action: MerchantAuditAction::IngredientCreated,
            subject: $ingredient,
            newValues: $request->validated(),
        );

        return (new IngredientResource($ingredient))->response()->setStatusCode(201);
    }

    public function show(Ingredient $ingredient): IngredientResource
    {
        $this->authorize('view', $ingredient);

        return new IngredientResource($ingredient);
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient, UpdateIngredientAction $action, RecordMerchantAuditLogAction $recordAuditLog): IngredientResource
    {
        $this->authorize('update', $ingredient);

        /** @var User $user */
        $user = $request->user();

        $before = $ingredient->getOriginal();

        $updated = $action->execute($ingredient, $request->validated());

        $recordAuditLog->execute(
            actor: $user,
            merchant: $updated->merchant,
            action: MerchantAuditAction::IngredientUpdated,
            subject: $updated,
            oldValues: Arr::only($before, array_keys($request->validated())),
            newValues: $request->validated(),
        );

        return new IngredientResource($updated);
    }

    public function destroy(Request $request, Ingredient $ingredient, DeleteIngredientAction $action, RecordMerchantAuditLogAction $recordAuditLog): JsonResponse
    {
        $this->authorize('delete', $ingredient);

        /** @var User $user */
        $user = $request->user();

        $merchant = $ingredient->merchant;
        $ingredientId = $ingredient->getKey();
        $ingredientName = $ingredient->name;

        $action->execute($ingredient);

        $recordAuditLog->execute(
            actor: $user,
            merchant: $merchant,
            action: MerchantAuditAction::IngredientDeleted,
            subject: $ingredient,
            oldValues: ['id' => $ingredientId, 'name' => $ingredientName],
        );

        return response()->json(null, 204);
    }
}
