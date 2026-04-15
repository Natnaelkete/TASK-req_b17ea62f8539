<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployerQualification extends Model
{
    use HasFactory;

    protected $fillable = [
        'employer_id', 'qualification_type', 'license_number',
        'issued_at', 'expires_at', 'document_path', 'document_original_name', 'status',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    public function employer()
    {
        return $this->belongsTo(Employer::class);
    }
}
