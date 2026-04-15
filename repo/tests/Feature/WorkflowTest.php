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
}
