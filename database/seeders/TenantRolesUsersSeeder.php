<?php

namespace Database\Seeders;

use App\Enums\AuditSource;
use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MembershipLifecycleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Crea o actualiza usuarios de prueba para todos los roles del sistema
 * vinculados con membresía activa al Tenant operativo del servidor.
 *
 * Uso en servidor:
 *   php artisan db:seed --class=TenantRolesUsersSeeder --force
 *   UNDSURF_SEED_PASSWORD="MiClaveSegura2026!" php artisan db:seed --class=TenantRolesUsersSeeder --force
 */
class TenantRolesUsersSeeder extends Seeder
{
    public function run(MembershipLifecycleService $lifecycle): void
    {
        $this->call(RbacSeeder::class);

        [$plainPassword, $generated] = $this->resolvePassword();
        $tenant = $this->resolveTenant();
        $makeAdminSuper = filter_var(env('UNDSURF_SEED_SUPER_ADMIN', false), FILTER_VALIDATE_BOOLEAN);

        $accounts = [
            [
                'role' => UserRole::Admin,
                'slug' => 'admin',
                'name' => 'Administrador Prueba',
                'email' => 'admin.test@undsurf.com',
                'shift' => 'A',
                'postman_var' => 'email_admin',
            ],
            [
                'role' => UserRole::Supervisor,
                'slug' => 'supervisor',
                'name' => 'Supervisor Prueba',
                'email' => 'supervisor.test@undsurf.com',
                'shift' => 'A',
                'postman_var' => 'email_supervisor',
            ],
            [
                'role' => UserRole::Geologist,
                'slug' => 'geologist',
                'name' => 'Geólogo Prueba',
                'email' => 'geologo.test@undsurf.com',
                'shift' => 'A',
                'postman_var' => 'email_geologist',
            ],
            [
                'role' => UserRole::Driller,
                'slug' => 'driller',
                'name' => 'Perforista Prueba',
                'email' => 'perforista.test@undsurf.com',
                'shift' => 'A',
                'postman_var' => 'email_driller',
            ],
            [
                'role' => UserRole::Helper,
                'slug' => 'helper',
                'name' => 'Ayudante Prueba',
                'email' => 'ayudante.test@undsurf.com',
                'shift' => 'B',
                'postman_var' => 'email_helper',
            ],
            [
                'role' => UserRole::Geotechnical,
                'slug' => 'geotechnical',
                'name' => 'Geotécnico Prueba',
                'email' => 'geotecnico.test@undsurf.com',
                'shift' => 'B',
                'postman_var' => 'email_geotechnical',
            ],
        ];

        $systemRoles = Role::query()->whereNull('tenant_id')->get()->keyBy('slug');
        $rows = [];

        DB::transaction(function () use ($accounts, $tenant, $plainPassword, $makeAdminSuper, $systemRoles, $lifecycle, &$rows) {
            $actor = null;

            foreach ($accounts as $account) {
                $email = Str::lower($account['email']);

                /** @var User|null $user */
                $user = User::withTrashed()->where('email', $email)->first();

                if ($user) {
                    if ($user->trashed()) {
                        $user->restore();
                    }

                    $user->name = $account['name'];
                    $user->password = Hash::make($plainPassword);
                    $user->role = $account['role'];
                    $user->shift = $account['shift'];
                    $user->active = true;

                    if ($account['slug'] === 'admin' && $makeAdminSuper) {
                        $user->is_super_admin = true;
                    }

                    $user->save();
                } else {
                    $user = User::create([
                        'name' => $account['name'],
                        'email' => $email,
                        'password' => Hash::make($plainPassword),
                        'role' => $account['role'],
                        'shift' => $account['shift'],
                        'active' => true,
                        'is_super_admin' => ($account['slug'] === 'admin' && $makeAdminSuper),
                    ]);
                }

                $actor ??= $user;

                // Cerrar membresías activas en otros tenants antes de activar en el tenant objetivo
                $otherActiveMemberships = Membership::query()
                    ->where('user_id', $user->id)
                    ->where('status', Membership::STATUS_ACTIVE)
                    ->where('tenant_id', '!=', $tenant->id)
                    ->get();

                foreach ($otherActiveMemberships as $other) {
                    $lifecycle->deactivate(
                        membership: $other,
                        actor: $actor,
                        reason: 'Reasignado por TenantRolesUsersSeeder',
                        source: AuditSource::System,
                    );
                }

                $existingInTenant = Membership::query()
                    ->where('user_id', $user->id)
                    ->where('tenant_id', $tenant->id)
                    ->first();

                if ($existingInTenant && $existingInTenant->status === Membership::STATUS_ACTIVE) {
                    $existingInTenant->forceFill([
                        'role' => $account['slug'],
                        'corporate_email' => $email,
                        'external_hr_id' => $existingInTenant->external_hr_id ?: 'seed_'.$account['slug'],
                    ])->save();
                    $membership = $existingInTenant->fresh();
                } else {
                    $membership = $lifecycle->activate(
                        user: $user,
                        tenant: $tenant,
                        role: $account['slug'],
                        corporateEmail: $email,
                        externalHrId: 'seed_'.$account['slug'],
                        actor: $actor,
                        source: AuditSource::System,
                    );
                }

                if (isset($systemRoles[$account['slug']])) {
                    $membership->roles()->sync([$systemRoles[$account['slug']]->id]);
                }

                $rows[] = [
                    $account['slug'],
                    $account['postman_var'],
                    $email,
                    $plainPassword,
                ];
            }
        });

        $this->command?->info('Tenant destino: '.$tenant->name.' (ID: '.$tenant->id.')');
        $this->command?->table(['Rol', 'Variable Postman', 'Email', 'Password'], $rows);

        if ($generated) {
            $this->command?->warn('Password aleatoria generada para esta corrida (no guardada en git).');
        }

        $this->command?->info('Copiar el Password en las variables "password" y "password_test" de Postman (UndSurf Producción).');
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolvePassword(): array
    {
        $configured = env('UNDSURF_SEED_PASSWORD');
        if (is_string($configured) && trim($configured) !== '') {
            $password = trim($configured);
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('UNDSURF_SEED_PASSWORD debe tener al menos 8 caracteres.');
            }

            return [$password, false];
        }

        if (filter_var(env('UNDSURF_SEED_RANDOM', false), FILTER_VALIDATE_BOOLEAN)) {
            return [Str::password(16, true, true, false, false), true];
        }

        return ['UndSurf2026!', false];
    }

    private function resolveTenant(): Tenant
    {
        $explicitId = env('UNDSURF_SEED_TENANT_ID');
        if (is_string($explicitId) && trim($explicitId) !== '') {
            return Tenant::query()->findOrFail(trim($explicitId));
        }

        $onlineConnector = Connector::query()
            ->where('status', Connector::STATUS_ONLINE)
            ->orderByDesc('last_seen_at')
            ->first();
        if ($onlineConnector?->tenant) {
            return $onlineConnector->tenant;
        }

        $seenConnector = Connector::query()
            ->where('status', '!=', Connector::STATUS_REVOKED)
            ->whereNotNull('last_seen_at')
            ->orderByDesc('last_seen_at')
            ->first();
        if ($seenConnector?->tenant) {
            return $seenConnector->tenant;
        }

        $anyConnector = Connector::query()
            ->where('status', '!=', Connector::STATUS_REVOKED)
            ->first();
        if ($anyConnector?->tenant) {
            return $anyConnector->tenant;
        }

        $activeMembership = Membership::query()
            ->where('status', Membership::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first();
        if ($activeMembership?->tenant) {
            return $activeMembership->tenant;
        }

        $existingTenant = Tenant::query()->orderBy('created_at')->first();
        if ($existingTenant) {
            return $existingTenant;
        }

        return Tenant::create([
            'id' => (string) Str::ulid(),
            'name' => 'Minera UndSurf Demo',
        ]);
    }
}
