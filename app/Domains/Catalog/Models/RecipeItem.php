<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Database\Factories\RecipeItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a product's recipe: how much of one Ingredient one unit of
 * the product consumes. See the recipe_items migration's docblock for why
 * both the as-entered (`quantity`, `unit`) and the computed
 * (`quantity_base_units`) forms are stored.
 *
 * @property int $id
 * @property int $merchant_id
 * @property int $product_id
 * @property int $ingredient_id
 * @property int $quantity
 * @property Unit $unit
 * @property int $quantity_base_units
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read Ingredient $ingredient
 */
class RecipeItem extends Model
{
    /** @use HasFactory<RecipeItemFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'ingredient_id',
        'quantity',
        'unit',
        'quantity_base_units',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit' => Unit::class,
            'quantity_base_units' => 'integer',
        ];
    }

    protected static function newFactory(): RecipeItemFactory
    {
        return RecipeItemFactory::new();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
