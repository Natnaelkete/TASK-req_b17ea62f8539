<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployerRequest;
use App\Http\Requests\UpdateEmployerRequest;
use App\Http\Requests\ReviewEmployerRequest;
use App\Models\Employer;
use App\Models\EmployerQualification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Employer::query()->with('qualifications');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('city')) {
            $query->where('city', $request->city);
        }
        if ($request->has('state')) {
            $query->where('state', $request->state);
        }

        $employers = $query->paginate($request->get('per_page', 15));

        $role = $request->user()->role;
        $employers->getCollection()->transform(fn ($employer) => $employer->maskForRole($role));

        return response()->json($employers);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employer = Employer::with('qualifications')->findOrFail($id);
        $role = $request->user()->role;

        return response()->json([
            'data' => $employer->maskForRole($role),
        ]);
    }

    public function store(StoreEmployerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated, $request) {
            $employer = Employer::create([
                'user_id' => $request->user()->id,
                'company_name' => $validated['company_name'],
                'trade_name' => $validated['trade_name'] ?? null,
                'ein' => $validated['ein'] ?? null,
                'contact_first_name' => $validated['contact_first_name'],
                'contact_last_name' => $validated['contact_last_name'],
                'contact_phone' => $validated['contact_phone'] ?? null,
                'contact_email' => $validated['contact_email'],
                'street' => $validated['street'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'zip' => $validated['zip'] ?? null,
                'status' => 'pending',
            ]);

            // Create qualifications
            if (!empty($validated['qualifications'])) {
                foreach ($validated['qualifications'] as $qual) {
                    EmployerQualification::create([
                        'employer_id' => $employer->id,
                        'qualification_type' => $qual['qualification_type'],
                        'license_number' => $qual['license_number'] ?? null,
                        'issued_at' => $qual['issued_at'] ?? null,
                        'expires_at' => $qual['expires_at'] ?? null,
                    ]);
                }
            }

            // Handle document uploads
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $file) {
                    $path = $file->store("employers/{$employer->id}/documents", 'local');
                    EmployerQualification::create([
                        'employer_id' => $employer->id,
                        'qualification_type' => 'document',
                        'document_path' => $path,
                        'document_original_name' => $file->getClientOriginalName(),
                    ]);
                }
            }

            $employer->logAudit(
                'create',
                $request->user()->id,
                $request->user()->role,
                'Employer registration submitted.',
                null,
                ['status' => 'pending']
            );

            return response()->json([
                'message' => 'Employer created successfully.',
                'data' => $employer->load('qualifications')->maskForRole($request->user()->role),
            ], 201);
        });
    }

    public function update(UpdateEmployerRequest $request, int $id): JsonResponse
    {
        $employer = Employer::findOrFail($id);

        // Only the owner or admin/reviewer can update
        $user = $request->user();
        if ($employer->user_id !== $user->id && !$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // If employer is approved, only admin/reviewer can modify restricted fields
        $validated = $request->validated();
        if ($employer->status === 'approved' && !$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            unset($validated['company_name'], $validated['ein']);
        }

        $priorValues = $employer->only(array_keys($validated));
        $employer->update($validated);

        $employer->logAudit(
            'update',
            $user->id,
            $user->role,
            'Employer record updated.',
            $priorValues,
            $validated,
        );

        return response()->json([
            'message' => 'Employer updated successfully.',
            'data' => $employer->fresh()->maskForRole($user->role),
        ]);
    }

    public function review(ReviewEmployerRequest $request, int $id): JsonResponse
    {
        $employer = Employer::findOrFail($id);

        if ($employer->status !== 'pending') {
            return response()->json(['message' => 'Employer is not in pending status.'], 422);
        }

        $user = $request->user();
        $action = $request->validated()['action'];
        $priorValues = ['status' => $employer->status];

        if ($action === 'approve') {
            $employer->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
            ]);
        } else {
            $employer->update([
                'status' => 'rejected',
                'rejection_reason_code' => $request->validated()['reason_code'],
                'rejection_notes' => $request->validated()['notes'] ?? null,
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
            ]);
        }

        $employer->logAudit(
            $action,
            $user->id,
            $user->role,
            $action === 'reject' ? "Rejected: {$request->validated()['reason_code']}" : 'Approved.',
            $priorValues,
            ['status' => $employer->status],
        );

        return response()->json([
            'message' => "Employer {$action}d successfully.",
            'data' => $employer->fresh()->maskForRole($user->role),
        ]);
    }
}
