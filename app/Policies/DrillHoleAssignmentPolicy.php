<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DrillHole;
use App\Models\User;

class DrillHoleAssignmentPolicy
{
    public function create(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return in_array($user->role, [
            UserRole::Supervisor,
        ], true);
    }
}
