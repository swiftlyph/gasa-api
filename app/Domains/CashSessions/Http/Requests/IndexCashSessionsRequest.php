<?php

namespace App\Domains\CashSessions\Http\Requests;

use App\Domains\CashSessions\Enums\CashSessionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string validation for GET /merchant/cash-sessions — the paginated
 * history. Matches IndexOrdersRequest's shape: recognised filters are
 * validated strictly, unrecognised ones are ignored rather than rejected.
 */
class IndexCashSessionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(CashSessionStatus::values())],
            'register_id' => ['sometimes', 'integer', 'min:1'],

            // A calendar day range, merchant-local. date_format rather
            // than `date`, matching IndexOrdersRequest, so "next tuesday"
            // fails loudly instead of being silently parsed.
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'The from filter must be a calendar day in YYYY-MM-DD format.',
            'to.date_format' => 'The to filter must be a calendar day in YYYY-MM-DD format.',
            'to.after_or_equal' => 'The to filter must not be before the from filter.',
        ];
    }
}
