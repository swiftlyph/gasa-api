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

];
