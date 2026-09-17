<?php

namespace App\Domains\Catalog\Http\Requests;

use App\Domains\Catalog\Support\ProductCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for GET /merchant/products. Authorization is
 * deliberately not here — it is the controller's $this->authorize(),
 * matching every other list endpoint in this codebase.
 */
class ListProductsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'category' => ['sometimes', Rule::in(array_keys(ProductCodeGenerator::categories()))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
