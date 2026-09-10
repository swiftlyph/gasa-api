<?php

namespace App\Domains\Orders\Http\Requests;

use App\Domains\Orders\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for GET /merchant/orders.
 *
 * Unknown filters are ignored rather than rejected — a frontend sending
 * `?sort=whatever` shouldn't get a 422 for a parameter this endpoint has
 * simply not implemented yet. Filters that ARE recognised are validated
 * strictly, so `?status=complete` fails loudly instead of silently
 * returning every order, which is the failure mode that makes people
 * distrust a list endpoint.
 *
 * Authorization is deliberately not here: it is the controller's
 * $this->authorize('viewAny', ...) against OrderPolicy, so all
 * order authorization reads from one class.
 */
class IndexOrdersRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(OrderStatus::values())],

            // A single day, merchant-local — see MerchantDay for what
            // "merchant-local" resolves to today. date_format rather than
            // `date`: `date` would happily accept "next tuesday".
            'date' => ['sometimes', 'date_format:Y-m-d'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'The date filter must be a calendar day in YYYY-MM-DD format.',
        ];
    }
}
