<?php

namespace App\Domains\Orders\Models;

use Database\Factories\OrderItemAddOnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on snapshot on one order line (extra shot, oat milk). Same rule
 * as OrderItem: `name` and `price_cents` are the record as of the sale,
 * not a live lookup. Tenancy is inherited through order_item -> order.
 *
 * @property int $id
 * @property int $order_item_id
 * @property string $name
 * @property int $price_cents
 */
class OrderItemAddOn extends Model
{
    /** @use HasFactory<OrderItemAddOnFactory> */
    use HasFactory;

    protected $table = 'order_item_add_ons';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_item_id',
        'name',
        'price_cents',
    ];

    protected static function newFactory(): OrderItemAddOnFactory
    {
        return OrderItemAddOnFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
