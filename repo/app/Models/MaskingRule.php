<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaskingRule extends Model
{
    use HasFactory;

    protected $fillable = ['field_name', 'mask_type', 'visible_roles', 'active'];

    protected function casts(): array
    {
        return [
            'visible_roles' => 'array',
            'active' => 'boolean',
        ];
    }
}
