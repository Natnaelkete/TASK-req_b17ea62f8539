<?php

namespace Tests\ApiTests;

use App\Models\Objection;
use App\Models\ResultVersion;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    private function publicResult(): ResultVersion
    {
        return ResultVersion::factory()->create([
            'status' => 'public', 'published_at' => now(),
            'snapshot' => ['summary' => 'Final'],
        ]);
    }

    // === Normal inputs ===

    /** @test */
    public function file_objection_against_public_result(): void
    {
        $user = User::factory()->create();
        $rv = $this->publicResult();
        $this->actingAs($user)->postJson("/api/result-versions/{$rv->id}/objections", [
            'reason' => 'Errors found.',
        ])->assertStatus(201)->assertJsonPath('data.status', 'intake');
        $this->assertDatabaseHas('tickets', ['objection_id' => 1]);
    }

    /** @test */
    public function objection_status_full_lifecycle(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $user = User::factory()->create();
        $rv = $this->publicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id, 'filed_by' => $user->id,
            'reason' => 'Test', 'status' => 'intake',
        ]);
        Ticket::create(['objection_id' => $objection->id, 'status' => 'intake']);

        $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", ['status' => 'review'])
            ->assertJsonPath('data.status', 'review');
        $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", ['status' => 'adjudication'])
            ->assertJsonPath('data.status', 'adjudication');
        $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", [
            'status' => 'resolved', 'resolution_notes' => 'Sustained.',
        ])->assertJsonPath('data.status', 'resolved');
    }

    /** @test */
    public function show_objection(): void
    {
        $user = User::factory()->create();
        $rv = $this->publicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id, 'filed_by' => $user->id, 'reason' => 'Show test',
        ]);
        $this->actingAs($user)->getJson("/api/objections/{$objection->id}")
            ->assertStatus(200)->assertJsonPath('data.reason', 'Show test');
    }

    /** @test */
    public function show_ticket(): void
    {
        $user = User::factory()->create();
        $rv = $this->publicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id, 'filed_by' => $user->id, 'reason' => 'T',
        ]);
        $ticket = Ticket::create(['objection_id' => $objection->id]);
        $this->actingAs($user)->getJson("/api/tickets/{$ticket->id}")
            ->assertStatus(200)->assertJsonStructure(['data' => ['id', 'status']]);
    }

    // === Missing parameters / invalid state ===

    /** @test */
    public function cannot_object_to_draft_result(): void
    {
        $user = User::factory()->create();
        $rv = ResultVersion::factory()->create(['status' => 'draft']);
        $this->actingAs($user)->postJson("/api/result-versions/{$rv->id}/objections", [
            'reason' => 'X',
        ])->assertStatus(422);
    }

    /** @test */
    public function objection_window_closes_after_7_days(): void
    {
        $user = User::factory()->create();
        $rv = ResultVersion::factory()->create([
            'status' => 'public', 'published_at' => now()->subDays(8),
        ]);
        $this->actingAs($user)->postJson("/api/result-versions/{$rv->id}/objections", [
            'reason' => 'Too late.',
        ])->assertStatus(422);
    }

    /** @test */
    public function invalid_objection_transition_returns_422(): void
    {
        $reviewer = User::factory()->complianceReviewer()->create();
        $user = User::factory()->create();
        $rv = $this->publicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id, 'filed_by' => $user->id,
            'reason' => 'T', 'status' => 'intake',
        ]);
        $this->actingAs($reviewer)->patchJson("/api/objections/{$objection->id}", ['status' => 'adjudication'])
            ->assertStatus(422);
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_update_objection_status(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $rv = $this->publicResult();
        $objection = Objection::create([
            'result_version_id' => $rv->id, 'filed_by' => $user->id, 'reason' => 'T',
        ]);
        $this->actingAs($user)->patchJson("/api/objections/{$objection->id}", ['status' => 'review'])
            ->assertStatus(403);
    }
}
