<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantRolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function createTenant(string $name = 'Minera UndSurf Test'): Tenant
    {
        return Tenant::create([
            'id' => (string) Str::ulid(),
            'name' => $name,
        ]);
    }

    private function makeTenantUser(Tenant $tenant, string $roleSlug, UserRole $userRole, string $email): User
    {
        $user = User::create([
            'name' => 'User '.$roleSlug,
            'email' => $email,
            'password' => Hash::make('UndSurf2026!'),
            'role' => $userRole,
            'active' => true,
        ]);

        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $roleSlug,
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $email,
        ]);

        $systemRole = Role::whereNull('tenant_id')->where('slug', $roleSlug)->first();
        if ($systemRole) {
            $membership->roles()->sync([$systemRole->id]);
        }

        return $user;
    }

    public function test_admin_can_list_permissions_catalog(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant/permissions');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'module', 'description'],
                ],
                'modules',
                'total',
            ]);

        $this->assertGreaterThan(0, $response->json('total'));
        $this->assertArrayHasKey('roles', $response->json('modules'));
    }

    public function test_admin_can_list_roles_including_system_and_tenant_roles(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        // Crear un rol personalizado para este tenant
        Role::create([
            'name' => 'Jefe de Turno Noche',
            'slug' => 'jefe-turno-noche',
            'tenant_id' => $tenant->id,
            'description' => 'Supervisa cuadrillas nocturnas',
            'is_system' => false,
        ]);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant/roles');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'is_system', 'permissions'],
                ],
                'total',
            ]);

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        // Contiene roles del sistema
        $this->assertContains('admin', $slugs);
        $this->assertContains('driller', $slugs);
        $this->assertContains('geologist', $slugs);
        // Contiene el rol personalizado del tenant
        $this->assertContains('jefe-turno-noche', $slugs);
    }

    public function test_admin_can_create_custom_role_with_permissions(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        $perm1 = Permission::where('name', 'drill_holes.view_all')->firstOrFail();
        $perm2 = Permission::where('name', 'progress.create')->firstOrFail();

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/tenant/roles', [
                'name' => 'Coordinador de Sondajes',
                'slug' => 'coordinador-sondajes',
                'description' => 'Coordina programas de perforación',
                'permission_ids' => [$perm1->id, $perm2->id],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Coordinador de Sondajes')
            ->assertJsonPath('data.slug', 'coordinador-sondajes')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.tenant_id', $tenant->id);

        $roleId = $response->json('data.id');
        $createdRole = Role::with('permissions')->findOrFail($roleId);
        $this->assertEquals(2, $createdRole->permissions->count());
        $this->assertTrue($createdRole->hasPermission('drill_holes.view_all'));
        $this->assertTrue($createdRole->hasPermission('progress.create'));
    }

    public function test_cannot_create_custom_role_with_slug_colliding_with_system_role(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/tenant/roles', [
                'name' => 'Otro Perforista',
                'slug' => 'driller', // Colisión con rol base del sistema
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_admin_can_update_custom_role_permissions(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        $customRole = Role::create([
            'name' => 'Inspector Técnico',
            'slug' => 'inspector-tecnico',
            'tenant_id' => $tenant->id,
            'is_system' => false,
        ]);

        $perm = Permission::where('name', 'audit.view')->firstOrFail();

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/tenant/roles/{$customRole->id}", [
                'name' => 'Inspector Técnico Senior',
                'permission_ids' => [$perm->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Inspector Técnico Senior');

        $customRole->refresh();
        $this->assertTrue($customRole->hasPermission('audit.view'));
    }

    public function test_admin_cannot_modify_or_delete_system_roles(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        $drillerSystemRole = Role::where('slug', 'driller')->firstOrFail();

        // Intento de modificar rol del sistema
        $updateRes = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/tenant/roles/{$drillerSystemRole->id}", [
                'name' => 'Nombre Modificado Prohibido',
            ]);

        $updateRes->assertStatus(422)
            ->assertJsonPath('error.code', 'PROTECTED_SYSTEM_ROLE');

        // Intento de eliminar rol del sistema
        $deleteRes = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson("/api/tenant/roles/{$drillerSystemRole->id}");

        $deleteRes->assertStatus(422)
            ->assertJsonPath('error.code', 'PROTECTED_SYSTEM_ROLE');
    }

    public function test_admin_can_delete_custom_role(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        $customRole = Role::create([
            'name' => 'Rol a Eliminar',
            'slug' => 'rol-a-eliminar',
            'tenant_id' => $tenant->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson("/api/tenant/roles/{$customRole->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $customRole->id]);
    }

    public function test_cross_tenant_isolation_cannot_access_or_modify_other_tenant_roles(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        $adminA = $this->makeTenantUser($tenantA, 'admin', UserRole::Admin, 'admin.a@test.com');

        $roleOfB = Role::create([
            'name' => 'Rol Exclusivo Tenant B',
            'slug' => 'rol-b',
            'tenant_id' => $tenantB->id,
            'is_system' => false,
        ]);

        // Admin A intenta consultar rol de B -> 404
        $showRes = $this->actingAs($adminA)
            ->withHeader('X-Tenant-ID', $tenantA->id)
            ->getJson("/api/tenant/roles/{$roleOfB->id}");
        $showRes->assertNotFound();

        // Admin A intenta modificar rol de B -> 404
        $patchRes = $this->actingAs($adminA)
            ->withHeader('X-Tenant-ID', $tenantA->id)
            ->patchJson("/api/tenant/roles/{$roleOfB->id}", ['name' => 'Hack']);
        $patchRes->assertNotFound();

        // Admin A intenta eliminar rol de B -> 404
        $deleteRes = $this->actingAs($adminA)
            ->withHeader('X-Tenant-ID', $tenantA->id)
            ->deleteJson("/api/tenant/roles/{$roleOfB->id}");
        $deleteRes->assertNotFound();
    }

    public function test_user_without_roles_manage_is_rejected_403(): void
    {
        $tenant = $this->createTenant();
        $driller = $this->makeTenantUser($tenant, 'driller', UserRole::Driller, 'driller.roles@test.com');

        $response = $this->actingAs($driller)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/tenant/roles', [
                'name' => 'Rol Ilegal',
                'slug' => 'rol-ilegal',
            ]);

        $response->assertForbidden();
    }

    public function test_custom_role_can_be_assigned_to_personnel_and_grants_permissions(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.roles@test.com');

        // Crear rol personalizado con permiso mines.manage
        $minesManagePerm = Permission::where('name', 'mines.manage')->firstOrFail();
        $role = Role::create([
            'name' => 'Coordinador Minero',
            'slug' => 'coordinador-minero',
            'tenant_id' => $tenant->id,
            'is_system' => false,
        ]);
        $role->permissions()->sync([$minesManagePerm->id]);

        // Admin da de alta un integrante con el rol personalizado
        $res = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/tenant/personnel', [
                'name' => 'Ingeniero Minero',
                'email' => 'ingeniero@minera.test',
                'role' => 'coordinador-minero',
            ]);

        $res->assertCreated();
        $createdUserId = $res->json('data.id');

        // Verificar que el usuario creado hereda el permiso mines.manage
        $createdUser = User::findOrFail($createdUserId);
        $this->assertTrue($createdUser->hasPermission('mines.manage', $tenant->id));
        $this->assertFalse($createdUser->hasPermission('projects.manage', $tenant->id));
    }
}
