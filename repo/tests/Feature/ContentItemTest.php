<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_user_can_list_published_content(): void
    {
        $author = User::factory()->admin()->create();
        $user = User::factory()->create();

        ContentItem::create([
            'title' => 'Public Post',
            'slug' => 'public-post',
            'body' => 'Public content',
            'status' => 'published',
            'author_id' => $author->id,
            'published_at' => now(),
        ]);
        ContentItem::create([
            'title' => 'Draft Post',
            'slug' => 'draft-post',
            'body' => 'Draft content',
            'status' => 'draft',
            'author_id' => $author->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/content');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_see_all_content(): void
    {
        $admin = User::factory()->admin()->create();

        ContentItem::create([
            'title' => 'Draft', 'slug' => 'draft-a',
            'body' => 'Body', 'status' => 'draft', 'author_id' => $admin->id,
        ]);
        ContentItem::create([
            'title' => 'Archived', 'slug' => 'arch-a',
            'body' => 'Body', 'status' => 'archived', 'author_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/content');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_author_can_see_their_own_drafts(): void
    {
        $author = User::factory()->create();
        ContentItem::create([
            'title' => 'My Draft', 'slug' => 'my-draft',
            'body' => 'Body', 'status' => 'draft', 'author_id' => $author->id,
        ]);

        $response = $this->actingAs($author)->getJson('/api/content');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_general_user_cannot_view_other_users_draft(): void
    {
        $author = User::factory()->admin()->create();
        $other = User::factory()->create();

        $item = ContentItem::create([
            'title' => 'Private Draft', 'slug' => 'private-draft',
            'body' => 'Body', 'status' => 'draft', 'author_id' => $author->id,
        ]);

        $response = $this->actingAs($other)->getJson("/api/content/{$item->id}");
        $response->assertStatus(403);
    }

    public function test_user_can_create_draft_content(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/content', [
            'title' => 'My First Post',
            'slug' => 'my-first-post',
            'body' => 'Hello world',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.author_id', $user->id);
    }

    public function test_general_user_cannot_directly_publish(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/content', [
            'title' => 'Trying to Publish',
            'slug' => 'trying-publish',
            'body' => 'Body',
            'status' => 'published',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_publish_via_publish_endpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $item = ContentItem::create([
            'title' => 'Will Publish', 'slug' => 'will-pub',
            'body' => 'Body', 'status' => 'draft', 'author_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->postJson("/api/content/{$item->id}/publish");
        $response->assertStatus(200);

        $item->refresh();
        $this->assertEquals('published', $item->status);
        $this->assertNotNull($item->published_at);
    }

    public function test_non_admin_cannot_publish(): void
    {
        $user = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'Blocked', 'slug' => 'blocked',
            'body' => 'Body', 'status' => 'draft', 'author_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson("/api/content/{$item->id}/publish");
        $response->assertStatus(403);
    }

    public function test_author_can_update_their_own_content(): void
    {
        $author = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'Old Title', 'slug' => 'old-title',
            'body' => 'Body', 'status' => 'draft', 'author_id' => $author->id,
        ]);

        $response = $this->actingAs($author)->patchJson("/api/content/{$item->id}", [
            'title' => 'New Title',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Title', $item->fresh()->title);
    }

    public function test_non_author_non_admin_cannot_update(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'Yours', 'slug' => 'yours',
            'body' => 'Body', 'status' => 'draft', 'author_id' => $author->id,
        ]);

        $response = $this->actingAs($other)->patchJson("/api/content/{$item->id}", [
            'title' => 'Hijacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_author_can_archive_their_own_content(): void
    {
        $author = User::factory()->create();
        $item = ContentItem::create([
            'title' => 'Archivable', 'slug' => 'arch-1',
            'body' => 'Body', 'status' => 'published', 'author_id' => $author->id,
        ]);

        $response = $this->actingAs($author)->postJson("/api/content/{$item->id}/archive");
        $response->assertStatus(200);
        $this->assertEquals('archived', $item->fresh()->status);
    }

    public function test_publish_already_published_returns_422(): void
    {
        $admin = User::factory()->admin()->create();
        $item = ContentItem::create([
            'title' => 'Already', 'slug' => 'already',
            'body' => 'Body', 'status' => 'published', 'author_id' => $admin->id,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/content/{$item->id}/publish");
        $response->assertStatus(422);
    }

    public function test_unauthenticated_user_cannot_access_content(): void
    {
        $response = $this->getJson('/api/content');
        $response->assertStatus(401);
    }
}
