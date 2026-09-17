<?php

/*
|--------------------------------------------------------------------------
| Catalog: product categories and their code prefixes
|--------------------------------------------------------------------------
|
| Every product created through the catalog module's own endpoints
| belongs to exactly one of these categories, and its product code is
| generated from the category's prefix plus a per-merchant counter: the
| first Drinks product a merchant creates is DRK-001, the next DRK-002,
| and so on. A code always reflects the product's CURRENT category:
| changing category on update issues a fresh code from the new prefix
| (see UpdateProductAction), rather than leaving a now-mismatched old one
| on a shelf label. The vacated number in the old category's sequence is
| never reused — same rule as a delete.
|
| Products created outside this module (e.g. ProductSeeder's demo menu,
| or any other domain's Product::factory() rows) may have a null category
| and code — they are still valid rows on the shared `products` contract
| table (see that migration's docblock), just not catalog-managed ones.
|
| Adding a category is a config change; the frontend reads the list from
| GET /merchant/catalog/categories rather than hardcoding it. Renaming or
| removing one is a data migration (existing rows reference the name).
|
*/

return [
    'categories' => [
        'Drinks' => 'DRK',
        'Snacks' => 'SNK',
        'Bakery' => 'BKY',
        'Food' => 'FOD',
        'Retail' => 'RTL',
    ],
];
