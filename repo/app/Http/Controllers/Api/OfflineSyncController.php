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
    // Chunk size limits in bytes (2MB min, 5MB max)
    private const MIN_CHUNK_SIZE = 2 * 1024 * 1024; // 2MB
    private const MAX_CHUNK_SIZE = 5 * 1024 * 1024; // 5MB
    private const MAX_ATTEMPTS = 8;

    /**
     * Upload offline sync batch with idempotency key for deduplication.
     * Supports chunked uploads with size validation.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'idempotency_key' => 'required|string|max:255',
            'device_id' => 'required|string|max:255',
            'payload' => 'required|array',
            'total_chunks' => 'sometimes|integer|min:1|max:100',
            'chunk_index' => 'sometimes|integer|min:0',
            'chunk_checksum' => 'sometimes|string|max:64',
        ]);

        // Enforce chunk size limits (2-5MB) on raw request payload
        $payloadSize = strlen(json_encode($request->payload));
        $totalChunks = $request->total_chunks ?? 1;

        if ($totalChunks > 1) {
            if ($payloadSize < self::MIN_CHUNK_SIZE) {
                return response()->json([
                    'message' => 'Chunk size too small. Minimum chunk size is 2MB.',
                    'min_bytes' => self::MIN_CHUNK_SIZE,
                    'actual_bytes' => $payloadSize,
                ], 422);
            }
            if ($payloadSize > self::MAX_CHUNK_SIZE) {
                return response()->json([
                    'message' => 'Chunk size too large. Maximum chunk size is 5MB.',
                    'max_bytes' => self::MAX_CHUNK_SIZE,
                    'actual_bytes' => $payloadSize,
                ], 422);
            }
        }

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

            // For multi-chunk uploads, handle chunk assembly
            if ($totalChunks > 1 && $request->has('chunk_index')) {
                return $this->handleChunkUpload($existing, $request);
            }

            // Resume processing for failed/quarantined batches
            if (in_array($existing->status, ['failed', 'quarantined'])) {
                $existing->update([
                    'status' => 'queued',
                    'attempts' => 0,
                    'last_error' => null,
                    'payload' => $request->payload,
                    'next_retry_at' => null,
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
            'total_chunks' => $totalChunks,
            'received_chunks' => 1,
            'chunk_checksums' => $request->chunk_checksum
                ? [0 => $request->chunk_checksum]
                : null,
        ]);

        // For single-chunk uploads, process immediately
        if ($totalChunks === 1) {
            $this->processBatch($batch);
        }

        return response()->json([
            'message' => $totalChunks > 1
                ? 'Chunk received. Awaiting remaining chunks.'
                : 'Sync batch received and processing.',
            'data' => $batch->fresh(),
        ], 201);
    }

    /**
     * Handle individual chunk uploads for resumable assembly.
     */
    private function handleChunkUpload(OfflineSyncBatch $batch, Request $request): JsonResponse
    {
        $chunkIndex = $request->chunk_index;

        // Track chunk checksums for integrity
        $checksums = $batch->chunk_checksums ?? [];
        if ($request->chunk_checksum) {
            $checksums[$chunkIndex] = $request->chunk_checksum;
        }

        // Merge chunk payload into assembled payload
        $assembled = $batch->assembled_payload ?? [];
        $assembled[$chunkIndex] = $request->payload;

        $receivedChunks = count($assembled);

        $batch->update([
            'assembled_payload' => $assembled,
            'chunk_checksums' => $checksums,
            'received_chunks' => $receivedChunks,
        ]);

        // Check if all chunks have been received
        if ($receivedChunks >= $batch->total_chunks) {
            // Assemble the final payload from ordered chunks
            ksort($assembled);
            $finalPayload = [];
            foreach ($assembled as $chunk) {
                $finalPayload = array_merge($finalPayload, $chunk);
            }

            $batch->update([
                'payload' => $finalPayload,
                'status' => 'queued',
            ]);

            $this->processBatch($batch);

            return response()->json([
                'message' => 'All chunks received. Batch assembled and processing.',
                'data' => $batch->fresh(),
            ]);
        }

        return response()->json([
            'message' => "Chunk {$chunkIndex} received. {$receivedChunks}/{$batch->total_chunks} chunks uploaded.",
            'data' => $batch->fresh(),
        ]);
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
     * Uses exponential backoff on failure.
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

                    // Additional conflict check using updated_at timestamp
                    if ($clientUpdatedAt && $inspection->updated_at
                        && $clientUpdatedAt < $inspection->updated_at->toIso8601String()) {
                        // Client data is stale - preserve server adjudication fields
                        if (isset($inspection->findings['adjudication'])) {
                            $payload['data']['adjudication'] = $inspection->findings['adjudication'];
                        }
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

            $batch->update([
                'status' => 'succeeded',
                'next_retry_at' => null,
            ]);
        } catch (\Exception $e) {
            $attempts = $batch->attempts + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                $batch->update([
                    'status' => 'quarantined',
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                    'next_retry_at' => null,
                ]);
            } else {
                // Exponential backoff: 2^attempts seconds (2s, 4s, 8s, 16s, 32s, 64s, 128s)
                $backoffSeconds = pow(2, $attempts);
                $batch->update([
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                    'next_retry_at' => now()->addSeconds($backoffSeconds),
                ]);
            }
        }
    }

    /**
     * Retry failed batches that are past their backoff window.
     * This method should be called by a scheduled command.
     */
    public function retryFailedBatches(): JsonResponse
    {
        $retryable = OfflineSyncBatch::where('status', 'failed')
            ->where('next_retry_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->get();

        $retried = 0;
        foreach ($retryable as $batch) {
            $this->processBatch($batch);
            $retried++;
        }

        return response()->json([
            'message' => "{$retried} batch(es) retried.",
        ]);
    }
}
