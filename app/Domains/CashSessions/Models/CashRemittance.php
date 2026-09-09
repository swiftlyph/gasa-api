<?php

namespace App\Domains\CashSessions\Models;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\RemittanceStatus;
use App\Domains\CashSessions\Policies\CashRemittancePolicy;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Carbon\CarbonInterface;
use Database\Factories\CashRemittanceFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash taken out of the till and remitted upward. See the
 * create_cash_remittances_table migration for why confirmation requires a
 * second user, and ConfirmRemittanceAction for where that is enforced.
 *
 * `confirmed_by_user_id`, `confirmed_at` and `status` are deliberately
 * absent from $fillable, matching the pattern on Order and CashSession:
 * a remittance is created `pending` (the column default) and only
 * ConfirmRemittanceAction moves it to `confirmed`, by direct assignment.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $cash_session_id
 * @property int $amount_cents
 * @property string|null $note
 * @property string|null $attachment_path
 * @property int $created_by_user_id
 * @property int|null $confirmed_by_user_id
 * @property CarbonInterface|null $confirmed_at
 * @property RemittanceStatus $status
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[UsePolicy(CashRemittancePolicy::class)]
class CashRemittance extends Model
{
    /** @use HasFactory<CashRemittanceFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'cash_session_id',
        'amount_cents',
        'note',
        'created_by_user_id',
    ];

    protected static function newFactory(): CashRemittanceFactory
    {
        return CashRemittanceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RemittanceStatus::class,
            'amount_cents' => 'integer',
            'confirmed_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function isConfirmed(): bool
    {
        return $this->status === RemittanceStatus::Confirmed;
    }
}
