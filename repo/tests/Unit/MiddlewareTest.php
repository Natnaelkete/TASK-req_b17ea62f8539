<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_role_rejects_unauthenticated_user(): void
    {
        // Create a route that requires a role for testing
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'role:system_admin'])
            ->get('/test-role', fn () => response()->json(['ok' => true]));

        $response = $this->getJson('/test-role');
        $response->assertStatus(401);
    }

    public function test_check_role_rejects_wrong_role(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'role:system_admin'])
            ->get('/test-role-admin', fn () => response()->json(['ok' => true]));

        $user = User::factory()->create(['role' => 'general_user']);

        $response = $this->actingAs($user)->getJson('/test-role-admin');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Insufficient permissions.']);
    }

    public function test_check_role_allows_matching_role(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'role:system_admin'])
            ->get('/test-role-ok', fn () => response()->json(['ok' => true]));

        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->getJson('/test-role-ok');
        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_check_role_accepts_multiple_roles(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'role:system_admin,compliance_reviewer'])
            ->get('/test-multi-role', fn () => response()->json(['ok' => true]));

        $user = User::factory()->complianceReviewer()->create();

        $response = $this->actingAs($user)->getJson('/test-multi-role');
        $response->assertStatus(200);
    }

    public function test_check_role_rejects_disabled_user(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'role:system_admin'])
            ->get('/test-disabled', fn () => response()->json(['ok' => true]));

        $user = User::factory()->admin()->disabled()->create();

        $response = $this->actingAs($user)->getJson('/test-disabled');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Account is disabled.']);
    }

    public function test_check_role_allows_empty_role_list(): void
    {
        // role middleware with no specific roles just checks auth + not disabled
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'role'])
            ->get('/test-role-empty', fn () => response()->json(['ok' => true]));

        $user = User::factory()->create(['role' => 'general_user']);
        $response = $this->actingAs($user)->getJson('/test-role-empty');
        $response->assertStatus(200);
    }

    public function test_trace_correlation_uses_provided_trace_id(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['trace'])
            ->get('/test-trace', fn () => response()->json(['ok' => true]));

        $traceId = 'test-trace-123';
        $response = $this->withHeader('X-Trace-Id', $traceId)->getJson('/test-trace');

        $response->assertStatus(200);
        $response->assertHeader('X-Trace-Id', $traceId);
    }

    public function test_trace_correlation_generates_uuid_when_no_header(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['trace'])
            ->get('/test-trace-auto', fn () => response()->json(['ok' => true]));

        $response = $this->getJson('/test-trace-auto');

        $response->assertStatus(200);
        $this->assertNotNull($response->headers->get('X-Trace-Id'));
    }
}
