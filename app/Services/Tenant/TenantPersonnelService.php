<?php

namespace App\Services\Tenant;

use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connector\CommandDispatcher;
use App\Services\MembershipLifecycleService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TenantPersonnelService
{
    public function __construct(
        private readonly MembershipLifecycleService $membershipLifecycle,
        private readonly CommandDispatcher $dispatcher,
    ) {}

    /**
     * Lista completa del personal con membresía en la organización.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(TenantContext $context): array
    {
        return Membership::query()
            ->with('user')
            ->where('tenant_id', $context->tenantId)
            ->orderByDesc('started_at')
            ->get()
            ->map(function (Membership $m) {
                $user = $m->user;
                $systemRole = $user?->role instanceof UserRole ? $user->role->value : (string) $user?->role;

                return [
                    'id' => $user?->id,
                    'membership_id' => $m->id,
                    'name' => $user?->name ?? 'Sin nombre',
                    'email' => $user?->email,
                    'corporate_email' => $m->corporate_email,
                    'role' => $m->role,
                    'system_role' => $systemRole,
                    'status' => $m->status,
                    'external_hr_id' => $m->external_hr_id,
                    'active' => ($user?->active ?? false) && $m->status === Membership::STATUS_ACTIVE,
                    'started_at' => $m->started_at?->toIso8601String(),
                    'ended_at' => $m->ended_at?->toIso8601String(),
                    'deactivation_reason' => $m->deactivation_reason,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Alta de un integrante en el Control Plane y sincronización atómica con el Conector.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(TenantContext $context, User $actor, array $data, ?string $correlationId = null): array
    {
        $tenant = Tenant::findOrFail($context->tenantId);
        $email = Str::lower(trim($data['email']));
        $role = trim($data['role']);
        $externalHrId = ! empty($data['external_hr_id'])
            ? trim($data['external_hr_id'])
            : 'usr_'.(string) Str::ulid();

        $generatedPassword = null;
        $password = $data['password'] ?? null;
        if (empty($password)) {
            $generatedPassword = Str::password(16, true, true, false, false);
            $password = $generatedPassword;
        }

        $membership = DB::transaction(function () use ($data, $email, $password, $role, $tenant, $externalHrId, $actor) {
            $user = User::where('email', $email)->first();

            $systemUserRole = \App\Enums\UserRole::tryFrom($role) ?? \App\Enums\UserRole::Helper;

            if (! $user) {
                $user = User::create([
                    'name' => trim($data['name']),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => $systemUserRole,
                    'active' => true,
                ]);
            } else {
                $user->name = trim($data['name']);
                if ($matchedSystemRole = \App\Enums\UserRole::tryFrom($role)) {
                    $user->role = $matchedSystemRole;
                }
                $user->active = true;
                $user->save();
            }

            $activated = $this->membershipLifecycle->activate(
                user: $user,
                tenant: $tenant,
                role: $role,
                corporateEmail: $email,
                externalHrId: $externalHrId,
                actor: $actor,
            );

            $roleModel = \App\Models\Role::forTenant($tenant->id)->where('slug', $role)->first()
                ?? \App\Models\Role::whereNull('tenant_id')->where('slug', $role)->first();
            if ($roleModel) {
                $activated->roles()->syncWithoutDetaching([$roleModel->id]);
            }

            return $activated;
        });

        // Sincronización segura al conector (base del cliente)
        // NOTA DE SEGURIDAD: La contraseña NUNCA se incluye en el payload enviado al conector.
        $syncResult = $this->syncToConnector($context->tenantId, [
            'id' => $membership->external_hr_id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role,
            'active' => true,
        ], $actor->id, $correlationId);

        return [
            'member' => [
                'id' => $membership->user->id,
                'membership_id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role,
                'status' => $membership->status,
                'external_hr_id' => $membership->external_hr_id,
                'active' => true,
                'started_at' => $membership->started_at?->toIso8601String(),
            ],
            'temporary_password' => $generatedPassword,
            'connector_synced' => $syncResult['synced'],
            'sync_message' => $syncResult['message'],
        ];
    }

    /**
     * Actualización de datos o rol operativo del integrante.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(TenantContext $context, User $actor, int $userId, array $data, ?string $correlationId = null): array
    {
        $membership = Membership::where('tenant_id', $context->tenantId)
            ->where('user_id', $userId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->firstOrFail();

        $user = $membership->user;

        DB::transaction(function () use ($user, $membership, $data) {
            if (! empty($data['name'])) {
                $user->name = trim($data['name']);
            }
            if (! empty($data['role'])) {
                $roleSlug = trim($data['role']);
                if ($matchedSystemRole = \App\Enums\UserRole::tryFrom($roleSlug)) {
                    $user->role = $matchedSystemRole;
                }
                $membership->role = $roleSlug;

                $roleModel = \App\Models\Role::forTenant($membership->tenant_id)->where('slug', $roleSlug)->first()
                    ?? \App\Models\Role::whereNull('tenant_id')->where('slug', $roleSlug)->first();
                if ($roleModel) {
                    $membership->roles()->sync([$roleModel->id]);
                }
            }
            if (isset($data['external_hr_id'])) {
                $membership->external_hr_id = trim($data['external_hr_id']);
            }
            $user->save();
            $membership->save();
        });

        $syncResult = $this->syncToConnector($context->tenantId, [
            'id' => $membership->external_hr_id ?: 'usr_'.$user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $membership->role,
            'active' => true,
        ], $actor->id, $correlationId);

        return [
            'member' => [
                'id' => $user->id,
                'membership_id' => $membership->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $membership->role,
                'status' => $membership->status,
                'external_hr_id' => $membership->external_hr_id,
                'active' => true,
            ],
            'connector_synced' => $syncResult['synced'],
            'sync_message' => $syncResult['message'],
        ];
    }

    /**
     * Cierre de membresía y revocación atómica de tokens en UndSurf,
     * sincronizando el estado inactivo en la base del conector.
     */
    public function deactivate(TenantContext $context, User $actor, int $userId, string $reason, ?string $correlationId = null): array
    {
        $membership = Membership::where('tenant_id', $context->tenantId)
            ->where('user_id', $userId)
            ->where('status', Membership::STATUS_ACTIVE)
            ->firstOrFail();

        $user = $membership->user;

        $deactivated = $this->membershipLifecycle->deactivate($membership, $actor, $reason);

        $syncResult = $this->syncToConnector($context->tenantId, [
            'id' => $deactivated->external_hr_id ?: 'usr_'.$user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $deactivated->role,
            'active' => false,
        ], $actor->id, $correlationId);

        return [
            'id' => $user->id,
            'membership_id' => $deactivated->id,
            'status' => $deactivated->status,
            'ended_at' => $deactivated->ended_at?->toIso8601String(),
            'deactivation_reason' => $deactivated->deactivation_reason,
            'connector_synced' => $syncResult['synced'],
            'sync_message' => $syncResult['message'],
        ];
    }

    /**
     * Envía el comando personnel.sync@1 al conector y purga atómicamente el payload (ADR-04).
     *
     * @param  array<string, mixed>  $personnelData
     * @return array{synced: bool, message: string}
     */
    private function syncToConnector(string $tenantId, array $personnelData, int $actorId, ?string $correlationId = null): array
    {
        $connector = Connector::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_seen_at')
            ->first();

        if ($connector === null) {
            return ['synced' => false, 'message' => 'No hay conector registrado para la organización.'];
        }

        try {
            $command = $this->dispatcher->dispatch(
                connector: $connector,
                op: 'personnel.sync@1',
                payload: $personnelData,
                actorType: 'user',
                actorId: (string) $actorId,
                timeoutSeconds: 8,
                correlationId: $correlationId,
            );

            $completed = $this->dispatcher->waitForResult($command, 8);

            // ADR-04: Purga inmediata de datos operativos en el Control Plane
            // Ni el payload enviado ni la respuesta persisten en la DB del servidor puente.
            $command->forceFill([
                'payload_json' => [],
                'result_json' => null,
            ])->save();

            if ($completed->status === ConnectorCommand::STATUS_COMPLETED) {
                return ['synced' => true, 'message' => 'Sincronizado exitosamente con la base de datos de origen.'];
            }

            return ['synced' => false, 'message' => 'El conector no confirmó la escritura: '.($completed->error_code ?? 'ERROR')];
        } catch (\Throwable $e) {
            return ['synced' => false, 'message' => 'Error durante el despacho al conector: '.$e->getMessage()];
        }
    }
}
