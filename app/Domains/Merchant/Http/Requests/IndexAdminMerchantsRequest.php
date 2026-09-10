<?php

namespace App\Domains\Merchant\Http\Requests;

use App\Domains\Merchant\Enums\MerchantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /admin/merchants filters. Mirrors IndexOrdersRequest's shape:
 * per_page capped at 100, recognized filters validated strictly.
 * Authorization is not here — the admin.api middleware group.
 */
class IndexAdminMerchantsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(MerchantStatus::values())],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
