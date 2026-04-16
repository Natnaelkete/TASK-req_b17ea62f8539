<?php

namespace Tests\ApiTests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    // === Normal inputs ===

    /** @test */
    public function register_with_valid_data_returns_201(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'user' => ['id', 'username', 'email', 'role'], 'token']);
        $this->assertDatabaseHas('users', ['username' => 'johndoe', 'email' => 'john@example.com']);
    }

    /** @test */
    public function login_with_valid_credentials_returns_token(): void
    {
        User::factory()->create([
            'username' => 'loginuser',
            'email' => 'login@test.com',
            'password' => 'SecurePass@123',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'loginuser',
            'password' => 'SecurePass@123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'user', 'token']);
    }

    /** @test */
    public function logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $response->assertStatus(200);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** @test */
    public function me_returns_authenticated_user(): void
    {
        $user = User::factory()->admin()->create();
        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.role', 'system_admin');
    }

    // === Missing parameters ===

    /** @test */
    public function register_without_required_fields_returns_422(): void
    {
        $response = $this->postJson('/api/register', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'username', 'email', 'password']);
    }

    /** @test */
    public function register_with_short_password_returns_422(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'X', 'last_name' => 'Y',
            'username' => 'xy',
            'email' => 'x@y.com', 'password' => 'short', 'password_confirmation' => 'short',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /** @test */
    public function register_without_special_char_returns_422(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'A', 'last_name' => 'B',
            'username' => 'ab',
            'email' => 'a@b.com', 'password' => 'NoSpecialChar123', 'password_confirmation' => 'NoSpecialChar123',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /** @test */
    public function register_with_duplicate_username_returns_422(): void
    {
        User::factory()->create(['username' => 'taken_user']);
        $response = $this->postJson('/api/register', [
            'first_name' => 'A', 'last_name' => 'B',
            'username' => 'taken_user',
            'email' => 'fresh@test.com', 'password' => 'SecurePass@123', 'password_confirmation' => 'SecurePass@123',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    /** @test */
    public function register_with_duplicate_email_returns_422(): void
    {
        User::factory()->create(['email' => 'taken@test.com']);
        $response = $this->postJson('/api/register', [
            'first_name' => 'A', 'last_name' => 'B',
            'username' => 'freshuser',
            'email' => 'taken@test.com', 'password' => 'SecurePass@123', 'password_confirmation' => 'SecurePass@123',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /** @test */
    public function login_with_wrong_password_returns_401(): void
    {
        User::factory()->create(['username' => 'wrongpw_user', 'email' => 'x@test.com', 'password' => 'SecurePass@123']);
        $response = $this->postJson('/api/login', ['username' => 'wrongpw_user', 'password' => 'Wrong@Password1']);
        $response->assertStatus(401);
    }

    // === Permission errors ===

    /** @test */
    public function unauthenticated_request_to_protected_route_returns_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    /** @test */
    public function disabled_user_cannot_login(): void
    {
        User::factory()->disabled()->create(['username' => 'disableduser', 'email' => 'd@test.com', 'password' => 'SecurePass@123']);
        $this->postJson('/api/login', ['username' => 'disableduser', 'password' => 'SecurePass@123'])
            ->assertStatus(403);
    }

    /** @test */
    public function account_locks_after_10_failed_attempts(): void
    {
        User::factory()->create(['username' => 'lockuser', 'email' => 'lock@test.com', 'password' => 'SecurePass@123']);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['username' => 'lockuser', 'password' => 'wrong@Pass1xx']);
        }
        $this->postJson('/api/login', ['username' => 'lockuser', 'password' => 'SecurePass@123'])
            ->assertStatus(429);
    }

    /** @test */
    public function register_with_invalid_username_chars_returns_422(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Bad', 'last_name' => 'User',
            'username' => 'bad user!',
            'email' => 'bad@example.com',
            'password' => 'SecurePass@123', 'password_confirmation' => 'SecurePass@123',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }
}
