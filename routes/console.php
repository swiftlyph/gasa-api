<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled maintenance
|--------------------------------------------------------------------------
|
| Requires a running scheduler on the host: `* * * * * php artisan schedule:run`
| (see README § Local setup). Without it these never fire.
|
*/

// Checkout idempotency keys are useful for the life of a retry and dead
// after that. Hourly with the default 24-hour window keeps the table at
// roughly one day of sales instead of growing forever.
Schedule::command('orders:prune-idempotency-keys')
    ->hourly()
    ->withoutOverlapping();
