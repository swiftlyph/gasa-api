<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Replaces a product's ENTIRE recipe in one call — the same
 * "send the complete desired state, the server reconciles" shape the old
 * track_inventory fields used, and the simplest one for a recipe-builder
 * form that's really editing one list. `ingredients.*.ingredient_id` must
 * belong to the caller's own merchant (checked in withValidator(), since
 * Rule::exists() can't see the authenticated user) and each id may only
 * appear once — two lines for the same ingredient should be one line with
 * a bigger quantity.
 *
 * `unit` is validated against the REFERENCED ingredient's own unit_type
 * in withValidator() too — this is the check that makes "30ml against a
 * kg-tracked ingredient" impossible to save (see the Unit enum's
 * docblock). An empty `ingredients` array is valid: it clears the recipe,
 * making the product stock-unconstrained again.
 */
class UpdateRecipeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ingredients' => ['present', 'array'],
            'ingredients.*.ingredient_id' => ['required', 'integer'],
            'ingredients.*.quantity' => ['required', 'integer', 'min:1'],
            'ingredients.*.unit' => ['required', 'string', Rule::enum(Unit::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lines = $this->input('ingredients');

            if (! is_array($lines)) {
                return;
            }

            $seenIngredientIds = [];

            foreach ($lines as $index => $line) {
                $ingredientId = $line['ingredient_id'] ?? null;

                if (! is_numeric($ingredientId)) {
                    continue;
                }

                $ingredientId = (int) $ingredientId;

                if (isset($seenIngredientIds[$ingredientId])) {
                    $validator->errors()->add(
                        "ingredients.{$index}.ingredient_id",
                        'The same ingredient can only appear once in a recipe.',
                    );

                    continue;
                }

                $seenIngredientIds[$ingredientId] = true;

                // BelongsToMerchant's global scope already restricts this
                // to the authenticated user's own merchant — a foreign
                // ingredient id resolves to null exactly like a missing
                // one, which is what makes "invalid" the right message
                // for both rather than leaking which ids exist elsewhere.
                $ingredient = Ingredient::find($ingredientId);

                if ($ingredient === null) {
                    $validator->errors()->add(
                        "ingredients.{$index}.ingredient_id",
                        'The selected ingredient is invalid.',
                    );

                    continue;
                }

                $unit = Unit::tryFrom((string) ($line['unit'] ?? ''));

                if ($unit !== null && $unit->type() !== $ingredient->unit_type) {
                    $validator->errors()->add(
                        "ingredients.{$index}.unit",
                        "This ingredient is tracked in {$ingredient->unit_type->value}; use one of: ".
                            implode(', ', Unit::valuesFor($ingredient->unit_type)).'.',
                    );
                }
            }
        });
    }
}
