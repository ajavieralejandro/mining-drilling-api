<?php

namespace App\Policies;

use App\Enums\ObservationType;
use App\Enums\UserRole;
use App\Models\DrillHole;
use App\Models\Observation;
use App\Models\User;

class ObservationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Observation $observation): bool
    {
        return app(DrillHolePolicy::class)->view($user, $observation->drillHole);
    }

    public function create(User $user, DrillHole $drillHole): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->canViewAll()) {
            return true;
        }

        return $user->isAssignedToHole($drillHole);
    }

    public function closeRisk(User $user, Observation $observation): bool
    {
        if (! $observation->isRisk()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return in_array($user->role, [
            UserRole::Supervisor,
            UserRole::Geotechnical,
        ], true);
    }
}
