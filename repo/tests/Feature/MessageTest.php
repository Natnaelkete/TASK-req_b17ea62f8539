<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleConfigSeeder::class);
    }

    public function test_admin_can_create_message(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/messages', [
            'recipient_id' => $recipient->id,
            'type' => 'check_in_reminder',
            'subject' => 'Reminder: Check-in tomorrow',
            'body' => 'You have a check-in scheduled for tomorrow at 9 AM.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'check_in_reminder');

        $this->assertDatabaseHas('messages', [
            'recipient_id' => $recipient->id,
            'type' => 'check_in_reminder',
        ]);
    }

    public function test_general_user_cannot_create_message(): void
    {
        $user = User::factory()->create(['role' => 'general_user']);
        $recipient = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/messages', [
            'recipient_id' => $recipient->id,
            'type' => 'test',
            'subject' => 'Test',
            'body' => 'Body',
        ]);

        $response->assertStatus(403);
    }

    public function test_list_messages_for_current_user(): void
    {
        $user = User::factory()->create();
        Message::create([
            'recipient_id' => $user->id,
            'type' => 'reminder',
            'subject' => 'Hello',
            'body' => 'World',
            'expires_at' => now()->addDays(365),
        ]);
        Message::create([
            'recipient_id' => $user->id,
            'type' => 'notice',
            'subject' => 'Notice',
            'body' => 'Important notice.',
            'expires_at' => now()->addDays(365),
        ]);

        $response = $this->actingAs($user)->getJson('/api/messages');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_filter_unread_messages(): void
    {
        $user = User::factory()->create();
        Message::create([
            'recipient_id' => $user->id,
            'type' => 'reminder',
            'subject' => 'Unread',
            'body' => 'Not read yet.',
        ]);
        Message::create([
            'recipient_id' => $user->id,
            'type' => 'notice',
            'subject' => 'Read',
            'body' => 'Already read.',
            'read_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/messages?unread=true');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_mark_message_as_read(): void
    {
        $user = User::factory()->create();
        $message = Message::create([
            'recipient_id' => $user->id,
            'type' => 'reminder',
            'subject' => 'Test',
            'body' => 'Mark me read.',
        ]);

        $response = $this->actingAs($user)->putJson("/api/messages/{$message->id}/read");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Marked as read.']);

        $message->refresh();
        $this->assertNotNull($message->read_at);
    }

    public function test_mark_already_read_message(): void
    {
        $user = User::factory()->create();
        $message = Message::create([
            'recipient_id' => $user->id,
            'type' => 'reminder',
            'subject' => 'Test',
            'body' => 'Already read.',
            'read_at' => now(),
        ]);

        $response = $this->actingAs($user)->putJson("/api/messages/{$message->id}/read");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Already read.']);
    }

    public function test_message_stats(): void
    {
        $user = User::factory()->create();
        Message::create(['recipient_id' => $user->id, 'type' => 'a', 'subject' => 'A', 'body' => 'A']);
        Message::create(['recipient_id' => $user->id, 'type' => 'b', 'subject' => 'B', 'body' => 'B']);
        Message::create(['recipient_id' => $user->id, 'type' => 'c', 'subject' => 'C', 'body' => 'C', 'read_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/messages/stats');

        $response->assertStatus(200)
            ->assertJson([
                'total' => 3,
                'unread' => 2,
                'read' => 1,
            ]);
    }
}
