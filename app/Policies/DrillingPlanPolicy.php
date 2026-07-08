<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DrillingPlan;
use App\Models\User;

class DrillingPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DrillingPlan $plan): bool
    {
        if ($user->canViewAll()) {
            return true;
        }

        return $plan->drillHoles()
            ->whereIn('id', $user->assignedDrillHoleIds())
            ->exists();
    }
}
