<?php

namespace App\Domains\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /merchant/menu.
 *
 * One knob. `boolean` accepts 1/0/"1"/"0"/true/false, so
 * ?include_unavailable=1 works from a plain URL and the value is still
 * strictly validated rather than being any truthy string.
 */
class IndexMenuRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'include_unavailable' => ['sometimes', 'boolean'],
        ];
    }
}
