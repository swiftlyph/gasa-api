<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Catalog\Models\Product;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tile on the POS menu screen.
 *
 * Deliberately narrower than the products table will eventually be: the
 * till needs a name, a price and whether it can be sold, and nothing
 * else. When the catalog module adds categories, images and modifiers it
 * will want its own richer product resource — this one stays the POS's
 * view, so growing the catalog doesn't silently grow every till payload.
 *
 * `is_available` is included even though the default listing only returns
 * available items, because ?include_unavailable=1 exists for a manager
 * screen that needs to see the greyed-out ones.
 *
 * @mixin Product
 */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_cents' => $this->price_cents,
            'price_formatted' => Money::format($this->price_cents, $this->currency),
            'currency' => $this->currency,
            'is_available' => $this->is_available,
        ];
    }
}
