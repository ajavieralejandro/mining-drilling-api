<?php

namespace Tests\Feature;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMine;
use App\Models\TenantProject;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantProjectsMinesHolesTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithUsers(): array
    {
        $this->seed(RbacSeeder::class);

        $tenant = Tenant::create([
            'id' => (string) Str::ulid(),
            'name' => 'Minera UndSurf Test',
        ]);

        $admin = $this->makeTenantUser($tenant, 'admin', UserRole::Admin, 'admin.struct@test.com');
        $supervisor = $this->makeTenantUser($tenant, 'supervisor', UserRole::Supervisor, 'sup.struct@test.com');
        $driller = $this->makeTenantUser($tenant, 'driller', UserRole::Driller, 'driller.struct@test.com');

        return [$tenant, $admin, $supervisor, $driller];
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

    public function test_admin_can_rename_organization_and_manage_projects_mines_holes_hierarchy(): void
    {
        [$tenant, $admin, $supervisor, $driller] = $this->createTenantWithUsers();

        Sanctum::actingAs($admin);

        // 1. Renombrar organización (Tenant)
        $this->getJson('/api/tenant')
            ->assertOk()
            ->assertJsonPath('data.name', 'Minera UndSurf Test');

        $this->patchJson('/api/tenant', ['name' => 'Minera Cordillera Real'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Minera Cordillera Real');

        // 2. Crear Proyectos A y B
        $projAResp = $this->postJson('/api/tenant/projects', [
            'code' => 'PRJ-NORTE',
            'name' => 'Exploración Zona Norte',
            'description' => 'Campaña DDH 2026',
        ])->assertCreated();

        $projectAId = $projAResp->json('data.id');

        $projBResp = $this->postJson('/api/tenant/projects', [
            'code' => 'PRJ-SUR',
            'name' => 'Exploración Zona Sur',
        ])->assertCreated();

        $projectBId = $projBResp->json('data.id');

        // 3. Crear Mina en Proyecto A, renombrarla y reasignarla a Proyecto B
        $mineResp = $this->postJson('/api/tenant/mines', [
            'project_id' => $projectAId,
            'code' => 'MIN-01',
            'name' => 'Mina Inicial Demo',
            'location' => 'Nivel 450',
        ])->assertCreated()
            ->assertJsonPath('data.project.id', $projectAId);

        $mineId = $mineResp->json('data.id');

        $mine2Resp = $this->postJson('/api/tenant/mines', [
            'project_id' => $projectBId,
            'code' => 'MIN-02',
            'name' => 'Mina Secundaria',
        ])->assertCreated();

        $mine2Id = $mine2Resp->json('data.id');

        $this->patchJson("/api/tenant/mines/{$mineId}", [
            'name' => 'Mina San Lorenzo',
            'location' => 'Nivel 620 - Sector Este',
            'project_id' => $projectBId,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Mina San Lorenzo')
            ->assertJsonPath('data.project_id', $projectBId)
            ->assertJsonPath('data.project.name', 'Exploración Zona Sur');

        // 4. Vincular pozos a Mina 1 y luego mover uno a Mina 2 sin duplicar
        $this->postJson("/api/tenant/mines/{$mineId}/holes", [
            'hole_id' => '1001',
            'hole_code' => 'DDH-1001',
            'status' => 'in_progress',
        ])->assertCreated();

        $this->postJson("/api/tenant/mines/{$mineId}/holes", [
            'holes' => [
                ['hole_id' => '1002', 'hole_code' => 'DDH-1002'],
                ['hole_id' => '1003', 'hole_code' => 'DDH-1003'],
            ],
        ])->assertOk()
            ->assertJsonPath('total', 2);

        // Mover pozo 1003 a Mina 2
        $this->postJson("/api/tenant/mines/{$mine2Id}/holes", [
            'hole_id' => '1003',
            'hole_code' => 'DDH-1003',
        ])->assertOk();

        $this->getJson("/api/tenant/mines/{$mineId}/holes")
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->getJson("/api/tenant/mines/{$mine2Id}/holes")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.hole_id', '1003');

        // 5. Consultar árbol completo Proyectos -> Minas -> Pozos
        $this->getJson('/api/tenant/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.tenant.name', 'Minera Cordillera Real')
            ->assertJsonCount(2, 'data.projects');

        // 6. Desvincular pozo y eliminar proyecto B (limpia minas hijas y vínculos)
        $this->deleteJson("/api/tenant/mines/{$mineId}/holes/1002")
            ->assertOk();

        $this->getJson("/api/tenant/mines/{$mineId}/holes")
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->deleteJson("/api/tenant/projects/{$projectBId}")
            ->assertOk();

        $this->getJson("/api/tenant/mines/{$mineId}")
            ->assertNotFound();

        $this->assertSoftDeleted('tenant_projects', ['id' => $projectBId]);
        $this->assertSoftDeleted('tenant_mines', ['id' => $mineId]);
    }

    public function test_rbac_blocks_non_managers_and_allows_custom_role_with_dynamic_mines_manage_permission(): void
    {
        [$tenant, $admin, $supervisor, $driller] = $this->createTenantWithUsers();

        $project = TenantProject::create([
            'tenant_id' => $tenant->id,
            'code' => 'PRJ-RBAC',
            'name' => 'Proyecto RBAC',
            'status' => 'active',
        ]);

        // Driller y Supervisor pueden leer estructura pero NO crear/modificar/renombrar
        Sanctum::actingAs($driller);
        $this->getJson('/api/tenant/hierarchy')->assertOk();
        $this->postJson('/api/tenant/projects', ['name' => 'No Permitido'])->assertForbidden();
        $this->postJson('/api/tenant/mines', ['project_id' => $project->id, 'name' => 'No Permitido'])->assertForbidden();
        $this->patchJson('/api/tenant', ['name' => 'Hijack'])->assertForbidden();

        Sanctum::actingAs($supervisor);
        $this->patchJson('/api/tenant', ['name' => 'Hijack Sup'])->assertForbidden();
        $this->postJson('/api/tenant/mines', ['project_id' => $project->id, 'name' => 'Sup No'])->assertForbidden();

        // Rol dinámico custom con permiso mines.manage puede crear y renombrar minas
        $customUser = $this->makeTenantUser($tenant, 'driller', UserRole::Driller, 'custom.mines@test.com');
        $customRole = Role::create([
            'name' => 'Gestor de Faenas',
            'slug' => 'mine_manager',
            'tenant_id' => $tenant->id,
            'is_system' => false,
        ]);
        $perm = Permission::where('name', 'mines.manage')->firstOrFail();
        $customRole->permissions()->sync([$perm->id]);
        $customUser->activeMembership()->roles()->sync([$customRole->id]);

        Sanctum::actingAs($customUser);
        $created = $this->postJson('/api/tenant/mines', [
            'project_id' => $project->id,
            'name' => 'Mina Dinámica Custom',
        ])->assertCreated();

        $mineId = $created->json('data.id');
        $this->patchJson("/api/tenant/mines/{$mineId}", [
            'name' => 'Mina Renombrada por Rol Dinámico',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Mina Renombrada por Rol Dinámico');
    }

    public function test_cross_tenant_isolation_for_projects_mines_and_holes(): void
    {
        [$tenantA, $adminA] = $this->createTenantWithUsers();

        $tenantB = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Competidora B']);
        $adminB = $this->makeTenantUser($tenantB, 'admin', UserRole::Admin, 'admin.b@test.com');

        $projectA = TenantProject::create([
            'tenant_id' => $tenantA->id,
            'code' => 'PA-01',
            'name' => 'Proyecto Secreto A',
        ]);
        $mineA = TenantMine::create([
            'tenant_id' => $tenantA->id,
            'project_id' => $projectA->id,
            'code' => 'MA-01',
            'name' => 'Mina A',
        ]);

        Sanctum::actingAs($adminB);

        $this->getJson("/api/tenant/projects/{$projectA->id}")->assertNotFound();
        $this->patchJson("/api/tenant/projects/{$projectA->id}", ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson("/api/tenant/mines/{$mineA->id}")->assertNotFound();
        $this->postJson('/api/tenant/mines', [
            'project_id' => $projectA->id,
            'name' => 'Mina Cruzada',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('project_id');
    }

    public function test_tenant_holes_endpoint_enriches_and_filters_by_mine_and_project(): void
    {
        [$tenant, $admin] = $this->createTenantWithUsers();

        $project = TenantProject::create([
            'tenant_id' => $tenant->id,
            'code' => 'PRJ-GW',
            'name' => 'Proyecto Gateway',
        ]);
        $mine = TenantMine::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'code' => 'MIN-GW',
            'name' => 'Mina Real Sur',
            'location' => 'Nivel 500',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/tenant/mines/{$mine->id}/holes", [
            'hole_id' => '1001',
            'hole_code' => 'H-1001',
        ])->assertCreated();

        $mockRepo = new class implements TenantHoleRepositoryInterface
        {
            public function list(\App\Support\TenantContext $context, string $correlationId, int $limit = 10): array
            {
                return [
                    'items' => [
                        ['id' => '1001', 'code' => 'H-1001', 'status' => 'in_progress'],
                        ['id' => '1002', 'code' => 'H-1002', 'status' => 'in_progress'],
                    ],
                    'origin_id' => 'orig-test',
                    'request_id' => 'req-1',
                    'correlation_id' => $correlationId,
                ];
            }

            public function find(\App\Support\TenantContext $context, string $correlationId, string $holeId): array
            {
                return [
                    'item' => ['id' => '1001', 'code' => 'H-1001', 'status' => 'in_progress'],
                    'origin_id' => 'orig-test',
                    'request_id' => 'req-2',
                    'correlation_id' => $correlationId,
                ];
            }
        };

        $this->app->instance(TenantHoleRepositoryInterface::class, $mockRepo);

        $this->getJson('/api/tenant/holes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.mine.name', 'Mina Real Sur')
            ->assertJsonPath('data.0.project.name', 'Proyecto Gateway');

        $this->getJson("/api/tenant/holes?mine_id={$mine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '1001');

        $this->getJson('/api/tenant/holes/1001')
            ->assertOk()
            ->assertJsonPath('data.mine.id', $mine->id)
            ->assertJsonPath('data.project.id', $project->id);
    }
}
