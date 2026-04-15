<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipient_id', 'type', 'subject', 'body', 'read_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
