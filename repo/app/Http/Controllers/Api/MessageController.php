<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Message::where('recipient_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('unread') && $request->unread === 'true') {
            $query->whereNull('read_at');
        }

        $messages = $query->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Only system-generated messages are allowed.'], 403);
        }

        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'type' => 'required|string|max:100',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $message = Message::create([
            'recipient_id' => $request->recipient_id,
            'type' => $request->type,
            'subject' => $request->subject,
            'body' => $request->body,
            'expires_at' => now()->addDays(365),
        ]);

        return response()->json([
            'message' => 'Message created.',
            'data' => $message,
        ], 201);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $message = Message::findOrFail($id);

        if ($message->recipient_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($message->read_at !== null) {
            return response()->json(['message' => 'Already read.']);
        }

        $message->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Marked as read.',
            'data' => $message,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $total = Message::where('recipient_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })->count();

        $unread = Message::where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })->count();

        return response()->json([
            'total' => $total,
            'unread' => $unread,
            'read' => $total - $unread,
        ]);
    }
}
