<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    public function test_create_workflow_definition(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/workflow-definitions', [
            'name' => 'Employer Approval',
            'slug' => 'employer-approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'next_approve' => 'end', 'next_reject' => 'end'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'approval_mode' => 'all_approve',
            'timeout_hours' => 48,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Employer Approval')
            ->assertJsonPath('data.version', 1);
    }

    public function test_list_workflow_definitions(): void
    {
        $admin = User::factory()->admin()->create();
        WorkflowDefinition::factory()->count(3)->create();

        $response = $this->actingAs($admin)->getJson('/api/workflow-definitions');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_create_workflow_instance(): void
    {
        $admin = User::factory()->admin()->create();
        $def = WorkflowDefinition::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/workflow-instances', [
            'workflow_definition_id' => $def->id,
            'entity_type' => 'employer',
            'entity_id' => 1,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_node', 'start');

        // Audit record should exist
        $this->assertDatabaseHas('workflow_action_audits', [
            'workflow_instance_id' => $response->json('data.id'),
            'action' => 'create',
        ]);
    }

    public function test_advance_workflow_approve(): void
    {
        $admin = User::factory()->admin()->create();
        $instance = WorkflowInstance::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($admin)->putJson("/api/workflow-instances/{$instance->id}/advance", [
            'action' => 'approve',
            'reason' => 'All checks passed.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_advance_workflow_reject(): void
    {
        $admin = User::factory()->admin()->create();
        $instance = WorkflowInstance::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($admin)->putJson("/api/workflow-instances/{$instance->id}/advance", [
            'action' => 'reject',
            'reason' => 'Failed compliance check.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_advance_workflow_escalate(): void
    {
        $admin = User::factory()->admin()->create();
        $instance = WorkflowInstance::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($admin)->putJson("/api/workflow-instances/{$instance->id}/advance", [
            'action' => 'escalate',
            'reason' => 'Timeout reached. Escalating to supervisor.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'escalated');

        $instance->refresh();
        $this->assertNotNull($instance->escalated_at);
        $this->assertEquals('Timeout reached. Escalating to supervisor.', $instance->escalation_note);
    }

    public function test_advance_workflow_reassign(): void
    {
        $admin = User::factory()->admin()->create();
        $newAssignee = User::factory()->complianceReviewer()->create();
        $instance = WorkflowInstance::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($admin)->putJson("/api/workflow-instances/{$instance->id}/advance", [
            'action' => 'reassign',
            'reason' => 'Reassigning to available reviewer.',
            'assign_to' => $newAssignee->id,
        ]);

        $response->assertStatus(200);
        $instance->refresh();
        $this->assertEquals($newAssignee->id, $instance->assigned_to);
    }

    public function test_show_workflow_instance_with_audit_trail(): void
    {
        $admin = User::factory()->admin()->create();
        $instance = WorkflowInstance::factory()->create();
        $instance->logAudit('create', $admin->id, 'system_admin', 'Created.');

        $response = $this->actingAs($admin)->getJson("/api/workflow-instances/{$instance->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'audit_trail']);
        $this->assertNotEmpty($response->json('audit_trail'));
    }

    public function test_general_user_cannot_access_workflows(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);

        $response = $this->actingAs($user)->getJson('/api/workflow-definitions');
        $response->assertStatus(403);
    }

    public function test_slug_plus_version_uniqueness_allows_multiple_versions(): void
    {
        $admin = User::factory()->admin()->create();

        // Create version 1
        $first = $this->actingAs($admin)->postJson('/api/workflow-definitions', [
            'name' => 'Approval Flow',
            'slug' => 'approval-flow',
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'next' => 'end'],
                ['id' => 'end', 'type' => 'end'],
            ],
        ]);
        $first->assertStatus(201)->assertJsonPath('data.version', 1);

        // Create version 2 with the same slug
        $second = $this->actingAs($admin)->postJson('/api/workflow-definitions', [
            'name' => 'Approval Flow v2',
            'slug' => 'approval-flow',
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'next' => 'end'],
                ['id' => 'end', 'type' => 'end'],
            ],
        ]);
        $second->assertStatus(201)->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('workflow_definitions', [
            'slug' => 'approval-flow', 'version' => 1, 'active' => false,
        ]);
        $this->assertDatabaseHas('workflow_definitions', [
            'slug' => 'approval-flow', 'version' => 2, 'active' => true,
        ]);
    }

    public function test_any_approve_mode_advances_on_single_approval(): void
    {
        $admin = User::factory()->admin()->create();
        $reviewer = User::factory()->complianceReviewer()->create();

        $def = WorkflowDefinition::create([
            'name' => 'Any Approve Flow',
            'slug' => 'any-approve',
            'version' => 1,
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'required_approvers' => 3, 'next' => 'end'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'approval_mode' => 'any_approve',
            'timeout_hours' => 48,
            'active' => true,
        ]);

        $instance = WorkflowInstance::create([
            'workflow_definition_id' => $def->id,
            'entity_type' => 'employer',
            'entity_id' => 1,
            'current_node' => 'review',
            'status' => 'in_progress',
            'initiated_by' => $admin->id,
            'started_at' => now(),
            'node_approvals' => [],
        ]);

        $response = $this->actingAs($reviewer)
            ->putJson("/api/workflow-instances/{$instance->id}/advance", [
                'action' => 'approve',
                'reason' => 'Single approver sufficient.',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('completed', $instance->fresh()->status);
    }

    public function test_all_approve_mode_requires_multiple_approvers(): void
    {
        $admin = User::factory()->admin()->create();
        $reviewerA = User::factory()->complianceReviewer()->create();
        $reviewerB = User::factory()->complianceReviewer()->create();

        $def = WorkflowDefinition::create([
            'name' => 'All Approve Flow',
            'slug' => 'all-approve',
            'version' => 1,
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'required_approvers' => 2, 'next' => 'end'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'approval_mode' => 'all_approve',
            'timeout_hours' => 48,
            'active' => true,
        ]);

        $instance = WorkflowInstance::create([
            'workflow_definition_id' => $def->id,
            'entity_type' => 'employer',
            'entity_id' => 1,
            'current_node' => 'review',
            'status' => 'in_progress',
            'initiated_by' => $admin->id,
            'started_at' => now(),
            'node_approvals' => [],
        ]);

        // First approval — should not complete
        $this->actingAs($reviewerA)
            ->putJson("/api/workflow-instances/{$instance->id}/advance", [
                'action' => 'approve',
                'reason' => 'First approval.',
            ])->assertStatus(200);
        $this->assertEquals('in_progress', $instance->fresh()->status);

        // Second approval — completes
        $this->actingAs($reviewerB)
            ->putJson("/api/workflow-instances/{$instance->id}/advance", [
                'action' => 'approve',
                'reason' => 'Second approval.',
            ])->assertStatus(200);
        $this->assertEquals('completed', $instance->fresh()->status);
    }

    public function test_reject_follows_rejection_branch_to_end(): void
    {
        $admin = User::factory()->admin()->create();

        $def = WorkflowDefinition::create([
            'name' => 'Branching Flow',
            'slug' => 'branching-flow',
            'version' => 1,
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'next' => ['approved' => 'end', 'rejected' => 'end']],
                ['id' => 'end', 'type' => 'end'],
            ],
            'approval_mode' => 'all_approve',
            'timeout_hours' => 48,
            'active' => true,
        ]);

        $instance = WorkflowInstance::create([
            'workflow_definition_id' => $def->id,
            'entity_type' => 'employer',
            'entity_id' => 1,
            'current_node' => 'review',
            'status' => 'in_progress',
            'initiated_by' => $admin->id,
            'started_at' => now(),
            'node_approvals' => [],
        ]);

        $response = $this->actingAs($admin)
            ->putJson("/api/workflow-instances/{$instance->id}/advance", [
                'action' => 'reject',
                'reason' => 'Not compliant.',
            ]);

        $response->assertStatus(200);
        $instance->refresh();
        $this->assertEquals('cancelled', $instance->status);
        $this->assertEquals('end', $instance->current_node);
    }

    public function test_cannot_advance_completed_workflow(): void
    {
        $admin = User::factory()->admin()->create();
        $instance = WorkflowInstance::factory()->create(['status' => 'completed']);

        $response = $this->actingAs($admin)
            ->putJson("/api/workflow-instances/{$instance->id}/advance", [
                'action' => 'approve',
                'reason' => 'Trying to advance again.',
            ]);

        $response->assertStatus(422);
    }

    public function test_process_timeouts_escalates_overdue_instances(): void
    {
        $admin = User::factory()->admin()->create();
        $escalationUser = User::factory()->admin()->create();

        $def = WorkflowDefinition::create([
            'name' => 'Timeout Flow',
            'slug' => 'timeout-flow',
            'version' => 1,
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'next' => 'review'],
                ['id' => 'review', 'type' => 'approval', 'next' => 'end'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'approval_mode' => 'all_approve',
            'timeout_hours' => 1,
            'escalation_role_user_id' => $escalationUser->id,
            'active' => true,
        ]);

        $overdueInstance = WorkflowInstance::create([
            'workflow_definition_id' => $def->id,
            'entity_type' => 'employer',
            'entity_id' => 1,
            'current_node' => 'review',
            'status' => 'in_progress',
            'initiated_by' => $admin->id,
            'started_at' => now()->subHours(3),
            'timeout_at' => now()->subHour(),
            'node_approvals' => [],
        ]);

        $response = $this->actingAs($admin)->postJson('/api/workflow-instances/process-timeouts');
        $response->assertStatus(200);

        $overdueInstance->refresh();
        $this->assertEquals('escalated', $overdueInstance->status);
        $this->assertEquals($escalationUser->id, $overdueInstance->assigned_to);
        $this->assertNotNull($overdueInstance->escalated_at);

        // Audit trail records the timeout escalate action
        $this->assertDatabaseHas('workflow_action_audits', [
            'workflow_instance_id' => $overdueInstance->id,
            'action' => 'timeout_escalate',
        ]);
    }

    public function test_process_timeouts_ignores_non_overdue_instances(): void
    {
        $admin = User::factory()->admin()->create();

        $def = WorkflowDefinition::factory()->create(['timeout_hours' => 48]);

        $freshInstance = WorkflowInstance::create([
            'workflow_definition_id' => $def->id,
            'entity_type' => 'employer',
            'entity_id' => 1,
            'current_node' => 'review',
            'status' => 'in_progress',
            'initiated_by' => $admin->id,
            'started_at' => now(),
            'timeout_at' => now()->addHours(48),
            'node_approvals' => [],
        ]);

        $this->actingAs($admin)->postJson('/api/workflow-instances/process-timeouts')
            ->assertStatus(200);

        $freshInstance->refresh();
        $this->assertEquals('in_progress', $freshInstance->status);
    }
}
