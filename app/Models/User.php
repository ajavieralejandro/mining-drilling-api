<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'shift',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DrillHoleAssignment::class);
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(DrillHoleProgressLog::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canViewAll(): bool
    {
        return $this->role->canViewAll();
    }

    public function isAssignedToHole(DrillHole $drillHole): bool
    {
        return $this->assignments()
            ->where('drill_hole_id', $drillHole->id)
            ->where('active', true)
            ->exists();
    }

    public function assignedDrillHoleIds(): array
    {
        return $this->assignments()
            ->where('active', true)
            ->pluck('drill_hole_id')
            ->all();
    }
}
