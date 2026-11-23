<?php

namespace Tests\Feature;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Gateway\GatewayHoleRecordsStore;
use App\Services\Connector\CommandDispatcher;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ADR-04 for daily progress: depth state, reports and their review live in
 * the mining company's database. The API authorizes, normalizes and relays.
 */
class HoleRecordsThroughConnectorTest extends TestCase
{
    use RefreshDatabase;

    // ---- HTTP flow (connector replaced by FakeHoleRecordsStore) ----------

    public function test_supervisor_initializes_depth_and_records_a_report_without_central_rows(): void
    {
        [, $supervisor] = $this->tenantWithSupervisor();
        $records = $this->bindFakeRecords();
        Sanctum::actingAs($supervisor);

        $this->postJson('/api/tenant/holes/POZO-01/initialize-depth', ['initial_depth' => 10])
            ->assertOk()
            ->assertJsonPath('data.depth_initialized', true)
            ->assertJsonPath('data.current_depth', '10.00')
            ->assertJsonPath('data.origin_id', 'orig-1');

        $response = $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report())
            ->assertCreated()
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('data.depth_from', '10.00')
            ->assertJsonPath('data.depth_to', '14.50')
            ->assertJsonPath('data.shift', 'A')
            ->assertJsonPath('data.user.name', 'Supervisor Carlos');

        $sent = collect($records->calls)->firstWhere('op', 'progress.create@2')['payload'];
        $this->assertSame('POZO-01', $sent['hole_id']);
        $this->assertSame('A', $sent['shift']);
        $this->assertSame(64, strlen($sent['payload_hash']));
        $this->assertArrayHasKey('crew_snapshot', $sent);

        $this->assertSame(0, DB::table('tenant_hole_progress_logs')->count());
        $this->assertSame(0, DB::table('tenant_hole_operational_states')->count());
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_retry_with_same_key_is_a_replay_and_changed_content_is_a_conflict(): void
    {
        [, $supervisor] = $this->tenantWithSupervisor();
        $records = $this->bindFakeRecords();
        $records->states['POZO-01'] = ['depth_initialized' => true, 'initial_depth' => '10.00', 'current_depth' => '10.00'];
        Sanctum::actingAs($supervisor);

        $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report())->assertCreated();

        $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report())
            ->assertOk()
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('idempotent_replay', true);

        $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report(['depth_to' => 15]))
            ->assertStatus(409);
    }

    public function test_shift_is_required_and_limited_to_a_b_c(): void
    {
        [, $supervisor] = $this->tenantWithSupervisor();
        $records = $this->bindFakeRecords();
        Sanctum::actingAs($supervisor);

        $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report(['shift' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('shift');
        $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report(['shift' => 'Turno 1']))
            ->assertStatus(422)->assertJsonValidationErrors('shift');

        $this->assertSame([], array_filter($records->calls, fn ($c) => $c['op'] === 'progress.create@2'));
    }

    public function test_hole_summary_reads_state_from_the_client_database(): void
    {
        [, $supervisor] = $this->tenantWithSupervisor();
        $records = $this->bindFakeRecords();
        $records->holeStatus['POZO-01'] = 'paused';
        $records->states['POZO-01'] = ['depth_initialized' => true, 'initial_depth' => '10.00', 'current_depth' => '22.40'];
        Sanctum::actingAs($supervisor);

        $this->getJson('/api/tenant/holes/POZO-01/progress')
            ->assertOk()
            ->assertJsonPath('summary.status', 'paused')
            ->assertJsonPath('summary.is_operable', false)
            ->assertJsonPath('summary.total_advance_meters', '12.40');
    }

    // ---- GatewayHoleRecordsStore: what goes to and comes from the connector ----

    public function test_created_report_is_returned_and_the_command_is_purged(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $command = $this->stubConnector($tenant, 'progress.create@2', [
            'outcome' => 'created',
            'item' => ['id' => 'prog_1', 'hole_id' => 'POZO-01', 'depth_from' => '10.00', 'depth_to' => '14.50'],
            'origin_id' => 'orig-db',
        ], expectPayload: function (array $payload) {
            return $payload['hole_id'] === 'POZO-01'
                && $payload['user_id'] !== ''
                && ! array_key_exists('initial_depth', $payload);
        });

        $result = $this->gatewayStore()->recordProgress($this->context($tenant, $supervisor), 'POZO-01', [
            'depth_from' => '10.00', 'depth_to' => '14.50', 'shift' => 'A', 'initial_depth' => null,
        ], 'cor_test');

        $this->assertSame('prog_1', $result['item']['id']);
        $this->assertFalse($result['replayed']);
        $this->assertSame('orig-db', $result['origin_id']);

        $stored = ConnectorCommand::query()->findOrFail($command->id);
        $this->assertSame([], $stored->payload_json);
        $this->assertNull($stored->result_json);
    }

    public function test_rejected_outcome_becomes_a_validation_error_on_the_reported_field(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $command = $this->stubConnector($tenant, 'progress.create@2', [
            'outcome' => 'rejected',
            'violation' => ['field' => 'depth_to', 'message' => 'La profundidad final supera la planificada.'],
        ]);

        try {
            $this->gatewayStore()->recordProgress($this->context($tenant, $supervisor), 'POZO-01', ['shift' => 'A'], 'cor_test');
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(['depth_to' => ['La profundidad final supera la planificada.']], $e->errors());
        }

        $this->assertNull(ConnectorCommand::query()->findOrFail($command->id)->result_json);
    }

    public function test_conflict_outcome_becomes_409(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $this->stubConnector($tenant, 'progress.review@1', ['outcome' => 'conflict']);

        $this->expectException(HttpException::class);
        $this->gatewayStore()->reviewProgress($this->context($tenant, $supervisor), 'prog_1', 'approve', null, 'cor_test');
    }

    public function test_connector_without_the_operation_reports_unsupported_and_is_purged(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $command = $this->stubConnector($tenant, 'hole_state.get@1', null, status: ConnectorCommand::STATUS_FAILED, errorCode: 'UNSUPPORTED_OP');

        try {
            $this->gatewayStore()->holeState($this->context($tenant, $supervisor), 'POZO-01', 'cor_test');
            $this->fail('expected a gateway error');
        } catch (GatewayException $e) {
            $this->assertSame('UNSUPPORTED_OP', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }

        $this->assertSame([], ConnectorCommand::query()->findOrFail($command->id)->payload_json);
    }

    public function test_core_log_update_sends_only_the_changes_and_is_purged(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $command = $this->stubConnector($tenant, 'core_logs.update@1', [
            'outcome' => 'ok',
            'item' => ['id' => 'core_1', 'hole_id' => 'POZO-01', 'geology_notes' => null, 'rqd_percentage' => '50.00'],
        ], expectPayload: fn (array $payload) => $payload === [
            'hole_id' => 'POZO-01',
            'id' => 'core_1',
            'text' => ['geology_notes' => null],
            'rqd_meters' => '1.50',
        ]);

        $item = $this->gatewayStore()->updateCoreLog($this->context($tenant, $supervisor), 'POZO-01', 'core_1', [
            'text' => ['geology_notes' => null],
            'rqd_meters' => '1.50',
        ], 'cor_test');

        $this->assertSame('50.00', $item['rqd_percentage']);
        $this->assertNull(ConnectorCommand::query()->findOrFail($command->id)->result_json);
    }

    public function test_core_log_of_another_hole_is_not_found(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $this->stubConnector($tenant, 'core_logs.get@1', null, status: ConnectorCommand::STATUS_FAILED, errorCode: 'NOT_FOUND');

        try {
            $this->gatewayStore()->getCoreLog($this->context($tenant, $supervisor), 'POZO-02', 'core_1', 'cor_test');
            $this->fail('expected NOT_FOUND');
        } catch (GatewayException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
    }

    // ---- crew --------------------------------------------------------------

    public function test_supervisor_assigns_a_crew_stored_in_the_client_database(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $this->bindFakeRecords();
        $driller = $this->member($tenant, 'Perforista Juan', 'driller');
        $helper = $this->member($tenant, 'Ayudante Pedro', 'helper');
        Sanctum::actingAs($supervisor);

        $this->postJson('/api/tenant/holes/POZO-01/assignments', [
            'driller_id' => $driller->id,
            'helper_id' => $helper->id,
        ])->assertCreated()
            ->assertJsonPath('data.crew.driller.name', 'Perforista Juan')
            ->assertJsonPath('data.crew.helper.user_id', $helper->id)
            ->assertJsonPath('data.active_count', 2);

        $this->getJson('/api/tenant/holes/POZO-01/assignments')
            ->assertOk()
            ->assertJsonPath('data.crew.driller.user_id', $driller->id)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.assigned_by.name', 'Supervisor Carlos');

        $this->assertSame(0, DB::table('tenant_hole_assignments')->count());
        $this->assertSame(0, DB::table('tenant_hole_operational_states')->count());
    }

    public function test_crew_rules_and_membership_are_enforced(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $records = $this->bindFakeRecords();
        $helper = $this->member($tenant, 'Ayudante Pedro', 'helper');
        $outsider = User::create([
            'name' => 'Ajeno', 'email' => 'ajeno@undsurf.test', 'role' => 'driller',
            'password' => Hash::make('password'), 'active' => true,
        ]);
        Sanctum::actingAs($supervisor);

        $this->postJson('/api/tenant/holes/POZO-01/assignments', ['helper_id' => $helper->id])
            ->assertStatus(422)->assertJsonValidationErrors('driller_id');

        $this->postJson('/api/tenant/holes/POZO-01/assignments', ['driller_id' => $outsider->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        $puts = array_filter($records->calls, fn ($c) => $c['op'] === 'assignments.put@1');
        $this->assertCount(1, $puts, 'Un no miembro se rechaza en UndSurf, antes del conector.');
    }

    public function test_driller_can_report_only_on_the_hole_where_the_client_database_assigns_him(): void
    {
        [$tenant] = $this->tenantWithSupervisor();
        $records = $this->bindFakeRecords();
        $records->states['POZO-01'] = ['depth_initialized' => true, 'initial_depth' => '10.00', 'current_depth' => '10.00'];
        $driller = $this->member($tenant, 'Perforista Juan', 'driller');
        $other = $this->member($tenant, 'Perforista Luis', 'driller');
        $records->assignments[] = [
            'id' => 'asg_0', 'hole_id' => 'POZO-01', 'user_id' => (string) $driller->id, 'role_in_hole' => 'driller',
            'assigned_by' => null, 'assigned_from' => null, 'assigned_to' => null, 'active' => true, 'notes' => null,
            'created_at' => '2026-11-21T10:00:00Z',
        ];

        Sanctum::actingAs($other);
        $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report())->assertForbidden();

        Sanctum::actingAs($driller);
        $this->postJson('/api/tenant/holes/POZO-01/progress', $this->report())->assertCreated();

        $sent = collect($records->calls)->firstWhere('op', 'progress.create@2')['payload'];
        $this->assertSame($driller->id, $sent['crew_snapshot']['driller']['user_id']);
        $this->assertSame('Perforista Juan', $sent['crew_snapshot']['driller']['name']);

        // One crew read per request: the permission and the snapshot share it.
        $this->assertCount(2, array_filter($records->calls, fn ($c) => $c['op'] === 'assignments.list@1'));
    }

    public function test_single_assignment_sends_roles_as_an_object_and_is_purged(): void
    {
        [$tenant, $supervisor] = $this->tenantWithSupervisor();
        $command = $this->stubConnector($tenant, 'assignments.put@1', [
            'outcome' => 'ok',
            'active' => [['id' => 'asg_1', 'hole_id' => 'POZO-01', 'user_id' => '7', 'role_in_hole' => 'driller', 'active' => true]],
        ], expectPayload: fn (array $payload) => $payload['mode'] === 'single'
            && $payload['roles'] === ['driller' => '7']
            && json_encode($payload['roles']) === '{"driller":"7"}'
            && $payload['assigned_by'] !== '');

        $result = $this->gatewayStore()->assignCrew($this->context($tenant, $supervisor), 'POZO-01', 'single', ['driller' => 7], [], 'cor_test');

        $this->assertSame('asg_1', $result['active'][0]['id']);
        $this->assertNull(ConnectorCommand::query()->findOrFail($command->id)->result_json);
    }

    private function member(Tenant $tenant, string $name, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name).'.'.Str::random(4).'@undsurf.test',
            'role' => $role,
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $role,
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $user->email,
        ]);

        return $user;
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithSupervisor(): array
    {
        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Norte']);
        $supervisor = User::create([
            'name' => 'Supervisor Carlos',
            'email' => 'carlos.'.Str::random(5).'@undsurf.test',
            'role' => 'supervisor',
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $supervisor->id,
            'tenant_id' => $tenant->id,
            'role' => 'supervisor',
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $supervisor->email,
        ]);

        $this->app->instance(TenantHoleRepositoryInterface::class, new class implements TenantHoleRepositoryInterface
        {
            public function list(TenantContext $context, string $correlationId, int $limit = 10): array
            {
                return ['items' => [], 'origin_id' => 'orig-1', 'request_id' => 'req', 'correlation_id' => $correlationId];
            }

            public function find(TenantContext $context, string $correlationId, string $holeId): array
            {
                return [
                    'item' => ['id' => $holeId, 'code' => 'H-'.$holeId, 'status' => 'in_progress'],
                    'origin_id' => 'orig-1',
                    'request_id' => 'req-find',
                    'correlation_id' => $correlationId,
                ];
            }
        });

        return [$tenant, $supervisor];
    }

    private function bindFakeRecords(): FakeHoleRecordsStore
    {
        $fake = new FakeHoleRecordsStore('orig-1');
        $this->app->instance(HoleRecordsStore::class, $fake);

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function report(array $overrides = []): array
    {
        return array_merge([
            'depth_from' => 10,
            'depth_to' => 14.5,
            'hours_worked' => 7.5,
            'shift' => 'a',
            'logged_at' => '2026-11-21T12:00:00Z',
            'idempotency_key' => 'idem-report-0001',
        ], $overrides);
    }

    private function context(Tenant $tenant, User $user): TenantContext
    {
        $membershipId = (string) Membership::query()->where('user_id', $user->id)->value('id');

        return new TenantContext($tenant->id, $user->id, $membershipId, 'supervisor');
    }

    private function gatewayStore(): GatewayHoleRecordsStore
    {
        return $this->app->make(GatewayHoleRecordsStore::class);
    }

    /**
     * Persists a command as the connector would have completed it and makes
     * the dispatcher return it, so the store's read-and-purge path runs for
     * real against the database.
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
