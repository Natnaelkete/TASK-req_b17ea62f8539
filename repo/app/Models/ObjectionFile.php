<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ObjectionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'objection_id', 'file_path', 'original_name', 'mime_type', 'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function objection()
    {
        return $this->belongsTo(Objection::class);
    }
}
