<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'objection_id', 'assigned_to', 'status', 'notes', 'adjudication_summary',
    ];

    public function objection()
    {
        return $this->belongsTo(Objection::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
