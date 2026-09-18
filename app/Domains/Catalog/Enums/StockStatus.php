<?php

namespace App\Domains\Catalog\Enums;

/**
 * Derived, never stored. Computed from quantity_on_hand vs
 * low_stock_threshold in exactly one place (InventoryItem::stockStatus()
 * and its matching query scope) so every client — POS, kitchen, the
 * merchant portal — agrees on what "low" means.
 */
enum StockStatus: string
{
    case InStock = 'in_stock';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
