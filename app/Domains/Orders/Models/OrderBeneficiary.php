<?php

namespace App\Domains\Orders\Models;

use App\Domains\Orders\Enums\BeneficiaryType;
use Database\Factories\OrderBeneficiaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person on an order who claimed a senior-citizen or PWD discount.
 *
 * `name`, `id_number` and `type` are the RECORD of the claim — what was
 * presented at the counter, snapshotted like every other thing on an
 * order. `discount_cents` and `vat_exempt_sales_cents` are this
 * beneficiary's own totals, written by CheckoutAction in the same pass
 * that writes the lines, so they can never disagree with the lines that
 * point at this row.
 *
 * No BelongsToMerchant, and that is deliberate rather than an oversight —
 * exactly as OrderItem explains: order_beneficiaries has no merchant_id
 * column, tenancy is inherited structurally through the order (which IS
 * scoped), and a merchant_id here would be a second source of truth for
 * who owns the row that could disagree with the first.
 *
 * @property int $id
 * @property int $order_id
 * @property BeneficiaryType $type
 * @property string $name
 * @property string $id_number
 * @property int $discount_cents
 * @property int $vat_exempt_sales_cents
 */
class OrderBeneficiary extends Model
{
    /** @use HasFactory<OrderBeneficiaryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'type',
        'name',
        'id_number',
        'discount_cents',
        'vat_exempt_sales_cents',
    ];

    /**
     * Laravel guesses the factory from the model's namespace tail, which
     * doesn't exist under our Domains layout. Point it at the real one.
     */
    protected static function newFactory(): OrderBeneficiaryFactory
    {
        return OrderBeneficiaryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BeneficiaryType::class,
            'discount_cents' => 'integer',
            'vat_exempt_sales_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The lines assigned to this beneficiary — their own consumption, and
     * the only lines their discount was computed from.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'beneficiary_id');
    }
}
