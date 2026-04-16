<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'first_name', 'last_name', 'email', 'role'],
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'role' => 'general_user',
        ]);
    }

    public function test_registration_fails_with_short_password(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_without_special_char(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'NoSpecialChar123',
            'password_confirmation' => 'NoSpecialChar123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'taken@example.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'SecurePass@123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'SecurePass@123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'first_name', 'last_name', 'email', 'role'],
                'token',
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'SecurePass@123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'WrongPassword@1',
        ]);

        $response->assertStatus(401);
    }

    public function test_disabled_user_cannot_login(): void
    {
        User::factory()->disabled()->create([
            'email' => 'disabled@example.com',
            'password' => 'SecurePass@123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'disabled@example.com',
            'password' => 'SecurePass@123',
        ]);

        $response->assertStatus(403);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully.']);

        // Token should be deleted
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'first_name', 'last_name', 'email', 'role', 'disabled'],
            ]);
    }

    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_account_locks_after_10_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'lockout@example.com',
            'password' => 'SecurePass@123',
        ]);

        // Fail 10 times
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'lockout@example.com',
                'password' => 'wrong@Password1',
            ]);
        }

        // 11th attempt should be locked out
        $response = $this->postJson('/api/login', [
            'email' => 'lockout@example.com',
            'password' => 'SecurePass@123',
        ]);

        $response->assertStatus(429);
    }

    public function test_register_role_input_is_always_forced_to_general_user(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Inspector',
            'last_name' => 'User',
            'email' => 'inspector@example.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
            'role' => 'system_admin', // attempt escalation
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'inspector@example.com',
            'role' => 'general_user', // must be forced
        ]);
    }

    public function test_login_with_nonexistent_email(): void
    {
        $uniqueEmail = 'nonexistent-' . uniqid() . '@example.com';
        $response = $this->postJson('/api/login', [
            'email' => $uniqueEmail,
            'password' => 'SecurePass@123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_register_without_required_fields(): void
    {
        $response = $this->postJson('/api/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
    }

    public function test_me_returns_correct_user_data(): void
    {
        $user = User::factory()->admin()->create([
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'email' => 'admin-test@example.com',
        ]);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJson([
                'user' => [
                    'first_name' => 'Admin',
                    'last_name' => 'Test',
                    'email' => 'admin-test@example.com',
                    'role' => 'system_admin',
                    'disabled' => false,
                ],
            ]);
    }
}
