<?php

namespace Tests\ApiTests;

use App\Models\Employer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    // === Normal inputs ===

    /** @test */
    public function create_employer_returns_201_with_pending_status(): void
    {
        $user = User::factory()->employerManager()->create();
        $response = $this->actingAs($user)->postJson('/api/employers', [
            'company_name' => 'Acme Corp',
            'contact_first_name' => 'John',
            'contact_last_name' => 'Doe',
            'contact_email' => 'john@acme.com',
        ]);
        $response->assertStatus(201)->assertJsonPath('data.status', 'pending');
    }

    /** @test */
    public function list_employers_returns_paginated_results(): void
    {
        $user = User::factory()->admin()->create();
        Employer::factory()->count(3)->create();
        $response = $this->actingAs($user)->getJson('/api/employers');
        $response->assertStatus(200)->assertJsonStructure(['data', 'current_page']);
    }

    /** @test */
    public function show_employer_returns_detail(): void
    {
        $user = User::factory()->admin()->create();
        $employer = Employer::factory()->create();
        $response = $this->actingAs($user)->getJson("/api/employers/{$employer->id}");
        $response->assertStatus(200)->assertJsonStructure(['data' => ['company_name']]);
    }

    /** @test */
    public function approve_employer_changes_status_and_creates_audit(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $employer = Employer::factory()->create();
        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'approve',
        ]);
        $response->assertStatus(200)->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('employer_decision_audits', [
            'employer_id' => $employer->id, 'action' => 'approve',
        ]);
    }

    /** @test */
    public function reject_employer_with_reason_code(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $employer = Employer::factory()->create();
        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'reject',
            'reason_code' => 'incomplete_docs',
            'notes' => 'Missing business license.',
        ]);
        $response->assertStatus(200)->assertJsonPath('data.status', 'rejected');
    }

    /** @test */
    public function update_employer_succeeds_for_owner(): void
    {
        $user = User::factory()->employerManager()->create();
        $employer = Employer::factory()->create(['user_id' => $user->id]);
        $response = $this->actingAs($user)->patchJson("/api/employers/{$employer->id}", [
            'company_name' => 'Updated Corp',
        ]);
        $response->assertStatus(200)->assertJsonPath('data.company_name', 'Updated Corp');
    }

    /** @test */
    public function put_update_employer_succeeds_for_owner(): void
    {
        $user = User::factory()->employerManager()->create();
        $employer = Employer::factory()->create(['user_id' => $user->id]);
        $response = $this->actingAs($user)->putJson("/api/employers/{$employer->id}", [
            'company_name' => 'Put Updated Corp',
        ]);
        $response->assertStatus(200)->assertJsonPath('data.company_name', 'Put Updated Corp');
    }

    // === Missing parameters ===

    /** @test */
    public function create_employer_without_required_fields_returns_422(): void
    {
        $user = User::factory()->employerManager()->create();
        $response = $this->actingAs($user)->postJson('/api/employers', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'contact_first_name', 'contact_last_name', 'contact_email']);
    }

    /** @test */
    public function reject_employer_without_reason_code_returns_422(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $employer = Employer::factory()->create();
        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'reject',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('reason_code');
    }

    /** @test */
    public function review_non_pending_employer_returns_422(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $employer = Employer::factory()->approved()->create();
        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'approve',
        ]);
        $response->assertStatus(422);
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_approve_employer(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $employer = Employer::factory()->create();
        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'approve',
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function non_owner_general_user_cannot_update_employer(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $employer = Employer::factory()->create();
        $response = $this->actingAs($user)->patchJson("/api/employers/{$employer->id}", [
            'company_name' => 'Hacked',
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function pii_masked_for_general_user(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $employer = Employer::factory()->create(['contact_last_name' => 'Thompson']);
        $response = $this->actingAs($user)->getJson("/api/employers/{$employer->id}");
        $response->assertStatus(200);
        $this->assertNotEquals('Thompson', $response->json('data.contact_last_name'));
    }
}
