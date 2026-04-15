<?php

namespace Tests\ApiTests;

use App\Models\Employer;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    private function ownerAndEmployer(): array
    {
        $user = User::factory()->employerManager()->create();
        $employer = Employer::factory()->approved()->create(['user_id' => $user->id]);
        return [$user, $employer];
    }

    // === Normal inputs ===

    /** @test */
    public function create_job_returns_201(): void
    {
        [$user, $employer] = $this->ownerAndEmployer();
        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Software Engineer',
            'description' => 'Build software.',
            'salary_min' => 80000, 'salary_max' => 120000,
            'education_level' => 'bachelor',
            'work_city' => 'Austin', 'work_state' => 'TX', 'work_zip' => '73301',
        ]);
        $response->assertStatus(201)->assertJsonPath('data.title', 'Software Engineer');
    }

    /** @test */
    public function list_jobs_returns_paginated(): void
    {
        $user = User::factory()->create();
        Job::factory()->count(3)->create();
        $this->actingAs($user)->getJson('/api/jobs')->assertStatus(200)->assertJsonStructure(['data']);
    }

    /** @test */
    public function show_job_returns_detail(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create();
        $this->actingAs($user)->getJson("/api/jobs/{$job->id}")->assertStatus(200);
    }

    /** @test */
    public function update_job_by_owner(): void
    {
        [$user, $employer] = $this->ownerAndEmployer();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $this->actingAs($user)->patchJson("/api/jobs/{$job->id}", ['title' => 'Updated'])
            ->assertStatus(200)->assertJsonPath('data.title', 'Updated');
    }

    // === Missing parameters ===

    /** @test */
    public function create_job_without_required_fields_returns_422(): void
    {
        [$user, $employer] = $this->ownerAndEmployer();
        $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'description', 'salary_min', 'salary_max', 'education_level', 'work_city', 'work_state', 'work_zip']);
    }

    /** @test */
    public function salary_max_less_than_min_returns_422(): void
    {
        [$user, $employer] = $this->ownerAndEmployer();
        $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'T', 'description' => 'D',
            'salary_min' => 100000, 'salary_max' => 50000,
            'education_level' => 'bachelor',
            'work_city' => 'X', 'work_state' => 'TX', 'work_zip' => '12345',
        ])->assertStatus(422)->assertJsonValidationErrors('salary_max');
    }

    /** @test */
    public function invalid_education_level_returns_422(): void
    {
        [$user, $employer] = $this->ownerAndEmployer();
        $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'T', 'description' => 'D',
            'salary_min' => 50000, 'salary_max' => 80000,
            'education_level' => 'phd',
            'work_city' => 'X', 'work_state' => 'TX', 'work_zip' => '12345',
        ])->assertStatus(422)->assertJsonValidationErrors('education_level');
    }

    /** @test */
    public function duplicate_job_same_title_zip_within_30_days_returns_422(): void
    {
        [$user, $employer] = $this->ownerAndEmployer();
        Job::create([
            'employer_id' => $employer->id, 'title' => 'Analyst', 'normalized_title' => 'analyst',
            'description' => 'D', 'salary_min' => 60000, 'salary_max' => 90000,
            'education_level' => 'bachelor', 'work_city' => 'Denver', 'work_state' => 'CO', 'work_zip' => '80201',
        ]);
        $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Analyst', 'description' => 'D2',
            'salary_min' => 65000, 'salary_max' => 95000,
            'education_level' => 'master',
            'work_city' => 'Denver', 'work_state' => 'CO', 'work_zip' => '80201',
        ])->assertStatus(422);
    }

    /** @test */
    public function rate_limit_20_jobs_per_employer_per_24h(): void
    {
        [$user, $employer] = $this->ownerAndEmployer();
        for ($i = 0; $i < 20; $i++) {
            Job::create([
                'employer_id' => $employer->id, 'title' => "Job $i", 'normalized_title' => "job $i",
                'description' => 'D', 'salary_min' => 50000, 'salary_max' => 80000,
                'education_level' => 'bachelor', 'work_city' => 'A', 'work_state' => 'TX', 'work_zip' => sprintf('7%04d', $i),
            ]);
        }
        $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Overflow', 'description' => 'D',
            'salary_min' => 50000, 'salary_max' => 80000,
            'education_level' => 'bachelor',
            'work_city' => 'X', 'work_state' => 'TX', 'work_zip' => '99999',
        ])->assertStatus(429);
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_create_job_for_other_employer(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $employer = Employer::factory()->create();
        $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'T', 'description' => 'D',
            'salary_min' => 50000, 'salary_max' => 80000,
            'education_level' => 'bachelor',
            'work_city' => 'X', 'work_state' => 'TX', 'work_zip' => '12345',
        ])->assertStatus(403);
    }

    /** @test */
    public function admin_can_create_job_for_any_employer(): void
    {
        $admin = User::factory()->admin()->create();
        $employer = Employer::factory()->create();
        $this->actingAs($admin)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Admin Job', 'description' => 'D',
            'salary_min' => 50000, 'salary_max' => 80000,
            'education_level' => 'bachelor',
            'work_city' => 'X', 'work_state' => 'TX', 'work_zip' => '12345',
        ])->assertStatus(201);
    }
}
