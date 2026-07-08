<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class CsvImportPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Admin,
            UserRole::Supervisor,
            UserRole::Geologist,
        ], true);
    }

    public function preview(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function confirm(User $user): bool
    {
        return $this->viewAny($user);
    }
}
