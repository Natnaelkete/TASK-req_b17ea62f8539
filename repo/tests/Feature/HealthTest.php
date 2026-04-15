<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks' => [
                    'database' => ['status', 'error'],
                    'disk' => ['free_percent', 'alert'],
                ],
            ])
            ->assertJson([
                'status' => 'healthy',
                'checks' => [
                    'database' => [
                        'status' => 'ok',
                        'error' => null,
                    ],
                ],
            ]);
    }

    public function test_health_endpoint_returns_disk_info(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertIsFloat($data['checks']['disk']['free_percent']);
        $this->assertIsBool($data['checks']['disk']['alert']);
    }

    public function test_health_timestamp_is_valid_iso8601(): void
    {
        $response = $this->getJson('/api/health');
        $data = $response->json();

        $this->assertNotNull($data['timestamp']);
        $parsed = \Carbon\Carbon::parse($data['timestamp']);
        $this->assertInstanceOf(\Carbon\Carbon::class, $parsed);
    }
}
