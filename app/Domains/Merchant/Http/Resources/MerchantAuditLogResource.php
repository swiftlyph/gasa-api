<?php

namespace App\Domains\Merchant\Http\Resources;

use App\Domains\Merchant\Models\MerchantAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single merchant_audit_logs row — flat shape, no `data` wrapper for the
 * single form, matching App\Domains\Platform\Http\Resources\AuditLogResource.
 * `merchant_id` is deliberately not exposed: it is always "the caller's
 * own merchant", never meaningfully different per row from this endpoint.
 *
 * @mixin MerchantAuditLog
 */
class MerchantAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor' => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => (string) $this->actor->email,
            ],
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'context' => $this->context,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
