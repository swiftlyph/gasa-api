<?php

namespace App\Domains\Orders\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reserved checkout idempotency key.
 *
 * Tenant-owned via BelongsToMerchant, and that trait is doing real work
 * here rather than being applied out of habit: it is what makes merchant
 * B's lookup of merchant A's key value find nothing, so B's checkout
 * proceeds as a new order instead of being answered with A's. The
 * UNIQUE (merchant_id, key) index is the other half of the same rule.
 *
 * Write-once. There is no `updated_at` column and nothing edits a row
 * after its transaction commits — the only later operations are reading
 * it back on a replay and deleting it during a retention sweep.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $key
 * @property int $user_id
 * @property string $request_fingerprint
 * @property int|null $order_id
 * @property int|null $response_status
 * @property CarbonInterface|null $created_at
 */
class CheckoutIdempotencyKey extends Model
{
    use BelongsToMerchant;

    /**
     * Only `created_at` is managed: the table has no `updated_at` column,
     * and a row is never updated after its creating transaction commits.
     */
    public const UPDATED_AT = null;

    protected $table = 'checkout_idempotency_keys';

    /**
     * `order_id` and `response_status` are stamped by CheckoutAction's
     * caller between reserving the key and committing, so they are
     * fillable; nothing else about a reserved key is ever mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'key',
        'user_id',
        'request_fingerprint',
        'order_id',
        'response_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'response_status' => 'integer',
        ];
    }

    /**
     * The order this key produced. Non-null on any committed row.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
