<?php

namespace Tests\ApiTests;

use App\Models\Job;
use App\Models\ResultVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultVersionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    // === Normal inputs ===

    /** @test */
    public function create_draft_result_version(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $job = Job::factory()->create();
        $this->actingAs($reviewer)->postJson("/api/jobs/{$job->id}/result-versions", [
            'data' => ['summary' => 'Results', 'score' => 85],
        ])->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }

    /** @test */
    public function transition_draft_to_internal(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $rv = ResultVersion::factory()->create(['status' => 'draft']);
        $this->actingAs($reviewer)->putJson("/api/result-versions/{$rv->id}/status", ['status' => 'internal'])
            ->assertStatus(200)->assertJsonPath('data.status', 'internal');
    }

    /** @test */
    public function transition_internal_to_public_creates_snapshot(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $rv = ResultVersion::factory()->create(['status' => 'internal']);
        $this->actingAs($reviewer)->putJson("/api/result-versions/{$rv->id}/status", ['status' => 'public'])
            ->assertStatus(200)->assertJsonPath('data.status', 'public');
        $rv->refresh();
        $this->assertNotNull($rv->snapshot);
        $this->assertNotNull($rv->published_at);
    }

    /** @test */
    public function show_result_version_includes_masked_data(): void
    {
        $user = User::factory()->create();
        $rv = ResultVersion::factory()->create();
        $this->actingAs($user)->getJson("/api/result-versions/{$rv->id}")
            ->assertStatus(200)->assertJsonStructure(['data' => ['masked_data']]);
    }

    /** @test */
    public function history_returns_all_versions_and_audit(): void
    {
        $user = User::factory()->complianceReviewer()->create();
        $job = Job::factory()->create();
        ResultVersion::factory()->create(['job_id' => $job->id, 'version_number' => 1]);
        $rv2 = ResultVersion::factory()->create(['job_id' => $job->id, 'version_number' => 2]);
        $response = $this->actingAs($user)->getJson("/api/result-versions/{$rv2->id}/history");
        $response->assertStatus(200)->assertJsonStructure(['versions', 'audit_trail']);
        $this->assertCount(2, $response->json('versions'));
    }

    // === Missing parameters ===

    /** @test */
    public function create_result_without_data_returns_422(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $job = Job::factory()->create();
        $this->actingAs($reviewer)->postJson("/api/jobs/{$job->id}/result-versions", [])
            ->assertStatus(422)->assertJsonValidationErrors('data');
    }

    /** @test */
    public function invalid_transition_draft_to_public_returns_422(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $rv = ResultVersion::factory()->create(['status' => 'draft']);
        $this->actingAs($reviewer)->putJson("/api/result-versions/{$rv->id}/status", ['status' => 'public'])
            ->assertStatus(422);
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_create_result(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $job = Job::factory()->create();
        $this->actingAs($user)->postJson("/api/jobs/{$job->id}/result-versions", [
            'data' => ['summary' => 'X'],
        ])->assertStatus(403);
    }

    /** @test */
    public function inspector_cannot_transition_status(): void
    {
        $inspector = User::factory()->inspector()->create();
        $rv = ResultVersion::factory()->create(['status' => 'draft']);
        $this->actingAs($inspector)->putJson("/api/result-versions/{$rv->id}/status", ['status' => 'internal'])
            ->assertStatus(403);
    }
}
