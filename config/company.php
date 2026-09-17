<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company Day Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone "today" means for the company portal, e.g. the default
    | separation date when an employee is marked separated without one.
    | Same reasoning as config/merchant.php's day_timezone: app.timezone
    | stays UTC for storage and logging, which is the wrong answer to
    | "what date is it for the HR person clicking this button."
    |
    | Falls back to the merchant value so a deployment that already set
    | one timezone doesn't need a second variable. There is no per-company
    | timezone column yet; when one lands, this is the only value it
    | replaces.
    |
    */

    'day_timezone' => env('COMPANY_DAY_TIMEZONE', env('MERCHANT_DAY_TIMEZONE', 'Asia/Manila')),

];
