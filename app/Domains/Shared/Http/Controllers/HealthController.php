<?php

namespace App\Domains\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Public health check. Degrades gracefully: a failing dependency never
 * throws past this controller — it's reported as "fail" in the body, and
 * the response status drops to 503 so uptime monitors and load balancers
 * see it as unhealthy without a stack trace ever reaching the client.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $db = $this->check(fn () => DB::connection()->getPdo());
        $redis = $this->check(fn () => Redis::connection()->ping());

        $healthy = $db === 'ok' && $redis === 'ok';

        return response()->json([
            'app' => config('app.name'),
            'version' => config('app.version'),
            'db' => $db,
            'redis' => $redis,
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            return 'fail';
        }
    }
}
