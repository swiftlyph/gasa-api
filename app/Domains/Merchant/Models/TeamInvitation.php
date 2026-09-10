<?php

namespace App\Domains\Merchant\Models;

use App\Domains\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time, expiring invite for a team member added via POST
 * /merchant/team — see the team_invitations migration for the storage
 * reasoning (token_hash, never plaintext).
 *
 * Deliberately NOT BelongsToMerchant: it is looked up by token from
 * AcceptInviteAction, a public unauthenticated endpoint with no
 * authenticated tenant in scope to apply that global scope against —
 * tenancy for this row is established by whichever merchant_id the token
 * itself resolves to, not by the caller.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class TeamInvitation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
