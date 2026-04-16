<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\ResultVersion;
use App\Models\MaskingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResultVersionController extends Controller
{
    public function store(Request $request, int $jobId): JsonResponse
    {
        $job = Job::findOrFail($jobId);

        $request->validate([
            'data' => 'required|array',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        if (!$user->hasAnyRole(['system_admin', 'compliance_reviewer', 'inspector'])) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        $latestVersion = ResultVersion::where('job_id', $jobId)->max('version_number') ?? 0;

        $rv = ResultVersion::create([
            'job_id' => $jobId,
            'version_number' => $latestVersion + 1,
            'status' => 'draft',
            'data' => $request->data,
            'notes' => $request->notes,
            'created_by' => $user->id,
        ]);

        $rv->logAudit('create_draft', $user->id, $user->role, 'Draft created.', null, ['status' => 'draft']);

        return response()->json([
            'message' => 'Result version draft created.',
            'data' => $rv,
        ], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $rv = ResultVersion::findOrFail($id);
        $user = $request->user();

        $request->validate([
            'status' => 'required|string|in:internal,public',
            'reason' => 'nullable|string|max:1000',
        ]);

        $newStatus = $request->status;
        $transitions = [
            'draft' => 'internal',
            'internal' => 'public',
        ];

        // Validate transition
        if (!isset($transitions[$rv->status]) || $transitions[$rv->status] !== $newStatus) {
            return response()->json([
                'message' => "Invalid transition from '{$rv->status}' to '{$newStatus}'.",
            ], 422);
        }

        // Only admin/compliance_reviewer can push to internal/public
        if (!$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        $priorValues = ['status' => $rv->status];

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'public') {
            // Create immutable snapshot
            $updateData['snapshot'] = $this->createSnapshot($rv);
            $updateData['published_at'] = now();
        }

        $rv->update($updateData);

        $actionName = $newStatus === 'internal' ? 'publish_internal' : 'publish_public';
        $rv->logAudit(
            $actionName,
            $user->id,
            $user->role,
            $request->reason ?? "Transitioned to {$newStatus}.",
            $priorValues,
            ['status' => $newStatus],
        );

        return response()->json([
            'message' => "Result version moved to {$newStatus}.",
            'data' => $rv->fresh(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $rv = ResultVersion::with('job')->findOrFail($id);
        $user = $request->user();

        // Access control: non-public results restricted to creator, admin, compliance_reviewer, or assigned inspector
        if ($rv->status !== 'public') {
            if (!$this->canAccessResultVersion($rv, $user)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $data = $rv->toArray();

        // Apply field masking to result data based on role
        if ($rv->status === 'public' && $rv->snapshot) {
            $data['masked_data'] = $this->applyMaskingToData($rv->snapshot, $user->role);
        } else {
            $data['masked_data'] = $this->applyMaskingToData($rv->data ?? [], $user->role);
        }

        return response()->json(['data' => $data]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $rv = ResultVersion::with('job')->findOrFail($id);
        $user = $request->user();

        // Access control: only creator, admin, compliance_reviewer, or assigned inspector
        if (!$this->canAccessResultVersion($rv, $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $allVersions = ResultVersion::where('job_id', $rv->job_id)
            ->orderBy('version_number', 'desc')
            ->get();

        $audits = $rv->getAuditTrail();

        return response()->json([
            'versions' => $allVersions,
            'audit_trail' => $audits,
        ]);
    }

    /**
     * Check if a user can access a result version based on ownership/role.
     */
    private function canAccessResultVersion(ResultVersion $rv, $user): bool
    {
        // Admins and compliance reviewers can access all result versions
        if ($user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return true;
        }

        // Creator can access their own result versions
        if ($rv->created_by === $user->id) {
            return true;
        }

        // Inspector assigned to the related job's inspections can access
        if ($user->hasRole('inspector') && $rv->job) {
            return $rv->job->inspections()
                ->where('inspector_id', $user->id)
                ->exists();
        }

        return false;
    }

    private function createSnapshot(ResultVersion $rv): array
    {
        return [
            'version_number' => $rv->version_number,
            'data' => $rv->data,
            'notes' => $rv->notes,
            'created_by' => $rv->created_by,
            'snapshotted_at' => now()->toIso8601String(),
        ];
    }

    private function applyMaskingToData(array $data, string $role): array
    {
        $rules = MaskingRule::where('active', true)->get()->keyBy('field_name');
        $masked = [];

        foreach ($data as $key => $value) {
            if ($rules->has($key) && !in_array($role, $rules[$key]->visible_roles)) {
                $maskType = $rules[$key]->mask_type;
                $masked[$key] = match ($maskType) {
                    'first_initial' => is_string($value) ? mb_substr($value, 0, 1) . '.' : $value,
                    'last_four' => is_string($value) ? str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4) : $value,
                    'redact' => '***REDACTED***',
                    default => $value,
                };
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}
