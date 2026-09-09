<?php

/*
|--------------------------------------------------------------------------
| Portal → Role Map
|--------------------------------------------------------------------------
|
| Maps each frontend portal to the single spatie/laravel-permission role
| permitted to log into it. Used by the login action to reject valid
| credentials presented against the wrong portal, and by the per-audience
| route groups in bootstrap/app.php for the role: middleware.
|
*/

return [
    'admin' => 'platform_admin',
    'company' => 'company_admin',
    'employee' => 'employee',
    'merchant' => 'merchant',
];
