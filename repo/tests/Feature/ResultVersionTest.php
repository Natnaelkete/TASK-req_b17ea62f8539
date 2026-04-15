<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\ResultVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    public function test_create_draft_result_version(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $job = Job::factory()->create();

        $response = $this->actingAs($reviewer)->postJson("/api/jobs/{$job->id}/result-versions", [
            'data' => ['summary' => 'Initial results', 'score' => 85],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version_number', 1);
    }

    public function test_general_user_cannot_create_result(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $job = Job::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/jobs/{$job->id}/result-versions", [
            'data' => ['summary' => 'Test'],
        ]);

        $response->assertStatus(403);
    }

    public function test_transition_draft_to_internal(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $rv = ResultVersion::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($reviewer)->putJson("/api/result-versions/{$rv->id}/status", [
            'status' => 'internal',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'internal');

        $this->assertDatabaseHas('result_decision_audits', [
            'result_version_id' => $rv->id,
            'action' => 'publish_internal',
        ]);
    }

    public function test_transition_internal_to_public_creates_snapshot(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $rv = ResultVersion::factory()->create(['status' => 'internal']);

        $response = $this->actingAs($reviewer)->putJson("/api/result-versions/{$rv->id}/status", [
            'status' => 'public',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'public');

        $rv->refresh();
        $this->assertNotNull($rv->snapshot);
        $this->assertNotNull($rv->published_at);
    }

    public function test_invalid_transition_rejected(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $rv = ResultVersion::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($reviewer)->putJson("/api/result-versions/{$rv->id}/status", [
            'status' => 'public', // Can't skip internal
        ]);

        $response->assertStatus(422);
    }

    public function test_show_result_version(): void
    {
        $user = User::factory()->create();
        $rv = ResultVersion::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/result-versions/{$rv->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'status', 'data', 'masked_data']]);
    }

    public function test_version_history(): void
    {
        $user = User::factory()->complianceReviewer()->create();
        $job = Job::factory()->create();
        ResultVersion::factory()->create(['job_id' => $job->id, 'version_number' => 1]);
        $rv2 = ResultVersion::factory()->create(['job_id' => $job->id, 'version_number' => 2]);

        $response = $this->actingAs($user)->getJson("/api/result-versions/{$rv2->id}/history");

        $response->assertStatus(200)
            ->assertJsonStructure(['versions', 'audit_trail']);
        $this->assertCount(2, $response->json('versions'));
    }
}
