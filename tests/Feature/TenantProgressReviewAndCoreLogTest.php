<?php

namespace Tests\Feature;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Http\Requests\Tenant\StoreTenantCoreLogRequest;
use App\Http\Requests\Tenant\StoreTenantHoleProgressRequest;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantProgressReviewAndCoreLogTest extends TestCase
{
    use RefreshDatabase;

    /** Las políticas deciden solo por permisos: los roles de sistema tienen que existir. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function setupTenantAndUsers(): array
    {
        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Sur']);

        $driller = User::create([
            'name' => 'Perforista Juan',
            'email' => 'juan.driller@undsurf.test',
            'role' => 'driller',
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $driller->id,
            'tenant_id' => $tenant->id,
            'role' => 'driller',
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $driller->email,
        ]);

        $supervisor = User::create([
            'name' => 'Supervisor Carlos',
            'email' => 'carlos.supervisor@undsurf.test',
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

        $geologist = User::create([
            'name' => 'Geóloga Elena',
            'email' => 'elena.geologist@undsurf.test',
            'role' => 'geologist',
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $geologist->id,
            'tenant_id' => $tenant->id,
            'role' => 'geologist',
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $geologist->email,
        ]);

        return [$tenant, $driller, $supervisor, $geologist];
    }

    private function bindMockHoleRepository(string $originId = 'orig-1'): void
    {
        $mockRepo = new class($originId) implements TenantHoleRepositoryInterface
        {
            public function __construct(private readonly string $originId) {}

            public function list(TenantContext $context, string $correlationId, int $limit = 10): array
            {
                return [
                    'items' => [
                        ['id' => 'POZO-01', 'code' => 'H-POZO-01', 'status' => 'in_progress'],
                    ],
                    'origin_id' => $this->originId,
                    'request_id' => 'req-1',
                    'correlation_id' => $correlationId,
                ];
            }

            public function find(TenantContext $context, string $correlationId, string $holeId): array
            {
                return [
                    'item' => ['id' => $holeId, 'code' => 'H-'.$holeId, 'status' => 'in_progress'],
                    'origin_id' => $this->originId,
                    'request_id' => 'req-find',
                    'correlation_id' => $correlationId,
                ];
            }
        };

        $this->app->instance(TenantHoleRepositoryInterface::class, $mockRepo);
    }

    public function test_supervisor_can_list_and_approve_pending_progress(): void
    {
        [$tenant, $driller, $supervisor] = $this->setupTenantAndUsers();
        $records = $this->bindRecords();
        $report = $records->seedReport(['user_id' => (string) $driller->id]);

        Sanctum::actingAs($supervisor);

        $this->getJson('/api/tenant/progress/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending_review')
            ->assertJsonPath('data.0.hours_worked', '7.50')
            ->assertJsonPath('data.0.user.name', 'Perforista Juan');

        $this->postJson("/api/tenant/progress/{$report['id']}/review", [
            'action' => 'approve',
            'review_notes' => 'Tramos y metros verificados en planilla.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', $supervisor->id)
            ->assertJsonPath('data.reviewer.name', 'Supervisor Carlos');

        $this->assertSame('approved', $records->reports[$report['id']]['status']);
        $this->assertSame((string) $supervisor->id, $records->reports[$report['id']]['reviewed_by']);
    }

    public function test_supervisor_can_reject_pending_progress_with_notes(): void
    {
        [$tenant, $driller, $supervisor] = $this->setupTenantAndUsers();
        $records = $this->bindRecords();
        $report = $records->seedReport(['user_id' => (string) $driller->id, 'depth_to' => '16.00']);

        Sanctum::actingAs($supervisor);

        // Rechazo sin motivo debe fallar (validación)
        $this->postJson("/api/tenant/progress/{$report['id']}/review", [
            'action' => 'reject',
        ])->assertUnprocessable();

        $this->postJson("/api/tenant/progress/{$report['id']}/review", [
            'action' => 'reject',
            'review_notes' => 'Metraje inconsistente con el desgaste de corona. Corregir y reenviar.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reviewed_by', $supervisor->id);
    }

    public function test_driller_cannot_review_progress(): void
    {
        [$tenant, $driller] = $this->setupTenantAndUsers();
        $records = $this->bindRecords();
        $report = $records->seedReport(['user_id' => (string) $driller->id]);

        Sanctum::actingAs($driller);

        $this->getJson('/api/tenant/progress/pending')->assertForbidden();
        $this->postJson("/api/tenant/progress/{$report['id']}/review", [
            'action' => 'approve',
        ])->assertForbidden();

        $this->assertSame([], $records->calls, 'Un rol sin permiso no debe llegar al conector.');
    }

    public function test_blasting_window_validation_rejects_night_blasting_hours(): void
    {
        $request = new StoreTenantHoleProgressRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('hours_worked', $rules);
        $this->assertContains('max:8.0', $rules['hours_worked']);

        // Simular intento a las 03:00 de la madrugada (ventana de voladuras 00:00 a 08:00)
        $validator = validator([
            'depth_from' => 10,
            'depth_to' => 14,
            'hours_worked' => 8,
            'shift' => 'Turno 1',
            'idempotency_key' => 'test-idem-blasting-key',
            'logged_at' => '2026-11-10T03:30:00Z',
        ], $rules, $request->messages());

        foreach ($request->after() as $afterHook) {
            $validator->after($afterHook);
        }

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('logged_at', $validator->errors()->messages());
        $this->assertStringContainsString('Ventana de voladura y ventilación activa', $validator->errors()->first('logged_at'));
    }

    public function test_geologist_can_record_core_log_with_calculated_rqd(): void
    {
        [$tenant, , , $geologist] = $this->setupTenantAndUsers();
        $this->bindMockHoleRepository('orig-1');
        $records = $this->bindRecords();
        Sanctum::actingAs($geologist);

        // Corrida 10.00 a 13.00 m, recuperado 2.70 m, sanos >= 10 cm 2.10 m:
        // largo 3.00, pérdida 0.30, RQD 70.00 %. El cálculo lo hace el conector
        // (internal/protocol: TestComputeCoreMetrics usa estos mismos valores).
        $this->postJson('/api/tenant/holes/POZO-01/core-logs', [
            'run_from' => 10,
            'run_to' => 13,
            'recovered_meters' => 2.7,
            'rqd_meters' => 2.1,
            'box_number' => 'CAJA-07',
        ])->assertCreated()
            ->assertJsonPath('data.drill_length', '3.00')
            ->assertJsonPath('data.core_loss_meters', '0.30')
            ->assertJsonPath('data.rqd_percentage', '70.00')
            ->assertJsonPath('data.logged_by', $geologist->id)
            ->assertJsonPath('data.logged_by_user.name', 'Geóloga Elena');

        $sent = collect($records->calls)->firstWhere('op', 'core_logs.create@1')['payload'];
        $this->assertSame('10.00', $sent['run_from']);
        $this->assertSame('2.10', $sent['rqd_meters']);
        $this->assertSame(0, DB::table('tenant_core_logs')->count());
    }

    public function test_core_log_request_validates_inconsistent_metrics(): void
    {
        $request = new StoreTenantCoreLogRequest;
        $rules = $request->rules();

        // Caso 1: Testigo recuperado (4 m) supera el largo de corrida (3 m)
        $validator1 = validator([
            'run_from' => 10,
            'run_to' => 13,
            'recovered_meters' => 4,
            'rqd_meters' => 2,
        ], $rules, $request->messages());

        foreach ($request->after() as $afterHook) {
            $validator1->after($afterHook);
        }

        $this->assertTrue($validator1->fails());
        $this->assertArrayHasKey('recovered_meters', $validator1->errors()->messages());

        // Caso 2: RQD (3 m) supera los metros recuperados totales (2 m)
        $validator2 = validator([
            'run_from' => 10,
            'run_to' => 13,
            'recovered_meters' => 2,
            'rqd_meters' => 3,
        ], $rules, $request->messages());

        foreach ($request->after() as $afterHook) {
            $validator2->after($afterHook);
        }

        $this->assertTrue($validator2->fails());
        $this->assertArrayHasKey('rqd_meters', $validator2->errors()->messages());
    }

    public function test_can_view_single_progress_log_and_filter_by_status(): void
    {
        [$tenant, $driller, $supervisor] = $this->setupTenantAndUsers();
        $records = $this->bindRecords();
        $first = $records->seedReport(['user_id' => (string) $driller->id]);
        $second = $records->seedReport([
            'user_id' => (string) $driller->id,
            'depth_from' => '14.50',
            'depth_to' => '18.00',
            'status' => 'approved',
            'logged_at' => '2026-11-10T20:00:00Z',
        ]);

        Sanctum::actingAs($supervisor);

        $this->getJson("/api/tenant/progress/{$first['id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.depth_from', '10.00')
            ->assertJsonPath('data.depth_to', '14.50')
            ->assertJsonPath('data.advance_meters', '4.50')
            ->assertJsonPath('data.origin_id', 'orig-1');

        $this->getJson('/api/tenant/progress/pending')
            ->assertOk()
            ->assertJsonPath('summary.total_pending', 1)
            ->assertJsonPath('summary.total_pending_meters', '4.50')
            ->assertJsonPath('summary.holes_count', 1);

        Connector::create([
            'id' => 'orig-1',
            'tenant_id' => $tenant->id,
            'connector_token_hash' => hash('sha256', 'token-1'),
            'status' => 'active',
            'communicating' => true,
            'last_seen_at' => now(),
        ]);
        $this->bindMockHoleRepository('orig-1');

        $this->getJson('/api/tenant/holes/POZO-01/progress?origin_id=orig-1&status=pending_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first['id'])
            ->assertJsonPath('summary.total_logs', 2);

        $this->getJson('/api/tenant/holes/POZO-01/progress?origin_id=orig-1&status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second['id']);
    }

    public function test_geologist_can_show_update_delete_core_log_and_view_rqd_summary(): void
    {
        [$tenant, , , $geologist] = $this->setupTenantAndUsers();
        $this->bindMockHoleRepository('orig-1');
        $records = $this->bindRecords();

        $first = $records->seedCoreLog([
            'box_number' => 'CAJA-01',
            'logged_by' => (string) $geologist->id,
            'geology_notes' => 'Pórfido dacítico moderadamente alterado',
        ]);
        $second = $records->seedCoreLog([
            'box_number' => 'CAJA-02',
            'run_from' => '3.00',
            'run_to' => '6.00',
            'recovered_meters' => '2.90',
            'rqd_meters' => '2.70',
            'logged_by' => (string) $geologist->id,
        ]);

        Sanctum::actingAs($geologist);

        $this->getJson('/api/tenant/holes/POZO-01/core-logs?origin_id=orig-1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('rqd_summary.total_runs', 2)
            ->assertJsonPath('rqd_summary.total_drill_length', '6.00')
            ->assertJsonPath('rqd_summary.total_recovered_meters', '5.70')
            ->assertJsonPath('rqd_summary.recovery_percentage', '95.00')
            ->assertJsonPath('rqd_summary.total_rqd_meters', '5.20')
            ->assertJsonPath('rqd_summary.average_rqd_percentage', '86.66')
            ->assertJsonPath('rqd_summary.rqd_classification', 'Buena')
            ->assertJsonPath('rqd_summary.total_boxes', 2);

        $this->getJson("/api/tenant/holes/POZO-01/core-logs/{$first['id']}?origin_id=orig-1")
            ->assertOk()
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.box_number', 'CAJA-01');

        $this->patchJson("/api/tenant/holes/POZO-01/core-logs/{$first['id']}?origin_id=orig-1", [
            'box_number' => 'CAJA-01-A',
            'geology_notes' => 'Presencia de pirita diseminada al 2%',
            'photo_url' => 'https://storage.undsurf.com/photos/box-01-a.jpg',
        ])->assertOk()
            ->assertJsonPath('data.box_number', 'CAJA-01-A')
            ->assertJsonPath('data.geology_notes', 'Presencia de pirita diseminada al 2%')
            ->assertJsonPath('data.photo_url', 'https://storage.undsurf.com/photos/box-01-a.jpg');

        $this->assertSame('CAJA-01-A', $records->coreLogs[$first['id']]['box_number']);

        $this->deleteJson("/api/tenant/holes/POZO-01/core-logs/{$second['id']}?origin_id=orig-1")
            ->assertOk();
        $this->assertArrayNotHasKey($second['id'], $records->coreLogs);
    }

    public function test_already_reviewed_progress_cannot_be_reviewed_again(): void
    {
        [$tenant, $driller, $supervisor] = $this->setupTenantAndUsers();
        $records = $this->bindRecords();
        $report = $records->seedReport(['user_id' => (string) $driller->id, 'depth_to' => '12.00']);

        Sanctum::actingAs($supervisor);

        $this->postJson("/api/tenant/progress/{$report['id']}/review", ['action' => 'approve'])
            ->assertOk();

        $this->postJson("/api/tenant/progress/{$report['id']}/review", [
            'action' => 'reject',
            'review_notes' => 'Cambio de opinión',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('approved', $records->reports[$report['id']]['status']);
    }

    private function bindRecords(): FakeHoleRecordsStore
    {
        $fake = new FakeHoleRecordsStore('orig-1');
        $this->app->instance(HoleRecordsStore::class, $fake);

        return $fake;
    }

    public function test_core_log_of_another_hole_is_not_reachable_and_update_keeps_metrics_consistent(): void
    {
        [$tenant, , , $geologist] = $this->setupTenantAndUsers();
        $this->bindMockHoleRepository('orig-1');
        $records = $this->bindRecords();
        $run = $records->seedCoreLog(['logged_by' => (string) $geologist->id]);

        Sanctum::actingAs($geologist);

        $this->getJson("/api/tenant/holes/POZO-02/core-logs/{$run['id']}")->assertNotFound();
        $this->patchJson("/api/tenant/holes/POZO-02/core-logs/{$run['id']}", ['box_number' => 'X'])->assertNotFound();
        $this->deleteJson("/api/tenant/holes/POZO-02/core-logs/{$run['id']}")->assertNotFound();
        $this->assertNull($records->coreLogs[$run['id']]['box_number']);

        $this->patchJson("/api/tenant/holes/POZO-01/core-logs/{$run['id']}", ['recovered_meters' => 3.5])
            ->assertStatus(422)->assertJsonValidationErrors('recovered_meters');
        $this->patchJson("/api/tenant/holes/POZO-01/core-logs/{$run['id']}", ['rqd_meters' => 2.9])
            ->assertStatus(422)->assertJsonValidationErrors('rqd_meters');

        $this->patchJson("/api/tenant/holes/POZO-01/core-logs/{$run['id']}", ['rqd_meters' => 1.5])
            ->assertOk()->assertJsonPath('data.rqd_percentage', '50.00');

        $sent = collect($records->calls)->where('op', 'core_logs.update@1')->last()['payload'];
        $this->assertSame(['rqd_meters' => '1.50'], $sent);
    }
}
