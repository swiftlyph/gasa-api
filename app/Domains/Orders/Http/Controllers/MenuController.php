<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;
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
 * No Policy CLASS, unlike the order endpoints — there is still no object
 * to authorize against (an id-addressed product route would need one;
 * this one has no id to check), and the route is reachable only by an
 * active merchant (merchant.api) with the query tenant-scoped by
 * BelongsToMerchant. P8 adds one more question on top of that: does this
 * ROLE hold menu.view? Checked directly against
 * User::hasMerchantPermission() rather than via $this->authorize() (no
 * model/policy exists to dispatch to), throwing PermissionDenied
 * explicitly on failure — the same shape TeamController already uses for
 * TeamMemberPolicy calls that can't go through $this->authorize() either.
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

        if (! $user->hasMerchantPermission(MerchantPermission::MenuView)) {
            throw new PermissionDenied(MerchantPermission::MenuView);
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
     * `->where('is_available', true)` is only the merchant's manual
     * toggle — a SQL-level pre-filter, cheap, but blind to ingredient
     * stock. The REAL "can this actually be sold" question is
     * Product::isSellable(), which also asks the recipe, and can only be
     * answered in PHP once recipeItems.ingredient is loaded — so the
     * default (available-only) listing filters AGAIN, in memory, after
     * the query. `?include_unavailable=1` skips both filters and instead
     * reports each tile's real isSellable() in the resource, so a manager
     * screen can tell "I turned this off" apart from "we ran out".
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMenu(IndexMenuRequest $request, bool $includeUnavailable): array
    {
        $products = Product::query()
            ->with('recipeItems.ingredient')
            ->when(! $includeUnavailable, fn ($query) => $query->where('is_available', true))
            ->orderBy('name')
            ->get();

        if (! $includeUnavailable) {
            $products = $products->filter(fn (Product $product): bool => $product->isSellable())->values();
        }

        return MenuItemResource::collection($products)->toArray($request);
    }
}
