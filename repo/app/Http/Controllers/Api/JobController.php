<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJobRequest;
use App\Http\Requests\UpdateJobRequest;
use App\Models\Employer;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Job::query()->with(['employer', 'category']);

        if ($request->has('employer_id')) {
            $query->where('employer_id', $request->employer_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('education_level')) {
            $query->where('education_level', $request->education_level);
        }
        if ($request->has('work_state')) {
            $query->where('work_state', $request->work_state);
        }
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->has('salary_min')) {
            $query->where('salary_min', '>=', (int) $request->salary_min);
        }

        $jobs = $query->paginate($request->get('per_page', 15));

        $role = $request->user()->role;
        $jobs->getCollection()->transform(fn ($job) => $job->maskForRole($role));

        return response()->json($jobs);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $job = Job::with(['employer', 'category'])->findOrFail($id);

        return response()->json([
            'data' => $job->maskForRole($request->user()->role),
        ]);
    }

    public function store(StoreJobRequest $request, int $employerId): JsonResponse
    {
        $employer = Employer::findOrFail($employerId);

        // Only employer owner or admin can create jobs
        $user = $request->user();
        if ($employer->user_id !== $user->id && !$user->hasAnyRole(['system_admin', 'employer_manager'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Rate limiting: max 20 jobs per employer per 24 hours
        $recentCount = Job::where('employer_id', $employerId)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        if ($recentCount >= 20) {
            return response()->json([
                'message' => 'Rate limit exceeded. Maximum 20 jobs per employer per 24 hours.',
            ], 429);
        }

        $validated = $request->validated();
        $normalizedTitle = strtolower(trim($validated['title']));

        // Duplicate detection
        $duplicate = Job::where('employer_id', $employerId)
            ->where('normalized_title', $normalizedTitle)
            ->where('work_zip', $validated['work_zip'])
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate job detected. A job with the same title and ZIP code was posted within the last 30 days.',
            ], 422);
        }

        $job = Job::create([
            'employer_id' => $employerId,
            'title' => $validated['title'],
            'normalized_title' => $normalizedTitle,
            'description' => $validated['description'],
            'category_id' => $validated['category_id'] ?? null,
            'salary_min' => $validated['salary_min'],
            'salary_max' => $validated['salary_max'],
            'education_level' => $validated['education_level'],
            'work_street' => $validated['work_street'] ?? null,
            'work_city' => $validated['work_city'],
            'work_state' => $validated['work_state'],
            'work_zip' => $validated['work_zip'],
            'status' => 'draft',
        ]);

        return response()->json([
            'message' => 'Job created successfully.',
            'data' => $job->maskForRole($user->role),
        ], 201);
    }

    public function update(UpdateJobRequest $request, int $id): JsonResponse
    {
        $job = Job::findOrFail($id);
        $user = $request->user();

        // Only employer owner or admin can edit
        $employer = $job->employer;
        if ($employer->user_id !== $user->id && !$user->hasAnyRole(['system_admin', 'employer_manager'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validated();

        // Update normalized_title if title changes
        if (isset($validated['title'])) {
            $validated['normalized_title'] = strtolower(trim($validated['title']));
        }

        $job->update($validated);

        return response()->json([
            'message' => 'Job updated successfully.',
            'data' => $job->fresh()->maskForRole($user->role),
        ]);
    }
}
