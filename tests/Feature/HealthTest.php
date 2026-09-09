<?php

use Illuminate\Support\Facades\Redis;

test('health endpoint reports app version and ok db/redis connectivity', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJson([
            'db' => 'ok',
            'redis' => 'ok',
        ])
        ->assertJsonStructure([
            'app',
            'version',
            'db',
            'redis',
        ]);
});

test('health endpoint degrades gracefully to 503 when redis is unreachable', function () {
    Redis::shouldReceive('connection->ping')
        ->andThrow(new RuntimeException('Connection refused'));

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503)
        ->assertJson([
            'db' => 'ok',
            'redis' => 'fail',
        ]);
});
