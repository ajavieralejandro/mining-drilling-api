<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DrillHole;
use App\Models\User;

class DrillHolePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DrillHole $drillHole): bool
    {
        if ($user->canViewAll()) {
            return true;
        }

        return $user->isAssignedToHole($drillHole);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Admin,
            UserRole::Supervisor,
            UserRole::Geologist,
        ], true);
    }

    public function updateTechnicalData(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return in_array($user->role, [
            UserRole::Geologist,
        ], true);
    }

    public function createRisk(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (in_array($user->role, [
            UserRole::Supervisor,
            UserRole::Geologist,
            UserRole::Geotechnical,
        ], true)) {
            return true;
        }

        if (in_array($user->role, [UserRole::Driller, UserRole::Helper], true)) {
            return $user->isAssignedToHole($drillHole);
        }

        return false;
    }

    public function assignPersonnel(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->role === UserRole::Supervisor;
    }

    public function logProgress(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->role === UserRole::Driller) {
            return $user->isAssignedToHole($drillHole);
        }

        return false;
    }

    public function createObservation(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->canViewAll()) {
            return true;
        }

        return $user->isAssignedToHole($drillHole);
    }
}
