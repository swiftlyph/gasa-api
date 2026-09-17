<?php

namespace App\Domains\Catalog\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues product codes: the category's prefix plus a zero-padded, per-
 * merchant counter — DRK-001, DRK-002, … Padding is three digits but the
 * number is not capped; the thousandth Drinks product is DRK-1000.
 *
 * Concurrency: next() locks the (merchant, prefix) counter row FOR UPDATE
 * before bumping it. Laravel's RefreshDatabase already wraps each test in
 * a transaction, so the lock is effective there too (as a savepoint);
 * outside tests it should be called inside DB::transaction() so the lock
 * is actually held until the row it protects is inserted.
 */
final class ProductCodeGenerator
{
    /**
     * @return array<string, string> category name => prefix
     */
    public static function categories(): array
    {
        /** @var array<string, string> $categories */
        $categories = config('catalog.categories', []);

        return $categories;
    }

    public static function prefixFor(string $category): string
    {
        $prefix = self::categories()[$category] ?? null;

        if (! is_string($prefix)) {
            throw new InvalidArgumentException("Unknown product category [{$category}].");
        }

        return $prefix;
    }

    public function next(int $merchantId, string $category): string
    {
        $prefix = self::prefixFor($category);
        $now = now();

        DB::table('product_code_sequences')->insertOrIgnore([
            'merchant_id' => $merchantId,
            'prefix' => $prefix,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = DB::table('product_code_sequences')
            ->where('merchant_id', $merchantId)
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->first();

        $number = (int) $row->last_number + 1;

        DB::table('product_code_sequences')
            ->where('id', $row->id)
            ->update(['last_number' => $number, 'updated_at' => $now]);

        return self::format($prefix, $number);
    }

    public static function format(string $prefix, int $number): string
    {
        return sprintf('%s-%03d', $prefix, $number);
    }
}
