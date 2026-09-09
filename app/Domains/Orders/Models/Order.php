<?php

namespace App\Domains\Orders\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use App\Domains\Orders\Policies\OrderPolicy;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single sale. Tenant-owned (BelongsToMerchant), never deleted, never
 * soft-deleted — a voided order is the reversal, and both `completed` and
 * `voided` are terminal (see OrderStatus).
 *
 * Deliberately absent from this class:
 *
 * - No SoftDeletes, and no destroy path anywhere in the domain. Financial
 *   records are append-only.
 * - No status-transition logic. Whether a move is legal is OrderStatus'
 *   decision and nothing else's; performing one is CompleteOrderAction /
 *   VoidOrderAction's job. A `markCompleted()` helper here would become a
 *   second, unguarded way to change status.
 * - No total recalculation. Totals are what was charged, not what the
 *   current catalog would charge.
 *
 * `#[UsePolicy]` is explicit because Laravel's policy auto-discovery
 * looks for App\Policies\OrderPolicy, which doesn't exist under the
 * Domains layout — same reason newFactory() is overridden.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $order_number
 * @property OrderStatus $status
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $total_cents
 * @property string $currency
 * @property PaymentMethod $payment_method
 * @property int|null $cash_cents
 * @property int|null $gcash_cents
 * @property int $created_by_user_id
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $voided_at
 * @property int|null $voided_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[UsePolicy(OrderPolicy::class)]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * The columns CREATION sets, and only those.
     *
     * `status`, `completed_at`, `voided_at` and `voided_by_user_id` are
     * deliberately absent. A new order is always `pending` (the column
     * default), and after that its lifecycle columns move only through
     * CompleteOrderAction / VoidOrderAction, which assign them directly
     * rather than mass-assigning — so leaving them out costs nothing and
     * means no `Order::create()` or `->update()` anywhere can ever set a
     * status or forge a void audit trail. Factories are unaffected:
     * Laravel builds factory models unguarded.
     *
     * With Model::preventSilentlyDiscardingAttributes() enabled globally
     * this list is also a typo guard — a misspelled key throws instead of
     * being silently dropped from the write.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'order_number',
        'subtotal_cents',
        'discount_cents',
        'total_cents',
        'currency',
        'payment_method',
        'cash_cents',
        'gcash_cents',
        'created_by_user_id',
    ];

    /**
     * Laravel guesses the factory from the model's namespace tail, which
     * doesn't exist under our Domains layout. Point it at the real one.
     */
    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'cash_cents' => 'integer',
            'gcash_cents' => 'integer',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The cashier who rang the order up.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
