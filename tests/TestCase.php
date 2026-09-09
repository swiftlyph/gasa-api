<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * DO NOT remove this override — it fixes a real bug we hit while
     * writing the logout tests.
     *
     * Every call() dispatches through the kernel but reuses the same
     * booted app for the whole test, so Auth's per-guard-instance user
     * cache (RequestGuard::$user, used by the "sanctum" guard too) would
     * otherwise persist across sequential requests within one test —
     * e.g. a token revoked by one request would still "work" on the next
     * call in the same test, because the guard never re-runs the token
     * lookup once it has cached a user. A real HTTP request never hits
     * this: each one is a fresh process with its own Auth instance.
     * Forgetting guards after every request makes each ->getJson()/
     * ->postJson() in a test re-authenticate exactly like a real
     * separate request would. (See config/sanctum.php for the related
     * but distinct 'web' guard issue this does NOT fix by itself.)
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        Auth::forgetGuards();

        return $response;
    }
}
