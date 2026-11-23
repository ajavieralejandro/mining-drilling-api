<?php

namespace Tests\Feature;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\ObservationStore;
use App\Domain\Repositories\PlanStore;
use App\Domain\Repositories\RiskStore;
use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Gateway\GatewayPlanStore;
use App\Repositories\Gateway\GatewayRiskStore;
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
 * Risks (report, mitigate, close) and drilling plans with platforms, all
 * kept in the client database. HTTP flows run against in-memory stand-ins;
 * the gateway mapping runs against persisted connector commands.
 */
class TenantRisksAndPlansTest extends TestCase
{
    use RefreshDatabase;

    private FakeRiskStore $risks;

    private FakePlanStore $plans;

    private FakeHoleRecordsStore $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->risks = new FakeRiskStore;
        $this->plans = new FakePlanStore;
        $this->records = new FakeHoleRecordsStore('orig-1');
        $this->app->instance(RiskStore::class, $this->risks);
        $this->app->instance(PlanStore::class, $this->plans);
        $this->app->instance(HoleRecordsStore::class, $this->records);
    }

    // ---- risks -----------------------------------------------------------

    public function test_a_crew_driller_reports_and_the_supervisor_mitigates_and_closes(): void
    {
        $tenant = $this->tenant();
        $driller = $this->user($tenant, 'driller', UserRole::Driller, 'Perforista Juan');
        $outsider = $this->user($tenant, 'driller', UserRole::Driller, 'Perforista Luis');
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $this->assignCrew('POZO-01', $driller);
        $this->risks->currentDepth['POZO-01'] = '40.00';

        Sanctum::actingAs($outsider);
        $this->postJson('/api/tenant/holes/POZO-01/risks', $this->riskPayload())->assertForbidden();

        Sanctum::actingAs($driller);
        $riskId = $this->postJson('/api/tenant/holes/POZO-01/risks', $this->riskPayload())
            ->assertCreated()
            ->assertJsonPath('data.level', 'critical')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.depth_detected', '32.00')
            ->assertJsonPath('data.reported_by.name', 'Perforista Juan')
            ->json('data.id');
        $this->postJson('/api/tenant/holes/POZO-01/risks', $this->riskPayload())->assertOk();
        $this->postJson("/api/tenant/risks/{$riskId}/mitigate")->assertForbidden();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/tenant/risks/{$riskId}/mitigate", ['notes' => 'Se cortó el agua'])
            ->assertOk()
            ->assertJsonPath('data.status', 'mitigated')
            ->assertJsonPath('data.mitigated_by.name', 'Supervisor Carlos');
        $this->postJson("/api/tenant/risks/{$riskId}/close")->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->postJson("/api/tenant/risks/{$riskId}/close", ['notes' => 'Se instaló malla'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/tenant/risks/{$riskId}/mitigate")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson('/api/tenant/risks/rsk_nope/close', ['notes' => 'x'])->assertNotFound();

        $this->assertSame(0, DB::table('observations')->count(), 'Nada queda en la tabla central vieja.');
    }

    public function test_risk_rules_and_global_list_with_counts(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'admin', UserRole::Admin, 'Admin Ana');
        $this->risks->currentDepth['POZO-01'] = '40.00';

        Sanctum::actingAs($admin);
        $this->postJson('/api/tenant/holes/POZO-01/risks', $this->riskPayload(['depth_detected' => 41]))
            ->assertStatus(422)->assertJsonValidationErrors('depth_detected');
        $this->postJson('/api/tenant/holes/POZO-01/risks', $this->riskPayload(['level' => 'extreme']))
            ->assertStatus(422)->assertJsonValidationErrors('level');

        $this->postJson('/api/tenant/holes/POZO-01/risks', $this->riskPayload())->assertCreated();
        $this->postJson('/api/tenant/holes/POZO-02/risks', $this->riskPayload(['level' => 'low', 'idempotency_key' => 'risk-key-0002']))->assertCreated();

        $this->getJson('/api/tenant/risks?status=open&level=critical,high')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hole_code', 'H-POZO-01')
            ->assertJsonPath('counts.open', 2)
            ->assertJsonPath('counts.open_critical', 1)
            ->assertJsonPath('counts.holes_with_open', 2)
            ->assertJsonPath('can_resolve', true);

        $this->getJson('/api/tenant/holes/POZO-02/risks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('can_report', true);
    }

    public function test_the_section_view_receives_unresolved_risks_with_depth(): void
    {
        $tenant = $this->tenant();
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $observations = new FakeObservationStore;
        $observations->risks['POZO-01'] = [[
            'id' => 'rsk_1', 'hole_id' => 'POZO-01', 'level' => 'critical', 'status' => 'open', 'body' => 'Agua',
            'depth_detected' => '32.00', 'critical_distance' => '4.00', 'reported_by' => (string) $supervisor->id,
        ]];
        $this->app->instance(ObservationStore::class, $observations);

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/tenant/holes/POZO-01/geometry')
            ->assertOk()
            ->assertJsonPath('data.risks.0.level', 'critical')
            ->assertJsonPath('data.risks.0.critical_distance', '4.00')
            ->assertJsonPath('data.risks.0.reported_by.name', 'Supervisor Carlos');
    }

    // ---- plans and platforms ---------------------------------------------

    public function test_a_planner_builds_a_plan_with_platforms_and_holes(): void
    {
        $tenant = $this->tenant();
        $geologist = $this->user($tenant, 'geologist', UserRole::Geologist, 'Geóloga Marta');
        $driller = $this->user($tenant, 'driller', UserRole::Driller, 'Perforista Juan');

        Sanctum::actingAs($geologist);
        $planId = $this->postJson('/api/tenant/plans', [
            'name' => 'Plan Norte 2027', 'code' => 'pn-27', 'purpose' => 'infill', 'planned_meters' => 1200.5, 'level' => 'N-450',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'PN-27')
            ->assertJsonPath('data.created_by', $geologist->id)
            ->json('data.id');
        $this->postJson('/api/tenant/plans', ['name' => 'Otro', 'code' => 'PN-27', 'purpose' => 'infill'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/tenant/plans', ['name' => 'Sin propósito'])
            ->assertStatus(422)->assertJsonValidationErrors('purpose');

        $platformId = $this->postJson('/api/tenant/platforms', [
            'plan_id' => $planId, 'code' => 'plt-01', 'easting' => 452210.8, 'northing' => 7123485.6, 'elevation' => -120.5,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/tenant/platforms', ['plan_id' => 'pln_nope', 'code' => 'PLT-02'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_id');

        $this->postJson("/api/tenant/platforms/{$platformId}/holes", ['holes' => [
            ['hole_id' => '1001', 'sequence' => 1, 'target' => 'Veta A'],
            ['hole_id' => '1002', 'sequence' => 2],
        ]])->assertOk()->assertJsonCount(2, 'data.holes')->assertJsonPath('data.holes.0.geometry.azimuth', '45.00');

        $this->plans->missingHoles = ['9999'];
        $this->postJson("/api/tenant/platforms/{$platformId}/holes", ['holes' => [['hole_id' => '1003'], ['hole_id' => '9999']]])
            ->assertStatus(422)->assertJsonValidationErrors('holes.1.hole_id');

        $this->getJson("/api/tenant/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.platforms_count', 1)
            ->assertJsonPath('data.holes_count', 2)
            ->assertJsonCount(1, 'data.platforms');

        // A driller sees plans and the fan, but cannot change them.
        Sanctum::actingAs($driller);
        $this->getJson('/api/tenant/plans')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can_manage', false);
        $this->getJson("/api/tenant/platforms/{$platformId}")->assertOk()->assertJsonCount(2, 'data.holes');
        $this->postJson('/api/tenant/plans', ['name' => 'X', 'purpose' => 'infill'])->assertForbidden();
        $this->deleteJson("/api/tenant/platforms/{$platformId}/holes/1001")->assertForbidden();

        Sanctum::actingAs($geologist);
        $this->deleteJson("/api/tenant/platforms/{$platformId}/holes/1001")->assertOk();
        $this->deleteJson("/api/tenant/platforms/{$platformId}/holes/1001")->assertNotFound();
        $this->deleteJson("/api/tenant/plans/{$planId}")->assertOk();
        $this->getJson("/api/tenant/platforms/{$platformId}")->assertNotFound();
        $this->assertSame([], $this->plans->links);

        foreach (['drilling_plans', 'drilling_platforms'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Nada queda en {$table}.");
        }
    }

    // ---- gateway mapping -------------------------------------------------

    public function test_gateway_reports_a_risk_and_purges_the_command(): void
    {
        $tenant = $this->tenant();
        $supervisor = $this->user($tenant, 'supervisor', UserRole::Supervisor, 'Supervisor Carlos');
        $command = $this->stubConnector($tenant, 'risks.create@1', [
            'outcome' => 'created',
            'item' => ['id' => 'rsk_1', 'hole_id' => 'POZO-01', 'level' => 'high', 'status' => 'open', 'body' => 'x'],
        ], fn (array $p) => $p['level'] === 'high' && $p['depth_detected'] === '12.5' && ! array_key_exists('critical_distance', $p)
            && $p['user_id'] === (string) $supervisor->id);

        $result = (new GatewayRiskStore($this->app->make(CommandDispatcher::class)))->report(
            $this->context($tenant, $supervisor), 'POZO-01',
            ['level' => 'high', 'body' => 'x', 'depth_detected' => '12.5', 'critical_distance' => null],
            'risk-key-0001', 'cor_test',
        );

        $this->assertSame('rsk_1', $result['item']['id']);
        $fresh = ConnectorCommand::query()->findOrFail($command->id);
        $this->assertNull($fresh->result_json);
        $this->assertSame([], $fresh->payload_json);
    }

    public function test_gateway_maps_plan_rejections_and_missing_platforms(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'admin', UserRole::Admin, 'Admin Ana');
        $this->stubConnector($tenant, 'plans.upsert@1', [
            'outcome' => 'rejected', 'violation' => ['field' => 'mine_id', 'message' => 'La mina indicada no existe en la organización.'],
        ]);
        try {
            (new GatewayPlanStore($this->app->make(CommandDispatcher::class)))->savePlan($this->context($tenant, $admin), null, ['name' => 'P', 'purpose' => 'infill', 'mine_id' => 'x'], 'cor_test');
            $this->fail('A rejected write must raise a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mine_id', $e->errors());
        }

        $this->stubConnector($tenant, 'platforms.get@1', null, fn () => true, ConnectorCommand::STATUS_FAILED, 'NOT_FOUND');
        $this->assertNull((new GatewayPlanStore($this->app->make(CommandDispatcher::class)))->platform($this->context($tenant, $admin), 'plt_x', 'cor_test'));
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function riskPayload(array $overrides = []): array
    {
        return array_merge([
            'level' => 'critical',
            'body' => 'Agua a presión en el frente',
            'depth_detected' => 32,
            'critical_distance' => 4,
            'recommended_action' => 'Detener perforación',
            'idempotency_key' => 'risk-key-0001',
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

    private function assignCrew(string $holeId, User $user): void
    {
        $this->records->assignments[] = [
            'id' => 'asg_'.count($this->records->assignments), 'hole_id' => $holeId, 'user_id' => (string) $user->id,
            'role_in_hole' => 'driller', 'assigned_by' => null, 'assigned_from' => null, 'assigned_to' => null,
            'active' => true, 'notes' => null, 'created_at' => '2026-11-22T10:00:00Z',
        ];
    }

    private function context(Tenant $tenant, User $user): TenantContext
    {
        $membershipId = (string) Membership::query()->where('user_id', $user->id)->value('id');

        return new TenantContext($tenant->id, $user->id, $membershipId, 'supervisor');
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function stubConnector(
        Tenant $tenant,
        string $op,
        ?array $result,
        ?\Closure $expectPayload = null,
        string $status = ConnectorCommand::STATUS_COMPLETED,
        ?string $errorCode = null,
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
