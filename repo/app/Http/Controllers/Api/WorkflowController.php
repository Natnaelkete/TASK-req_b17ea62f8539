<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    /**
     * Create a new workflow definition. Supports slug+version uniqueness:
     * the same slug can have multiple versions.
     */
    public function storeDefinition(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'nodes' => 'required|array|min:1',
            'nodes.*.id' => 'required|string',
            'nodes.*.type' => 'required|string|in:start,approval,decision,parallel,end',
            'approval_mode' => 'sometimes|string|in:all_approve,any_approve',
            'timeout_hours' => 'sometimes|integer|min:1',
        ]);

        $user = $request->user();

        $latestVersion = WorkflowDefinition::where('slug', $request->slug)->max('version') ?? 0;
        $newVersion = $latestVersion + 1;

        // Deactivate previous versions of this slug
        WorkflowDefinition::where('slug', $request->slug)
            ->where('active', true)
            ->update(['active' => false]);

        $def = WorkflowDefinition::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'version' => $newVersion,
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

    /**
     * Create a workflow instance, starting at the first node.
     */
    public function createInstance(Request $request): JsonResponse
    {
        $request->validate([
            'workflow_definition_id' => 'required|exists:workflow_definitions,id',
            'entity_type' => 'required|string|max:100',
            'entity_id' => 'required|integer',
        ]);

        $user = $request->user();
        $definition = WorkflowDefinition::findOrFail($request->workflow_definition_id);
        $nodes = $definition->nodes;

        // Find the start node
        $startNode = collect($nodes)->firstWhere('type', 'start');
        $firstNodeId = $startNode['next'] ?? ($startNode['id'] ?? 'start');

        $instance = WorkflowInstance::create([
            'workflow_definition_id' => $request->workflow_definition_id,
            'entity_type' => $request->entity_type,
            'entity_id' => $request->entity_id,
            'current_node' => $firstNodeId,
            'status' => 'pending',
            'initiated_by' => $user->id,
            'started_at' => now(),
            'node_approvals' => [],
            'timeout_at' => now()->addHours($definition->timeout_hours),
        ]);

        $instance->logAudit('create', $user->id, $user->role, 'Workflow instance created.');

        return response()->json([
            'message' => 'Workflow instance created.',
            'data' => $instance->load('definition'),
        ], 201);
    }

    /**
     * Advance a workflow instance through its node graph.
     * Supports approve, reject, escalate, reassign actions with
     * parallel approval tracking and conditional branching.
     */
    public function advanceInstance(Request $request, int $id): JsonResponse
    {
        $instance = WorkflowInstance::with('definition')->findOrFail($id);
        $user = $request->user();

        if (in_array($instance->status, ['completed', 'cancelled'])) {
            return response()->json([
                'message' => 'Workflow instance is already finalized.',
            ], 422);
        }

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
        $definition = $instance->definition;
        $nodes = collect($definition->nodes);
        $currentNodeDef = $nodes->firstWhere('id', $instance->current_node);

        if ($action === 'escalate') {
            $instance->update([
                'status' => 'escalated',
                'escalated_at' => now(),
                'escalation_note' => $request->reason,
            ]);
        } elseif ($action === 'reassign') {
            $instance->update([
                'assigned_to' => $request->assign_to,
            ]);
        } elseif ($action === 'reject') {
            // Check if node defines a rejection path
            $nextOnReject = $currentNodeDef['next']['rejected'] ?? null;
            if ($nextOnReject) {
                $nextNode = $nodes->firstWhere('id', $nextOnReject);
                if ($nextNode && $nextNode['type'] === 'end') {
                    $instance->update([
                        'status' => 'cancelled',
                        'current_node' => $nextOnReject,
                        'completed_at' => now(),
                        'node_approvals' => [],
                    ]);
                } else {
                    $instance->update([
                        'current_node' => $nextOnReject,
                        'status' => 'in_progress',
                        'node_approvals' => [],
                        'timeout_at' => now()->addHours($definition->timeout_hours),
                    ]);
                }
            } else {
                $instance->update([
                    'status' => 'cancelled',
                    'current_node' => 'end',
                    'completed_at' => now(),
                    'node_approvals' => [],
                ]);
            }
        } elseif ($action === 'approve') {
            $this->handleApproval($instance, $user, $definition, $nodes, $currentNodeDef);
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

    /**
     * Handle approval logic with parallel approval mode support.
     */
    private function handleApproval(
        WorkflowInstance $instance,
        $user,
        WorkflowDefinition $definition,
        $nodes,
        ?array $currentNodeDef
    ): void {
        $approvalMode = $definition->approval_mode;
        $nodeApprovals = $instance->node_approvals ?? [];
        $currentNode = $instance->current_node;

        // Record this user's approval for the current node
        $nodeApprovals[$currentNode] = $nodeApprovals[$currentNode] ?? [];
        if (!in_array($user->id, $nodeApprovals[$currentNode])) {
            $nodeApprovals[$currentNode][] = $user->id;
        }

        $requiredApprovers = $currentNodeDef['required_approvers'] ?? 1;
        $approvalCount = count($nodeApprovals[$currentNode]);

        // Determine if the approval threshold is met
        $thresholdMet = match ($approvalMode) {
            'any_approve' => $approvalCount >= 1,
            'all_approve' => $approvalCount >= $requiredApprovers,
            default => $approvalCount >= $requiredApprovers,
        };

        if ($thresholdMet) {
            // Advance to next node
            $nextNodeId = $this->resolveNextNode($currentNodeDef, 'approved');
            $nextNodeDef = $nodes->firstWhere('id', $nextNodeId);

            if (!$nextNodeDef || $nextNodeDef['type'] === 'end') {
                $instance->update([
                    'status' => 'completed',
                    'current_node' => $nextNodeId ?? 'end',
                    'completed_at' => now(),
                    'node_approvals' => $nodeApprovals,
                ]);
            } elseif ($nextNodeDef['type'] === 'decision') {
                // Evaluate decision node condition and branch
                $branchTarget = $this->evaluateDecisionNode($nextNodeDef, $instance);
                $branchNodeDef = $nodes->firstWhere('id', $branchTarget);
                if ($branchNodeDef && $branchNodeDef['type'] === 'end') {
                    $instance->update([
                        'status' => 'completed',
                        'current_node' => $branchTarget,
                        'completed_at' => now(),
                        'node_approvals' => $nodeApprovals,
                    ]);
                } else {
                    $instance->update([
                        'current_node' => $branchTarget,
                        'status' => 'in_progress',
                        'node_approvals' => $nodeApprovals,
                        'timeout_at' => now()->addHours($definition->timeout_hours),
                    ]);
                }
            } elseif ($nextNodeDef['type'] === 'parallel') {
                // Parallel node: enter parallel branch tracking
                $branches = $nextNodeDef['branches'] ?? [];
                $parallelState = [];
                foreach ($branches as $branch) {
                    $parallelState[$branch] = [];
                }
                $nodeApprovals[$nextNodeId] = $parallelState;
                $instance->update([
                    'current_node' => $nextNodeId,
                    'status' => 'in_progress',
                    'node_approvals' => $nodeApprovals,
                    'timeout_at' => now()->addHours($definition->timeout_hours),
                ]);
            } else {
                $instance->update([
                    'current_node' => $nextNodeId,
                    'status' => 'in_progress',
                    'node_approvals' => $nodeApprovals,
                    'timeout_at' => now()->addHours($definition->timeout_hours),
                ]);
            }
        } else {
            // Not enough approvals yet; save state and wait
            $instance->update([
                'node_approvals' => $nodeApprovals,
                'status' => 'in_progress',
            ]);
        }
    }

    /**
     * Resolve the next node ID from a node definition.
     * Supports both simple string 'next' and conditional object 'next'.
     */
    private function resolveNextNode(?array $nodeDef, string $outcome): ?string
    {
        if (!$nodeDef || !isset($nodeDef['next'])) {
            return 'end';
        }

        $next = $nodeDef['next'];

        if (is_string($next)) {
            return $next;
        }

        if (is_array($next)) {
            return $next[$outcome] ?? $next['default'] ?? 'end';
        }

        return 'end';
    }

    /**
     * Evaluate a decision node's condition to determine the branch target.
     */
    private function evaluateDecisionNode(array $decisionNode, WorkflowInstance $instance): string
    {
        $condition = $decisionNode['condition'] ?? null;
        $branches = $decisionNode['next'] ?? [];

        if ($condition && is_array($branches)) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'eq';
            $value = $condition['value'] ?? null;

            // Evaluate condition against entity metadata or instance state
            $entityValue = $instance->node_approvals[$field] ?? null;

            $conditionMet = match ($operator) {
                'eq' => $entityValue == $value,
                'gt' => $entityValue > $value,
                'lt' => $entityValue < $value,
                'gte' => $entityValue >= $value,
                'lte' => $entityValue <= $value,
                default => false,
            };

            return $conditionMet ? ($branches['true'] ?? 'end') : ($branches['false'] ?? 'end');
        }

        // Default: follow the first branch or 'default'
        return $branches['default'] ?? 'end';
    }

    /**
     * Auto-escalate workflow instances that have exceeded their timeout.
     * This method should be called by a scheduled command.
     */
    public function processTimeouts(): JsonResponse
    {
        $timedOut = WorkflowInstance::where('status', 'in_progress')
            ->whereNotNull('timeout_at')
            ->where('timeout_at', '<=', now())
            ->with('definition')
            ->get();

        $escalatedCount = 0;

        foreach ($timedOut as $instance) {
            $escalationUserId = $instance->definition->escalation_role_user_id;

            $priorValues = [
                'current_node' => $instance->current_node,
                'status' => $instance->status,
            ];

            $instance->update([
                'status' => 'escalated',
                'escalated_at' => now(),
                'escalation_note' => 'Auto-escalated due to timeout.',
                'assigned_to' => $escalationUserId,
            ]);

            $instance->logAudit(
                'timeout_escalate',
                0, // system actor
                'system',
                'Auto-escalated: node timed out after ' . $instance->definition->timeout_hours . ' hours.',
                $priorValues,
                ['status' => 'escalated'],
            );

            $escalatedCount++;
        }

        return response()->json([
            'message' => "{$escalatedCount} workflow instance(s) escalated due to timeout.",
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
