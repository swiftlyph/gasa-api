<?php

namespace App\Domains\CashSessions\Models;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\CashSessionStatus;
use App\Domains\CashSessions\Policies\CashSessionPolicy;
use App\Domains\Orders\Models\Order;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Carbon\CarbonInterface;
use Database\Factories\CashSessionFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One till-shift. Tenant-owned, and — like an order — never deleted: a
 * closed session is a financial record of what a cashier counted, and
 * "reopen" is not a thing this domain offers (see CashSessionStatus).
 *
 * Deliberately absent from this class, for the same reasons Order states
 * them:
 *
 * - No status-transition logic. Whether opening/closing is legal belongs
 *   to the Actions (OpenCashSessionAction / CloseCashSessionAction) and
 *   the partial unique index, not a method here.
 * - No reconciliation maths. `expected_cash_cents` is SNAPSHOTTED at
 *   close by ReconcileCashSessionAction, the one authority on that
 *   arithmetic — this model never computes it itself, so there is exactly
 *   one formula to audit and test.
 *
 * `#[UsePolicy]` is explicit for the same reason as on Order: auto-
 * discovery looks for App\Policies\CashSessionPolicy, which doesn't exist
 * under the Domains layout.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $register_id
 * @property int $opened_by_user_id
 * @property int|null $closed_by_user_id
 * @property CashSessionStatus $status
 * @property int $opening_float_cents
 * @property int|null $counted_cash_cents
 * @property int|null $expected_cash_cents
 * @property int|null $variance_cents
 * @property CarbonInterface $opened_at
 * @property CarbonInterface|null $closed_at
 * @property string|null $notes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[UsePolicy(CashSessionPolicy::class)]
class CashSession extends Model
{
    /** @use HasFactory<CashSessionFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * `status`, `counted_cash_cents`, `expected_cash_cents`,
     * `variance_cents`, `closed_at` and `closed_by_user_id` are
     * deliberately absent — a session opens as `open` (the column
     * default) with everything else null, and only
     * CloseCashSessionAction ever assigns them, directly rather than via
     * mass assignment, so no generic update() can forge a close.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'register_id',
        'opened_by_user_id',
        'opening_float_cents',
        'opened_at',
        'notes',
    ];

    protected static function newFactory(): CashSessionFactory
    {
        return CashSessionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opening_float_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'expected_cash_cents' => 'integer',
            'variance_cents' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Register, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * @return HasMany<CashRemittance, $this>
     */
    public function remittances(): HasMany
    {
        return $this->hasMany(CashRemittance::class);
    }

    /**
     * Orders rung up while this session was open — see CheckoutAction for
     * how (and how conditionally) that stamp happens.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }
}
