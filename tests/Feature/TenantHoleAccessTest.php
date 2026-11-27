<?php

namespace Tests\Feature;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\OrganizationStore;
use App\Domain\Repositories\RiskStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan 15, bloque C y B6: el perforista y el ayudante leen solo los pozos de
 * su cuadrilla activa; leer nómina, cuadrillas o avance exige el permiso.
 */
class TenantHoleAccessTest extends TestCase
{
    private const HOLES = ['1001', '1002', '1003'];

    private FakeHoleRecordsStore $records;

    private Tenant $tenant;

    private FakeRiskStore $risks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Norte']);
        $this->records = new FakeHoleRecordsStore('orig-1');
        $this->app->instance(HoleRecordsStore::class, $this->records);
        $this->app->instance(OrganizationStore::class, new FakeOrganizationStore);
        $this->risks = new FakeRiskStore;
        $this->app->instance(RiskStore::class, $this->risks);
        $this->app->instance(TenantHoleRepositoryInterface::class, $this->holes());
    }

    public function test_a_driller_lists_only_the_holes_of_his_active_crew(): void
    {
        $driller = $this->member('driller');
        $this->assign('1002', 'driller', $driller);
        $this->assign('1003', 'driller', $driller, active: false);
        Sanctum::actingAs($driller);

        $this->getJson('/api/tenant/holes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '1002');
        $this->assertContains('assignments.by_user@1', array_column($this->records->calls, 'op'));
    }

    public function test_a_supervisor_lists_every_hole_without_asking_for_assignments(): void
    {
        Sanctum::actingAs($this->member('supervisor'));

        $this->getJson('/api/tenant/holes')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([], $this->records->calls, 'view_all does not read the crew');
    }

    public function test_an_older_connector_falls_back_to_each_holes_crew(): void
    {
        $this->records->supportsAssignmentsByUser = false;
        $helper = $this->member('helper');
        $this->assign('1001', 'helper', $helper);
        Sanctum::actingAs($helper);

        $this->getJson('/api/tenant/holes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '1001');
    }

    public function test_a_driller_cannot_open_a_hole_outside_his_crew(): void
    {
        $driller = $this->member('driller');
        $this->assign('1002', 'driller', $driller);
        Sanctum::actingAs($driller);

        $this->getJson('/api/tenant/holes/1002')->assertOk()->assertJsonPath('data.id', '1002');
        $this->getJson('/api/tenant/holes/1001')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson('/api/tenant/holes/1001/progress')->assertForbidden();
        $this->getJson('/api/tenant/holes/1001/assignments')->assertForbidden();
        $this->getJson('/api/tenant/holes/1001/risks')->assertForbidden();
    }

    public function test_a_role_without_hole_permissions_sees_no_hole(): void
    {
        $role = Role::create(['name' => 'Bodega', 'slug' => 'bodega', 'tenant_id' => $this->tenant->id, 'description' => null, 'is_system' => false]);
        Sanctum::actingAs($this->member('bodega', $role));

        // Su users.role queda en helper, pero con membership los permisos salen solo de
        // sus roles (plan 15, D2'): sin permisos de pozos no lista ninguno.
        $this->getJson('/api/tenant/holes')->assertForbidden();
        $this->getJson('/api/tenant/holes/1001')->assertForbidden();
    }

    public function test_reading_people_and_progress_needs_the_permission_not_just_a_membership(): void
    {
        $helper = $this->member('helper');
        $this->assign('1001', 'helper', $helper);
        Sanctum::actingAs($helper);

        // El ayudante no tiene personnel.view, groups.view ni progress.view (D4).
        $this->getJson('/api/tenant/personnel')->assertForbidden();
        $this->getJson('/api/tenant/groups')->assertForbidden();
        $this->getJson('/api/tenant/holes/1001/progress')->assertForbidden();
        // Sí ve la cuadrilla de su propio pozo (assignments.view).
        $this->getJson('/api/tenant/holes/1001/assignments')->assertOk();
    }

    public function test_the_hierarchy_of_a_driller_keeps_only_the_branch_with_his_hole(): void
    {
        $this->structure();
        $driller = $this->member('driller');
        $this->assign('1003', 'driller', $driller);
        Sanctum::actingAs($driller);

        $projects = $this->getJson('/api/tenant/hierarchy')->assertOk()->json('data.projects');
        $this->assertCount(1, $projects);
        $this->assertSame('Proyecto Sur', $projects[0]['name']);
        $this->assertSame(1, $projects[0]['mines_count']);
        $this->assertSame(1, $projects[0]['holes_count']);
        $this->assertSame(['1003'], array_column($projects[0]['mines'][0]['holes'], 'hole_id'));

        Sanctum::actingAs($this->member('supervisor'));
        $this->assertCount(2, $this->getJson('/api/tenant/hierarchy')->assertOk()->json('data.projects'));
    }

    public function test_the_holes_of_a_mine_are_limited_to_the_crew(): void
    {
        $mines = $this->structure();
        $driller = $this->member('driller');
        $this->assign('1002', 'driller', $driller);
        Sanctum::actingAs($driller);

        $this->getJson("/api/tenant/mines/{$mines['norte']}/holes")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.hole_id', '1002');
    }

    public function test_the_company_risk_list_shows_a_driller_only_his_holes(): void
    {
        $driller = $this->member('driller');
        $this->assign('1001', 'driller', $driller);
        $this->risk('1001', 'high', '2026-11-25T10:00:00Z');
        $this->risk('1002', 'critical', '2026-11-26T10:00:00Z');
        $this->risk('1001', 'low', '2026-11-26T09:00:00Z');
        Sanctum::actingAs($driller);

        $this->getJson('/api/tenant/risks')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('counts.open', 2)
            ->assertJsonPath('counts.open_critical', 0)
            ->assertJsonPath('data.0.level', 'low', 'newest first');

        Sanctum::actingAs($this->member('supervisor'));
        $this->getJson('/api/tenant/risks')->assertOk()->assertJsonPath('pagination.total', 3);
    }

    public function test_the_session_reports_the_role_in_the_organization(): void
    {
        $role = Role::create(['name' => 'Jefe de turno', 'slug' => 'jefe_turno', 'tenant_id' => $this->tenant->id, 'description' => null, 'is_system' => false]);
        Sanctum::actingAs($this->member('jefe_turno', $role));

        // Plan 15, D1': la web elige su árbol de rutas por este rol.
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'jefe_turno')
            ->assertJsonPath('data.system_role', 'helper')
            ->assertJsonPath('data.permissions', []);
    }

    public function test_a_driller_reads_his_assignments_with_the_hole_code(): void
    {
        $driller = $this->member('driller');
        $driller->update(['shift' => 'A']);
        $this->assign('1002', 'driller', $driller);
        $this->assign('1003', 'driller', $driller, active: false);
        Sanctum::actingAs($driller);

        $this->getJson('/api/tenant/me/assignments')
            ->assertOk()
            ->assertJsonPath('data.shift', 'A')
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.hole_id', '1002')
            ->assertJsonPath('data.assignments.0.hole_code', 'H-1002')
            ->assertJsonPath('data.assignments.0.role_in_hole', 'driller');
    }

    public function test_my_progress_lists_only_my_reports_on_my_holes_newest_first(): void
    {
        $driller = $this->member('driller');
        $other = $this->member('driller');
        $this->assign('1001', 'driller', $driller);
        $this->records->seedReport(['hole_id' => '1001', 'user_id' => (string) $driller->id, 'logged_at' => '2026-11-25T10:00:00Z']);
        $this->records->seedReport(['hole_id' => '1001', 'user_id' => (string) $other->id, 'logged_at' => '2026-11-26T10:00:00Z']);
        $this->records->seedReport(['hole_id' => '1001', 'user_id' => (string) $driller->id, 'logged_at' => '2026-11-26T08:00:00Z']);
        $this->records->seedReport(['hole_id' => '1002', 'user_id' => (string) $driller->id, 'logged_at' => '2026-11-26T09:00:00Z']);
        Sanctum::actingAs($driller);

        $data = $this->getJson('/api/tenant/me/progress')->assertOk()->json('data');
        $this->assertSame(['2026-11-26T08:00:00Z', '2026-11-25T10:00:00Z'], array_column($data, 'logged_at'));
        $this->assertSame([$driller->id, $driller->id], array_column($data, 'user_id'));
    }

    public function test_a_helper_reads_his_assignments_but_not_progress(): void
    {
        $helper = $this->member('helper');
        $this->assign('1001', 'helper', $helper);
        Sanctum::actingAs($helper);

        $this->getJson('/api/tenant/me/assignments')->assertOk()->assertJsonCount(1, 'data.assignments');
        $this->getJson('/api/tenant/me/progress')->assertForbidden();
    }

    /**
     * Proyecto Norte (Mina Norte: 1001, 1002) y Proyecto Sur (Mina Sur: 1003), creados por el admin.
     *
     * @return array{norte: string, sur: string}
     */
    private function structure(): array
    {
        Sanctum::actingAs($this->member('admin'));
        $ids = [];
        foreach (['norte' => ['Proyecto Norte', ['1001', '1002']], 'sur' => ['Proyecto Sur', ['1003']]] as $key => [$name, $holes]) {
            $project = $this->postJson('/api/tenant/projects', ['name' => $name])->assertCreated()->json('data.id');
            $mine = $this->postJson('/api/tenant/mines', ['project_id' => $project, 'name' => 'Mina '.ucfirst($key)])->assertCreated()->json('data.id');
            $this->postJson("/api/tenant/mines/{$mine}/holes", ['holes' => array_map(fn ($h) => ['hole_id' => $h], $holes)])->assertSuccessful();
            $ids[$key] = $mine;
        }

        return $ids;
    }

    private function risk(string $holeId, string $level, string $reportedAt): void
    {
        $this->risks->risks[] = [
            'id' => 'rsk_'.count($this->risks->risks), 'hole_id' => $holeId, 'hole_code' => "H-{$holeId}",
            'level' => $level, 'status' => 'open', 'body' => 'Inestabilidad', 'depth_detected' => null,
            'critical_distance' => null, 'recommended_action' => null, 'reported_by' => '1',
            'reported_at' => $reportedAt, 'idempotency_key' => Str::random(12),
        ];
    }

    private function member(string $slug, ?Role $customRole = null): User
    {
        $user = User::create([
            'name' => "Usuario {$slug}",
            'email' => "{$slug}.".Str::random(4).'@minera-norte.test',
            'role' => $customRole ? 'helper' : $slug,
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role' => $slug,
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $user->email,
        ]);
        $role = $customRole ?? Role::whereNull('tenant_id')->where('slug', $slug)->firstOrFail();
        $membership->roles()->sync([$role->id]);

        return $user;
    }

    private function assign(string $holeId, string $role, User $user, bool $active = true): void
    {
        $this->records->assignments[] = [
            'id' => 'asg_'.count($this->records->assignments), 'hole_id' => $holeId, 'user_id' => (string) $user->id,
            'role_in_hole' => $role, 'assigned_by' => null, 'assigned_from' => null, 'assigned_to' => null,
            'active' => $active, 'notes' => null, 'created_at' => '2026-11-26T10:00:00Z',
        ];
    }

    private function holes(): TenantHoleRepositoryInterface
    {
        return new class(self::HOLES) implements TenantHoleRepositoryInterface
        {
            /** @param list<string> $ids */
            public function __construct(private readonly array $ids) {}

            public function list(TenantContext $context, string $correlationId, int $limit = 10): array
            {
                return [
                    'items' => array_map(fn ($id) => ['id' => $id, 'code' => "H-{$id}", 'status' => 'in_progress'], $this->ids),
                    'origin_id' => 'orig-1',
                    'request_id' => 'req-list',
                    'correlation_id' => $correlationId,
                ];
            }

            public function find(TenantContext $context, string $correlationId, string $holeId): array
            {
                if (! in_array($holeId, $this->ids, true)) {
                    throw new GatewayException('NOT_FOUND', 'Connector reported an error', 404);
                }

                return [
                    'item' => ['id' => $holeId, 'code' => "H-{$holeId}", 'status' => 'in_progress'],
                    'origin_id' => 'orig-1',
                    'request_id' => 'req-find',
                    'correlation_id' => $correlationId,
                ];
            }
        };
    }
}
