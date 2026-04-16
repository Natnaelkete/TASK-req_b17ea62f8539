<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_their_preferences(): void
    {
        $user = User::factory()->create();
        UserNotificationPreference::create([
            'user_id' => $user->id,
            'notification_type' => 'email_digest',
            'enabled' => true,
        ]);
        UserNotificationPreference::create([
            'user_id' => $user->id,
            'notification_type' => 'sms_alert',
            'enabled' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/notification-preferences');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_user_sees_only_their_own_preferences(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        UserNotificationPreference::create([
            'user_id' => $userA->id, 'notification_type' => 'a_pref', 'enabled' => true,
        ]);
        UserNotificationPreference::create([
            'user_id' => $userB->id, 'notification_type' => 'b_pref', 'enabled' => true,
        ]);

        $response = $this->actingAs($userA)->getJson('/api/notification-preferences');
        $response->assertStatus(200);

        $types = collect($response->json('data'))->pluck('notification_type')->toArray();
        $this->assertContains('a_pref', $types);
        $this->assertNotContains('b_pref', $types);
    }

    public function test_user_can_create_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/notification-preferences', [
            'notification_type' => 'email_digest',
            'enabled' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'notification_type' => 'email_digest',
            'enabled' => true,
        ]);
    }

    public function test_create_is_idempotent_on_notification_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/notification-preferences', [
            'notification_type' => 'same_type',
            'enabled' => true,
        ]);

        $this->actingAs($user)->postJson('/api/notification-preferences', [
            'notification_type' => 'same_type',
            'enabled' => false,
        ]);

        $count = UserNotificationPreference::where('user_id', $user->id)
            ->where('notification_type', 'same_type')
            ->count();
        $this->assertEquals(1, $count);

        $pref = UserNotificationPreference::where('user_id', $user->id)
            ->where('notification_type', 'same_type')->first();
        $this->assertFalse($pref->enabled);
    }

    public function test_user_can_update_their_preference(): void
    {
        $user = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $user->id,
            'notification_type' => 'weekly_summary',
            'enabled' => true,
        ]);

        $response = $this->actingAs($user)->putJson("/api/notification-preferences/{$pref->id}", [
            'enabled' => false,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($pref->fresh()->enabled);
    }

    public function test_user_cannot_update_another_users_preference(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $pref = UserNotificationPreference::create([
            'user_id' => $owner->id,
            'notification_type' => 'owner_only',
            'enabled' => true,
        ]);

        $response = $this->actingAs($other)->putJson("/api/notification-preferences/{$pref->id}", [
            'enabled' => false,
        ]);

        $response->assertStatus(403);
        $this->assertTrue($pref->fresh()->enabled);
    }

    public function test_user_can_delete_their_preference(): void
    {
        $user = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $user->id,
            'notification_type' => 'deletable',
            'enabled' => true,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/notification-preferences/{$pref->id}");
        $response->assertStatus(200);
        $this->assertDatabaseMissing('user_notification_preferences', ['id' => $pref->id]);
    }

    public function test_user_cannot_delete_another_users_preference(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $pref = UserNotificationPreference::create([
            'user_id' => $owner->id,
            'notification_type' => 'protected',
            'enabled' => true,
        ]);

        $response = $this->actingAs($other)->deleteJson("/api/notification-preferences/{$pref->id}");
        $response->assertStatus(403);
        $this->assertDatabaseHas('user_notification_preferences', ['id' => $pref->id]);
    }

    public function test_create_preference_requires_fields(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/notification-preferences', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notification_type', 'enabled']);
    }

    public function test_unauthenticated_user_cannot_access_preferences(): void
    {
        $response = $this->getJson('/api/notification-preferences');
        $response->assertStatus(401);
    }
}
