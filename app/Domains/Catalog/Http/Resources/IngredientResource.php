<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `code` (e.g. "0001") is a display-only, zero-padded rendering of the
 * numeric id (see Ingredient::code()) — there is no separate generated
 * sequence the way Product::code is; the id already is the unique,
 * auto-incrementing identifier the merchant asked for.
 *
 * Quantities ship three ways: the base-unit integer (`quantity_on_hand`)
 * for any client that wants to compute with it, and a formatted string in
 * the ingredient's own `display_unit` for anything that just wants to
 * show it — same shape as every money field in this codebase.
 *
 * @mixin Ingredient
 */
class IngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code(),
            'name' => $this->name,
            'unit_type' => $this->unit_type->value,
            'display_unit' => $this->display_unit->value,
            'quantity_on_hand' => $this->quantity_on_hand,
            'quantity_on_hand_formatted' => $this->formattedQuantityOnHand().' '.$this->display_unit->value,
            'low_stock_threshold' => $this->low_stock_threshold,
            'low_stock_threshold_formatted' => $this->formattedLowStockThreshold().' '.$this->display_unit->value,
            'stock_status' => $this->stockStatus()->value,
            // The units this ingredient's family supports — so a recipe
            // builder can offer the right dropdown for a line referencing
            // it without a second request.
            'available_units' => Unit::valuesFor($this->unit_type),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
