<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkflowDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'version', 'nodes', 'approval_mode',
        'timeout_hours', 'escalation_role_user_id', 'active',
    ];

    protected function casts(): array
    {
        return [
            'nodes' => 'array',
            'version' => 'integer',
            'timeout_hours' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function instances()
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    public function escalationUser()
    {
        return $this->belongsTo(User::class, 'escalation_role_user_id');
    }
}
