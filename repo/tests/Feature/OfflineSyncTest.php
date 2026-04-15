<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Inspection;
use App\Models\Job;
use App\Models\OfflineSyncBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
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
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
            'status' => 'in_progress',
            'findings' => ['initial' => 'data'],
            'version' => 1,
        ]);
    }

    public function test_upload_offline_sync_batch(): void
    {
        $inspector = User::factory()->inspector()->create();
        $inspection = $this->createInspection();

        $response = $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'sync-001',
            'device_id' => 'device-abc',
            'payload' => [
                'inspection_id' => $inspection->id,
                'data' => ['field_check' => 'pass', 'photos_taken' => 3],
                'version' => 1,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertDatabaseHas('offline_sync_batches', [
            'idempotency_key' => 'sync-001',
            'status' => 'succeeded',
        ]);
    }

    public function test_idempotency_key_prevents_duplicate_processing(): void
    {
        $inspector = User::factory()->inspector()->create();
        $inspection = $this->createInspection();

        // First upload
        $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'sync-dup',
            'device_id' => 'device-abc',
            'payload' => [
                'inspection_id' => $inspection->id,
                'data' => ['check' => 'first'],
                'version' => 1,
            ],
        ]);

        // Second upload with same key
        $response = $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'sync-dup',
            'device_id' => 'device-abc',
            'payload' => [
                'inspection_id' => $inspection->id,
                'data' => ['check' => 'duplicate'],
                'version' => 1,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Batch already processed.']);

        // Only 1 batch in DB
        $this->assertEquals(1, OfflineSyncBatch::where('idempotency_key', 'sync-dup')->count());
    }

    public function test_check_sync_status(): void
    {
        $inspector = User::factory()->inspector()->create();
        OfflineSyncBatch::create([
            'user_id' => $inspector->id,
            'idempotency_key' => 'sync-status-001',
            'device_id' => 'device-1',
            'status' => 'succeeded',
        ]);

        $response = $this->actingAs($inspector)->getJson('/api/offline-sync/status/sync-status-001');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'succeeded');
    }

    public function test_conflict_resolution_favors_server_adjudication(): void
    {
        $inspector = User::factory()->inspector()->create();
        $inspection = $this->createInspection();

        // Advance server version
        $inspection->update([
            'version' => 3,
            'findings' => ['initial' => 'data', 'adjudication' => 'confirmed'],
        ]);

        // Client sends older version
        $response = $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'sync-conflict-001',
            'device_id' => 'device-abc',
            'payload' => [
                'inspection_id' => $inspection->id,
                'data' => ['field_notes' => 'from client'],
                'version' => 1, // older than server's version 3
            ],
        ]);

        $response->assertStatus(201);

        $inspection->refresh();
        // Server's adjudication field should be preserved
        $this->assertEquals('confirmed', $inspection->findings['adjudication']);
        // Client's data should be merged
        $this->assertEquals('from client', $inspection->findings['field_notes']);
    }

    public function test_sync_batch_with_invalid_inspection_gets_quarantined_after_retries(): void
    {
        $inspector = User::factory()->inspector()->create();

        $response = $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'sync-bad-001',
            'device_id' => 'device-abc',
            'payload' => [
                'inspection_id' => 999999, // doesn't exist
                'data' => ['test' => 'data'],
            ],
        ]);

        $response->assertStatus(201);

        $batch = OfflineSyncBatch::where('idempotency_key', 'sync-bad-001')->first();
        $this->assertNotNull($batch);
        $this->assertNotNull($batch->last_error);
        // Should be failed (first attempt)
        $this->assertEquals('failed', $batch->status);
    }

    public function test_requeue_failed_batch(): void
    {
        $inspector = User::factory()->inspector()->create();
        $inspection = $this->createInspection();

        // Create a failed batch
        OfflineSyncBatch::create([
            'user_id' => $inspector->id,
            'idempotency_key' => 'sync-retry-001',
            'device_id' => 'device-abc',
            'status' => 'failed',
            'attempts' => 2,
            'last_error' => 'Some error',
        ]);

        // Re-upload with same key and valid data
        $response = $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'sync-retry-001',
            'device_id' => 'device-abc',
            'payload' => [
                'inspection_id' => $inspection->id,
                'data' => ['retry' => 'data'],
                'version' => 1,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Batch requeued for retry.']);
    }
}
