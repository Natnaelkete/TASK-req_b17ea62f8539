<?php

namespace Tests\Unit;

use App\Models\{
    User, Employer, EmployerQualification, Job, JobCategory,
    Inspection, ResultVersion, Objection, ObjectionFile, Ticket,
    Message, ContentItem, WorkflowDefinition, WorkflowInstance,
    OfflineSyncBatch, DeviceSession, FeatureFlag, MaskingRule,
    UserNotificationPreference
};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_belongs_to_user(): void
    {
        $employer = Employer::factory()->create();
        $this->assertInstanceOf(User::class, $employer->user);
    }

    public function test_employer_has_many_qualifications(): void
    {
        $employer = Employer::factory()->create();
        EmployerQualification::create([
            'employer_id' => $employer->id,
            'qualification_type' => 'business_license',
            'license_number' => 'LIC-123',
        ]);

        $this->assertCount(1, $employer->qualifications);
    }

    public function test_employer_has_many_jobs(): void
    {
        $employer = Employer::factory()->create();
        Job::factory()->create(['employer_id' => $employer->id]);

        $this->assertCount(1, $employer->jobs);
    }

    public function test_job_belongs_to_employer(): void
    {
        $job = Job::factory()->create();
        $this->assertInstanceOf(Employer::class, $job->employer);
    }

    public function test_job_belongs_to_category(): void
    {
        $category = JobCategory::create(['name' => 'Tech', 'slug' => 'tech']);
        $job = Job::factory()->create(['category_id' => $category->id]);

        $this->assertInstanceOf(JobCategory::class, $job->category);
        $this->assertEquals('Tech', $job->category->name);
    }

    public function test_job_has_many_inspections(): void
    {
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspector = User::factory()->inspector()->create();
        Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $this->assertCount(1, $job->inspections);
    }

    public function test_job_has_many_result_versions(): void
    {
        $job = Job::factory()->create();
        ResultVersion::factory()->create(['job_id' => $job->id]);

        $this->assertCount(1, $job->resultVersions);
    }

    public function test_result_version_has_many_objections(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test objection',
        ]);

        $this->assertCount(1, $rv->objections);
    }

    public function test_objection_has_one_ticket(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test objection',
        ]);
        Ticket::create(['objection_id' => $objection->id]);

        $this->assertInstanceOf(Ticket::class, $objection->ticket);
    }

    public function test_objection_has_many_files(): void
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
            'file_path' => '/storage/test.pdf',
            'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        $this->assertCount(1, $objection->files);
    }

    public function test_message_belongs_to_recipient(): void
    {
        $user = User::factory()->create();
        $message = Message::create([
            'recipient_id' => $user->id,
            'type' => 'check_in_reminder',
            'subject' => 'Reminder',
            'body' => 'You have an upcoming check-in.',
        ]);

        $this->assertInstanceOf(User::class, $message->recipient);
    }

    public function test_workflow_definition_has_many_instances(): void
    {
        $def = WorkflowDefinition::factory()->create();
        WorkflowInstance::factory()->create(['workflow_definition_id' => $def->id]);

        $this->assertCount(1, $def->instances);
    }

    public function test_workflow_instance_belongs_to_definition(): void
    {
        $instance = WorkflowInstance::factory()->create();
        $this->assertInstanceOf(WorkflowDefinition::class, $instance->definition);
    }

    public function test_content_item_belongs_to_author(): void
    {
        $user = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'Test Article',
            'slug' => 'test-article',
            'body' => 'Article body text.',
            'author_id' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $item->author);
    }

    public function test_device_session_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $session = DeviceSession::create([
            'user_id' => $user->id,
            'device_id' => 'device-abc-123',
            'last_seen_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $session->user);
    }

    public function test_offline_sync_batch_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $batch = OfflineSyncBatch::create([
            'user_id' => $user->id,
            'idempotency_key' => 'key-' . uniqid(),
            'device_id' => 'device-1',
        ]);

        $this->assertInstanceOf(User::class, $batch->user);
    }

    public function test_feature_flag_is_enabled_check(): void
    {
        FeatureFlag::create(['key' => 'test_flag', 'enabled' => true]);
        FeatureFlag::create(['key' => 'disabled_flag', 'enabled' => false]);

        $this->assertTrue(FeatureFlag::isEnabled('test_flag'));
        $this->assertFalse(FeatureFlag::isEnabled('disabled_flag'));
        $this->assertFalse(FeatureFlag::isEnabled('nonexistent'));
    }

    public function test_user_notification_preference(): void
    {
        $user = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $user->id,
            'notification_type' => 'check_in_reminder',
            'enabled' => true,
        ]);

        $this->assertTrue($pref->enabled);
        $this->assertInstanceOf(User::class, $pref->user);
    }
}
