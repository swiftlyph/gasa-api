<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Shared\Concerns\BelongsToMerchant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * THE JOINT CONTRACT MODEL — see the original create_products_table
 * migration's docblock for what Orders relies on (id, merchant_id, name,
 * price_cents) and must never lose.
 *
 * `category` and `code` are the catalog module's own extension, added by
 * a later migration. Both are nullable: only products created through
 * this module's endpoints (ProductController) get them — a product from
 * ProductSeeder's demo menu, or another domain's factory row, is still a
 * perfectly valid row here with both null. `code` (e.g. "DRK-001") is
 * issued by ProductCodeGenerator on create, and reissued whenever
 * `category` changes on update (see UpdateProductAction). It's
 * deliberately NOT fillable either way, so only that generator, never a
 * request payload or a careless update(), decides its value.
 *
 * STOCK LIVES ON INGREDIENTS, NOT HERE. A product carries no quantity of
 * its own — recipeItems() is the list of ingredients (and how much of
 * each) one unit of it consumes, and isSellable() asks THOSE ingredients'
 * stock, not a number on this row. A product with no recipe is
 * unconstrained by stock entirely (isSellable() then depends only on
 * is_available) — that covers every product created before this system,
 * and any product a merchant genuinely never wants stock-limited.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 * @property string|null $category
 * @property string|null $code
 * @property string|null $description
 * @property int $price_cents
 * @property string $currency
 * @property bool $is_available
 * @property-read Collection<int, RecipeItem> $recipeItems
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * merchant_id and code are deliberately NOT fillable: the trait stamps
     * the tenant, and CreateProductAction/UpdateProductAction set the code
     * explicitly via setAttribute — client input can never supply either.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'description',
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

    /**
     * @return HasMany<RecipeItem, $this>
     */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /**
     * Can this be sold RIGHT NOW — the merchant's own on/off switch AND,
     * for a product with a recipe, enough of every ingredient for at
     * least one unit. This is what CheckoutAction and the POS menu
     * (MenuItemResource) both ask; neither reads `is_available` directly
     * any more, exactly so a depleted ingredient greys out a tile without
     * the merchant having to notice and flip a switch by hand.
     *
     * Only a fast, defensive signal — it is NOT what actually guards a
     * sale. Two POS terminals can both see "sellable" a moment apart and
     * both try to buy the last cup; DeductIngredientsForOrderAction is
     * the real, row-locked guard that runs inside the checkout
     * transaction and is the only thing allowed to reject a sale for
     * certain.
     *
     * Requires recipeItems.ingredient to already be eager-loaded by the
     * caller (every caller does) — this deliberately never lazy-loads,
     * so checking a whole menu's worth of products never N+1s.
     */
    public function isSellable(): bool
    {
        if (! $this->is_available) {
            return false;
        }

        foreach ($this->recipeItems as $item) {
            if ($item->ingredient->quantity_on_hand < $item->quantity_base_units) {
                return false;
            }
        }

        return true;
    }
}
