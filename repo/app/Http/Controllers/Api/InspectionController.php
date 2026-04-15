<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\Employer;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InspectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Inspection::with(['job', 'inspector', 'employer']);

        // Inspectors only see their own inspections
        if ($user->role === 'inspector') {
            $query->where('inspector_id', $user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('job_id')) {
            $query->where('job_id', $request->job_id);
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function show(int $id): JsonResponse
    {
        $inspection = Inspection::with(['job', 'inspector', 'employer'])->findOrFail($id);
        return response()->json(['data' => $inspection]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAnyRole(['system_admin', 'inspector'])) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        $request->validate([
            'job_id' => 'required|exists:jobs,id',
            'scheduled_at' => 'required|date|after:now',
            'notes' => 'nullable|string|max:2000',
        ]);

        $job = Job::findOrFail($request->job_id);

        $inspection = Inspection::create([
            'job_id' => $job->id,
            'inspector_id' => $user->id,
            'employer_id' => $job->employer_id,
            'status' => 'scheduled',
            'scheduled_at' => $request->scheduled_at,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => 'Inspection scheduled.',
            'data' => $inspection,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $inspection = Inspection::findOrFail($id);
        $user = $request->user();

        $request->validate([
            'status' => 'sometimes|string|in:scheduled,in_progress,completed,cancelled,pending_sync',
            'notes' => 'nullable|string|max:2000',
            'findings' => 'nullable|array',
            'is_offline' => 'sometimes|boolean',
        ]);

        $validated = $request->only(['status', 'notes', 'findings', 'is_offline']);

        if (isset($validated['status'])) {
            if ($validated['status'] === 'in_progress') {
                $validated['started_at'] = now();
            }
            if ($validated['status'] === 'completed') {
                $validated['completed_at'] = now();
            }
        }

        $inspection->update($validated);

        return response()->json([
            'message' => 'Inspection updated.',
            'data' => $inspection->fresh(),
        ]);
    }

    /**
     * Get inspections assigned to the current user for offline caching.
     */
    public function assigned(Request $request): JsonResponse
    {
        $user = $request->user();

        $inspections = Inspection::with(['job', 'employer'])
            ->where('inspector_id', $user->id)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->get();

        return response()->json(['data' => $inspections]);
    }
}
