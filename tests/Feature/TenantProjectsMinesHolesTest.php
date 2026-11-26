<?php

namespace Tests\Feature;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\OrganizationStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Gateway\GatewayOrganizationStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Projects, mines and hole links live in each company's database, reached
 * through its connector. These tests run the HTTP flow against an in-memory
 * stand-in of those databases (one per tenant) and check the gateway
 * mapping against persisted connector commands.
 */
class TenantProjectsMinesHolesTest extends TestCase
{
    use RefreshDatabase;

    private FakeOrganizationStore $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new FakeOrganizationStore;
        $this->app->instance(OrganizationStore::class, $this->records);
        // La jerarquía de un perforista se recorta a su cuadrilla (plan 15, C3).
        $this->app->instance(HoleRecordsStore::class, new FakeHoleRecordsStore);
    }

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
        [$tenant, $admin] = $this->createTenantWithUsers();

        Sanctum::actingAs($admin);

        // 1. Renombrar organización (Tenant): sigue siendo dato de UndSurf
        $this->getJson('/api/tenant')
            ->assertOk()
            ->assertJsonPath('data.name', 'Minera UndSurf Test');

        $this->patchJson('/api/tenant', ['name' => 'Minera Cordillera Real'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Minera Cordillera Real');

        // 2. Crear Proyectos A y B (el código se normaliza)
        $projectAId = $this->postJson('/api/tenant/projects', [
            'code' => ' prj-norte ',
            'name' => 'Exploración Zona Norte',
            'description' => 'Campaña DDH 2026',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'PRJ-NORTE')
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.created_by', $admin->id)
            ->json('data.id');

        $projectBId = $this->postJson('/api/tenant/projects', [
            'code' => 'PRJ-SUR',
            'name' => 'Exploración Zona Sur',
        ])->assertCreated()->json('data.id');

        // 3. Crear Mina en Proyecto A, renombrarla y reasignarla a Proyecto B
        $mineId = $this->postJson('/api/tenant/mines', [
            'project_id' => $projectAId,
            'code' => 'MIN-01',
            'name' => 'Mina Inicial Demo',
            'location' => 'Nivel 450',
        ])->assertCreated()
            ->assertJsonPath('data.project.id', $projectAId)
            ->json('data.id');

        $mine2Id = $this->postJson('/api/tenant/mines', [
            'project_id' => $projectBId,
            'code' => 'MIN-02',
            'name' => 'Mina Secundaria',
        ])->assertCreated()->json('data.id');

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
            'status' => 'in_progress',
        ])->assertCreated()
            ->assertJsonPath('data.hole_id', '1001')
            ->assertJsonPath('data.hole_code', 'H-1001')
            ->assertJsonPath('data.mine.id', $mineId);

        $this->postJson("/api/tenant/mines/{$mineId}/holes", [
            'holes' => [
                ['hole_id' => '1002'],
                ['hole_id' => '1003'],
            ],
        ])->assertOk()
            ->assertJsonPath('total', 2);

        // Mover pozo 1003 a Mina 2: ya tenía mina, así que no es un alta
        $this->postJson("/api/tenant/mines/{$mine2Id}/holes", ['hole_id' => '1003'])
            ->assertOk();

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
            ->assertJsonCount(2, 'data.projects')
            ->assertJsonPath('data.projects.1.name', 'Exploración Zona Sur')
            ->assertJsonPath('data.projects.1.mines_count', 2)
            ->assertJsonPath('data.projects.1.holes_count', 3);

        $this->getJson('/api/tenant/projects?include=mines')
            ->assertOk()
            ->assertJsonCount(2, 'data.1.mines');

        // 6. Desvincular pozo y eliminar proyecto B (limpia minas hijas y vínculos)
        $this->deleteJson("/api/tenant/mines/{$mineId}/holes/1002")->assertOk();
        $this->deleteJson("/api/tenant/mines/{$mineId}/holes/1002")->assertNotFound();

        $this->getJson("/api/tenant/mines/{$mineId}/holes")
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->deleteJson("/api/tenant/projects/{$projectBId}")->assertOk();

        $this->getJson("/api/tenant/mines/{$mineId}")->assertNotFound();
        $this->getJson("/api/tenant/projects/{$projectBId}")->assertNotFound();
        $this->assertSame([], $this->records->db[$tenant->id]['links']);

        // Nada de esto quedó en las tablas centrales de UndSurf.
        foreach (['tenant_projects', 'tenant_mines', 'tenant_mine_holes'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_connector_rules_reach_the_user_as_field_errors(): void
    {
        [, $admin] = $this->createTenantWithUsers();
        Sanctum::actingAs($admin);

        $projectId = $this->postJson('/api/tenant/projects', ['code' => 'PRJ-1', 'name' => 'Proyecto Uno'])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/tenant/projects', ['code' => 'prj-1', 'name' => 'Proyecto Repetido'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/tenant/mines', ['project_id' => 'prj_inexistente', 'name' => 'Mina Huérfana'])
            ->assertStatus(422)->assertJsonValidationErrors('project_id');

        $mineId = $this->postJson('/api/tenant/mines', ['project_id' => $projectId, 'name' => 'Mina Uno'])
            ->assertCreated()->json('data.id');

        $this->records->missingHoles = ['9999'];
        $this->postJson("/api/tenant/mines/{$mineId}/holes", ['hole_id' => '9999'])
            ->assertStatus(422)->assertJsonValidationErrors('hole_id');
        $this->postJson("/api/tenant/mines/{$mineId}/holes", ['holes' => [['hole_id' => '1001'], ['hole_id' => '9999']]])
            ->assertStatus(422)->assertJsonValidationErrors('holes.1.hole_id');

        $this->postJson('/api/tenant/mines/min_inexistente/holes', ['hole_id' => '1001'])->assertNotFound();
        $this->patchJson('/api/tenant/projects/prj_inexistente', ['name' => 'Nada'])->assertNotFound();
    }

    public function test_rbac_blocks_non_managers_and_allows_custom_role_with_dynamic_mines_manage_permission(): void
    {
        [$tenant, $admin, $supervisor, $driller] = $this->createTenantWithUsers();

        Sanctum::actingAs($admin);
        $projectId = $this->postJson('/api/tenant/projects', ['code' => 'PRJ-RBAC', 'name' => 'Proyecto RBAC'])
            ->assertCreated()->json('data.id');

        // Driller y Supervisor pueden leer estructura pero NO crear/modificar/renombrar
        Sanctum::actingAs($driller);
        $this->getJson('/api/tenant/hierarchy')->assertOk();
        $this->postJson('/api/tenant/projects', ['name' => 'No Permitido'])->assertForbidden();
        $this->postJson('/api/tenant/mines', ['project_id' => $projectId, 'name' => 'No Permitido'])->assertForbidden();
        $this->patchJson('/api/tenant', ['name' => 'Hijack'])->assertForbidden();

        Sanctum::actingAs($supervisor);
        $this->patchJson('/api/tenant', ['name' => 'Hijack Sup'])->assertForbidden();
        $this->postJson('/api/tenant/mines', ['project_id' => $projectId, 'name' => 'Sup No'])->assertForbidden();

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
        $mineId = $this->postJson('/api/tenant/mines', [
            'project_id' => $projectId,
            'name' => 'Mina Dinámica Custom',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/tenant/mines/{$mineId}", [
            'name' => 'Mina Renombrada por Rol Dinámico',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Mina Renombrada por Rol Dinámico');

        $writes = array_filter($this->records->calls, fn ($c) => str_ends_with($c['op'], '.upsert@1'));
        $this->assertCount(3, $writes, 'Los rechazos por permiso no llegan al conector.');
    }

    public function test_cross_tenant_isolation_for_projects_mines_and_holes(): void
    {
        [$tenantA, $adminA] = $this->createTenantWithUsers();

        $tenantB = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Competidora B']);
        $adminB = $this->makeTenantUser($tenantB, 'admin', UserRole::Admin, 'admin.b@test.com');

        Sanctum::actingAs($adminA);
        $projectAId = $this->postJson('/api/tenant/projects', ['code' => 'PA-01', 'name' => 'Proyecto Secreto A'])
            ->assertCreated()->json('data.id');
        $mineAId = $this->postJson('/api/tenant/mines', ['project_id' => $projectAId, 'code' => 'MA-01', 'name' => 'Mina A'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($adminB);

        $this->getJson("/api/tenant/projects/{$projectAId}")->assertNotFound();
        $this->patchJson("/api/tenant/projects/{$projectAId}", ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson("/api/tenant/mines/{$mineAId}")->assertNotFound();
        $this->postJson('/api/tenant/mines', [
            'project_id' => $projectAId,
            'name' => 'Mina Cruzada',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('project_id');

        // B usa el mismo código sin chocar: cada empresa tiene su propia base.
        $this->postJson('/api/tenant/projects', ['code' => 'PA-01', 'name' => 'Proyecto de B'])->assertCreated();

        $this->assertCount(1, $this->records->db[$tenantA->id]['projects']);
        $this->assertCount(1, $this->records->db[$tenantB->id]['projects']);
        $this->assertCount(1, $this->records->db[$tenantA->id]['mines']);
    }

    public function test_tenant_holes_endpoint_enriches_and_filters_by_mine_and_project(): void
    {
        [, $admin] = $this->createTenantWithUsers();

        Sanctum::actingAs($admin);
        $projectId = $this->postJson('/api/tenant/projects', ['code' => 'PRJ-GW', 'name' => 'Proyecto Gateway'])
            ->assertCreated()->json('data.id');
        $mineId = $this->postJson('/api/tenant/mines', [
            'project_id' => $projectId,
            'code' => 'MIN-GW',
            'name' => 'Mina Real Sur',
            'location' => 'Nivel 500',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/tenant/mines/{$mineId}/holes", ['hole_id' => '1001'])->assertCreated();

        $this->app->instance(TenantHoleRepositoryInterface::class, $this->holesRepository());

        $this->getJson('/api/tenant/holes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.mine.name', 'Mina Real Sur')
            ->assertJsonPath('data.0.project.name', 'Proyecto Gateway')
            ->assertJsonMissingPath('data.1.mine');

        $this->getJson("/api/tenant/holes?mine_id={$mineId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '1001');

        $this->getJson("/api/tenant/holes?project_id={$projectId}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/tenant/holes/1001')
            ->assertOk()
            ->assertJsonPath('data.mine.id', $mineId)
            ->assertJsonPath('data.project.id', $projectId);
    }

    // ---- gateway mapping -------------------------------------------------

    public function test_gateway_sends_only_changed_fields_and_purges_the_command(): void
    {
        [$tenant, $admin] = $this->createTenantWithUsers();
        $command = $this->stubConnector($tenant, 'projects.upsert@1', [
            'outcome' => 'ok',
            'item' => ['id' => 'prj_1', 'name' => 'Proyecto', 'status' => 'active', 'code' => null],
        ], expectPayload: fn (array $payload) => $payload['id'] === 'prj_1'
            && json_encode($payload['fields']) === '{"description":null}'
            && $payload['user_id'] === (string) $admin->id);

        $row = $this->gateway()->saveProject($this->context($tenant, $admin), 'prj_1', ['description' => null], 'cor_test');

        $this->assertSame('prj_1', $row['id']);
        $fresh = ConnectorCommand::query()->findOrFail($command->id);
        $this->assertNull($fresh->result_json);
        $this->assertSame([], $fresh->payload_json);
    }

    public function test_gateway_maps_not_found_to_null(): void
    {
        [$tenant, $admin] = $this->createTenantWithUsers();
        $this->stubConnector($tenant, 'mines.get@1', null, ConnectorCommand::STATUS_FAILED, 'NOT_FOUND');

        $this->assertNull($this->gateway()->getMine($this->context($tenant, $admin), 'min_x', 'cor_test'));
    }

    public function test_gateway_maps_a_rejection_to_a_field_error(): void
    {
        [$tenant, $admin] = $this->createTenantWithUsers();
        $this->stubConnector($tenant, 'mines.upsert@1', [
            'outcome' => 'rejected',
            'violation' => ['field' => 'code', 'message' => 'Ya existe una mina con ese código en la organización.'],
        ]);

        try {
            $this->gateway()->saveMine($this->context($tenant, $admin), null, ['name' => 'M', 'project_id' => 'p'], 'cor_test');
            $this->fail('A rejected write must raise a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
    }

    public function test_hole_list_survives_a_connector_without_the_lookup_operation(): void
    {
        [$tenant, $admin] = $this->createTenantWithUsers();
        $this->app->forgetInstance(OrganizationStore::class);
        $this->app->bind(OrganizationStore::class, GatewayOrganizationStore::class);
        $this->app->instance(TenantHoleRepositoryInterface::class, $this->holesRepository());
        $this->stubConnector($tenant, 'mine_holes.lookup@1', null, ConnectorCommand::STATUS_FAILED, 'UNSUPPORTED_OP');

        Sanctum::actingAs($admin);
        $this->getJson('/api/tenant/holes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.mine');
    }

    // ---- helpers ---------------------------------------------------------

    private function holesRepository(): TenantHoleRepositoryInterface
    {
        return new class implements TenantHoleRepositoryInterface
        {
            public function list(TenantContext $context, string $correlationId, int $limit = 10): array
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

            public function find(TenantContext $context, string $correlationId, string $holeId): array
            {
                return [
                    'item' => ['id' => '1001', 'code' => 'H-1001', 'status' => 'in_progress'],
                    'origin_id' => 'orig-test',
                    'request_id' => 'req-2',
                    'correlation_id' => $correlationId,
                ];
            }
        };
    }

    private function gateway(): GatewayOrganizationStore
    {
        return $this->app->make(GatewayOrganizationStore::class);
    }

    private function context(Tenant $tenant, User $user): TenantContext
    {
        $membershipId = (string) Membership::query()->where('user_id', $user->id)->value('id');

        return new TenantContext($tenant->id, $user->id, $membershipId, 'admin');
    }

    /**
     * Persists a command as the connector would have completed it and makes
     * the dispatcher return it, so the read-and-purge path runs for real.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function stubConnector(
        Tenant $tenant,
        string $op,
        ?array $result,
        string $status = ConnectorCommand::STATUS_COMPLETED,
        ?string $errorCode = null,
        ?\Closure $expectPayload = null,
    ): ConnectorCommand {
        $connector = Connector::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'label' => 'lab',
            'status' => 'active',
            'connector_token_hash' => hash('sha256', 'token-'.Str::random(6)),
            'last_seen_at' => now(),
        ]);
        $command = ConnectorCommand::create([
            'id' => (string) Str::ulid(),
            'request_id' => 'req_'.(string) Str::ulid(),
            'correlation_id' => 'cor_test',
            'tenant_id' => $tenant->id,
            'connector_id' => $connector->id,
            'op' => $op,
            'payload_json' => ['sent' => true],
            'status' => $status,
            'result_json' => $result,
            'error_code' => $errorCode,
            'deadline_at' => now()->addMinute(),
            'dispatched_at' => now(),
            'completed_at' => now(),
        ]);

        $dispatcher = \Mockery::mock(CommandDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($c, $o, $payload) => $o === $op && ($expectPayload === null || $expectPayload($payload)))
            ->andReturn($command);
        $dispatcher->shouldReceive('waitForResult')->once()->andReturn($command);
        $this->app->instance(CommandDispatcher::class, $dispatcher);

        return $command;
    }
}
