<?php

namespace App\Domains\Platform\Http\Resources;

use App\Domains\Platform\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single audit_logs row. Flat shape, no `data` wrapper for the single
 * form — see README § Response shapes. Used both standalone (embedded as
 * a merchant's status history) and inside a ::collection() for
 * GET /admin/audit-logs.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
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
