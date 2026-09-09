<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Shared\Concerns\BelongsToMerchant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * STUB — the joint contract model for the catalog module.
 *
 * The catalog (categories, availability rules, modifiers, images,
 * endpoints) belongs to another developer's lane. This class exists only
 * so the Orders domain has something to FK against and something for P2's
 * checkout to read a server-side price from. It is deliberately bare: no
 * controller, no policy, no resource, no scopes.
 *
 * Extend it in the catalog module rather than replacing it — orders point
 * at `products.id` and that link must stay stable.
 *
 * Note what Orders does NOT do with this model: it never reads a name or
 * price back out of it to render an existing order. Those are snapshotted
 * onto order_items at creation (see that migration), so this model can
 * change or be deleted without rewriting history.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 * @property int $price_cents
 * @property string $currency
 * @property bool $is_available
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'name',
        'price_cents',
        'currency',
        'is_available',
    ];

    /**
     * Laravel guesses the factory from the model's namespace tail, which
     * doesn't exist under our Domains layout. Point it at the real one.
     */
    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_available' => 'boolean',
        ];
    }
}
