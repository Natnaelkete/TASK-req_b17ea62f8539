<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_created(): void
    {
        $user = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@test.com',
        ]);

        $this->assertDatabaseHas('users', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@test.com',
        ]);
    }

    public function test_user_has_role_method(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertTrue($user->hasRole('system_admin'));
        $this->assertFalse($user->hasRole('general_user'));
    }

    public function test_user_has_any_role_method(): void
    {
        $user = User::factory()->complianceReviewer()->create();

        $this->assertTrue($user->hasAnyRole(['compliance_reviewer', 'system_admin']));
        $this->assertFalse($user->hasAnyRole(['inspector', 'general_user']));
    }

    public function test_user_factory_states(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertEquals('system_admin', $admin->role);

        $reviewer = User::factory()->complianceReviewer()->create();
        $this->assertEquals('compliance_reviewer', $reviewer->role);

        $manager = User::factory()->employerManager()->create();
        $this->assertEquals('employer_manager', $manager->role);

        $inspector = User::factory()->inspector()->create();
        $this->assertEquals('inspector', $inspector->role);

        $disabled = User::factory()->disabled()->create();
        $this->assertTrue($disabled->disabled);
    }

    public function test_user_roles_constant(): void
    {
        $this->assertContains('system_admin', User::ROLES);
        $this->assertContains('compliance_reviewer', User::ROLES);
        $this->assertContains('employer_manager', User::ROLES);
        $this->assertContains('inspector', User::ROLES);
        $this->assertContains('general_user', User::ROLES);
        $this->assertCount(5, User::ROLES);
    }

    public function test_user_password_is_hidden(): void
    {
        $user = User::factory()->create();
        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('ssn', $array);
    }

    public function test_user_default_role_is_general_user(): void
    {
        $user = User::factory()->create();
        $this->assertEquals('general_user', $user->role);
    }

    public function test_user_disabled_defaults_to_false(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->disabled);
    }
}
