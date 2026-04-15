<?php

namespace Tests\UnitTests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_be_created_with_all_fields(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@test.com',
            'role' => 'general_user',
        ]);

        $this->assertDatabaseHas('users', [
            'first_name' => 'John',
            'email' => 'john@test.com',
            'role' => 'general_user',
        ]);
    }

    /** @test */
    public function has_role_returns_true_for_matching_role(): void
    {
        $user = User::factory()->admin()->create();
        $this->assertTrue($user->hasRole('system_admin'));
        $this->assertFalse($user->hasRole('general_user'));
    }

    /** @test */
    public function has_any_role_checks_multiple_roles(): void
    {
        $user = User::factory()->complianceReviewer()->create();
        $this->assertTrue($user->hasAnyRole(['compliance_reviewer', 'system_admin']));
        $this->assertFalse($user->hasAnyRole(['inspector', 'general_user']));
    }

    /** @test */
    public function all_five_role_states_are_defined(): void
    {
        $this->assertCount(5, User::ROLES);
        $this->assertContains('system_admin', User::ROLES);
        $this->assertContains('compliance_reviewer', User::ROLES);
        $this->assertContains('employer_manager', User::ROLES);
        $this->assertContains('inspector', User::ROLES);
        $this->assertContains('general_user', User::ROLES);
    }

    /** @test */
    public function password_and_ssn_are_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('ssn', $array);
    }

    /** @test */
    public function default_role_is_general_user(): void
    {
        $user = User::factory()->create();
        $this->assertEquals('general_user', $user->role);
    }

    /** @test */
    public function disabled_defaults_to_false(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->disabled);
    }

    /** @test */
    public function disabled_factory_state_works(): void
    {
        $user = User::factory()->disabled()->create();
        $this->assertTrue($user->disabled);
    }

    /** @test */
    public function user_has_sessions_relationship(): void
    {
        $user = User::factory()->create();
        \App\Models\DeviceSession::create([
            'user_id' => $user->id,
            'device_id' => 'test-device',
        ]);
        $this->assertCount(1, $user->sessions);
    }

    /** @test */
    public function user_has_messages_relationship(): void
    {
        $user = User::factory()->create();
        \App\Models\Message::create([
            'recipient_id' => $user->id,
            'type' => 'test',
            'subject' => 'Hello',
            'body' => 'World',
        ]);
        $this->assertCount(1, $user->messages);
    }
}
