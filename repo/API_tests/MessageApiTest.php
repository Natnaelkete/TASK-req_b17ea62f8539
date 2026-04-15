<?php

namespace Tests\ApiTests;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    // === Normal inputs ===

    /** @test */
    public function admin_creates_message(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $this->actingAs($admin)->postJson('/api/messages', [
            'recipient_id' => $recipient->id,
            'type' => 'check_in_reminder',
            'subject' => 'Reminder',
            'body' => 'Upcoming check-in.',
        ])->assertStatus(201);
    }

    /** @test */
    public function list_messages_filtered_by_user(): void
    {
        $user = User::factory()->create();
        Message::create(['recipient_id' => $user->id, 'type' => 'a', 'subject' => 'A', 'body' => 'A']);
        Message::create(['recipient_id' => $user->id, 'type' => 'b', 'subject' => 'B', 'body' => 'B']);
        $response = $this->actingAs($user)->getJson('/api/messages');
        $this->assertCount(2, $response->json('data'));
    }

    /** @test */
    public function mark_message_as_read(): void
    {
        $user = User::factory()->create();
        $msg = Message::create(['recipient_id' => $user->id, 'type' => 'a', 'subject' => 'X', 'body' => 'Y']);
        $this->actingAs($user)->putJson("/api/messages/{$msg->id}/read")
            ->assertStatus(200)->assertJson(['message' => 'Marked as read.']);
    }

    /** @test */
    public function stats_returns_correct_counts(): void
    {
        $user = User::factory()->create();
        Message::create(['recipient_id' => $user->id, 'type' => 'a', 'subject' => 'A', 'body' => 'A']);
        Message::create(['recipient_id' => $user->id, 'type' => 'b', 'subject' => 'B', 'body' => 'B', 'read_at' => now()]);
        $this->actingAs($user)->getJson('/api/messages/stats')
            ->assertJson(['total' => 2, 'unread' => 1, 'read' => 1]);
    }

    // === Missing parameters ===

    /** @test */
    public function create_message_without_fields_returns_422(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/api/messages', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_id', 'type', 'subject', 'body']);
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_create_message(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $recipient = User::factory()->create();
        $this->actingAs($user)->postJson('/api/messages', [
            'recipient_id' => $recipient->id,
            'type' => 'test', 'subject' => 'X', 'body' => 'Y',
        ])->assertStatus(403);
    }
}
