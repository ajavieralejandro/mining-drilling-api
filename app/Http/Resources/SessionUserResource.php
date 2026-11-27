<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * The signed-in user for /auth/login and /auth/me (plan 15, D1').
 *
 * `role` is the role in the active organization (the membership's role,
 * which may be a role of the organization's own), because the web picks
 * its route tree from it. `system_role` keeps users.role, which the
 * legacy control-plane routes still use. Without a membership both match.
 */
class SessionUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $systemRole = $data['role'];

        try {
            $membershipRole = $this->resource->activeMembership()?->role;
        } catch (\RuntimeException) {
            // MULTIPLE_ACTIVE_MEMBERSHIPS: the tenant routes refuse this user anyway.
            $membershipRole = null;
        }

        $data['role'] = $membershipRole ?: $systemRole;
        $data['system_role'] = $systemRole;

        return $data;
    }
}
