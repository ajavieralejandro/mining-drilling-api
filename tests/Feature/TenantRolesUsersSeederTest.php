<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantRolesUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantRolesUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_links_all_roles_to_online_connector_tenant_and_allows_login(): void
    {
        $unusedTenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Lab Vacio']);
        $prodTenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Activa']);

        Connector::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $prodTenant->id,
            'label' => 'connector-prod',
            'status' => Connector::STATUS_ONLINE,
            'connector_token_hash' => hash('sha256', 'tok-prod-1'),
            'last_seen_at' => now(),
        ]);

        $this->seed(TenantRolesUsersSeeder::class);

        $expected = [
            'admin.test@undsurf.com' => UserRole::Admin->value,
            'supervisor.test@undsurf.com' => UserRole::Supervisor->value,
            'geologo.test@undsurf.com' => UserRole::Geologist->value,
            'perforista.test@undsurf.com' => UserRole::Driller->value,
            'ayudante.test@undsurf.com' => UserRole::Helper->value,
            'geotecnico.test@undsurf.com' => UserRole::Geotechnical->value,
        ];

        foreach ($expected as $email => $role) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue($user->active);
            $this->assertFalse($user->isSuperAdmin());

            $membership = $user->activeMembership();
            $this->assertNotNull($membership);
            $this->assertSame($prodTenant->id, $membership->tenant_id);
            $this->assertNotSame($unusedTenant->id, $membership->tenant_id);
            $this->assertSame($role, $membership->role);
            $this->assertTrue($membership->roles()->where('slug', $role)->exists());

            $login = $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'UndSurf2026!',
            ]);
            $login->assertOk();
            $this->assertNotEmpty($login->json('token'));
            $this->assertSame($role, $login->json('user.role'));
        }
    }

    public function test_seeder_is_idempotent_and_handles_soft_deleted_users_and_custom_password(): void
    {
        $oldTenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Tenant Anterior']);
        $targetTenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Tenant Objetivo']);

        Connector::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $targetTenant->id,
            'label' => 'connector-target',
            'status' => Connector::STATUS_ONLINE,
            'connector_token_hash' => hash('sha256', 'tok-target-1'),
            'last_seen_at' => now(),
        ]);

        // Pre-seed en otro tenant y soft-delete de otro usuario
        $existingDriller = User::create([
            'name' => 'Old Driller',
            'email' => 'perforista.test@undsurf.com',
            'password' => 'OldSecret123!',
            'role' => UserRole::Driller,
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $existingDriller->id,
            'tenant_id' => $oldTenant->id,
            'role' => 'driller',
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $deletedSupervisor = User::create([
            'name' => 'Deleted Sup',
            'email' => 'supervisor.test@undsurf.com',
            'password' => 'OldSecret123!',
            'role' => UserRole::Supervisor,
            'active' => false,
        ]);
        $deletedSupervisor->delete();

        putenv('UNDSURF_SEED_PASSWORD=ClaveCustomProd2026!');
        $_ENV['UNDSURF_SEED_PASSWORD'] = 'ClaveCustomProd2026!';
        $_SERVER['UNDSURF_SEED_PASSWORD'] = 'ClaveCustomProd2026!';

        try {
            $this->seed(TenantRolesUsersSeeder::class);
            $this->seed(TenantRolesUsersSeeder::class);

            $driller = User::where('email', 'perforista.test@undsurf.com')->firstOrFail();
            $this->assertSame($targetTenant->id, $driller->activeMembership()?->tenant_id);
            $this->assertSame(1, Membership::where('user_id', $driller->id)->where('status', Membership::STATUS_ACTIVE)->count());

            $sup = User::where('email', 'supervisor.test@undsurf.com')->firstOrFail();
            $this->assertFalse($sup->trashed());
            $this->assertTrue($sup->active);

            $this->postJson('/api/auth/login', [
                'email' => 'supervisor.test@undsurf.com',
                'password' => 'ClaveCustomProd2026!',
            ])->assertOk();
        } finally {
            putenv('UNDSURF_SEED_PASSWORD');
            unset($_ENV['UNDSURF_SEED_PASSWORD'], $_SERVER['UNDSURF_SEED_PASSWORD']);
        }
    }

    public function test_seeder_rejects_short_password_and_falls_back_to_active_membership_tenant(): void
    {
        $tenantA = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Tenant Sin Membresia']);
        $tenantB = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Tenant Con Membresia Activa']);

        $existingUser = User::create([
            'name' => 'Operador Existente',
            'email' => 'existente@undsurf.com',
            'password' => 'Secret1234!',
            'role' => UserRole::Supervisor,
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $existingUser->id,
            'tenant_id' => $tenantB->id,
            'role' => 'supervisor',
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $this->seed(TenantRolesUsersSeeder::class);

        $admin = User::where('email', 'admin.test@undsurf.com')->firstOrFail();
        $this->assertSame($tenantB->id, $admin->activeMembership()?->tenant_id);
        $this->assertNotSame($tenantA->id, $admin->activeMembership()?->tenant_id);

        putenv('UNDSURF_SEED_PASSWORD=corta');
        $_ENV['UNDSURF_SEED_PASSWORD'] = 'corta';
        $_SERVER['UNDSURF_SEED_PASSWORD'] = 'corta';

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->seed(TenantRolesUsersSeeder::class);
        } finally {
            putenv('UNDSURF_SEED_PASSWORD');
            unset($_ENV['UNDSURF_SEED_PASSWORD'], $_SERVER['UNDSURF_SEED_PASSWORD']);
        }
    }

    public function test_seeder_generates_non_hardcoded_password_in_production_when_env_omitted(): void
    {
        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Prod Tenant']);

        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', [
                '--class' => TenantRolesUsersSeeder::class,
                '--force' => true,
            ])->assertExitCode(0);

            $admin = User::where('email', 'admin.test@undsurf.com')->firstOrFail();
            $this->assertSame($tenant->id, $admin->activeMembership()?->tenant_id);

            $this->postJson('/api/auth/login', [
                'email' => 'admin.test@undsurf.com',
                'password' => 'UndSurf2026!',
            ])->assertStatus(422);
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }
}
