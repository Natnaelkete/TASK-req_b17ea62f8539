<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'password',
        'phone',
        'ssn',
        'date_of_birth',
        'role',
        'disabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'ssn',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'disabled' => 'boolean',
            'date_of_birth' => 'date',
        ];
    }

    public const ROLES = [
        'system_admin',
        'compliance_reviewer',
        'employer_manager',
        'inspector',
        'general_user',
    ];

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles);
    }

    public function sessions()
    {
        return $this->hasMany(DeviceSession::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'recipient_id');
    }
}
