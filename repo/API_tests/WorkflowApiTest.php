<?php

namespace Tests\ApiTests;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    // === Normal inputs ===

    /** @test */
    public function create_workflow_definition(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/workflow-definitions', [
            'name' => 'Employer Approval',
            'slug' => 'employer-approval',
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'end', 'type' => 'end'],
            ],
        ])->assertStatus(201)->assertJsonPath('data.version', 1);
    }

    /** @test */
    public function create_and_advance_workflow_instance(): void
    {
        $admin = User::factory()->admin()->create();
        $def = WorkflowDefinition::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/workflow-instances', [
            'workflow_definition_id' => $def->id,
            'entity_type' => 'employer', 'entity_id' => 1,
        ]);
        $response->assertStatus(201);
        $instanceId = $response->json('data.id');

        $this->actingAs($admin)->putJson("/api/workflow-instances/{$instanceId}/advance", [
            'action' => 'approve', 'reason' => 'All checks passed.',
        ])->assertStatus(200)->assertJsonPath('data.status', 'completed');
    }

    /** @test */
    public function escalate_workflow_instance(): void
    {
        $admin = User::factory()->admin()->create();
        $instance = WorkflowInstance::factory()->create();
        $this->actingAs($admin)->putJson("/api/workflow-instances/{$instance->id}/advance", [
            'action' => 'escalate', 'reason' => 'Timeout.',
        ])->assertStatus(200)->assertJsonPath('data.status', 'escalated');
    }

    /** @test */
    public function show_workflow_instance_with_audit_trail(): void
    {
        $admin = User::factory()->admin()->create();
        $instance = WorkflowInstance::factory()->create();
        $instance->logAudit('create', $admin->id, 'system_admin', 'Created.');
        $response = $this->actingAs($admin)->getJson("/api/workflow-instances/{$instance->id}");
        $response->assertStatus(200)->assertJsonStructure(['data', 'audit_trail']);
        $this->assertNotEmpty($response->json('audit_trail'));
    }

    // === Missing parameters ===

    /** @test */
    public function create_definition_without_slug_returns_422(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/workflow-definitions', [
            'name' => 'Test',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug', 'nodes']);
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_access_workflows(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $this->actingAs($user)->getJson('/api/workflow-definitions')->assertStatus(403);
    }

    /** @test */
    public function inspector_cannot_create_workflow_definition(): void
    {
        $inspector = User::factory()->inspector()->create();
        $this->actingAs($inspector)->postJson('/api/workflow-definitions', [
            'name' => 'T', 'slug' => 'test', 'nodes' => [['id' => 'start']],
        ])->assertStatus(403);
    }
}
