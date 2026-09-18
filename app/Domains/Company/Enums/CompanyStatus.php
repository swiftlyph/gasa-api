<?php

namespace App\Domains\Company\Enums;

/**
 * Mirrors the CHECK constraint on companies.status the same way
 * MerchantStatus mirrors merchants.status. Adding a case here REQUIRES a
 * migration widening that constraint: the database is the real
 * enforcement, this enum is the typed view of it.
 *
 * Only `Active` companies may use the company portal; `Pending` (not yet
 * approved) and `Suspended` both get a 403 `company_inactive` from
 * EnsureCompanyActive.
 *
 * No allowedTransitions() yet, deliberately: nothing moves a company's
 * status this phase except a seeder. The platform-admin company
 * provisioning phase adds the transition map here, mirroring
 * MerchantStatus, once there is an endpoint to enforce it on.
 */
enum CompanyStatus: string
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
