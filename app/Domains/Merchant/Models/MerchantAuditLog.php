<?php

namespace App\Domains\Merchant\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Database\Factories\MerchantAuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An append-only record of a merchant-portal team member's mutating
 * action — who did what, to which subject, within THEIR OWN merchant.
 * Written exclusively by
 * App\Domains\Merchant\Actions\RecordMerchantAuditLogAction; nothing else
 * should call MerchantAuditLog::create() directly.
 *
 * Deliberately separate from App\Domains\Platform\Models\AuditLog (which
 * stays admin-only and unscoped by design — see its docblock): this table
 * exists so a merchant's own Owner/Manager can see their team's activity,
 * and platform_admin must never be able to reach it. Uses BelongsToMerchant
 * like every other tenant-owned model, so it is scoped to the acting
 * user's active merchant on every read and auto-stamped on create — there
 * is no admin-context route anywhere that queries this model, so
 * BelongsToMerchant's admin bypass is never exercised for it in practice
 * (see MerchantAuditLogPolicy's docblock for the second, independent wall).
 *
 * No `updated_at`: an audit entry is immutable once written.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $actor_user_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 */
class MerchantAuditLog extends Model
{
    /** @use HasFactory<MerchantAuditLogFactory> */
    use HasFactory;

    use BelongsToMerchant;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
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

    protected static function newFactory(): MerchantAuditLogFactory
    {
        return MerchantAuditLogFactory::new();
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
