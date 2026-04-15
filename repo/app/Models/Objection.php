<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Objection extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'result_version_id', 'filed_by', 'reason', 'status', 'resolution_notes',
    ];

    public function resultVersion()
    {
        return $this->belongsTo(ResultVersion::class);
    }

    public function filer()
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function files()
    {
        return $this->hasMany(ObjectionFile::class);
    }

    public function ticket()
    {
        return $this->hasOne(Ticket::class);
    }

    protected function getAuditTable(): string
    {
        return 'objection_decision_audits';
    }

    protected function getAuditForeignKey(): string
    {
        return 'objection_id';
    }
}
