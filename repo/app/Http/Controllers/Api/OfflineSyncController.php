<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\OfflineSyncBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineSyncController extends Controller
{
    /**
     * Upload offline sync batch with idempotency key for deduplication.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'idempotency_key' => 'required|string|max:255',
            'device_id' => 'required|string|max:255',
            'payload' => 'required|array',
            'total_chunks' => 'sometimes|integer|min:1|max:100',
            'chunk_index' => 'sometimes|integer|min:0',
        ]);

        $user = $request->user();

        // Idempotency check: if batch with this key already exists, return it
        $existing = OfflineSyncBatch::where('idempotency_key', $request->idempotency_key)->first();
        if ($existing) {
            if ($existing->status === 'succeeded') {
                return response()->json([
                    'message' => 'Batch already processed.',
                    'data' => $existing,
                ]);
            }
            // Resume processing for failed/quarantined batches
            if (in_array($existing->status, ['failed', 'quarantined'])) {
                $existing->update([
                    'status' => 'queued',
                    'attempts' => 0,
                    'last_error' => null,
                    'payload' => $request->payload,
                ]);
                return response()->json([
                    'message' => 'Batch requeued for retry.',
                    'data' => $existing->fresh(),
                ]);
            }
            return response()->json([
                'message' => 'Batch already in progress.',
                'data' => $existing,
            ]);
        }

        $batch = OfflineSyncBatch::create([
            'user_id' => $user->id,
            'idempotency_key' => $request->idempotency_key,
            'device_id' => $request->device_id,
            'status' => 'queued',
            'payload' => $request->payload,
            'total_chunks' => $request->total_chunks ?? 1,
            'received_chunks' => 1,
        ]);

        // Process the sync batch
        $this->processBatch($batch);

        return response()->json([
            'message' => 'Sync batch received and processing.',
            'data' => $batch->fresh(),
        ], 201);
    }

    /**
     * Get sync batch status.
     */
    public function status(Request $request, string $idempotencyKey): JsonResponse
    {
        $batch = OfflineSyncBatch::where('idempotency_key', $idempotencyKey)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['data' => $batch]);
    }

    /**
     * Process a sync batch - apply inspection data with conflict resolution.
     */
    private function processBatch(OfflineSyncBatch $batch): void
    {
        try {
            $batch->update(['status' => 'in_progress']);

            DB::transaction(function () use ($batch) {
                $payload = $batch->payload;

                if (isset($payload['inspection_id']) && isset($payload['data'])) {
                    $inspection = Inspection::find($payload['inspection_id']);

                    if (!$inspection) {
                        throw new \Exception("Inspection {$payload['inspection_id']} not found.");
                    }

                    // Conflict detection: version check + updated_at
                    $clientVersion = $payload['version'] ?? null;
                    $clientUpdatedAt = $payload['updated_at'] ?? null;

                    if ($clientVersion !== null && $clientVersion < $inspection->version) {
                        // Server has newer version - favor server's adjudication fields
                        // but still merge non-conflicting fields
                        $mergedData = $payload['data'];
                        if (isset($inspection->findings['adjudication'])) {
                            $mergedData['adjudication'] = $inspection->findings['adjudication'];
                        }
                        $payload['data'] = $mergedData;
                    }

                    // Apply the sync data
                    $inspection->update([
                        'findings' => array_merge($inspection->findings ?? [], $payload['data']),
                        'notes' => $payload['notes'] ?? $inspection->notes,
                        'status' => $payload['status'] ?? $inspection->status,
                        'version' => $inspection->version + 1,
                    ]);
                }
            });

            $batch->update(['status' => 'succeeded']);
        } catch (\Exception $e) {
            $attempts = $batch->attempts + 1;
            $maxAttempts = 8;

            if ($attempts >= $maxAttempts) {
                $batch->update([
                    'status' => 'quarantined',
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                ]);
            } else {
                $batch->update([
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                ]);
            }
        }
    }
}
