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

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The user's one active tenant membership, or null. Deliberately does
     * NOT use first() to pick one silently: the app-wide rule is at most
     * one active membership per user (enforced by a DB partial unique
     * index — see MembershipLifecycleService), so finding more than one
     * here means that invariant was violated somewhere outside the
     * service. Failing loudly is the safe choice — this method must never
     * be the place that arbitrarily decides which tenant a request
     * belongs to.
     *
     * @throws \RuntimeException if more than one active membership exists
     */
    public function activeMembership(): ?Membership
    {
        $active = $this->memberships()->where('status', Membership::STATUS_ACTIVE)->get();

        if ($active->count() > 1) {
            throw new \RuntimeException('MULTIPLE_ACTIVE_MEMBERSHIPS');
        }

        return $active->first();
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
