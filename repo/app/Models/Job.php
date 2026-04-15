<?php

namespace App\Models;

use App\Traits\EncryptsPii;
use App\Traits\MasksPii;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    use HasFactory, EncryptsPii, MasksPii;

    protected $fillable = [
        'employer_id', 'title', 'normalized_title', 'description',
        'category_id', 'salary_min', 'salary_max', 'education_level',
        'work_street', 'work_city', 'work_state', 'work_zip',
        'status', 'is_offline',
    ];

    protected $piiFields = ['work_street'];

    protected function casts(): array
    {
        return [
            'salary_min' => 'integer',
            'salary_max' => 'integer',
            'is_offline' => 'boolean',
        ];
    }

    public function employer()
    {
        return $this->belongsTo(Employer::class);
    }

    public function category()
    {
        return $this->belongsTo(JobCategory::class, 'category_id');
    }

    public function inspections()
    {
        return $this->hasMany(Inspection::class);
    }

    public function resultVersions()
    {
        return $this->hasMany(ResultVersion::class);
    }

    public function getPiiFields(): array
    {
        return $this->piiFields;
    }
}
