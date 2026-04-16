<?php

namespace Tests\ApiTests;

use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentItemApiTest extends TestCase
{
    use RefreshDatabase;

    // === Normal inputs ===

    /** @test */
    public function list_returns_published_for_general_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        ContentItem::create([
            'title' => 'Pub', 'slug' => 'pub',
            'body' => 'x', 'status' => 'published',
            'author_id' => $admin->id, 'published_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/content')
            ->assertStatus(200);
    }

    /** @test */
    public function show_returns_published_item(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'Pub', 'slug' => 'pub-show',
            'body' => 'x', 'status' => 'published',
            'author_id' => $admin->id, 'published_at' => now(),
        ]);

        $this->actingAs($user)->getJson("/api/content/{$item->id}")
            ->assertStatus(200);
    }

    /** @test */
    public function author_can_create_draft(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/content', [
            'title' => 'New', 'slug' => 'new-draft', 'body' => 'Body',
        ])->assertStatus(201)
          ->assertJsonPath('data.status', 'draft');
    }

    /** @test */
    public function admin_can_publish_via_dedicated_endpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $item = ContentItem::create([
            'title' => 'Pub', 'slug' => 'to-pub',
            'body' => 'x', 'status' => 'draft',
            'author_id' => $admin->id,
        ]);
        $this->actingAs($admin)->postJson("/api/content/{$item->id}/publish")
            ->assertStatus(200);
    }

    // === Missing / invalid parameters ===

    /** @test */
    public function create_requires_title_slug_body(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/content', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'slug', 'body']);
    }

    /** @test */
    public function create_rejects_duplicate_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        ContentItem::create([
            'title' => 'X', 'slug' => 'taken',
            'body' => 'x', 'status' => 'draft', 'author_id' => $admin->id,
        ]);

        $this->actingAs($user)->postJson('/api/content', [
            'title' => 'Y', 'slug' => 'taken', 'body' => 'y',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    // === Permission errors ===

    /** @test */
    public function general_user_cannot_publish_directly_on_create(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/content', [
            'title' => 'P', 'slug' => 'p1', 'body' => 'b', 'status' => 'published',
        ])->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_publish(): void
    {
        $user = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'P', 'slug' => 'p2',
            'body' => 'b', 'status' => 'draft', 'author_id' => $user->id,
        ]);
        $this->actingAs($user)->postJson("/api/content/{$item->id}/publish")
            ->assertStatus(403);
    }

    /** @test */
    public function non_author_cannot_update(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'O', 'slug' => 'o1',
            'body' => 'b', 'status' => 'draft', 'author_id' => $author->id,
        ]);
        $this->actingAs($other)->patchJson("/api/content/{$item->id}", [
            'title' => 'Hacked',
        ])->assertStatus(403);
    }

    /** @test */
    public function non_author_cannot_view_draft(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'D', 'slug' => 'd1',
            'body' => 'b', 'status' => 'draft', 'author_id' => $author->id,
        ]);
        $this->actingAs($other)->getJson("/api/content/{$item->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/content')->assertStatus(401);
    }
}
