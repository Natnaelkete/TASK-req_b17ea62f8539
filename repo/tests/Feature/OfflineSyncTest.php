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

    public function test_chunk_size_too_small_rejected(): void
    {
        $inspector = User::factory()->inspector()->create();

        // Tiny payload < 2MB for multi-chunk upload
        $response = $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'chunk-too-small',
            'device_id' => 'device-1',
            'payload' => ['small' => 'data'],
            'total_chunks' => 3,
            'chunk_index' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Chunk size too small. Minimum chunk size is 2MB.']);
    }

    public function test_chunk_size_too_large_rejected(): void
    {
        $inspector = User::factory()->inspector()->create();

        // Build a payload that serializes to > 5MB
        $largeString = str_repeat('a', 6 * 1024 * 1024); // 6MB string
        $response = $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'chunk-too-large',
            'device_id' => 'device-1',
            'payload' => ['blob' => $largeString],
            'total_chunks' => 3,
            'chunk_index' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Chunk size too large. Maximum chunk size is 5MB.']);
    }

    public function test_exponential_backoff_sets_next_retry_at(): void
    {
        $inspector = User::factory()->inspector()->create();

        // Bad inspection id to force failure
        $this->actingAs($inspector)->postJson('/api/offline-sync/upload', [
            'idempotency_key' => 'backoff-001',
            'device_id' => 'device-1',
            'payload' => [
                'inspection_id' => 999999,
                'data' => ['x' => 1],
            ],
        ])->assertStatus(201);

        $batch = OfflineSyncBatch::where('idempotency_key', 'backoff-001')->first();
        $this->assertEquals('failed', $batch->status);
        $this->assertEquals(1, $batch->attempts);
        $this->assertNotNull($batch->next_retry_at);

        // After 1 attempt, backoff should be ~2 seconds from now (2^1)
        $this->assertTrue($batch->next_retry_at->greaterThan(now()->subSecond()));
        $this->assertTrue($batch->next_retry_at->lessThan(now()->addSeconds(5)));
    }

    public function test_retry_failed_batches_endpoint_retries_eligible_batches(): void
    {
        $admin = User::factory()->admin()->create();
        $inspector = User::factory()->inspector()->create();
        $inspection = $this->createInspection();

        // Batch eligible for retry (past next_retry_at)
        OfflineSyncBatch::create([
            'user_id' => $inspector->id,
            'idempotency_key' => 'eligible-retry',
            'device_id' => 'device-1',
            'status' => 'failed',
            'attempts' => 1,
            'payload' => [
                'inspection_id' => $inspection->id,
                'data' => ['recover' => 'yes'],
                'version' => 1,
            ],
            'next_retry_at' => now()->subSecond(),
        ]);

        // Batch NOT eligible (next_retry_at in future)
        OfflineSyncBatch::create([
            'user_id' => $inspector->id,
            'idempotency_key' => 'pending-retry',
            'device_id' => 'device-1',
            'status' => 'failed',
            'attempts' => 1,
            'payload' => ['inspection_id' => $inspection->id, 'data' => ['x' => 1], 'version' => 1],
            'next_retry_at' => now()->addMinutes(10),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/offline-sync/retry');
        $response->assertStatus(200);

        $eligible = OfflineSyncBatch::where('idempotency_key', 'eligible-retry')->first();
        $pending = OfflineSyncBatch::where('idempotency_key', 'pending-retry')->first();

        $this->assertEquals('succeeded', $eligible->status);
        $this->assertEquals('failed', $pending->status);
    }

    public function test_quarantine_after_max_attempts(): void
    {
        $inspector = User::factory()->inspector()->create();

        OfflineSyncBatch::create([
            'user_id' => $inspector->id,
            'idempotency_key' => 'quarantine-test',
            'device_id' => 'device-1',
            'status' => 'failed',
            'attempts' => 7, // one below max
            'payload' => [
                'inspection_id' => 999999,
                'data' => ['x' => 1],
            ],
            'next_retry_at' => now()->subSecond(),
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/offline-sync/retry')->assertStatus(200);

        $batch = OfflineSyncBatch::where('idempotency_key', 'quarantine-test')->first();
        $this->assertEquals('quarantined', $batch->status);
        $this->assertEquals(8, $batch->attempts);
        $this->assertNull($batch->next_retry_at);
    }
}
