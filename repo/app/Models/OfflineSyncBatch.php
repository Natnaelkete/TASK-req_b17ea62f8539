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
        'assembled_payload', 'chunk_checksums',
        'total_chunks', 'received_chunks',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'assembled_payload' => 'array',
            'chunk_checksums' => 'array',
            'attempts' => 'integer',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
