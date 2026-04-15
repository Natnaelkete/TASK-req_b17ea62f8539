<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Objection;
use App\Models\ObjectionFile;
use App\Models\ResultVersion;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ObjectionController extends Controller
{
    public function store(Request $request, int $resultVersionId): JsonResponse
    {
        $rv = ResultVersion::findOrFail($resultVersionId);

        if ($rv->status !== 'public') {
            return response()->json(['message' => 'Objections can only be filed against public results.'], 422);
        }

        // Check 7-day window
        if ($rv->published_at && $rv->published_at->diffInDays(now()) > 7) {
            return response()->json(['message' => 'Objection window has closed (7 days from publication).'], 422);
        }

        $request->validate([
            'reason' => 'required|string|max:2000',
            'files' => 'nullable|array|max:5',
            'files.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($request, $rv, $user) {
            $objection = Objection::create([
                'result_version_id' => $rv->id,
                'filed_by' => $user->id,
                'reason' => $request->reason,
                'status' => 'intake',
            ]);

            // Handle file uploads
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $path = $file->store("objections/{$objection->id}", 'local');
                    ObjectionFile::create([
                        'objection_id' => $objection->id,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            // Create linked ticket
            Ticket::create([
                'objection_id' => $objection->id,
                'status' => 'intake',
            ]);

            $objection->logAudit('create', $user->id, $user->role, 'Objection filed.');

            return response()->json([
                'message' => 'Objection filed successfully.',
                'data' => $objection->load(['files', 'ticket']),
            ], 201);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $objection = Objection::with('ticket')->findOrFail($id);
        $user = $request->user();

        if (!$user->hasAnyRole(['system_admin', 'compliance_reviewer'])) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        $request->validate([
            'status' => 'sometimes|string|in:intake,review,adjudication,resolved,dismissed',
            'resolution_notes' => 'nullable|string|max:2000',
        ]);

        $priorValues = ['status' => $objection->status];

        if ($request->has('status')) {
            $validTransitions = [
                'intake' => ['review'],
                'review' => ['adjudication'],
                'adjudication' => ['resolved', 'dismissed'],
            ];

            $currentStatus = $objection->status;
            $newStatus = $request->status;

            if (!isset($validTransitions[$currentStatus]) || !in_array($newStatus, $validTransitions[$currentStatus])) {
                return response()->json([
                    'message' => "Invalid transition from '{$currentStatus}' to '{$newStatus}'.",
                ], 422);
            }

            $objection->update(['status' => $newStatus]);

            // Update linked ticket status
            if ($objection->ticket) {
                $ticketStatus = in_array($newStatus, ['resolved', 'dismissed']) ? 'closed' : $newStatus;
                $objection->ticket->update(['status' => $ticketStatus]);
            }

            // If adjudicated (resolved/dismissed), sync back to result version
            if (in_array($newStatus, ['resolved', 'dismissed'])) {
                $objection->update(['resolution_notes' => $request->resolution_notes]);
                if ($objection->ticket) {
                    $objection->ticket->update([
                        'adjudication_summary' => $request->resolution_notes,
                    ]);
                }
            }
        }

        $objection->logAudit(
            $request->status ?? 'update',
            $user->id,
            $user->role,
            $request->resolution_notes ?? 'Status updated.',
            $priorValues,
            ['status' => $objection->status],
        );

        return response()->json([
            'message' => 'Objection updated.',
            'data' => $objection->fresh()->load(['files', 'ticket']),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $objection = Objection::with(['files', 'ticket', 'filer', 'resultVersion'])->findOrFail($id);

        return response()->json(['data' => $objection]);
    }
}
