<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultVersion extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'job_id', 'version_number', 'status', 'data', 'snapshot',
        'notes', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'snapshot' => 'array',
            'version_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function objections()
    {
        return $this->hasMany(Objection::class);
    }

    protected function getAuditTable(): string
    {
        return 'result_decision_audits';
    }

    protected function getAuditForeignKey(): string
    {
        return 'result_version_id';
    }
}
