<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbStatus = 'ok';
        $dbError = null;

        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
        } catch (\Exception $e) {
            $dbStatus = 'error';
            $dbError = $e->getMessage();
        }

        // Check disk space
        $diskFreeBytes = disk_free_space(storage_path());
        $diskTotalBytes = disk_total_space(storage_path());
        $diskFreePercent = $diskTotalBytes > 0
            ? round(($diskFreeBytes / $diskTotalBytes) * 100, 2)
            : 0;
        $diskAlert = $diskFreePercent < 15;

        if ($diskAlert) {
            \Log::warning('Disk free space below 15%', [
                'free_percent' => $diskFreePercent,
                'free_bytes' => $diskFreeBytes,
            ]);
        }

        $status = $dbStatus === 'ok' ? 'healthy' : 'unhealthy';
        $httpCode = $dbStatus === 'ok' ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => [
                    'status' => $dbStatus,
                    'error' => $dbError,
                ],
                'disk' => [
                    'free_percent' => $diskFreePercent,
                    'alert' => $diskAlert,
                ],
            ],
        ], $httpCode);
    }
}
