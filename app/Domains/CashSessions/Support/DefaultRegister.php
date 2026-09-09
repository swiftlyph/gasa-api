<?php

namespace App\Domains\CashSessions\Support;

use App\Domains\CashSessions\Exceptions\NoRegisterConfigured;
use App\Domains\CashSessions\Models\Register;

/**
 * "The default register" — the single place that decides which register
 * checkout and the cash-session endpoints fall back to when a caller
 * doesn't name one.
 *
 * The merchant's OLDEST register (lowest id) among their ACTIVE ones, not
 * a stored `is_default` flag. A single-till shop has exactly one register,
 * so this is simply "that one" — the concept only starts to matter once a
 * second till exists, and by then a merchant naming registers explicitly
 * in requests no longer needs a default at all.
 */
class DefaultRegister
{
    /**
     * @throws NoRegisterConfigured if the merchant has no active register —
     *                              unreachable in practice once DevSeeder
     *                              or merchant onboarding has run, but a
     *                              merchant record with no register is not
     *                              a state checkout should silently ignore.
     */
    public static function for(int $merchantId): Register
    {
        $register = Register::query()
            ->where('is_active', true)
            ->oldest('id')
            ->first();

        if ($register === null) {
            throw new NoRegisterConfigured($merchantId);
        }

        return $register;
    }
}
