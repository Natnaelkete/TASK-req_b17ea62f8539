<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkflowInstance extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'workflow_definition_id', 'entity_type', 'entity_id',
        'current_node', 'status', 'initiated_by', 'assigned_to',
        'started_at', 'completed_at', 'escalated_at', 'escalation_note',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    protected function getAuditTable(): string
    {
        return 'workflow_action_audits';
    }

    protected function getAuditForeignKey(): string
    {
        return 'workflow_instance_id';
    }
}
