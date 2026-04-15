<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'enabled', 'description'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function isEnabled(string $key): bool
    {
        $flag = static::where('key', $key)->first();
        return $flag ? $flag->enabled : false;
    }
}
