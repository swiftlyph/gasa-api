<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * No `unit_type` here — deliberately immutable after creation. Existing
 * stock and every recipe line pointing at this ingredient are stored in
 * ITS unit_type's base unit; changing the family after the fact would
 * leave that history meaning something different than the number says.
 * A merchant who genuinely needs a different family (matcha as a liquid
 * concentrate instead of powder, say) creates a new ingredient and
 * retires this one, same as a product's immutable code forces a
 * re-issue rather than a rewrite.
 */
class UpdateIngredientRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'display_unit' => ['sometimes', 'string'],
            'quantity_on_hand' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $displayUnit = $this->input('display_unit');

            if (! is_string($displayUnit)) {
                return;
            }

            /** @var Ingredient $ingredient */
            $ingredient = $this->route('ingredient');
            $unitType = $ingredient->unit_type;

            if (Unit::tryFrom($displayUnit)?->type() !== $unitType) {
                $validator->errors()->add(
                    'display_unit',
                    "The display unit must be a {$unitType->value} unit (".implode(', ', Unit::valuesFor($unitType)).').',
                );
            }
        });
    }
}
