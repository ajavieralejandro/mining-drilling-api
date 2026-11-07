<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRoleAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_non_super_admin_cannot_access_admin_endpoints(): void
    {
        $regularUser = User::factory()->create(['is_super_admin' => false]);
        Sanctum::actingAs($regularUser);

        $response = $this->getJson('/api/admin/permissions');
        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $response = $this->getJson('/api/admin/roles');
        $response->assertStatus(403);
    }

    public function test_super_admin_can_list_permissions_and_modules(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/admin/permissions');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'module', 'description'],
                ],
                'modules',
                'total',
            ]);

        $this->assertGreaterThan(10, $response->json('total'));
        $this->assertArrayHasKey('pozos', $response->json('modules'));
    }

    public function test_super_admin_can_create_new_role_and_assign_permissions(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $permIds = Permission::whereIn('name', ['progress.view', 'progress.create'])->pluck('id')->all();

        $payload = [
            'name' => 'Perforista Especializado',
            'slug' => 'specialized_driller',
            'description' => 'Operador con permisos ampliados de avance',
            'permission_ids' => $permIds,
        ];

        $response = $this->postJson('/api/admin/roles', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'specialized_driller')
            ->assertJsonCount(2, 'data.permissions');

        $this->assertDatabaseHas('roles', [
            'slug' => 'specialized_driller',
            'is_system' => false,
        ]);
    }

    public function test_cannot_delete_system_role(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        $response = $this->deleteJson("/api/admin/roles/{$adminRole->id}");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'PROTECTED_SYSTEM_ROLE');

        $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
    }

    public function test_super_admin_can_update_and_delete_custom_role(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $role = Role::create([
            'name' => 'Auditor Temporal',
            'slug' => 'temp_auditor',
            'description' => 'Rol de auditoría temporal',
            'is_system' => false,
        ]);

        $auditPerm = Permission::where('name', 'audit.view')->firstOrFail();
        $updateResponse = $this->putJson("/api/admin/roles/{$role->id}", [
            'name' => 'Auditor Senior',
            'permission_ids' => [$auditPerm->id],
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.name', 'Auditor Senior')
            ->assertJsonCount(1, 'data.permissions');

        $deleteResponse = $this->deleteJson("/api/admin/roles/{$role->id}");
        $deleteResponse->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_tenant_user_groups_crud_and_permission_inheritance(): void
    {
        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Sur']);
        $adminUser = User::factory()->create(['role' => 'admin']);
        $operatorUser = User::factory()->create(['role' => 'helper']);

        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $adminUser->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'status' => Membership::STATUS_ACTIVE,
        ]);

        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $operatorUser->id,
            'tenant_id' => $tenant->id,
            'role' => 'helper',
            'status' => Membership::STATUS_ACTIVE,
        ]);

        // El ayudante inicialmente NO tiene permiso de crear avances
        $this->assertFalse($operatorUser->hasPermission('progress.create', $tenant->id));

        // El admin crea un grupo con rol de perforista
        Sanctum::actingAs($adminUser);
        $drillerRole = Role::where('slug', 'driller')->firstOrFail();

        $response = $this->postJson('/api/tenant/groups', [
            'name' => 'Cuadrilla de Refuerzo Noche',
            'description' => 'Ayudantes habilitados como operadores de turno',
            'user_ids' => [$operatorUser->id],
            'role_ids' => [$drillerRole->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Cuadrilla de Refuerzo Noche');

        $groupId = $response->json('data.id');

        // Ahora el operador hereda el permiso progress.create a través del grupo
        $this->assertTrue($operatorUser->fresh()->hasPermission('progress.create', $tenant->id));

        // Listar grupos
        $listResponse = $this->getJson('/api/tenant/groups');
        $listResponse->assertOk()
            ->assertJsonCount(1, 'data');

        // Actualizar grupo
        $putResponse = $this->putJson("/api/tenant/groups/{$groupId}", [
            'name' => 'Cuadrilla Noche Modificada',
        ]);
        $putResponse->assertOk()
            ->assertJsonPath('data.name', 'Cuadrilla Noche Modificada');

        // Eliminar grupo
        $delResponse = $this->deleteJson("/api/tenant/groups/{$groupId}");
        $delResponse->assertOk();

        // Tras eliminar el grupo, el operador ya no tiene progress.create
        $this->assertFalse($operatorUser->fresh()->hasPermission('progress.create', $tenant->id));
    }

    public function test_auth_me_returns_permissions_and_super_admin_status(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'super@app.test',
            'is_super_admin' => true,
        ]);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'is_super_admin',
                    'permissions',
                ],
            ]);

        $this->assertContains('roles.manage', $response->json('data.permissions'));
    }
}
