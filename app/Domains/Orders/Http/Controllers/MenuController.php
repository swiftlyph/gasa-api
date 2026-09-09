<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Orders\Http\Requests\IndexMenuRequest;
use App\Domains\Orders\Http\Resources\MenuItemResource;
use App\Domains\Shared\Support\MenuCache;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /merchant/menu — what the POS screen draws its tiles from.
 *
 * WHY THIS LIVES IN Orders AND NOT Catalog: it is a read-only projection
 * of the shared `products` table for the till, owned by the POS lane. The
 * catalog module owns products themselves and will bring its own
 * management endpoints (categories, images, availability rules) in its
 * own namespace. Nothing here writes to products, and nothing here should
 * grow into product CRUD.
 *
 * No Policy call, unlike the order endpoints, and that is a considered
 * difference rather than an omission: there is no object to authorize.
 * The route is reachable only by an active merchant (merchant.api), and
 * the query is tenant-scoped by BelongsToMerchant, so "which products"
 * has exactly one possible answer — the caller's own. An id-addressed
 * product route would need a policy; this one has no id to check.
 */
class MenuController extends Controller
{
    public function __invoke(IndexMenuRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Guaranteed non-null by EnsureMerchantActive; read here because
        // the cache key must be namespaced by tenant and there is no
        // acceptable fallback if it isn't.
        $merchant = $user->merchant();

        if ($merchant === null) {
            return response()->json(['data' => []]);
        }

        $includeUnavailable = $request->boolean('include_unavailable');

        $menu = MenuCache::remember(
            $merchant->getKey(),
            $includeUnavailable,
            fn (): array => $this->buildMenu($request, $includeUnavailable),
        );

        // An explicit envelope rather than a bare array: unpaginated
        // collections return { data: [...] } with no links/meta, so every
        // list endpoint has a `data` key regardless of pagination (see
        // README § Response shapes).
        return response()->json(['data' => $menu]);
    }

    /**
     * Resolved to a plain array before it reaches the cache. Caching
     * Eloquent models would serialise the model class and its casts into
     * Redis, so a later change to Product (a column, a cast, a namespace
     * move) would hit unserialisable rows already in the cache. Arrays
     * survive that; the Resource still owns the shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMenu(IndexMenuRequest $request, bool $includeUnavailable): array
    {
        $products = Product::query()
            ->when(! $includeUnavailable, fn ($query) => $query->where('is_available', true))
            ->orderBy('name')
            ->get();

        return MenuItemResource::collection($products)->toArray($request);
    }
}
