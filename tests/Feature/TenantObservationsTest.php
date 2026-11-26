<?php

namespace Tests\Feature;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\ObservationStore;
use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Gateway\GatewayObservationStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Typed observation log of a real hole, the company's own observation types
 * and the geometry for the graphic views. All of it lives in the client
 * database; these tests run against in-memory stand-ins and check the
 * gateway mapping against persisted connector commands.
 */
class TenantObservationsTest extends TestCase
{
    use RefreshDatabase;

    private FakeObservationStore $observations;

    private FakeHoleRecordsStore $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->observations = new FakeObservationStore;
        $this->records = new FakeHoleRecordsStore('orig-1');
        $this->app->instance(ObservationStore::class, $this->observations);
        $this->app->instance(HoleRecordsStore::class, $this->records);
    }

    public function test_crew_members_log_typed_observations_stored_in_the_client_database(): void
    {
        $tenant = $this->tenant();
        $driller = $this->user($tenant, 'driller', UserRole::Driller, 'Perforista Juan');
        $this->assignCrew('POZO-01', 'driller', $driller);
        $this->observations->currentDepth['POZO-01'] = '40.00';

        Sanctum::actingAs($driller);
        $this->postJson('/api/tenant/holes/POZO-01/observations', [
            'type_id' => 'obt_geology',
            'body' => '  Zona de falla con arcilla  ',
            'depth' => 32.5,
            'idempotency_key' => 'obs-key-0001',
        ])->assertCreated()
            ->assertJsonPath('data.type.name', 'Geología')
            ->assertJsonPath('data.body', 'Zona de falla con arcilla')
            ->assertJsonPath('data.depth', '32.50')
            ->assertJsonPath('data.user.name', 'Perforista Juan');

        // A retry with the same key is not a second observation.
        $this->postJson('/api/tenant/holes/POZO-01/observations', [
            'type_id' => 'obt_geology',
            'body' => 'Zona de falla con arcilla',
            'depth' => 32.5,
            'idempotency_key' => 'obs-key-0001',
        ])->assertOk();

        $this->getJson('/api/tenant/holes/POZO-01/observations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('can_create', true);

        $this->assertSame(0, DB::table('observations')->count(), 'Nada queda en la tabla central vieja.');
    }

    public function test_only_the_hole_crew_supervisor_or_admin_can_observe(): void
    {
        $tenant = $this->tenant();
        $driller = $this->user($tenant, 'driller', UserRole::Driller, 'Perforista Juan');
        $outsider = $this->user($tenant, 'driller', UserRole::Driller, 'Perforista Luis');
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $this->assignCrew('POZO-01', 'driller', $driller);

        Sanctum::actingAs($outsider);
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload())->assertForbidden();
        // Plan 15, bloque C: fuera de su cuadrilla tampoco lee el libro del pozo.
        $this->getJson('/api/tenant/holes/POZO-01/observations')->assertForbidden();

        Sanctum::actingAs($supervisor);
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload())->assertCreated();

        $this->assertSame(['observations.create@1'], $this->observations->calls,
            'El rechazo por permiso no llega al conector.');
    }

    public function test_connector_rules_reach_the_user_as_field_errors(): void
    {
        $tenant = $this->tenant();
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $this->observations->currentDepth['POZO-01'] = '40.00';
        $this->observations->types['obt_general']['active'] = false;

        Sanctum::actingAs($supervisor);
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload(['type_id' => 'obt_general']))
            ->assertStatus(422)->assertJsonValidationErrors('type_id');
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload(['depth' => 41]))
            ->assertStatus(422)->assertJsonValidationErrors('depth');
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload(['depth' => 1.234]))
            ->assertStatus(422)->assertJsonValidationErrors('depth');
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload(['body' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_admin_manages_the_company_observation_types(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'admin', UserRole::Admin, 'Admin Ana');
        $driller = $this->user($tenant, 'driller', UserRole::Driller, 'Perforista Juan');

        Sanctum::actingAs($admin);
        $this->getJson('/api/tenant/observation-types')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.name', 'Operativa')
            ->assertJsonPath('can_manage', true);

        $typeId = $this->postJson('/api/tenant/observation-types', ['code' => 'ventilacion', 'name' => 'Ventilación', 'sort_order' => 5])
            ->assertCreated()
            ->json('data.id');
        $this->postJson('/api/tenant/observation-types', ['code' => 'ventilacion', 'name' => 'Otra'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/tenant/observation-types', ['code' => 'con espacio', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->patchJson("/api/tenant/observation-types/{$typeId}", ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->patchJson('/api/tenant/observation-types/obt_inexistente', ['name' => 'X'])->assertNotFound();

        $this->getJson('/api/tenant/observation-types')->assertJsonCount(4, 'data');
        $this->getJson('/api/tenant/observation-types?include_inactive=1')->assertJsonCount(5, 'data');

        Sanctum::actingAs($driller);
        $this->getJson('/api/tenant/observation-types?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('can_manage', false);
        $this->postJson('/api/tenant/observation-types', ['code' => 'x', 'name' => 'X'])->assertForbidden();
        $this->patchJson("/api/tenant/observation-types/{$typeId}", ['active' => true])->assertForbidden();
    }

    public function test_geometry_reports_present_and_missing_columns_with_depth_marks(): void
    {
        $tenant = $this->tenant();
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $this->observations->currentDepth['POZO-01'] = '40.00';
        $this->observations->geometry['POZO-01'] = ['azimuth' => '45.00', 'dip' => '-60.00', 'easting' => null];

        Sanctum::actingAs($supervisor);
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload(['depth' => 12]))->assertCreated();
        $this->postJson('/api/tenant/holes/POZO-01/observations', $this->payload(['idempotency_key' => 'obs-key-0002']))->assertCreated();

        $this->getJson('/api/tenant/holes/POZO-01/geometry')
            ->assertOk()
            ->assertJsonPath('data.values.azimuth', '45.00')
            ->assertJsonPath('data.values.easting', null)
            ->assertJsonPath('data.missing', ['planned_depth', 'northing', 'elevation'])
            ->assertJsonPath('data.current_depth', '40.00')
            ->assertJsonCount(1, 'data.marks')
            ->assertJsonPath('data.marks.0.depth', '12.00')
            ->assertJsonPath('data.marks.0.user.name', 'Supervisor Carlos');
    }

    // ---- gateway mapping -------------------------------------------------

    public function test_gateway_sends_the_observation_and_purges_the_command(): void
    {
        $tenant = $this->tenant();
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $command = $this->stubConnector($tenant, 'observations.create@1', [
            'outcome' => 'created',
            'item' => ['id' => 'obs_1', 'hole_id' => 'POZO-01', 'type' => ['id' => 'obt_geology'], 'body' => 'x', 'depth' => '12.50', 'user_id' => (string) $supervisor->id],
        ], expectPayload: fn (array $p) => $p['hole_id'] === 'POZO-01'
            && $p['type_id'] === 'obt_geology'
            && $p['depth'] === '12.5'
            && $p['user_id'] === (string) $supervisor->id
            && $p['idempotency_key'] === 'obs-key-0001');

        $result = $this->gateway()->record($this->context($tenant, $supervisor), 'POZO-01', 'obt_geology', 'x', '12.5', 'obs-key-0001', 'cor_test');

        $this->assertFalse($result['replayed']);
        $fresh = ConnectorCommand::query()->findOrFail($command->id);
        $this->assertNull($fresh->result_json);
        $this->assertSame([], $fresh->payload_json);
    }

    public function test_gateway_maps_a_reused_key_to_conflict(): void
    {
        $tenant = $this->tenant();
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $this->stubConnector($tenant, 'observations.create@1', ['outcome' => 'conflict']);

        $this->expectException(HttpException::class);
        $this->gateway()->record($this->context($tenant, $supervisor), 'POZO-01', 'obt_geology', 'otro', null, 'obs-key-0001', 'cor_test');
    }

    public function test_gateway_reads_geometry(): void
    {
        $tenant = $this->tenant();
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $this->stubConnector($tenant, 'hole_geometry.get@1', [
            'hole_id' => 'POZO-01',
            'values' => ['azimuth' => '45.00', 'dip' => null],
            'missing' => ['planned_depth'],
            'current_depth' => '40.00',
            'marks' => [],
        ]);

        $g = $this->gateway()->geometry($this->context($tenant, $supervisor), 'POZO-01', 'cor_test');

        $this->assertSame(['azimuth' => '45.00', 'dip' => null], $g['values']);
        $this->assertSame(['planned_depth'], $g['missing']);
        $this->assertSame('40.00', $g['current_depth']);
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type_id' => 'obt_safety',
            'body' => 'Sostenimiento flojo en el acceso',
            'idempotency_key' => 'obs-key-0001',
        ], $overrides);
    }

    private function tenant(): Tenant
    {
        return Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Norte']);
    }

    private function user(Tenant $tenant, string $roleSlug, UserRole $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name).'.'.Str::random(4).'@undsurf.test',
            'role' => $role,
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $roleSlug,
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $user->email,
        ]);
        $systemRole = Role::whereNull('tenant_id')->where('slug', $roleSlug)->first();
        if ($systemRole) {
            $membership->roles()->sync([$systemRole->id]);
        }

        return $user;
    }

    private function assignCrew(string $holeId, string $role, User $user): void
    {
        $this->records->assignments[] = [
            'id' => 'asg_'.count($this->records->assignments), 'hole_id' => $holeId, 'user_id' => (string) $user->id,
            'role_in_hole' => $role, 'assigned_by' => null, 'assigned_from' => null, 'assigned_to' => null,
            'active' => true, 'notes' => null, 'created_at' => '2026-11-22T10:00:00Z',
        ];
    }

    private function gateway(): GatewayObservationStore
    {
        return $this->app->make(GatewayObservationStore::class);
    }

    private function context(Tenant $tenant, User $user): TenantContext
    {
        $membershipId = (string) Membership::query()->where('user_id', $user->id)->value('id');

        return new TenantContext($tenant->id, $user->id, $membershipId, 'supervisor');
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function stubConnector(Tenant $tenant, string $op, ?array $result, ?\Closure $expectPayload = null): ConnectorCommand
    {
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
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'result_json' => $result,
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
