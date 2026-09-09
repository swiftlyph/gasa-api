<?php

namespace App\Domains\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for both kitchen-queue endpoints.
 *
 * One knob, shared by the queue and its summary so the badge and the
 * screen can never describe different sets of orders.
 *
 * `all=1` drops the today-only filter. It exists because the audited
 * system silently lost work: an order rung up at 23:58 vanished from the
 * queue two minutes later, and a queue left open overnight came back
 * empty in the morning with unmade drinks still pending. Today-by-default
 * keeps the screen small; `all=1` is how the kitchen finds anything that
 * fell off the edge of a day.
 */
class IndexKitchenQueueRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
        ];
    }
}
