<?php

namespace App\Domains\Merchant\Enums;

/**
 * Mirrors the CHECK constraint on merchants.status. Adding a case here
 * REQUIRES a migration widening that constraint — the database is the
 * real enforcement, this enum is the typed view of it.
 *
 * Only `Active` merchants may use the merchant portal; `Pending` (not yet
 * approved) and `Suspended` both get a 403 `merchant_inactive` from
 * EnsureMerchantActive.
 */
enum MerchantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
