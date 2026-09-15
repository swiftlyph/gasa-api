<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Merchant Day Timezone
    |--------------------------------------------------------------------------
    |
    | The ONE timezone every "calendar day" question in the merchant API
    | resolves against — see App\Domains\Orders\Support\MerchantDay. This is
    | deliberately NOT config('app.timezone'): that setting drives PHP's
    | date functions and stays UTC for logging/storage, which is correct for
    | it and wrong for "what day did the shop experience this sale on."
    | Conflating the two was the bug that shipped: a merchant in UTC+8 asking
    | for "today" got UTC's today instead of their own.
    |
    | There is no per-merchant timezone column yet, so every merchant shares
    | this single value. When one is added, MerchantDay::timezone() is the
    | only call site that changes — nothing here or at any call site needs
    | to know that happened.
    |
    */

    'day_timezone' => env('MERCHANT_DAY_TIMEZONE', 'Asia/Manila'),

    /*
    |--------------------------------------------------------------------------
    | Statutory Tax & Discount Constants (P10)
    |--------------------------------------------------------------------------
    |
    | Philippine statutory figures, kept HERE rather than inline in any
    | Action so an accountant can correct a rate without reading PHP. They
    | are deliberately NOT per-merchant columns: the VAT rate and the
    | senior/PWD discount rate are set by national law, identical for every
    | merchant on the platform. What IS per-merchant is whether the shop is
    | VAT-registered at all — that's `merchants.vat_registered`, a column.
    |
    | Expressed in BASIS POINTS (1/100th of a percent) so the arithmetic
    | stays in integers end to end, exactly like every money value in this
    | codebase: 12% VAT is 1200 bps, a 20% discount is 2000 bps. A float
    | rate here would reintroduce the floating-point money the whole
    | project bans, in the one place it would be least visible.
    |
    | Nothing reads these but App\Domains\Orders\Support\StatutoryTax —
    | see its docblock for the formulas and the rounding rule.
    |
    */

    'vat_rate_bps' => (int) env('MERCHANT_VAT_RATE_BPS', 1200),

    'statutory_discount_bps' => (int) env('MERCHANT_STATUTORY_DISCOUNT_BPS', 2000),

];
