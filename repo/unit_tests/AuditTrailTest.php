<?php

namespace Tests\UnitTests;

use App\Models\Employer;
use App\Models\ResultVersion;
use App\Models\Objection;
use App\Models\WorkflowInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function employer_audit_stores_actor_role_timestamp_hashes_reason(): void
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
            ->where('employer_id', $employer->id)->first();

        $this->assertNotNull($audit);
        $this->assertEquals($admin->id, $audit->actor_id);
        $this->assertEquals('system_admin', $audit->role);
        $this->assertEquals('approve', $audit->action);
        $this->assertEquals('All documents verified.', $audit->reason);
        $this->assertNotNull($audit->prior_value_hash);
        $this->assertNotNull($audit->new_value_hash);
        $this->assertNotNull($audit->timestamp);
    }

    /** @test */
    public function audit_trail_is_ordered_chronologically(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->admin()->create();

        $employer->logAudit('create', $admin->id, 'system_admin', 'Created');
        $employer->logAudit('approve', $admin->id, 'system_admin', 'Approved');

        $trail = $employer->getAuditTrail();
        $this->assertCount(2, $trail);
        $this->assertEquals('create', $trail[0]->action);
        $this->assertEquals('approve', $trail[1]->action);
    }

    /** @test */
    public function audit_records_are_append_only_never_overwritten(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->admin()->create();

        $employer->logAudit('create', $admin->id, 'system_admin');
        $employer->logAudit('approve', $admin->id, 'system_admin');
        $employer->logAudit('update', $admin->id, 'system_admin');

        $count = DB::table('employer_decision_audits')
            ->where('employer_id', $employer->id)->count();
        $this->assertEquals(3, $count);

        $actions = DB::table('employer_decision_audits')
            ->where('employer_id', $employer->id)
            ->pluck('action')->toArray();
        $this->assertEquals(['create', 'approve', 'update'], $actions);
    }

    /** @test */
    public function hash_is_deterministic_for_same_values(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->admin()->create();
        $values = ['status' => 'pending'];
        $expectedHash = hash('sha256', json_encode($values));

        $employer->logAudit('test', $admin->id, 'system_admin', null, $values, null);

        $audit = DB::table('employer_decision_audits')
            ->where('employer_id', $employer->id)->first();
        $this->assertEquals($expectedHash, $audit->prior_value_hash);
        $this->assertNull($audit->new_value_hash);
    }

    /** @test */
    public function result_version_audit_writes_to_correct_table(): void
    {
        $rv = ResultVersion::factory()->create();
        $reviewer = User::factory()->complianceReviewer()->create();

        $rv->logAudit('publish_internal', $reviewer->id, 'compliance_reviewer', 'Internal publish');

        $audit = DB::table('result_decision_audits')
            ->where('result_version_id', $rv->id)->first();
        $this->assertNotNull($audit);
        $this->assertEquals('publish_internal', $audit->action);
    }

    /** @test */
    public function objection_audit_writes_to_correct_table(): void
    {
        $rv = ResultVersion::factory()->create();
        $user = User::factory()->create();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);
        $reviewer = User::factory()->complianceReviewer()->create();

        $objection->logAudit('move_to_review', $reviewer->id, 'compliance_reviewer', 'Accepted');

        $this->assertDatabaseHas('objection_decision_audits', [
            'objection_id' => $objection->id,
            'action' => 'move_to_review',
        ]);
    }

    /** @test */
    public function workflow_instance_audit_writes_to_correct_table(): void
    {
        $instance = WorkflowInstance::factory()->create();
        $admin = User::factory()->admin()->create();

        $instance->logAudit('escalate', $admin->id, 'system_admin', 'Timeout');

        $this->assertDatabaseHas('workflow_action_audits', [
            'workflow_instance_id' => $instance->id,
            'action' => 'escalate',
        ]);
    }
}
