<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfflineSyncBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'idempotency_key', 'device_id', 'status',
        'attempts', 'last_error', 'payload',
        'total_chunks', 'received_chunks',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
