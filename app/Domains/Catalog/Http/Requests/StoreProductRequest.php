<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Support\ProductCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * No `code` field here: it's issued by the server from the category (see
 * ProductCodeGenerator). Sending one is silently ignored — validated()
 * only returns listed keys.
 *
 * No recipe fields either — a new product starts with no recipe (stock-
 * unconstrained) and one is attached afterwards via
 * PUT /merchant/products/{product}/recipe (see RecipeController). Keeping
 * creation and recipe-editing as two separate steps means a recipe never
 * has to be re-validated (ingredient ownership, unit-family matching)
 * inside the same request that's also deciding the product's name/price.
 */
class StoreProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(array_keys(ProductCodeGenerator::categories()))],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }
}
