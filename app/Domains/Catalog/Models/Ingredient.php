<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\StockStatus;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Enums\UnitType;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A raw material a product's recipe is built from — matcha powder, milk,
 * a cup. THE inventory this merchant's stock actually lives on; a
 * product itself carries no stock of its own (see Product::recipeItems()).
 *
 * `quantity_on_hand` / `low_stock_threshold` are always stored in the
 * family's BASE unit (see the Unit enum's docblock for why: exact integer
 * math, no drift across thousands of deductions). `display_unit` is
 * purely which unit the merchant sees them formatted in.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 * @property UnitType $unit_type
 * @property Unit $display_unit
 * @property int $quantity_on_hand
 * @property int $low_stock_threshold
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RecipeItem> $recipeItems
 */
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'unit_type',
        'display_unit',
        'quantity_on_hand',
        'low_stock_threshold',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity_on_hand' => 0,
        'low_stock_threshold' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_type' => UnitType::class,
            'display_unit' => Unit::class,
            'quantity_on_hand' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    protected static function newFactory(): IngredientFactory
    {
        return IngredientFactory::new();
    }

    /**
     * @return HasMany<RecipeItem, $this>
     */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** The four-digit ID people see, e.g. "0001" — see the class docblock. Never truncates past 9999. */
    public function code(): string
    {
        return str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function formattedQuantityOnHand(): string
    {
        return Unit::formatQuantity($this->quantity_on_hand, $this->display_unit);
    }

    public function formattedLowStockThreshold(): string
    {
        return Unit::formatQuantity($this->low_stock_threshold, $this->display_unit);
    }

    /**
     * The single definition of "low" for an ingredient — same rule
     * StockStatus already encodes for the old per-product model, reused
     * as-is: it was always about comparing an on-hand quantity to a
     * threshold, never about what the quantity represented.
     */
    public function stockStatus(): StockStatus
    {
        if ($this->quantity_on_hand <= 0) {
            return StockStatus::OutOfStock;
        }

        if ($this->quantity_on_hand <= $this->low_stock_threshold) {
            return StockStatus::LowStock;
        }

        return StockStatus::InStock;
    }

    /**
     * SQL twin of stockStatus(), for filtering a list server-side.
     *
     * @param  Builder<Ingredient>  $query
     * @return Builder<Ingredient>
     */
    public function scopeWithStockStatus(Builder $query, StockStatus $status): Builder
    {
        return match ($status) {
            StockStatus::OutOfStock => $query->where('quantity_on_hand', '<=', 0),
            StockStatus::LowStock => $query
                ->where('quantity_on_hand', '>', 0)
                ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold'),
            StockStatus::InStock => $query
                ->whereColumn('quantity_on_hand', '>', 'low_stock_threshold'),
        };
    }
}
