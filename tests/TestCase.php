<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every call() dispatches through the kernel but reuses the same
     * booted app for the whole test, so Auth's per-guard-instance user
     * cache (RequestGuard::$user) would otherwise persist across
     * sequential requests within one test — e.g. a token revoked by one
     * request would still "work" on the next call in the same test. A
     * real HTTP request never hits this: each one is a fresh process.
     * Forgetting guards after every request makes each ->getJson()/
     * ->postJson() in a test re-authenticate exactly like a real
     * separate request would.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        Auth::forgetGuards();

        return $response;
    }
}
