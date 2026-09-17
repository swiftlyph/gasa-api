<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Support\ProductCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update: every field is optional, only what's sent changes. Used
 * for both PUT and PATCH.
 *
 * `code` is not accepted as input — a client can never set or choose
 * one — but sending a `category` different from the product's current
 * one DOES cause a new code to be issued for it, by
 * UpdateProductAction/ProductCodeGenerator (see config/catalog.php).
 *
 * The recipe is edited on its own endpoint (see RecipeController), not
 * here — see StoreProductRequest's docblock for why.
 */
class UpdateProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', Rule::in(array_keys(ProductCodeGenerator::categories()))],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
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
