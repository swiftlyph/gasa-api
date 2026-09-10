<?php

namespace App\Domains\Platform\Models;

use App\Domains\Auth\Models\User;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An append-only record of a platform-admin action — who did what, to
 * which subject, and what changed. Written exclusively by
 * App\Domains\Platform\Actions\RecordAuditLogAction; nothing else should
 * call AuditLog::create() directly.
 *
 * Deliberately NOT App\Domains\Shared\Concerns\BelongsToMerchant: this
 * table spans every merchant by design (a single admin's action log, or
 * one merchant's full status history, both need to read across tenancy).
 * Because it carries no tenant scope of its own, it must only ever be
 * reachable through routes/api/v1/admin.php (auth:sanctum +
 * role:platform_admin + AllowsAdminContext) — there is no other guard
 * standing between this table and a request.
 *
 * No `updated_at`: an audit entry is immutable once written.
 *
 * @property int $id
 * @property int $actor_user_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_user_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'context',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
