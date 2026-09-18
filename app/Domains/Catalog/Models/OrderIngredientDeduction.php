<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Orders\Models\Order;
use Database\Factories\OrderIngredientDeductionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A snapshot of exactly what one order deducted from one ingredient's
 * stock at checkout — see the order_ingredient_deductions migration's
 * docblock. Not tenant-scoped (no BelongsToMerchant): it is always
 * reached through an already-scoped Order, and never listed or queried
 * directly by a merchant — the same reasoning OrderItem itself follows.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $ingredient_id
 * @property string $ingredient_name
 * @property int $quantity_base_units
 * @property Carbon|null $restored_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Ingredient|null $ingredient
 */
class OrderIngredientDeduction extends Model
{
    /** @use HasFactory<OrderIngredientDeductionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'ingredient_id',
        'ingredient_name',
        'quantity_base_units',
        'restored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_base_units' => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrderIngredientDeductionFactory
    {
        return OrderIngredientDeductionFactory::new();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
