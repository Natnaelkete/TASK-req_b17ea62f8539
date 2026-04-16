<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Published items visible to all; drafts/archived only to author or admin
        $query = ContentItem::query();

        if ($user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            // Admins see all content
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('status', 'published')
                  ->orWhere('author_id', $user->id);
            });
        }

        $items = $query->orderByDesc('created_at')->paginate(20);

        return response()->json($items);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $item = ContentItem::findOrFail($id);
        $user = $request->user();

        // Non-published items only visible to author or admin
        if ($item->status !== 'published'
            && $item->author_id !== $user->id
            && !$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => $item->load('author')]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:content_items,slug',
            'body' => 'required|string',
            'status' => 'sometimes|string|in:draft,published,archived',
        ]);

        $user = $request->user();

        // Only admin/compliance_reviewer can publish directly
        $status = $request->status ?? 'draft';
        if ($status === 'published' && !$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Only administrators can publish content directly.'], 403);
        }

        $item = ContentItem::create([
            'title' => $request->title,
            'slug' => $request->slug,
            'body' => $request->body,
            'status' => $status,
            'author_id' => $user->id,
            'published_at' => $status === 'published' ? now() : null,
        ]);

        return response()->json([
            'message' => 'Content item created.',
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = ContentItem::findOrFail($id);
        $user = $request->user();

        // Only author or admin can update
        if ($item->author_id !== $user->id && !$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'body' => 'sometimes|string',
            'slug' => 'sometimes|string|max:255|unique:content_items,slug,' . $item->id,
        ]);

        $item->update($request->only(['title', 'body', 'slug']));

        return response()->json([
            'message' => 'Content item updated.',
            'data' => $item->fresh(),
        ]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $item = ContentItem::findOrFail($id);
        $user = $request->user();

        if (!$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Only administrators can publish content.'], 403);
        }

        if ($item->status === 'published') {
            return response()->json(['message' => 'Content is already published.'], 422);
        }

        $item->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return response()->json([
            'message' => 'Content item published.',
            'data' => $item->fresh(),
        ]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $item = ContentItem::findOrFail($id);
        $user = $request->user();

        if ($item->author_id !== $user->id && !$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $item->update(['status' => 'archived']);

        return response()->json([
            'message' => 'Content item archived.',
            'data' => $item->fresh(),
        ]);
    }
}
