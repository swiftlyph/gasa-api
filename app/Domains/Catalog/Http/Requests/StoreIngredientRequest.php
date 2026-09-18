<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Enums\UnitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `unit_type` is required here and NEVER accepted on update — it is
 * immutable once set (see UpdateIngredientRequest's docblock for why).
 * `display_unit` must belong to whichever `unit_type` was sent, checked
 * in withValidator() because Rule::in() alone can't see a sibling field's
 * value while building the rule list.
 *
 * `quantity_on_hand` / `low_stock_threshold` are entered in the
 * ingredient's OWN display unit (matching what a merchant actually reads
 * off a bag or a scale) and converted to base units by
 * CreateIngredientAction — never stored as typed.
 */
class StoreIngredientRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'unit_type' => ['required', Rule::enum(UnitType::class)],
            'display_unit' => ['required', 'string'],
            'quantity_on_hand' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unitType = UnitType::tryFrom((string) $this->input('unit_type'));
            $displayUnit = $this->input('display_unit');

            if ($unitType === null || ! is_string($displayUnit)) {
                return;
            }

            if (Unit::tryFrom($displayUnit)?->type() !== $unitType) {
                $validator->errors()->add(
                    'display_unit',
                    "The display unit must be a {$unitType->value} unit (".implode(', ', Unit::valuesFor($unitType)).').',
                );
            }
        });
    }
}
