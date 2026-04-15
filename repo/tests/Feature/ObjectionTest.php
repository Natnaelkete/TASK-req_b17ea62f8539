<?php

namespace Tests\Feature;

use App\Models\Objection;
use App\Models\ResultVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    private function createPublicResult(): ResultVersion
    {
        $rv = ResultVersion::factory()->create([
            'status' => 'public',
            'published_at' => now(),
            'snapshot' => ['summary' => 'Final result'],
        ]);
        return $rv;
    }

    public function test_file_objection_against_public_result(): void
    {
        $user = User::factory()->create();
        $rv = $this->createPublicResult();

        $response = $this->actingAs($user)->postJson("/api/result-versions/{$rv->id}/objections", [
            'reason' => 'The results contain errors.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'intake');

        // Ticket should be created
        $this->assertDatabaseHas('tickets', ['objection_id' => $response->json('data.id')]);
    }

    public function test_cannot_object_to_draft_result(): void
    {
        $user = User::factory()->create();
        $rv = ResultVersion::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->postJson("/api/result-versions/{$rv->id}/objections", [
            'reason' => 'This should fail.',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Objections can only be filed against public results.']);
    }

    public function test_objection_window_closes_after_7_days(): void
    {
        $user = User::factory()->create();
        $rv = ResultVersion::factory()->create([
            'status' => 'public',
            'published_at' => now()->subDays(8),
        ]);

        $response = $this->actingAs($user)->postJson("/api/result-versions/{$rv->id}/objections", [
            'reason' => 'Too late.',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Objection window has closed (7 days from publication).']);
    }

    public function test_objection_status_transitions(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $user = User::factory()->create();
        $rv = $this->createPublicResult();

        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test objection',
            'status' => 'intake',
        ]);
        \App\Models\Ticket::create(['objection_id' => $objection->id, 'status' => 'intake']);

        // intake -> review
        $response = $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", [
            'status' => 'review',
        ]);
        $response->assertStatus(200)->assertJsonPath('data.status', 'review');

        // review -> adjudication
        $response = $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", [
            'status' => 'adjudication',
        ]);
        $response->assertStatus(200)->assertJsonPath('data.status', 'adjudication');

        // adjudication -> resolved
        $response = $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", [
            'status' => 'resolved',
            'resolution_notes' => 'Objection sustained. Corrections applied.',
        ]);
        $response->assertStatus(200)->assertJsonPath('data.status', 'resolved');
    }

    public function test_invalid_objection_transition(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $user = User::factory()->create();
        $rv = $this->createPublicResult();

        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
            'status' => 'intake',
        ]);

        // Can't go directly from intake to adjudication
        $response = $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", [
            'status' => 'adjudication',
        ]);

        $response->assertStatus(422);
    }

    public function test_general_user_cannot_update_objection_status(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $rv = $this->createPublicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);

        $response = $this->actingAs($user)->patchJson("/api/objections/{$objection->id}", [
            'status' => 'review',
        ]);

        $response->assertStatus(403);
    }

    public function test_show_objection(): void
    {
        $user = User::factory()->create();
        $rv = $this->createPublicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Show this objection.',
        ]);

        $response = $this->actingAs($user)->getJson("/api/objections/{$objection->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.reason', 'Show this objection.');
    }

    public function test_show_ticket(): void
    {
        $user = User::factory()->create();
        $rv = $this->createPublicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id,
            'filed_by' => $user->id,
            'reason' => 'Test',
        ]);
        $ticket = \App\Models\Ticket::create(['objection_id' => $objection->id]);

        $response = $this->actingAs($user)->getJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'objection_id', 'status']]);
    }
}
