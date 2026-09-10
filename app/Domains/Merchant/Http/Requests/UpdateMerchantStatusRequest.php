<?php

namespace App\Domains\Merchant\Http\Requests;

use App\Domains\Merchant\Enums\MerchantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /admin/merchants/{merchant}/status. `status` is validated only
 * for SHAPE here (one of the three known values) — whether the specific
 * FROM -> TO move is legal is MerchantStatus::assertCanTransitionTo()'s
 * question, asked by ChangeMerchantStatusAction, not this FormRequest's.
 */
class UpdateMerchantStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(MerchantStatus::values())],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{status: string, reason?: string|null}
     */
    public function payload(): array
    {
        /** @var array{status: string, reason?: string|null} $payload */
        $payload = $this->validated();

        return $payload;
    }
}
