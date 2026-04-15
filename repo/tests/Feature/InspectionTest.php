<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Inspection;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    public function test_inspector_can_create_inspection(): void
    {
        $inspector = User::factory()->inspector()->create();
        $job = Job::factory()->create();

        $response = $this->actingAs($inspector)->postJson('/api/inspections', [
            'job_id' => $job->id,
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
            'notes' => 'Routine inspection.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'scheduled');
    }

    public function test_general_user_cannot_create_inspection(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $job = Job::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/inspections', [
            'job_id' => $job->id,
            'scheduled_at' => now()->addDays(3)->toIso8601String(),
        ]);

        $response->assertStatus(403);
    }

    public function test_list_inspections(): void
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);

        Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($inspector)->getJson('/api/inspections');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_update_inspection_status(): void
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($inspector)->patchJson("/api/inspections/{$inspection->id}", [
            'status' => 'in_progress',
            'findings' => ['initial_check' => 'pass'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');

        $inspection->refresh();
        $this->assertNotNull($inspection->started_at);
    }

    public function test_complete_inspection(): void
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($inspector)->patchJson("/api/inspections/{$inspection->id}", [
            'status' => 'completed',
            'findings' => ['result' => 'compliant', 'score' => 95],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'completed');

        $inspection->refresh();
        $this->assertNotNull($inspection->completed_at);
    }

    public function test_show_inspection(): void
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($inspector)->getJson("/api/inspections/{$inspection->id}");
        $response->assertStatus(200)->assertJsonPath('data.id', $inspection->id);
    }
}
