<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Job;
use App\Models\JobCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    private function createEmployerWithOwner(): array
    {
        $user = User::factory()->employerManager()->create();
        $employer = Employer::factory()->approved()->create(['user_id' => $user->id]);
        return [$user, $employer];
    }

    public function test_create_job(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();

        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Software Engineer',
            'description' => 'Build great software.',
            'salary_min' => 80000,
            'salary_max' => 120000,
            'education_level' => 'bachelor',
            'work_city' => 'Austin',
            'work_state' => 'TX',
            'work_zip' => '73301',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Software Engineer')
            ->assertJsonPath('data.salary_min', 80000)
            ->assertJsonPath('data.salary_max', 120000);
    }

    public function test_create_job_validates_salary_range(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();

        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Tester',
            'description' => 'Test stuff.',
            'salary_min' => 100000,
            'salary_max' => 50000, // less than min
            'education_level' => 'bachelor',
            'work_city' => 'Austin',
            'work_state' => 'TX',
            'work_zip' => '73301',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('salary_max');
    }

    public function test_create_job_validates_education_level(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();

        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Tester',
            'description' => 'Test stuff.',
            'salary_min' => 50000,
            'salary_max' => 80000,
            'education_level' => 'phd', // invalid
            'work_city' => 'Austin',
            'work_state' => 'TX',
            'work_zip' => '73301',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('education_level');
    }

    public function test_duplicate_detection_blocks_same_title_zip(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();

        // Create first job
        Job::create([
            'employer_id' => $employer->id,
            'title' => 'Data Analyst',
            'normalized_title' => 'data analyst',
            'description' => 'Analyze data.',
            'salary_min' => 60000,
            'salary_max' => 90000,
            'education_level' => 'bachelor',
            'work_city' => 'Denver',
            'work_state' => 'CO',
            'work_zip' => '80201',
        ]);

        // Attempt duplicate
        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => '  Data Analyst  ', // whitespace + same title
            'description' => 'Different description.',
            'salary_min' => 65000,
            'salary_max' => 95000,
            'education_level' => 'master',
            'work_city' => 'Denver',
            'work_state' => 'CO',
            'work_zip' => '80201', // same ZIP
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Duplicate job detected. A job with the same title and ZIP code was posted within the last 30 days.']);
    }

    public function test_duplicate_detection_allows_different_zip(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();

        Job::create([
            'employer_id' => $employer->id,
            'title' => 'Data Analyst',
            'normalized_title' => 'data analyst',
            'description' => 'Analyze data.',
            'salary_min' => 60000,
            'salary_max' => 90000,
            'education_level' => 'bachelor',
            'work_city' => 'Denver',
            'work_state' => 'CO',
            'work_zip' => '80201',
        ]);

        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Data Analyst',
            'description' => 'Same title but different ZIP.',
            'salary_min' => 60000,
            'salary_max' => 90000,
            'education_level' => 'bachelor',
            'work_city' => 'Boulder',
            'work_state' => 'CO',
            'work_zip' => '80301', // different ZIP
        ]);

        $response->assertStatus(201);
    }

    public function test_rate_limiting_20_jobs_per_24_hours(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();

        // Create 20 jobs directly in DB
        for ($i = 0; $i < 20; $i++) {
            Job::create([
                'employer_id' => $employer->id,
                'title' => "Job Title $i",
                'normalized_title' => "job title $i",
                'description' => "Description $i",
                'salary_min' => 50000,
                'salary_max' => 80000,
                'education_level' => 'bachelor',
                'work_city' => 'Austin',
                'work_state' => 'TX',
                'work_zip' => sprintf('7%04d', $i),
            ]);
        }

        // 21st should be rejected
        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'One Too Many',
            'description' => 'This should fail.',
            'salary_min' => 50000,
            'salary_max' => 80000,
            'education_level' => 'bachelor',
            'work_city' => 'Austin',
            'work_state' => 'TX',
            'work_zip' => '99999',
        ]);

        $response->assertStatus(429);
    }

    public function test_list_jobs(): void
    {
        $user = User::factory()->create();
        Job::factory()->count(3)->create();

        $response = $this->actingAs($user)->getJson('/api/jobs');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_show_job(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/jobs/{$job->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.title', $job->title);
    }

    public function test_update_job(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();
        $job = Job::factory()->create(['employer_id' => $employer->id]);

        $response = $this->actingAs($user)->patchJson("/api/jobs/{$job->id}", [
            'title' => 'Updated Title',
            'salary_min' => 90000,
            'salary_max' => 130000,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_unauthorized_user_cannot_create_job_for_other_employer(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $employer = Employer::factory()->create(); // owned by different user

        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Hacked Job',
            'description' => 'Should fail.',
            'salary_min' => 50000,
            'salary_max' => 80000,
            'education_level' => 'bachelor',
            'work_city' => 'Austin',
            'work_state' => 'TX',
            'work_zip' => '73301',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_job_for_any_employer(): void
    {
        $admin = User::factory()->admin()->create();
        $employer = Employer::factory()->create();

        $response = $this->actingAs($admin)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Admin Created Job',
            'description' => 'Created by admin.',
            'salary_min' => 50000,
            'salary_max' => 80000,
            'education_level' => 'bachelor',
            'work_city' => 'Houston',
            'work_state' => 'TX',
            'work_zip' => '77001',
        ]);

        $response->assertStatus(201);
    }

    public function test_create_job_with_category(): void
    {
        [$user, $employer] = $this->createEmployerWithOwner();
        $category = JobCategory::where('slug', 'technology')->first();

        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/jobs", [
            'title' => 'Full Stack Dev',
            'description' => 'Full stack development.',
            'salary_min' => 90000,
            'salary_max' => 140000,
            'education_level' => 'bachelor',
            'work_city' => 'Seattle',
            'work_state' => 'WA',
            'work_zip' => '98101',
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.category_id', $category->id);
    }

    public function test_filter_jobs_by_state(): void
    {
        $user = User::factory()->create();
        Job::factory()->create(['work_state' => 'TX']);
        Job::factory()->create(['work_state' => 'CA']);

        $response = $this->actingAs($user)->getJson('/api/jobs?work_state=TX');
        $data = $response->json('data');
        $this->assertCount(1, $data);
    }
}
