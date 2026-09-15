<?php

namespace App\Domains\Orders\Support;

/**
 * THE single authority on Philippine statutory tax and discount
 * arithmetic. Nothing else in the codebase divides by a VAT rate or takes
 * a percentage off a line — CheckoutAction composes these methods, the
 * reports sum what they produced, and there is exactly one formula of
 * each kind to audit.
 *
 * Same discipline as ReconcileCashSessionAction (see its docblock): a
 * money formula that exists in two places will eventually disagree with
 * itself, and the disagreement surfaces as a shop's books not balancing
 * rather than as a failing test.
 *
 * THE RULES, as the law states them (see README § Tax & statutory
 * discounts for the worked examples):
 *
 * Senior citizens (RA 9994) and PWDs (RA 10754) get 20% off THEIR OWN
 * consumption, and those sales are VAT-EXEMPT. In a group order only the
 * lines assigned to the beneficiary are affected.
 *
 *   VAT-registered merchant (prices are VAT-INCLUSIVE at 12%):
 *     beneficiary line:      net      = round(line_total / 1.12)
 *                            discount = round(net × 20%)
 *                            payable  = net − discount
 *                            → `net` is VAT-EXEMPT SALES; no VAT is due
 *                              on it at all. The customer is relieved of
 *                              BOTH the VAT and a further 20%, which is
 *                              why the discount is taken off the NET and
 *                              not off the shelf price.
 *     non-beneficiary line:  vatable  = round(line_total / 1.12)
 *                            vat      = line_total − vatable
 *                            payable  = line_total (VAT stays in the price)
 *
 *   Non-VAT merchant (no VAT was ever in the price):
 *     beneficiary line:      discount = round(line_total × 20%)
 *                            payable  = line_total − discount
 *     non-beneficiary line:  payable  = line_total
 *     → every line is NON-VAT SALES; every VAT field is zero.
 *
 * ROUNDING: HALF-UP to the cent, at each step named above, and never
 * anywhere else. A half-cent rounds away from zero (₱0.005 → ₱0.01),
 * which is the convention Philippine receipts use and the one a customer
 * checking the arithmetic by hand will expect.
 *
 * NO FLOATS. Every method here is integer arithmetic end to end — the
 * "/ 1.12" above is written as a basis-point ratio, not a float divide.
 * A float would reintroduce the binary-fraction error this codebase bans
 * everywhere else, in the one place it would be hardest to spot: 1.12 is
 * not representable in binary, so round($x / 1.12) is already wrong at
 * the half-cent boundary before any rounding rule is applied.
 *
 * The two rates are national law, identical for every merchant, and live
 * in config/merchant.php so an accountant can correct one without
 * reading PHP. Whether a given shop is VAT-registered is per-merchant
 * DATA (merchants.vat_registered), and is passed in rather than read
 * here — this class knows the formulas, never whose order it is.
 */
class StatutoryTax
{
    /**
     * The VAT rate in basis points (1200 = 12%), from config.
     */
    public static function vatRateBps(): int
    {
        return (int) config('merchant.vat_rate_bps');
    }

    /**
     * The senior/PWD discount rate in basis points (2000 = 20%), from
     * config. The same rate for both beneficiary types — see
     * BeneficiaryType, which records which basis was claimed, not a
     * different entitlement.
     */
    public static function statutoryDiscountBps(): int
    {
        return (int) config('merchant.statutory_discount_bps');
    }

    /**
     * Strips VAT out of a VAT-INCLUSIVE amount:
     * net = round(gross / (1 + rate)), half-up.
     *
     * In basis points, gross / (1 + bps/10000) is gross × 10000 /
     * (10000 + bps) — one integer division, rounded half-up, with no
     * float anywhere. At 1200 bps this is exactly the "/ 1.12" the law
     * describes.
     */
    public static function netOfVat(int $grossCents, int $vatRateBps): int
    {
        return self::divideRoundingHalfUp(
            $grossCents * 10_000,
            10_000 + $vatRateBps,
        );
    }

    /**
     * The VAT contained in a VAT-inclusive amount — DERIVED BY
     * SUBTRACTION, never computed as its own rounded percentage.
     *
     * That is deliberate and load-bearing: net + vat must equal the gross
     * exactly, or a receipt's own lines don't add up to the amount the
     * customer paid. Rounding both halves independently would let them
     * miss each other by a centavo.
     */
    public static function vatOn(int $grossCents, int $vatRateBps): int
    {
        return $grossCents - self::netOfVat($grossCents, $vatRateBps);
    }

    /**
     * A percentage of an amount, in basis points, rounded half-up — the
     * 20% statutory discount.
     */
    public static function percentageOf(int $amountCents, int $bps): int
    {
        return self::divideRoundingHalfUp($amountCents * $bps, 10_000);
    }

    /**
     * Integer division rounding HALF-UP (away from zero on a tie), the
     * one rounding rule this phase uses.
     *
     * Written as integer arithmetic rather than round($n / $d): the float
     * form is wrong twice over — it rounds half-to-even in some PHP
     * configurations, and $n / $d has already lost precision before
     * round() sees it. Adding half the divisor before truncating gives
     * the half-up answer exactly, for any inputs.
     *
     * The negative branch keeps the rule symmetric (−0.5 → −1). No caller
     * passes a negative today — every money input here is a non-negative
     * line amount — but a rounding helper that silently rounds the wrong
     * way for negatives is a trap for whoever first subtracts something.
     */
    private static function divideRoundingHalfUp(int $numerator, int $divisor): int
    {
        if ($numerator >= 0) {
            return intdiv(2 * $numerator + $divisor, 2 * $divisor);
        }

        return -intdiv(2 * -$numerator + $divisor, 2 * $divisor);
    }
}
