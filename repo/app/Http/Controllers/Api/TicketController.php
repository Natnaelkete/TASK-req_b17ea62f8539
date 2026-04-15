<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $ticket = Ticket::with(['objection', 'assignee'])->findOrFail($id);

        return response()->json(['data' => $ticket]);
    }
}
