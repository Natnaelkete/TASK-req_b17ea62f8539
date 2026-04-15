<?php

namespace Tests\Unit;

use App\Models\Employer;
use App\Models\ResultVersion;
use App\Models\Objection;
use App\Models\WorkflowInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_audit_record_is_created(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->admin()->create();

        $employer->logAudit(
            action: 'approve',
            actorId: $admin->id,
            role: 'system_admin',
            reason: 'All documents verified.',
            priorValues: ['status' => 'pending'],
            newValues: ['status' => 'approved'],
        );

        $audit = DB::table('employer_decision_audits')
            ->where('employer_id', $employer->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals($admin->id, $audit->actor_id);
        $this->assertEquals('system_admin', $audit->role);
        $this->assertEquals('approve', $audit->action);
        $this->assertEquals('All documents verified.', $audit->reason);
        $this->assertNotNull($audit->prior_value_hash);
        $this->assertNotNull($audit->new_value_hash);
        $this->assertNotNull($audit->timestamp);
    }

    public function test_employer_audit_trail_is_retrieved(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->admin()->create();

        $employer->logAudit('create', $admin->id, 'system_admin', 'Initial creation');
        $employer->logAudit('approve', $admin->id, 'system_admin', 'Approved');

        $trail = $employer->getAuditTrail();
        $this->assertCount(2, $trail);
        $this->assertEquals('create', $trail[0]->action);
        $this->assertEquals('approve', $trail[1]->action);
    }

    public function test_result_version_audit_record(): void
    {
        $rv = ResultVersion::factory()->create();
        $reviewer = User::factory()->complianceReviewer()->create();

        $rv->logAudit(
            action: 'publish_internal',
            actorId: $reviewer->id,
            role: 'compliance_reviewer',
            reason: 'Moving to internal review.',
            priorValues: ['status' => 'draft'],
            newValues: ['status' => 'internal'],
        );

        $audit = DB::table('result_decision_audits')
            ->where('result_version_id', $rv->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('compliance_reviewer', $audit->role);
        $this->assertEquals('publish_internal', $audit->action);
    }

    public function test_objection_audit_record(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Incorrect data',
        ]);

        $reviewer = User::factory()->complianceReviewer()->create();

        $objection->logAudit(
            action: 'move_to_review',
            actorId: $reviewer->id,
            role: 'compliance_reviewer',
            reason: 'Objection accepted for review.',
        );

        $audit = DB::table('objection_decision_audits')
            ->where('objection_id', $objection->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('move_to_review', $audit->action);
    }

    public function test_workflow_instance_audit_record(): void
    {
        $instance = WorkflowInstance::factory()->create();
        $admin = User::factory()->admin()->create();

        $instance->logAudit(
            action: 'escalate',
            actorId: $admin->id,
            role: 'system_admin',
            reason: 'Timeout reached, escalating.',
            priorValues: ['status' => 'pending'],
            newValues: ['status' => 'escalated'],
        );

        $audit = DB::table('workflow_action_audits')
            ->where('workflow_instance_id', $instance->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('escalate', $audit->action);
        $this->assertNotNull($audit->prior_value_hash);
    }

    public function test_audit_hash_consistency(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->admin()->create();

        $values = ['status' => 'pending'];
        $expectedHash = hash('sha256', json_encode($values));

        $employer->logAudit('test', $admin->id, 'system_admin', null, $values, null);

        $audit = DB::table('employer_decision_audits')
            ->where('employer_id', $employer->id)
            ->first();

        $this->assertEquals($expectedHash, $audit->prior_value_hash);
        $this->assertNull($audit->new_value_hash);
    }

    public function test_audit_records_are_append_only(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->admin()->create();

        $employer->logAudit('create', $admin->id, 'system_admin');
        $employer->logAudit('approve', $admin->id, 'system_admin');
        $employer->logAudit('update', $admin->id, 'system_admin');

        // All three records should exist
        $count = DB::table('employer_decision_audits')
            ->where('employer_id', $employer->id)
            ->count();

        $this->assertEquals(3, $count);

        // Verify no records were overwritten
        $actions = DB::table('employer_decision_audits')
            ->where('employer_id', $employer->id)
            ->pluck('action')
            ->toArray();

        $this->assertEquals(['create', 'approve', 'update'], $actions);
    }
}
