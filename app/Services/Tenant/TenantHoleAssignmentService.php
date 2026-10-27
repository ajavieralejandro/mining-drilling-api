<?php

namespace App\Services\Tenant;

use App\Enums\RoleInHole;
use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\TenantHoleAssignment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantHoleAssignmentService
{
    public function __construct(
        private readonly TenantHoleOperationalStateService $operationalService,
    ) {}

    /**
     * Devuelve la cuadrilla activa actual asignada al pozo en el origen correspondiente.
     */
    public function getCrewForHole(string $tenantId, string $originId, string $holeId): array
    {
        $active = TenantHoleAssignment::with(['user', 'assignedBy'])
            ->forWell($tenantId, $originId, $holeId)
            ->active()
            ->get();

        $crew = [
            'driller' => null,
            'helper' => null,
            'geologist' => null,
            'supervisor' => null,
            'geotechnical' => null,
        ];

        foreach ($active as $assignment) {
            $roleKey = $assignment->role_in_hole instanceof RoleInHole
                ? $assignment->role_in_hole->value
                : (string) $assignment->role_in_hole;

            $crew[$roleKey] = [
                'assignment_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'name' => $assignment->user?->name,
                'email' => $assignment->user?->email,
                'role_in_hole' => $roleKey,
                'assigned_from' => $assignment->assigned_from?->toIso8601String(),
                'notes' => $assignment->notes,
            ];
        }

        return [
            'hole_id' => $holeId,
            'origin_id' => $originId,
            'crew' => $crew,
            'active_count' => $active->count(),
        ];
    }

    /**
     * Genera un snapshot inmutable de la cuadrilla activa para adjuntarlo al parte de perforación.
     */
    public function getActiveCrewSnapshot(string $tenantId, string $originId, string $holeId): array
    {
        $crewData = $this->getCrewForHole($tenantId, $originId, $holeId);

        return $crewData['crew'];
    }

    /**
     * Historial completo de asignaciones del pozo en el origen.
     */
    public function listAssignments(string $tenantId, string $originId, string $holeId): Collection
    {
        return TenantHoleAssignment::with(['user', 'assignedBy'])
            ->forWell($tenantId, $originId, $holeId)
            ->orderByDesc('assigned_from')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Lista de personal elegible para el selector web (usuarios activos con membresía activa en el tenant).
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
     * Asigna cuadrilla completa o integrante individual garantizando reglas de negocio y transaccionalidad.
     */
    public function assignCrew(TenantContext $context, string $originId, string $holeId, array $data): array
    {
        $isCrewFormat = isset($data['driller_id'])
            || isset($data['helper_id'])
            || isset($data['geologist_id'])
            || isset($data['supervisor_id']);

        if ($isCrewFormat) {
            return $this->processCrewPayload($context, $originId, $holeId, $data);
        }

        if (isset($data['user_id'], $data['role_in_hole'])) {
            return $this->processSingleAssignment($context, $originId, $holeId, $data);
        }

        throw ValidationException::withMessages([
            'payload' => 'Debe indicar al menos un rol de cuadrilla o un usuario con su rol operativo.',
        ]);
    }

    private function processCrewPayload(TenantContext $context, string $originId, string $holeId, array $data): array
    {
        return DB::transaction(function () use ($context, $originId, $holeId, $data) {
            // 1. Bloqueo pesimista sobre la referencia operativa del pozo
            $state = $this->operationalService->resolveAndLock($context->tenantId, $originId, $holeId);

            if (! $state->isOperable()) {
                throw ValidationException::withMessages([
                    'status' => "El pozo se encuentra en estado '{$state->status}' y no admite asignaciones de cuadrilla.",
                ]);
            }

            $rolesToAssign = [
                'driller' => $data['driller_id'] ?? null,
                'helper' => $data['helper_id'] ?? null,
                'geologist' => $data['geologist_id'] ?? null,
                'supervisor' => $data['supervisor_id'] ?? null,
            ];

            // 2. Simular el estado final de la cuadrilla activa
            $currentActive = TenantHoleAssignment::forWell($context->tenantId, $originId, $holeId)
                ->active()
                ->pluck('user_id', 'role_in_hole')
                ->all();

            $finalCrew = $currentActive;
            foreach ($rolesToAssign as $roleKey => $userId) {
                if (array_key_exists($roleKey . '_id', $data)) {
                    if ($userId === null || $userId === '') {
                        unset($finalCrew[$roleKey]);
                    } else {
                        $finalCrew[$roleKey] = (int) $userId;
                    }
                }
            }

            // 3. Validar integrantes duplicados en la cuadrilla activa
            $assignedUserIds = array_values(array_filter($finalCrew));
            if (count($assignedUserIds) !== count(array_unique($assignedUserIds))) {
                throw ValidationException::withMessages([
                    'crew' => 'Un usuario no puede tener múltiples roles activos simultáneos en la misma cuadrilla.',
                ]);
            }

            // 4. Validar que cada usuario esté activo y posea membresía activa
            foreach ($assignedUserIds as $userId) {
                $this->assertUserIsActiveMember((int) $userId, $context->tenantId);
            }

            // 5. Validar regla de perforista obligatorio para cuadrilla operativa
            $hasHelper = ! empty($finalCrew['helper']);
            $hasGeologist = ! empty($finalCrew['geologist']);
            $hasDriller = ! empty($finalCrew['driller']);

            if (($hasHelper || $hasGeologist) && ! $hasDriller) {
                throw ValidationException::withMessages([
                    'driller_id' => 'Debe asignar al menos un perforista para el equipo operativo.',
                ]);
            }

            // 6. Aplicar cambios atómicamente
            $now = now();
            foreach ($rolesToAssign as $roleValue => $userId) {
                if (! array_key_exists($roleValue . '_id', $data)) {
                    continue;
                }

                // Desactivar asignación anterior para ese rol
                TenantHoleAssignment::forWell($context->tenantId, $originId, $holeId)
                    ->where('role_in_hole', $roleValue)
                    ->where('active', true)
                    ->update([
                        'active' => false,
                        'assigned_to' => $now,
                    ]);

                // Asentar nueva asignación
                if ($userId) {
                    TenantHoleAssignment::create([
                        'tenant_id' => $context->tenantId,
                        'operational_state_id' => $state->id,
                        'origin_id' => $originId,
                        'hole_id' => $holeId,
                        'user_id' => (int) $userId,
                        'role_in_hole' => $roleValue,
                        'assigned_by' => $context->userId,
                        'assigned_from' => $now,
                        'active' => true,
                        'notes' => $data['notes'] ?? null,
                    ]);
                }
            }

            return $this->getCrewForHole($context->tenantId, $originId, $holeId);
        }, attempts: 5);
    }

    private function processSingleAssignment(TenantContext $context, string $originId, string $holeId, array $data): array
    {
        return DB::transaction(function () use ($context, $originId, $holeId, $data) {
            $state = $this->operationalService->resolveAndLock($context->tenantId, $originId, $holeId);

            if (! $state->isOperable()) {
                throw ValidationException::withMessages([
                    'status' => "El pozo se encuentra en estado '{$state->status}' y no admite asignaciones de cuadrilla.",
                ]);
            }

            $userId = (int) $data['user_id'];
            $roleInHole = $data['role_in_hole'] instanceof RoleInHole
                ? $data['role_in_hole']->value
                : (string) $data['role_in_hole'];

            $this->assertUserIsActiveMember($userId, $context->tenantId);

            // Simular estado final de cuadrilla
            $currentActive = TenantHoleAssignment::forWell($context->tenantId, $originId, $holeId)
                ->active()
                ->pluck('user_id', 'role_in_hole')
                ->all();

            $finalCrew = $currentActive;
            $finalCrew[$roleInHole] = $userId;

            // Validar integrantes duplicados
            $assignedUserIds = array_values(array_filter($finalCrew));
            if (count($assignedUserIds) !== count(array_unique($assignedUserIds))) {
                throw ValidationException::withMessages([
                    'user_id' => 'Este usuario ya se encuentra asignado a otro rol activo en la cuadrilla de este pozo.',
                ]);
            }

            // Validar regla de perforista obligatorio
            $hasHelper = ! empty($finalCrew['helper']);
            $hasGeologist = ! empty($finalCrew['geologist']);
            $hasDriller = ! empty($finalCrew['driller']);

            if (($hasHelper || $hasGeologist) && ! $hasDriller) {
                throw ValidationException::withMessages([
                    'user_id' => 'Debe asignar al menos un perforista para el equipo operativo.',
                ]);
            }

            $now = now();
            TenantHoleAssignment::forWell($context->tenantId, $originId, $holeId)
                ->where('role_in_hole', $roleInHole)
                ->where('active', true)
                ->update([
                    'active' => false,
                    'assigned_to' => $now,
                ]);

            TenantHoleAssignment::create([
                'tenant_id' => $context->tenantId,
                'operational_state_id' => $state->id,
                'origin_id' => $originId,
                'hole_id' => $holeId,
                'user_id' => $userId,
                'role_in_hole' => $roleInHole,
                'assigned_by' => $context->userId,
                'assigned_from' => $data['assigned_from'] ?? $now,
                'assigned_to' => $data['assigned_to'] ?? null,
                'active' => true,
                'notes' => $data['notes'] ?? null,
            ]);

            return $this->getCrewForHole($context->tenantId, $originId, $holeId);
        }, attempts: 5);
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
