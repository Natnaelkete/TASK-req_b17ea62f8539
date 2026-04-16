<?php

namespace Tests\ApiTests;

use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    // === Normal inputs ===

    /** @test */
    public function user_can_list_own_preferences(): void
    {
        $user = User::factory()->create();
        UserNotificationPreference::create([
            'user_id' => $user->id, 'notification_type' => 'mail', 'enabled' => true,
        ]);

        $this->actingAs($user)->getJson('/api/notification-preferences')
            ->assertStatus(200);
    }

    /** @test */
    public function user_can_create_preference(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/notification-preferences', [
            'notification_type' => 'digest', 'enabled' => true,
        ])->assertStatus(201);
    }

    /** @test */
    public function user_can_update_own_preference(): void
    {
        $user = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $user->id, 'notification_type' => 'sms', 'enabled' => true,
        ]);

        $this->actingAs($user)->putJson("/api/notification-preferences/{$pref->id}", [
            'enabled' => false,
        ])->assertStatus(200);
    }

    /** @test */
    public function user_can_delete_own_preference(): void
    {
        $user = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $user->id, 'notification_type' => 'weekly', 'enabled' => true,
        ]);
        $this->actingAs($user)->deleteJson("/api/notification-preferences/{$pref->id}")
            ->assertStatus(200);
    }

    // === Missing parameters ===

    /** @test */
    public function create_without_fields_returns_422(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/notification-preferences', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notification_type', 'enabled']);
    }

    /** @test */
    public function update_without_enabled_returns_422(): void
    {
        $user = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $user->id, 'notification_type' => 'foo', 'enabled' => true,
        ]);
        $this->actingAs($user)->putJson("/api/notification-preferences/{$pref->id}", [])
            ->assertStatus(422)->assertJsonValidationErrors('enabled');
    }

    // === Permission errors ===

    /** @test */
    public function cannot_update_other_users_preference(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $owner->id, 'notification_type' => 'x', 'enabled' => true,
        ]);
        $this->actingAs($other)->putJson("/api/notification-preferences/{$pref->id}", [
            'enabled' => false,
        ])->assertStatus(403);
    }

    /** @test */
    public function cannot_delete_other_users_preference(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $pref = UserNotificationPreference::create([
            'user_id' => $owner->id, 'notification_type' => 'y', 'enabled' => true,
        ]);
        $this->actingAs($other)->deleteJson("/api/notification-preferences/{$pref->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/notification-preferences')->assertStatus(401);
    }
}
