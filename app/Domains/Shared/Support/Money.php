<?php

namespace App\Domains\Shared\Support;

/**
 * Formatting only. Money in this codebase is ALWAYS an integer number of
 * cents plus a currency code; no arithmetic belongs here — this class
 * turns stored cents into a string for humans, and that is its whole job.
 *
 * API responses carry BOTH: `total_cents` for clients that compute, and
 * `total_formatted` for clients that display. Frontends that build their
 * own display string from cents drift apart from each other on rounding
 * and symbol placement; handing them a server-rendered string keeps a
 * receipt, a kitchen ticket and a report reading identically.
 *
 * Deliberately NOT ext-intl / NumberFormatter: the extension is not
 * installed on this box or in CI, and a currency formatter that silently
 * changes its output when a server's ICU data differs is worse than a
 * three-line lookup table. Add a symbol here when a currency is added.
 */
class Money
{
    /**
     * Currency code => display symbol. A code with no entry formats with
     * the code itself as the prefix ("USD 120.50"), which is correct and
     * readable rather than wrong and pretty.
     *
     * @var array<string, string>
     */
    private const SYMBOLS = [
        'PHP' => '₱',
    ];

    /**
     * Renders cents in the given currency, e.g. (12050, 'PHP') => "₱120.50".
     *
     * Division happens exactly once, here, at the boundary where the value
     * stops being arithmetic and becomes text.
     */
    public static function format(int $cents, string $currency): string
    {
        $currency = mb_strtoupper($currency);
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        // Formatted from the absolute value so the sign stays outside the
        // symbol: -₱5.00, not ₱-5.00.
        $amount = number_format(abs($cents) / 100, 2);

        return ($cents < 0 ? '-' : '').$symbol.$amount;
    }

    /**
     * Null-tolerant format(), for the columns that are legitimately absent
     * — cash_cents / gcash_cents on a non-split order. A null amount
     * formats to null, never to "₱0.00", because "this order had no cash
     * component" and "this order had zero pesos of cash" are different
     * statements and only one of them is true.
     */
    public static function formatNullable(?int $cents, string $currency): ?string
    {
        return $cents === null ? null : self::format($cents, $currency);
    }
}
