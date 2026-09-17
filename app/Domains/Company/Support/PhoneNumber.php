<?php

namespace App\Domains\Company\Support;

/**
 * Normalizes a typed mobile number to E.164 (+639171234567) so the value
 * stored is the one an SMS gateway or an OTP flow can use as-is. Applied
 * by the employee FormRequests and the CSV importer BEFORE validation,
 * which then only has to check the E.164 shape.
 *
 * Numbers typed without a country code are read as Philippine mobiles
 * (09XXXXXXXXX, 9XXXXXXXXX), which is what an HR team here will type.
 * Anything already carrying a country code (+, 00, or a leading 63) is
 * kept as that country's number.
 *
 * What it cannot make sense of is returned AS TYPED, deliberately: the
 * validation rule then rejects it with the value the person recognises,
 * instead of silently storing or dropping a mangled number.
 */
final class PhoneNumber
{
    public const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $typed = trim($raw);

        if ($typed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $typed) ?? '';

        if ($digits === '') {
            return $typed;
        }

        return match (true) {
            str_starts_with($typed, '+') => '+'.$digits,
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            strlen($digits) === 12 && str_starts_with($digits, '63') => '+'.$digits,
            strlen($digits) === 11 && str_starts_with($digits, '09') => '+63'.substr($digits, 1),
            strlen($digits) === 10 && str_starts_with($digits, '9') => '+63'.$digits,
            default => $typed,
        };
    }
}
