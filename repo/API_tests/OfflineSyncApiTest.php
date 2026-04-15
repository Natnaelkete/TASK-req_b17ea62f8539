<?php

namespace Tests\ApiTests;

use App\Models\Employer;
use App\Models\Inspection;
use App\Models\Job;
use App\Models\OfflineSyncBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineSyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    private function createInspection(): Inspection
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        return Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
            'status' => 'in_progress', 'findings' => ['initial' => 'data'], 'version' => 1,
        ]);
    }

    // === Normal inputs ===

    /** @test */
    public function upload_sync_batch(): void
    {
        $inspector = User::factory()->inspector()->create();
        $inspection = $this->createInspection();
        $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'sync-001', 'device_id' => 'dev-1',
            'payload' => ['inspection_id' => $inspection->id, 'data' => ['check' => 'pass'], 'version' => 1],
        ])->assertStatus(201)->assertJsonPath('data.status', 'succeeded');
    }

    /** @test */
    public function idempotency_key_prevents_duplicate(): void
    {
        $inspector = User::factory()->inspector()->create();
        $inspection = $this->createInspection();
        $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'dup-key', 'device_id' => 'dev-1',
            'payload' => ['inspection_id' => $inspection->id, 'data' => ['x' => 1], 'version' => 1],
        ]);
        $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'dup-key', 'device_id' => 'dev-1',
            'payload' => ['inspection_id' => $inspection->id, 'data' => ['x' => 2], 'version' => 1],
        ])->assertStatus(200)->assertJson(['message' => 'Batch already processed.']);
    }

    /** @test */
    public function check_sync_status(): void
    {
        $inspector = User::factory()->inspector()->create();
        OfflineSyncBatch::create([
            'user_id' => $inspector->id, 'idempotency_key' => 'check-001',
            'device_id' => 'dev-1', 'status' => 'succeeded',
        ]);
        $this->actingAs($inspector)->getJson('/api/offline-sync/status/check-001')
            ->assertStatus(200)->assertJsonPath('data.status', 'succeeded');
    }

    // === Missing parameters ===

    /** @test */
    public function upload_without_required_fields_returns_422(): void
    {
        $inspector = User::factory()->inspector()->create();
        $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key', 'device_id', 'payload']);
    }

    /** @test */
    public function invalid_inspection_id_results_in_failed_batch(): void
    {
        $inspector = User::factory()->inspector()->create();
        $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'bad-001', 'device_id' => 'dev-1',
            'payload' => ['inspection_id' => 999999, 'data' => ['x' => 1]],
        ])->assertStatus(201);
        $batch = OfflineSyncBatch::where('idempotency_key', 'bad-001')->first();
        $this->assertEquals('failed', $batch->status);
        $this->assertNotNull($batch->last_error);
    }
}
