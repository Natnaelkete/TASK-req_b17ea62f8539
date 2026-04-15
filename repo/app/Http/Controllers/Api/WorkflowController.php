<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function storeDefinition(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:workflow_definitions,slug',
            'nodes' => 'required|array|min:1',
            'approval_mode' => 'sometimes|string|in:all_approve,any_approve',
            'timeout_hours' => 'sometimes|integer|min:1',
        ]);

        $user = $request->user();

        $latestVersion = WorkflowDefinition::where('slug', $request->slug)->max('version') ?? 0;

        $def = WorkflowDefinition::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'version' => $latestVersion + 1,
            'nodes' => $request->nodes,
            'approval_mode' => $request->approval_mode ?? 'all_approve',
            'timeout_hours' => $request->timeout_hours ?? 48,
            'escalation_role_user_id' => $request->escalation_role_user_id,
            'active' => true,
        ]);

        return response()->json([
            'message' => 'Workflow definition created.',
            'data' => $def,
        ], 201);
    }

    public function indexDefinitions(): JsonResponse
    {
        $defs = WorkflowDefinition::where('active', true)->orderByDesc('created_at')->get();
        return response()->json(['data' => $defs]);
    }

    public function createInstance(Request $request): JsonResponse
    {
        $request->validate([
            'workflow_definition_id' => 'required|exists:workflow_definitions,id',
            'entity_type' => 'required|string|max:100',
            'entity_id' => 'required|integer',
        ]);

        $user = $request->user();

        $instance = WorkflowInstance::create([
            'workflow_definition_id' => $request->workflow_definition_id,
            'entity_type' => $request->entity_type,
            'entity_id' => $request->entity_id,
            'current_node' => 'start',
            'status' => 'pending',
            'initiated_by' => $user->id,
            'started_at' => now(),
        ]);

        $instance->logAudit('create', $user->id, $user->role, 'Workflow instance created.');

        return response()->json([
            'message' => 'Workflow instance created.',
            'data' => $instance->load('definition'),
        ], 201);
    }

    public function advanceInstance(Request $request, int $id): JsonResponse
    {
        $instance = WorkflowInstance::with('definition')->findOrFail($id);
        $user = $request->user();

        $request->validate([
            'action' => 'required|string|in:approve,reject,escalate,reassign',
            'reason' => 'required|string|max:1000',
            'assign_to' => 'sometimes|exists:users,id',
        ]);

        $priorValues = [
            'current_node' => $instance->current_node,
            'status' => $instance->status,
        ];

        $action = $request->action;

        if ($action === 'approve') {
            $instance->update([
                'status' => 'completed',
                'current_node' => 'end',
                'completed_at' => now(),
            ]);
        } elseif ($action === 'reject') {
            $instance->update([
                'status' => 'cancelled',
                'current_node' => 'end',
                'completed_at' => now(),
            ]);
        } elseif ($action === 'escalate') {
            $instance->update([
                'status' => 'escalated',
                'escalated_at' => now(),
                'escalation_note' => $request->reason,
            ]);
        } elseif ($action === 'reassign') {
            $instance->update([
                'assigned_to' => $request->assign_to,
            ]);
        }

        $instance->logAudit(
            $action,
            $user->id,
            $user->role,
            $request->reason,
            $priorValues,
            ['status' => $instance->status, 'current_node' => $instance->current_node],
        );

        return response()->json([
            'message' => "Workflow action '{$action}' completed.",
            'data' => $instance->fresh()->load('definition'),
        ]);
    }

    public function showInstance(int $id): JsonResponse
    {
        $instance = WorkflowInstance::with(['definition', 'initiator', 'assignee'])->findOrFail($id);

        $audits = $instance->getAuditTrail();

        return response()->json([
            'data' => $instance,
            'audit_trail' => $audits,
        ]);
    }
}
