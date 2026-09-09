<?php

namespace App\Domains\CashSessions\Models;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\CashMovementType;
use App\Domains\CashSessions\Policies\CashMovementPolicy;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Carbon\CarbonInterface;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in the cash ledger. Write-once: a movement, once recorded, is
 * never edited or deleted — a mistaken entry is corrected with an
 * offsetting movement, not by rewriting history, matching the "orders are
 * never deleted" reasoning used throughout this codebase.
 *
 * `amount_cents` is always positive; `type` carries direction (see
 * CashMovementType::sign(), which ReconcileCashSessionAction is the only
 * caller of).
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $cash_session_id
 * @property CashMovementType $type
 * @property int $amount_cents
 * @property string $reason
 * @property int $created_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[UsePolicy(CashMovementPolicy::class)]
class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'cash_session_id',
        'type',
        'amount_cents',
        'reason',
        'created_by_user_id',
    ];

    protected static function newFactory(): CashMovementFactory
    {
        return CashMovementFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
