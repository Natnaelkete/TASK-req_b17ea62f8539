<?php

namespace Tests\ApiTests;

use App\Models\Employer;
use App\Models\Inspection;
use App\Models\Job;
use App\Models\Message;
use App\Models\Objection;
use App\Models\ResultVersion;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDOR (Insecure Direct Object Reference) protection tests.
 * Verifies object-level authorization prevents cross-user data access.
 */
class IdorProtectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    // ========================================
    // Registration privilege escalation
    // ========================================

    /** @test */
    public function register_with_role_system_admin_is_forced_to_general_user(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Evil',
            'last_name' => 'Hacker',
            'email' => 'escalation@test.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
            'role' => 'system_admin',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'escalation@test.com',
            'role' => 'general_user',
        ]);
    }

    /** @test */
    public function register_with_role_compliance_reviewer_is_forced_to_general_user(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Sneaky',
            'last_name' => 'User',
            'email' => 'reviewer-esc@test.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
            'role' => 'compliance_reviewer',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'reviewer-esc@test.com',
            'role' => 'general_user',
        ]);
    }

    /** @test */
    public function register_with_role_inspector_is_forced_to_general_user(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Wannabe',
            'last_name' => 'Inspector',
            'email' => 'inspector-esc@test.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
            'role' => 'inspector',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'inspector-esc@test.com',
            'role' => 'general_user',
        ]);
    }

    /** @test */
    public function register_without_role_defaults_to_general_user(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Normal',
            'last_name' => 'User',
            'email' => 'normal@test.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'normal@test.com',
            'role' => 'general_user',
        ]);
    }

    // ========================================
    // Objection IDOR
    // ========================================

    /** @test */
    public function other_user_cannot_view_objection_they_did_not_file(): void
    {
        $filer = User::factory()->create();
        $otherUser = User::factory()->create();
        $rv = ResultVersion::factory()->create(['status' => 'public', 'published_at' => now()]);
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $filer->id,
            'reason' => 'My objection',
            'status' => 'intake',
        ]);

        $this->actingAs($otherUser)->getJson("/api/objections/{$objection->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function filer_can_view_own_objection(): void
    {
        $filer = User::factory()->create();
        $rv = ResultVersion::factory()->create(['status' => 'public', 'published_at' => now()]);
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $filer->id,
            'reason' => 'My objection',
            'status' => 'intake',
        ]);

        $this->actingAs($filer)->getJson("/api/objections/{$objection->id}")
            ->assertStatus(200);
    }

    /** @test */
    public function admin_can_view_any_objection(): void
    {
        $admin = User::factory()->admin()->create();
        $filer = User::factory()->create();
        $rv = ResultVersion::factory()->create(['status' => 'public', 'published_at' => now()]);
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $filer->id,
            'reason' => 'Objection',
            'status' => 'intake',
        ]);

        $this->actingAs($admin)->getJson("/api/objections/{$objection->id}")
            ->assertStatus(200);
    }

    // ========================================
    // Ticket IDOR
    // ========================================

    /** @test */
    public function other_user_cannot_view_ticket(): void
    {
        $filer = User::factory()->create();
        $otherUser = User::factory()->create();
        $rv = ResultVersion::factory()->create(['status' => 'public', 'published_at' => now()]);
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $filer->id,
            'reason' => 'T', 'status' => 'intake',
        ]);
        $ticket = Ticket::create(['objection_id' => $objection->id]);

        $this->actingAs($otherUser)->getJson("/api/tickets/{$ticket->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function filer_can_view_own_ticket(): void
    {
        $filer = User::factory()->create();
        $rv = ResultVersion::factory()->create(['status' => 'public', 'published_at' => now()]);
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $filer->id,
            'reason' => 'T', 'status' => 'intake',
        ]);
        $ticket = Ticket::create(['objection_id' => $objection->id]);

        $this->actingAs($filer)->getJson("/api/tickets/{$ticket->id}")
            ->assertStatus(200);
    }

    // ========================================
    // Message IDOR
    // ========================================

    /** @test */
    public function other_user_cannot_mark_someone_elses_message_as_read(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $message = Message::create([
            'recipient_id' => $owner->id,
            'type' => 'test', 'subject' => 'Private', 'body' => 'Content',
        ]);

        $this->actingAs($otherUser)->putJson("/api/messages/{$message->id}/read")
            ->assertStatus(403);
    }

    /** @test */
    public function recipient_can_mark_own_message_as_read(): void
    {
        $owner = User::factory()->create();
        $message = Message::create([
            'recipient_id' => $owner->id,
            'type' => 'test', 'subject' => 'Mine', 'body' => 'Content',
        ]);

        $this->actingAs($owner)->putJson("/api/messages/{$message->id}/read")
            ->assertStatus(200);
    }

    // ========================================
    // Inspection IDOR
    // ========================================

    /** @test */
    public function inspector_cannot_view_another_inspectors_inspection(): void
    {
        $inspector1 = User::factory()->inspector()->create();
        $inspector2 = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector1->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($inspector2)->getJson("/api/inspections/{$inspection->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function inspector_cannot_update_another_inspectors_inspection(): void
    {
        $inspector1 = User::factory()->inspector()->create();
        $inspector2 = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector1->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($inspector2)->patchJson("/api/inspections/{$inspection->id}", [
            'status' => 'in_progress',
        ])->assertStatus(403);
    }

    /** @test */
    public function general_user_cannot_view_inspection(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->getJson("/api/inspections/{$inspection->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function admin_can_view_any_inspection(): void
    {
        $admin = User::factory()->admin()->create();
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)->getJson("/api/inspections/{$inspection->id}")
            ->assertStatus(200);
    }

    /** @test */
    public function inspector_can_view_own_inspection(): void
    {
        $inspector = User::factory()->inspector()->create();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $inspection = Inspection::create([
            'job_id' => $job->id, 'inspector_id' => $inspector->id,
            'employer_id' => $employer->id, 'scheduled_at' => now()->addDay(),
        ]);

        $this->actingAs($inspector)->getJson("/api/inspections/{$inspection->id}")
            ->assertStatus(200);
    }
}
