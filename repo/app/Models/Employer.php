<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\EncryptsPii;
use App\Traits\MasksPii;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employer extends Model
{
    use HasFactory, EncryptsPii, MasksPii, Auditable;

    protected $fillable = [
        'user_id', 'company_name', 'trade_name', 'ein',
        'contact_first_name', 'contact_last_name', 'contact_phone', 'contact_email',
        'street', 'city', 'state', 'zip',
        'status', 'rejection_reason_code', 'rejection_notes',
        'reviewed_at', 'reviewed_by',
    ];

    protected $piiFields = [
        'contact_last_name', 'contact_phone', 'contact_email', 'street', 'ein',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public const REJECTION_REASONS = [
        'incomplete_docs' => 'Incomplete documentation',
        'invalid_license' => 'Invalid or expired license',
        'failed_verification' => 'Failed identity verification',
        'duplicate_entry' => 'Duplicate employer entry',
        'policy_violation' => 'Policy violation',
        'other' => 'Other',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function qualifications()
    {
        return $this->hasMany(EmployerQualification::class);
    }

    public function jobs()
    {
        return $this->hasMany(Job::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function getAuditTable(): string
    {
        return 'employer_decision_audits';
    }

    protected function getAuditForeignKey(): string
    {
        return 'employer_id';
    }
}
