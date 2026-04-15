<?php

namespace Tests\UnitTests;

use App\Models\{
    User, Employer, EmployerQualification, Job, JobCategory,
    Inspection, ResultVersion, Objection, ObjectionFile, Ticket,
    Message, ContentItem, WorkflowDefinition, WorkflowInstance,
    OfflineSyncBatch, DeviceSession
};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function employer_belongs_to_user(): void
    {
        $employer = Employer::factory()->create();
        $this->assertInstanceOf(User::class, $employer->user);
    }

    /** @test */
    public function employer_has_many_qualifications(): void
    {
        $employer = Employer::factory()->create();
        EmployerQualification::create([
            'employer_id' => $employer->id,
            'qualification_type' => 'license',
        ]);
        $this->assertCount(1, $employer->qualifications);
    }

    /** @test */
    public function employer_has_many_jobs(): void
    {
        $employer = Employer::factory()->create();
        Job::factory()->create(['employer_id' => $employer->id]);
        $this->assertCount(1, $employer->jobs);
    }

    /** @test */
    public function job_belongs_to_employer_and_category(): void
    {
        $cat = JobCategory::create(['name' => 'Tech', 'slug' => 'tech']);
        $job = Job::factory()->create(['category_id' => $cat->id]);
        $this->assertInstanceOf(Employer::class, $job->employer);
        $this->assertInstanceOf(JobCategory::class, $job->category);
    }

    /** @test */
    public function result_version_belongs_to_job_and_creator(): void
    {
        $rv = ResultVersion::factory()->create();
        $this->assertInstanceOf(Job::class, $rv->job);
        $this->assertInstanceOf(User::class, $rv->creator);
    }

    /** @test */
    public function objection_has_files_and_ticket(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);
        ObjectionFile::create([
            'objection_id' => $objection->id,
            'file_path' => 'test.pdf',
            'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);
        Ticket::create(['objection_id' => $objection->id]);

        $this->assertCount(1, $objection->files);
        $this->assertInstanceOf(Ticket::class, $objection->ticket);
        $this->assertInstanceOf(User::class, $objection->filer);
    }

    /** @test */
    public function workflow_definition_has_instances(): void
    {
        $def = WorkflowDefinition::factory()->create();
        WorkflowInstance::factory()->create(['workflow_definition_id' => $def->id]);
        $this->assertCount(1, $def->instances);
    }

    /** @test */
    public function inspection_belongs_to_job_inspector_employer(): void
    {
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspector = User::factory()->inspector()->create();
        $inspection = Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
        ]);
        $this->assertInstanceOf(Job::class, $inspection->job);
        $this->assertInstanceOf(User::class, $inspection->inspector);
        $this->assertInstanceOf(Employer::class, $inspection->employer);
    }

    /** @test */
    public function offline_sync_batch_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $batch = OfflineSyncBatch::create([
            'user_id' => $user->id,
            'idempotency_key' => 'key-' . uniqid(),
            'device_id' => 'dev-1',
        ]);
        $this->assertInstanceOf(User::class, $batch->user);
    }
}
