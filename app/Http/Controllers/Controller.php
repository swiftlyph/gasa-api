<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller. Carries AuthorizesRequests only, so domain controllers
 * can call $this->authorize() — the form the README documents for policy
 * checks. Nothing else belongs here: shared behaviour goes in middleware
 * or a Shared Action, not in a god-class every controller inherits.
 *
 * Extending this is optional. Controllers that never authorize (e.g.
 * HealthController, AuthController) deliberately don't.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
