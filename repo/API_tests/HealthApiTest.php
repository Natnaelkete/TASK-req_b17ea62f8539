<?php

namespace Tests\ApiTests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function health_endpoint_returns_200_with_db_status(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status', 'timestamp',
                'checks' => ['database' => ['status', 'error'], 'disk' => ['free_percent', 'alert']],
            ])
            ->assertJson(['status' => 'healthy', 'checks' => ['database' => ['status' => 'ok']]]);
    }

    /** @test */
    public function health_endpoint_requires_no_auth(): void
    {
        // Should work without any token
        $this->getJson('/api/health')->assertStatus(200);
    }

    /** @test */
    public function health_timestamp_is_valid(): void
    {
        $data = $this->getJson('/api/health')->json();
        $parsed = \Carbon\Carbon::parse($data['timestamp']);
        $this->assertInstanceOf(\Carbon\Carbon::class, $parsed);
    }
}
