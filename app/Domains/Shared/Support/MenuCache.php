<?php

namespace App\Domains\Shared\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The merchant menu cache — and, more importantly, the ONLY place its key
 * is constructed.
 *
 * THE KEY IS TENANT-NAMESPACED, AND THAT IS THE WHOLE POINT. The audited
 * system cached its product list under a single global key
 * ("pos_products"), so whichever merchant warmed the cache first served
 * their menu — prices included — to every other merchant on the platform.
 * Nothing about that failure is visible in a single-tenant test, or on a
 * dev box with one shop in it; it only shows up in production, as another
 * shop's drinks appearing on your till.
 *
 * Hence: no caller anywhere composes a menu cache key by hand. They call
 * remember() or forget(), both of which take a merchant id and cannot
 * produce a key without one.
 *
 * CROSS-LANE CONTRACT. This class lives in Shared rather than Orders
 * because two lanes touch it: the POS menu endpoint READS through it, and
 * the catalog module (LO's) must INVALIDATE it. Any code that creates,
 * updates, deletes, or changes the availability or price of a product
 * must call MenuCache::forget($product->merchant_id) — a Product model
 * observer is the obvious home for that once the catalog module exists.
 * The short TTL below is a safety net for a missed invalidation, not a
 * substitute for one.
 */
class MenuCache
{
    /**
     * Deliberately short. A menu is read on every POS screen load and
     * changes rarely, so a minute of caching removes almost all of the
     * query load; and if an invalidation is ever missed, a stale price is
     * visible for a minute rather than until someone notices.
     */
    public const TTL_SECONDS = 60;

    /**
     * The two variants the menu endpoint can ask for. Enumerated as a
     * constant so forget() can clear every one of them without guessing —
     * a variant that gets added later but not listed here would survive
     * invalidation and serve stale prices indefinitely.
     */
    private const VARIANTS = ['available', 'all'];

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(int $merchantId, bool $includeUnavailable, Closure $callback): mixed
    {
        return Cache::remember(
            self::key($merchantId, $includeUnavailable),
            self::TTL_SECONDS,
            $callback,
        );
    }

    /**
     * Drops every cached variant of one merchant's menu. Safe to call
     * more often than strictly necessary — that is much cheaper than
     * calling it less often than necessary.
     */
    public static function forget(int $merchantId): void
    {
        foreach (self::VARIANTS as $variant) {
            Cache::forget(self::keyFor($merchantId, $variant));
        }
    }

    public static function key(int $merchantId, bool $includeUnavailable): string
    {
        return self::keyFor($merchantId, $includeUnavailable ? 'all' : 'available');
    }

    private static function keyFor(int $merchantId, string $variant): string
    {
        return sprintf('merchant:%d:menu:%s', $merchantId, $variant);
    }
}
