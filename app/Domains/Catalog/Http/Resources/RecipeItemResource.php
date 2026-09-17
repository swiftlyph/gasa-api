<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\RecipeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a product's recipe. Flattens the ingredient's own
 * name/code/stock-status into the line (expects `ingredient` eager-loaded
 * by the caller) so a recipe builder can render a full row — including
 * whether THIS ingredient is currently low — without a second request per
 * line.
 *
 * @mixin RecipeItem
 */
class RecipeItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ingredient_id' => $this->ingredient_id,
            'ingredient_code' => $this->ingredient->code(),
            'ingredient_name' => $this->ingredient->name,
            'quantity' => $this->quantity,
            'unit' => $this->unit->value,
            'ingredient_stock_status' => $this->ingredient->stockStatus()->value,
        ];
    }
}
