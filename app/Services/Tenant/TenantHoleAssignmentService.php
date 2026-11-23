<?php

namespace App\Services\Tenant;

use App\Domain\Repositories\HoleRecordsStore;
use App\Enums\RoleInHole;
use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use App\Models\Membership;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Crew of a hole. The assignments live in the mining company's database and
 * the crew rules (one role per person, a driller for helpers and geologists,
 * hole in progress) run in the connector's transaction. UndSurf checks what
 * only it knows: that each person is an active member of the organization.
 */
class TenantHoleAssignmentService
{
    private const CREW_KEYS = ['driller', 'helper', 'geologist', 'supervisor', 'geotechnical'];

    public function __construct(private readonly HoleRecordsStore $records) {}

    /**
     * Active crew by role, with names from UndSurf's users, plus the history.
     *
     * @return array{hole_id: string, origin_id: ?string, crew: array<string, mixed>, active_count: int, history: list<array<string, mixed>>}
     */
    public function getCrewForHole(TenantContext $context, string $holeId, string $correlationId): array
    {
        $result = $this->records->crew($context, $holeId, $correlationId);

        return $this->present($holeId, $result['active'], $result['history'], $result['origin_id']);
    }

    /**
     * Eligible people for the web selector: active users with an active
     * membership in the organization. This is UndSurf identity data.
     *
     * @return list<array<string, mixed>>
     */
    public function getEligiblePersonnel(string $tenantId): array
    {
        return User::query()
            ->where('active', true)
            ->whereHas('memberships', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->where('status', Membership::STATUS_ACTIVE);
            })
            ->with(['memberships' => function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->where('status', Membership::STATUS_ACTIVE);
            }])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                $membership = $user->memberships->first();
                $systemRole = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $systemRole,
                    'membership_role' => $membership?->role,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Assigns a whole crew (driller_id, helper_id, geologist_id,
     * supervisor_id; an explicit null releases the role) or one person
     * (user_id + role_in_hole).
     *
     * @param  array<string, mixed>  $data
     * @return array{hole_id: string, origin_id: ?string, crew: array<string, mixed>, active_count: int, history: list<array<string, mixed>>}
     */
    public function assignCrew(TenantContext $context, string $holeId, array $data, string $correlationId): array
    {
        $isCrewFormat = isset($data['driller_id'])
            || isset($data['helper_id'])
            || isset($data['geologist_id'])
            || isset($data['supervisor_id']);

        if ($isCrewFormat) {
            $roles = [];
            foreach (['driller', 'helper', 'geologist', 'supervisor'] as $role) {
                if (array_key_exists($role.'_id', $data)) {
                    $user = $data[$role.'_id'];
                    $roles[$role] = $user === null || $user === '' ? null : (int) $user;
                }
            }
            $mode = 'crew';
            $extra = ['notes' => $data['notes'] ?? null];
        } elseif (isset($data['user_id'], $data['role_in_hole'])) {
            $role = $data['role_in_hole'] instanceof RoleInHole ? $data['role_in_hole']->value : (string) $data['role_in_hole'];
            $roles = [$role => (int) $data['user_id']];
            $mode = 'single';
            $extra = [
                'notes' => $data['notes'] ?? null,
                'assigned_from' => isset($data['assigned_from']) ? Carbon::parse($data['assigned_from'])->toISOString() : null,
                'assigned_to' => isset($data['assigned_to']) ? Carbon::parse($data['assigned_to'])->toISOString() : null,
            ];
        } else {
            throw ValidationException::withMessages([
                'payload' => 'Debe indicar al menos un rol de cuadrilla o un usuario con su rol operativo.',
            ]);
        }

        foreach (array_filter($roles) as $userId) {
            $this->assertUserIsActiveMember($userId, $context->tenantId);
        }

        $result = $this->records->assignCrew($context, $holeId, $mode, $roles, $extra, $correlationId);

        return $this->present($holeId, $result['active'], [], $result['origin_id']);
    }

    /**
     * @param  list<array<string, mixed>>  $active
     * @param  list<array<string, mixed>>  $history
     * @return array{hole_id: string, origin_id: ?string, crew: array<string, mixed>, active_count: int, history: list<array<string, mixed>>}
     */
    private function present(string $holeId, array $active, array $history, ?string $originId): array
    {
        $users = $this->users(array_merge($active, $history));
        $crew = array_fill_keys(self::CREW_KEYS, null);

        foreach ($active as $row) {
            $user = $users->get((int) $row['user_id']);
            $crew[$row['role_in_hole']] = [
                'assignment_id' => $row['id'],
                'user_id' => (int) $row['user_id'],
                'name' => $user?->name,
                'email' => $user?->email,
                'role_in_hole' => $row['role_in_hole'],
                'assigned_from' => $row['assigned_from'] ?? null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        return [
            'hole_id' => $holeId,
            'origin_id' => $originId,
            'crew' => $crew,
            'active_count' => count($active),
            'history' => array_map(function (array $row) use ($users, $originId) {
                $user = $users->get((int) $row['user_id']);
                $by = isset($row['assigned_by']) ? $users->get((int) $row['assigned_by']) : null;

                return [
                    'id' => $row['id'],
                    'origin_id' => $originId,
                    'hole_id' => $row['hole_id'],
                    'user_id' => (int) $row['user_id'],
                    'role_in_hole' => $row['role_in_hole'],
                    'assigned_from' => $row['assigned_from'] ?? null,
                    'assigned_to' => $row['assigned_to'] ?? null,
                    'active' => (bool) ($row['active'] ?? false),
                    'notes' => $row['notes'] ?? null,
                    'user' => $user ? (new UserResource($user))->resolve() : null,
                    'assigned_by' => $by ? (new UserResource($by))->resolve() : null,
                    'created_at' => $row['created_at'] ?? null,
                ];
            }, $history),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, User>
     */
    private function users(array $rows): Collection
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach (['user_id', 'assigned_by'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $ids[] = (int) $row[$key];
                }
            }
        }

        return User::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');
    }

    private function assertUserIsActiveMember(int $userId, string $tenantId): void
    {
        $user = User::query()->find($userId);

        if (! $user || ! $user->active) {
            throw ValidationException::withMessages([
                'user_id' => "El usuario (ID: {$userId}) no existe o se encuentra inactivo.",
            ]);
        }

        $hasMembership = Membership::query()
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->exists();

        if (! $hasMembership) {
            throw ValidationException::withMessages([
                'user_id' => "El usuario '{$user->name}' no posee una membresía activa en esta organización.",
            ]);
        }
    }
}
