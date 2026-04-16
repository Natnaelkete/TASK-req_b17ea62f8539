<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $preferences = UserNotificationPreference::where('user_id', $request->user()->id)->get();

        return response()->json(['data' => $preferences]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'notification_type' => 'required|string|max:255',
            'enabled' => 'required|boolean',
        ]);

        $user = $request->user();

        $preference = UserNotificationPreference::updateOrCreate(
            [
                'user_id' => $user->id,
                'notification_type' => $request->notification_type,
            ],
            [
                'enabled' => $request->enabled,
            ]
        );

        return response()->json([
            'message' => 'Notification preference saved.',
            'data' => $preference,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $preference = UserNotificationPreference::findOrFail($id);
        $user = $request->user();

        // Users can only update their own preferences
        if ($preference->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $preference->update(['enabled' => $request->enabled]);

        return response()->json([
            'message' => 'Notification preference updated.',
            'data' => $preference->fresh(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $preference = UserNotificationPreference::findOrFail($id);
        $user = $request->user();

        if ($preference->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $preference->delete();

        return response()->json(['message' => 'Notification preference removed.']);
    }
}
