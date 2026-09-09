<?php

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
