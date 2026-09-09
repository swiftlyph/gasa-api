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
     * @return HasMany<OrderItemAddOn, $this>
     */
    public function addOns(): HasMany
    {
        return $this->hasMany(OrderItemAddOn::class);
    }
}
