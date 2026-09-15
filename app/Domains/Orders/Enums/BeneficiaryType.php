<?php

namespace App\Domains\Orders\Enums;

/**
 * The two statutory discount beneficiaries Philippine law recognises for
 * this purpose: senior citizens (RA 9994) and persons with disability
 * (RA 10754). Both carry the SAME entitlement — 20% off their own
 * consumption, and those sales are VAT-exempt — so this enum exists to
 * record WHICH basis was claimed, not to branch the arithmetic on it.
 * Nothing in StatutoryTax reads this value; the discount rate comes from
 * config('merchant.statutory_discount_bps') for both.
 *
 * Recording the basis anyway is the point: the slip has to say whether
 * the 20% was given to a senior or a PWD, and a shop asked to justify a
 * month of discounts needs the two counted separately.
 *
 * A plain string column guarded by a CHECK constraint rather than a
 * native Postgres enum type, matching the merchants.status /
 * orders.status precedent exactly: ALTER TYPE gymnastics to add a value
 * is worse than widening a CHECK, and this enum is the typed PHP mirror.
 * ADDING A CASE HERE REQUIRES A MIGRATION widening
 * order_beneficiaries_type_check.
 */
enum BeneficiaryType: string
{
    case Senior = 'senior';
    case Pwd = 'pwd';

    /**
     * A short human label — for the frontend and the printed slip, never
     * used in any arithmetic or comparison (that's always ->value).
     */
    public function label(): string
    {
        return match ($this) {
            self::Senior => 'Senior Citizen',
            self::Pwd => 'Person with Disability',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
