<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * The framework health endpoint is registered in bootstrap/app.php
     * (health: '/up'). Running this test requires composer dependencies
     * to be installed (vendor/).
     */
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
