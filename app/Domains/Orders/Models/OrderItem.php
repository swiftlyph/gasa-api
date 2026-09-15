<?php

namespace App\Domains\Orders\Models;

use App\Domains\Catalog\Models\Product;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line on an order. `product_name` and `unit_price_cents` are
 * SNAPSHOTS taken at creation — read them, never `$item->product->name`.
 * The product relation exists for reporting ("units sold of this
 * product") and is null once the product is deleted; the line still
 * renders in full.
 *
 * No BelongsToMerchant here, and that is deliberate rather than an
 * oversight: order_items has no merchant_id column. Tenancy is inherited
 * structurally — an item is only ever reachable through its order, which
 * is scoped, so there is no unscoped query that could return another
 * merchant's lines. Adding a merchant_id here would create a second
 * source of truth for who owns the row, and the two could disagree.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property string $product_name
 * @property int $unit_price_cents
 * @property int $quantity
 * @property int $line_total_cents
 * @property int|null $beneficiary_id
 * @property int $net_of_vat_cents
 * @property int $discount_cents
 * @property int $payable_cents
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'unit_price_cents',
        'quantity',
        'line_total_cents',

        // P10. `line_total_cents` above is UNCHANGED — still the
        // pre-discount, VAT-inclusive amount charged for the line. These
        // decompose it: `discount_cents` is this line's STATUTORY
        // (senior/PWD) discount only, never the order-level promo.
        'beneficiary_id',
        'net_of_vat_cents',
        'discount_cents',
        'payable_cents',
    ];

    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'line_total_cents' => 'integer',
            'beneficiary_id' => 'integer',
            'net_of_vat_cents' => 'integer',
            'discount_cents' => 'integer',
            'payable_cents' => 'integer',
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
     * Reporting link only — NOT the source of this line's name or price.
     * Null once the product is deleted from the catalog.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The beneficiary this line was consumed by, if any (P10). Null for
     * every ordinary line — most lines belong to nobody — and it is only
     * a line with a beneficiary that ever carries a statutory discount.
     *
     * @return BelongsTo<OrderBeneficiary, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(OrderBeneficiary::class, 'beneficiary_id');
    }

    /**
     * @return HasMany<OrderItemAddOn, $this>
     */
    public function addOns(): HasMany
    {
        return $this->hasMany(OrderItemAddOn::class);
    }
}
