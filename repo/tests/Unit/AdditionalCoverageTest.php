<?php

namespace Tests\Unit;

use App\Models\{
    Employer, Job, ResultVersion, Objection, Ticket,
    Inspection, Message, ContentItem, WorkflowDefinition,
    WorkflowInstance, OfflineSyncBatch, DeviceSession,
    EmployerQualification, ObjectionFile, MaskingRule, User
};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdditionalCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_rejection_reasons_constant(): void
    {
        $reasons = Employer::REJECTION_REASONS;
        $this->assertArrayHasKey('incomplete_docs', $reasons);
        $this->assertArrayHasKey('invalid_license', $reasons);
        $this->assertArrayHasKey('failed_verification', $reasons);
        $this->assertArrayHasKey('duplicate_entry', $reasons);
        $this->assertArrayHasKey('policy_violation', $reasons);
        $this->assertArrayHasKey('other', $reasons);
    }

    public function test_employer_reviewer_relationship(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $employer = Employer::factory()->approved()->create(['reviewed_by' => $reviewer->id]);
        $this->assertInstanceOf(User::class, $employer->reviewer);
    }

    public function test_result_version_creator_relationship(): void
    {
        $rv = ResultVersion::factory()->create();
        $this->assertInstanceOf(User::class, $rv->creator);
    }

    public function test_objection_filer_relationship(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);
        $this->assertInstanceOf(User::class, $objection->filer);
    }

    public function test_objection_result_version_relationship(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);
        $this->assertInstanceOf(ResultVersion::class, $objection->resultVersion);
    }

    public function test_ticket_assignee_relationship(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $assignee = User::factory()->complianceReviewer()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);
        $ticket = Ticket::create([
            'objection_id' => $objection->id,
            'assigned_to' => $assignee->id,
        ]);
        $this->assertInstanceOf(User::class, $ticket->assignee);
        $this->assertInstanceOf(Objection::class, $ticket->objection);
    }

    public function test_inspection_relationships(): void
    {
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspector = User::factory()->inspector()->create();
        $inspection = Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $inspector->id,
            'employer_id' => $employer->id,
            'scheduled_at' => now()->addDay(),
            'findings' => ['compliant' => true],
        ]);

        $this->assertInstanceOf(Job::class, $inspection->job);
        $this->assertInstanceOf(User::class, $inspection->inspector);
        $this->assertInstanceOf(Employer::class, $inspection->employer);
        $this->assertIsArray($inspection->findings);
    }

    public function test_content_item_casts(): void
    {
        $user = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'Test',
            'slug' => 'test',
            'body' => 'body',
            'author_id' => $user->id,
            'published_at' => now(),
        ]);
        $this->assertNotNull($item->published_at);
    }

    public function test_message_casts(): void
    {
        $user = User::factory()->create();
        $message = Message::create([
            'recipient_id' => $user->id,
            'type' => 'test',
            'subject' => 'Subject',
            'body' => 'Body',
            'read_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $this->assertNotNull($message->read_at);
        $this->assertNotNull($message->expires_at);
    }

    public function test_workflow_definition_escalation_user(): void
    {
        $admin = User::factory()->admin()->create();
        $def = WorkflowDefinition::factory()->create(['escalation_role_user_id' => $admin->id]);
        $this->assertInstanceOf(User::class, $def->escalationUser);
    }

    public function test_workflow_instance_relationships(): void
    {
        $user = User::factory()->create();
        $assignee = User::factory()->complianceReviewer()->create();
        $instance = WorkflowInstance::factory()->create([
            'assigned_to' => $assignee->id,
            'started_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $instance->initiator);
        $this->assertInstanceOf(User::class, $instance->assignee);
        $this->assertNotNull($instance->started_at);
    }

    public function test_objection_file_relationship(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);
        $file = ObjectionFile::create([
            'objection_id' => $objection->id,
            'file_path' => 'test.pdf',
            'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 5000,
        ]);

        $this->assertInstanceOf(Objection::class, $file->objection);
        $this->assertEquals(5000, $file->file_size);
    }

    public function test_employer_qualification_relationship(): void
    {
        $employer = Employer::factory()->create();
        $qual = EmployerQualification::create([
            'employer_id' => $employer->id,
            'qualification_type' => 'license',
            'issued_at' => '2024-01-01',
            'expires_at' => '2025-01-01',
        ]);

        $this->assertInstanceOf(Employer::class, $qual->employer);
        $this->assertNotNull($qual->issued_at);
        $this->assertNotNull($qual->expires_at);
    }

    public function test_offline_sync_batch_casts(): void
    {
        $user = User::factory()->create();
        $batch = OfflineSyncBatch::create([
            'user_id' => $user->id,
            'idempotency_key' => 'test-key-' . uniqid(),
            'device_id' => 'dev-1',
            'payload' => ['inspection_id' => 1, 'data' => 'test'],
            'total_chunks' => 3,
            'received_chunks' => 1,
        ]);

        $this->assertIsArray($batch->payload);
        $this->assertEquals(3, $batch->total_chunks);
        $this->assertEquals(1, $batch->received_chunks);
    }

    public function test_device_session_casts(): void
    {
        $user = User::factory()->create();
        $session = DeviceSession::create([
            'user_id' => $user->id,
            'device_id' => 'dev-abc',
            'last_seen_at' => now(),
            'ip_address' => '192.168.1.1',
            'user_agent' => 'TestAgent/1.0',
        ]);

        $this->assertNotNull($session->last_seen_at);
        $this->assertEquals('192.168.1.1', $session->ip_address);
    }

    public function test_job_category_has_many_jobs(): void
    {
        $cat = \App\Models\JobCategory::create(['name' => 'Testing', 'slug' => 'testing']);
        Job::factory()->create(['category_id' => $cat->id]);

        $this->assertCount(1, $cat->jobs);
    }

    public function test_user_sessions_relationship(): void
    {
        $user = User::factory()->create();
        DeviceSession::create([
            'user_id' => $user->id,
            'device_id' => 'test-device',
        ]);

        $this->assertCount(1, $user->sessions);
    }

    public function test_user_messages_relationship(): void
    {
        $user = User::factory()->create();
        Message::create([
            'recipient_id' => $user->id,
            'type' => 'test',
            'subject' => 'Hello',
            'body' => 'World',
        ]);

        $this->assertCount(1, $user->messages);
    }
}
