<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\User;
use App\Models\MaskingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    private function authAs(string $role = 'system_admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_create_employer(): void
    {
        $user = $this->authAs('employer_manager');

        $response = $this->actingAs($user)->postJson('/api/employers', [
            'company_name' => 'Acme Corp',
            'contact_first_name' => 'John',
            'contact_last_name' => 'Doe',
            'contact_email' => 'john@acme.com',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '73301',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.company_name', 'Acme Corp')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('employers', ['company_name' => 'Acme Corp', 'status' => 'pending']);
    }

    public function test_create_employer_with_qualifications(): void
    {
        $user = $this->authAs('employer_manager');

        $response = $this->actingAs($user)->postJson('/api/employers', [
            'company_name' => 'Qualified Inc',
            'contact_first_name' => 'Jane',
            'contact_last_name' => 'Smith',
            'contact_email' => 'jane@qualified.com',
            'qualifications' => [
                ['qualification_type' => 'business_license', 'license_number' => 'LIC-001'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('employer_qualifications', [
            'qualification_type' => 'business_license',
            'license_number' => 'LIC-001',
        ]);
    }

    public function test_list_employers(): void
    {
        $user = $this->authAs();
        Employer::factory()->count(3)->create();

        $response = $this->actingAs($user)->getJson('/api/employers');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_list_employers_with_status_filter(): void
    {
        $user = $this->authAs();
        Employer::factory()->create(['status' => 'pending']);
        Employer::factory()->approved()->create();

        $response = $this->actingAs($user)->getJson('/api/employers?status=approved');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    public function test_show_employer(): void
    {
        $user = $this->authAs();
        $employer = Employer::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/employers/{$employer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.company_name', $employer->company_name);
    }

    public function test_update_employer(): void
    {
        $user = $this->authAs('employer_manager');
        $employer = Employer::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->patchJson("/api/employers/{$employer->id}", [
            'company_name' => 'Updated Corp',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.company_name', 'Updated Corp');
    }

    public function test_unauthorized_user_cannot_update_other_employer(): void
    {
        $user = $this->authAs('general_user');
        $employer = Employer::factory()->create(); // owned by another user

        $response = $this->actingAs($user)->patchJson("/api/employers/{$employer->id}", [
            'company_name' => 'Hacked Corp',
        ]);

        $response->assertStatus(403);
    }

    public function test_approve_employer(): void
    {
        $reviewer = $this->authAs('compliance_reviewer');
        $employer = Employer::factory()->create();

        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'approve',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Verify audit record
        $this->assertDatabaseHas('employer_decision_audits', [
            'employer_id' => $employer->id,
            'action' => 'approve',
            'role' => 'compliance_reviewer',
        ]);
    }

    public function test_reject_employer_requires_reason_code(): void
    {
        $reviewer = $this->authAs('compliance_reviewer');
        $employer = Employer::factory()->create();

        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'reject',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('reason_code');
    }

    public function test_reject_employer_with_reason(): void
    {
        $reviewer = $this->authAs('compliance_reviewer');
        $employer = Employer::factory()->create();

        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'reject',
            'reason_code' => 'incomplete_docs',
            'notes' => 'Missing business license.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('employers', [
            'id' => $employer->id,
            'rejection_reason_code' => 'incomplete_docs',
        ]);
    }

    public function test_general_user_cannot_approve_employer(): void
    {
        $user = $this->authAs('general_user');
        $employer = Employer::factory()->create();

        $response = $this->actingAs($user)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'approve',
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_review_non_pending_employer(): void
    {
        $reviewer = $this->authAs('compliance_reviewer');
        $employer = Employer::factory()->approved()->create();

        $response = $this->actingAs($reviewer)->postJson("/api/employers/{$employer->id}/review", [
            'action' => 'approve',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Employer is not in pending status.']);
    }

    public function test_employer_pii_masked_for_general_user(): void
    {
        $user = $this->authAs('general_user');
        $employer = Employer::factory()->create([
            'contact_last_name' => 'Thompson',
        ]);

        $response = $this->actingAs($user)->getJson("/api/employers/{$employer->id}");

        $response->assertStatus(200);
        // general_user should see masked last name
        $this->assertNotEquals('Thompson', $response->json('data.contact_last_name'));
    }
}
