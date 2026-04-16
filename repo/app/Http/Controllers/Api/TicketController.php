<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::with(['objection', 'assignee'])->findOrFail($id);
        $user = $request->user();

        $objection = $ticket->objection;
        $isFiler = $objection && $objection->filed_by === $user->id;
        $isAssigned = $ticket->assigned_to === $user->id;
        $isPrivileged = $user->hasAnyRole(['system_admin', 'compliance_reviewer']);

        if (!$isFiler && !$isAssigned && !$isPrivileged) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => $ticket]);
    }
}
