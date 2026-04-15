<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployerController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\ResultVersionController;
use App\Http\Controllers\Api\ObjectionController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\OfflineSyncController;

// Health check - no auth required
Route::get('/health', HealthController::class);

// Auth routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Employer routes
    Route::get('/employers', [EmployerController::class, 'index']);
    Route::get('/employers/{id}', [EmployerController::class, 'show']);
    Route::post('/employers', [EmployerController::class, 'store']);
    Route::match(['put', 'patch'], '/employers/{id}', [EmployerController::class, 'update']);

    // Employer review (approve/reject) - restricted to admin/compliance_reviewer
    Route::post('/employers/{id}/review', [EmployerController::class, 'review'])
        ->middleware('role:system_admin,compliance_reviewer');

    // Job routes
    Route::post('/employers/{employerId}/jobs', [JobController::class, 'store']);
    Route::get('/jobs', [JobController::class, 'index']);
    Route::get('/jobs/{id}', [JobController::class, 'show']);
    Route::match(['put', 'patch'], '/jobs/{id}', [JobController::class, 'update']);

    // Result version routes
    Route::post('/jobs/{id}/result-versions', [ResultVersionController::class, 'store']);
    Route::put('/result-versions/{id}/status', [ResultVersionController::class, 'updateStatus']);
    Route::get('/result-versions/{id}', [ResultVersionController::class, 'show']);
    Route::get('/result-versions/{id}/history', [ResultVersionController::class, 'history']);

    // Objection routes
    Route::post('/result-versions/{id}/objections', [ObjectionController::class, 'store']);
    Route::match(['put', 'patch'], '/objections/{id}', [ObjectionController::class, 'update']);
    Route::get('/objections/{id}', [ObjectionController::class, 'show']);

    // Ticket routes
    Route::get('/tickets/{id}', [TicketController::class, 'show']);

    // Message routes
    Route::post('/messages', [MessageController::class, 'store']);
    Route::get('/messages', [MessageController::class, 'index']);
    Route::put('/messages/{id}/read', [MessageController::class, 'markRead']);
    Route::get('/messages/stats', [MessageController::class, 'stats']);

    // Inspection routes
    Route::get('/inspections', [InspectionController::class, 'index']);
    Route::get('/inspections/{id}', [InspectionController::class, 'show']);
    Route::post('/inspections', [InspectionController::class, 'store']);
    Route::match(['put', 'patch'], '/inspections/{id}', [InspectionController::class, 'update']);
    Route::get('/inspections/assigned/me', [InspectionController::class, 'assigned']);

    // Offline sync routes
    Route::post('/offline-sync/upload', [OfflineSyncController::class, 'upload']);
    Route::get('/offline-sync/status/{idempotencyKey}', [OfflineSyncController::class, 'status']);

    // Workflow routes
    Route::middleware('role:system_admin,compliance_reviewer')->group(function () {
        Route::post('/workflow-definitions', [WorkflowController::class, 'storeDefinition']);
        Route::get('/workflow-definitions', [WorkflowController::class, 'indexDefinitions']);
        Route::post('/workflow-instances', [WorkflowController::class, 'createInstance']);
        Route::put('/workflow-instances/{id}/advance', [WorkflowController::class, 'advanceInstance']);
        Route::get('/workflow-instances/{id}', [WorkflowController::class, 'showInstance']);
    });
});
