<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id', 'inspector_id', 'employer_id', 'status',
        'scheduled_at', 'started_at', 'completed_at',
        'notes', 'findings', 'is_offline', 'version',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'findings' => 'array',
            'is_offline' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function employer()
    {
        return $this->belongsTo(Employer::class);
    }
}
