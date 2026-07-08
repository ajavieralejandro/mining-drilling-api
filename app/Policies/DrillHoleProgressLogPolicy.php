<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DrillHole;
use App\Models\User;

class DrillHoleProgressLogPolicy
{
    public function create(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->role === UserRole::Driller) {
            return $user->isAssignedToHole($drillHole);
        }

        return false;
    }
}