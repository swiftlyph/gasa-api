<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * The only place App\Domains\Platform\Models\AuditLog::create() is ever
 * called. Every admin Action that mutates a subject (provisioning a
 * merchant, changing its status, resending an invite) writes its entry
 * through here rather than inline — one place to get the shape right,
 * and one place to look to know every action this platform ever records.
 */
class RecordAuditLogAction
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $context
     */
    public function execute(
        User $actor,
        string $action,
        Model $subject,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $context = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'context' => $context,
            'ip_address' => Request::ip(),
        ]);
    }
}
