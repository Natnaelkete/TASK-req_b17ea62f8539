<?php

namespace Tests\ApiTests;

use App\Models\Employer;
use App\Models\Inspection;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    // === Normal inputs ===

    /** @test */
    public function inspector_creates_inspection(): void
    {
        $inspector = User::factory()->inspector()->create();
        $job = Job::factory()->create();
        $this->actingAs($inspector)->postJson('/api/inspections', [
            'job_id' => $job->id,
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
        ])->assertStatus(201)->assertJsonPath('data.status', 'scheduled');
    }

    /** @test */
    public function update_inspection_to_in_progress(): void
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
        ]);
        $this->actingAs($inspector)->patchJson("/api/inspections/{$inspection->id}", [
            'status' => 'in_progress',
        ])->assertStatus(200)->assertJsonPath('data.status', 'in_progress');
    }

    /** @test */
    public function list_inspections_filtered_by_inspector(): void
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
        ]);
        $response = $this->actingAs($inspector)->getJson('/api/inspections');
        $this->assertCount(1, $response->json('data'));
    }

    // === Missing parameters ===

    /** @test */
    public function create_inspection_without_job_id_returns_422(): void
    {
        $inspector = User::factory()->inspector()->create();
        $this->actingAs($inspector)->postJson('/api/inspections', [
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('job_id');
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_create_inspection(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $job = Job::factory()->create();
        $this->actingAs($user)->postJson('/api/inspections', [
            'job_id' => $job->id,
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
        ])->assertStatus(403);
    }
}
