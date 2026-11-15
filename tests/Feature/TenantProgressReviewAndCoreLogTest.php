<?php

namespace Tests\Feature;

use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantCoreLog;
use App\Models\TenantHoleOperationalState;
use App\Models\TenantHoleProgressLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantProgressReviewAndCoreLogTest extends TestCase
{
    use RefreshDatabase;

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

    private function createOperationalState(Tenant $tenant, string $originId = 'orig-1', string $holeId = 'POZO-01', string $currentDepth = '10.00'): TenantHoleOperationalState
    {
        return TenantHoleOperationalState::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'origin_id' => $originId,
            'hole_id' => $holeId,
            'status' => 'in_progress',
            'initial_depth' => '0.00',
            'current_depth' => $currentDepth,
            'depth_initialized' => true,
            'depth_initialized_at' => now(),
        ]);
    }

    private function bindMockHoleRepository(string $originId = 'orig-1'): void
    {
        $mockRepo = new class($originId) implements TenantHoleRepositoryInterface
        {
            public function __construct(private readonly string $originId) {}

            public function list(\App\Support\TenantContext $context, string $correlationId, int $limit = 10): array
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

            public function find(\App\Support\TenantContext $context, string $correlationId, string $holeId): array
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
        $state = $this->createOperationalState($tenant);

        $progressLog = TenantHoleProgressLog::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'operational_state_id' => $state->id,
            'origin_id' => $state->origin_id,
            'hole_id' => $state->hole_id,
            'user_id' => $driller->id,
            'depth_from' => '10.00',
            'depth_to' => '14.50',
            'depth_current' => '14.50',
            'hours_worked' => '7.50',
            'shift' => 'TURNO 1',
            'status' => 'pending_review',
            'idempotency_key' => 'idem-test-pending-1234',
            'payload_hash' => hash('sha256', 'payload-pending-1'),
            'logged_at' => '2026-11-10T12:00:00Z',
        ]);

        Sanctum::actingAs($supervisor);

        // 1. Listar pendientes
        $listRes = $this->getJson('/api/tenant/progress/pending');
        $listRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending_review')
            ->assertJsonPath('data.0.hours_worked', '7.50');

        // 2. Aprobar
        $approveRes = $this->postJson("/api/tenant/progress/{$progressLog->id}/review", [
            'action' => 'approve',
            'review_notes' => 'Tramos y metros verificados en planilla.',
        ]);

        $approveRes->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', $supervisor->id);

        $this->assertDatabaseHas('tenant_hole_progress_logs', [
            'id' => $progressLog->id,
            'status' => 'approved',
            'reviewed_by' => $supervisor->id,
        ]);
    }

    public function test_supervisor_can_reject_pending_progress_with_notes(): void
    {
        [$tenant, $driller, $supervisor] = $this->setupTenantAndUsers();
        $state = $this->createOperationalState($tenant);

        $progressLog = TenantHoleProgressLog::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'operational_state_id' => $state->id,
            'origin_id' => $state->origin_id,
            'hole_id' => $state->hole_id,
            'user_id' => $driller->id,
            'depth_from' => '10.00',
            'depth_to' => '16.00',
            'depth_current' => '16.00',
            'hours_worked' => '8.00',
            'shift' => 'TURNO 2',
            'status' => 'pending_review',
            'idempotency_key' => 'idem-test-reject-1234',
            'payload_hash' => hash('sha256', 'payload-reject-1'),
            'logged_at' => '2026-11-10T19:00:00Z',
        ]);

        Sanctum::actingAs($supervisor);

        // Rechazo sin motivo debe fallar (validación)
        $this->postJson("/api/tenant/progress/{$progressLog->id}/review", [
            'action' => 'reject',
        ])->assertUnprocessable();

        // Rechazo con motivo
        $this->postJson("/api/tenant/progress/{$progressLog->id}/review", [
            'action' => 'reject',
            'review_notes' => 'Metraje inconsistente con el desgaste de corona. Corregir y reenviar.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reviewed_by', $supervisor->id);
    }

    public function test_driller_cannot_review_progress(): void
    {
        [$tenant, $driller] = $this->setupTenantAndUsers();
        $state = $this->createOperationalState($tenant);

        $progressLog = TenantHoleProgressLog::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'operational_state_id' => $state->id,
            'origin_id' => $state->origin_id,
            'hole_id' => $state->hole_id,
            'user_id' => $driller->id,
            'depth_from' => '10.00',
            'depth_to' => '14.00',
            'depth_current' => '14.00',
            'hours_worked' => '7.00',
            'shift' => 'TURNO 1',
            'status' => 'pending_review',
            'idempotency_key' => 'idem-test-driller-unauth',
            'payload_hash' => hash('sha256', 'driller-unauth'),
            'logged_at' => '2026-11-10T14:00:00Z',
        ]);

        Sanctum::actingAs($driller);

        $this->getJson('/api/tenant/progress/pending')->assertForbidden();
        $this->postJson("/api/tenant/progress/{$progressLog->id}/review", [
            'action' => 'approve',
        ])->assertForbidden();
    }

    public function test_blasting_window_validation_rejects_night_blasting_hours(): void
    {
        $request = new \App\Http\Requests\Tenant\StoreTenantHoleProgressRequest();
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
        // Prueba unitaria/lógica del cálculo RQD:
        // Corrida de 10.00 a 13.00 m (Largo corrida = 3.00 m).
        // Testigo recuperado = 2.70 m.
        // Testigos sanos >= 10 cm = 2.10 m.
        // RQD (%) = (2.10 / 3.00) * 100 = 70.00%
        // Pérdida de testigo = 3.00 - 2.70 = 0.30 m
        $metrics = TenantCoreLog::calculateMetrics(10.0, 13.0, 2.7, 2.1);

        $this->assertEquals('3.00', $metrics['drill_length']);
        $this->assertEquals('2.70', $metrics['recovered_meters']);
        $this->assertEquals('2.10', $metrics['rqd_meters']);
        $this->assertEquals('0.30', $metrics['core_loss_meters']);
        $this->assertEquals('70.00', $metrics['rqd_percentage']);
    }

    public function test_core_log_request_validates_inconsistent_metrics(): void
    {
        $request = new \App\Http\Requests\Tenant\StoreTenantCoreLogRequest();
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
        $state = $this->createOperationalState($tenant);

        $progressLog1 = TenantHoleProgressLog::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'operational_state_id' => $state->id,
            'origin_id' => $state->origin_id,
            'hole_id' => $state->hole_id,
            'user_id' => $driller->id,
            'depth_from' => '10.00',
            'depth_to' => '14.50',
            'depth_current' => '14.50',
            'hours_worked' => '7.50',
            'shift' => 'TURNO 1',
            'status' => 'pending_review',
            'idempotency_key' => 'idem-filter-1',
            'payload_hash' => hash('sha256', 'payload-1'),
            'logged_at' => '2026-11-10T12:00:00Z',
        ]);

        $progressLog2 = TenantHoleProgressLog::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'operational_state_id' => $state->id,
            'origin_id' => $state->origin_id,
            'hole_id' => $state->hole_id,
            'user_id' => $driller->id,
            'depth_from' => '14.50',
            'depth_to' => '18.00',
            'depth_current' => '18.00',
            'hours_worked' => '8.00',
            'shift' => 'TURNO 2',
            'status' => 'approved',
            'idempotency_key' => 'idem-filter-2',
            'payload_hash' => hash('sha256', 'payload-2'),
            'logged_at' => '2026-11-10T20:00:00Z',
        ]);

        Sanctum::actingAs($supervisor);

        // 1. Ver detalle de un parte diario específico
        $showRes = $this->getJson("/api/tenant/progress/{$progressLog1->id}");
        $showRes->assertOk()
            ->assertJsonPath('data.id', $progressLog1->id)
            ->assertJsonPath('data.depth_from', '10.00')
            ->assertJsonPath('data.depth_to', '14.50')
            ->assertJsonPath('data.advance_meters', '4.50');

        // 2. Pending list incluye summary
        $pendingRes = $this->getJson('/api/tenant/progress/pending');
        $pendingRes->assertOk()
            ->assertJsonPath('summary.total_pending', 1)
            ->assertJsonPath('summary.total_pending_meters', '4.50')
            ->assertJsonPath('summary.holes_count', 1);

        // 3. Conector / holes progress con filtro por status
        // Simulamos el origen
        Connector::create([
            'id' => 'orig-1',
            'tenant_id' => $tenant->id,
            'connector_token_hash' => hash('sha256', 'token-1'),
            'status' => 'active',
            'communicating' => true,
            'last_seen_at' => now(),
        ]);

        $this->bindMockHoleRepository('orig-1');

        $filterPending = $this->getJson("/api/tenant/holes/{$state->hole_id}/progress?origin_id=orig-1&status=pending_review");
        $filterPending->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $progressLog1->id);

        $filterApproved = $this->getJson("/api/tenant/holes/{$state->hole_id}/progress?origin_id=orig-1&status=approved");
        $filterApproved->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $progressLog2->id);
    }

    public function test_geologist_can_show_update_delete_core_log_and_view_rqd_summary(): void
    {
        [$tenant, , , $geologist] = $this->setupTenantAndUsers();

        $this->bindMockHoleRepository('orig-1');

        Connector::create([
            'id' => 'orig-1',
            'tenant_id' => $tenant->id,
            'connector_token_hash' => hash('sha256', 'token-2'),
            'status' => 'active',
            'communicating' => true,
            'last_seen_at' => now(),
        ]);

        $coreLog1 = TenantCoreLog::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'origin_id' => 'orig-1',
            'hole_id' => 'POZO-01',
            'box_number' => 'CAJA-01',
            'run_from' => '0.00',
            'run_to' => '3.00',
            'drill_length' => '3.00',
            'recovered_meters' => '2.80',
            'rqd_meters' => '2.50',
            'rqd_percentage' => '83.33',
            'core_loss_meters' => '0.20',
            'logged_by' => $geologist->id,
            'logged_at' => now(),
            'geology_notes' => 'Pórfido dacítico moderadamente alterado',
        ]);

        $coreLog2 = TenantCoreLog::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'origin_id' => 'orig-1',
            'hole_id' => 'POZO-01',
            'box_number' => 'CAJA-02',
            'run_from' => '3.00',
            'run_to' => '6.00',
            'drill_length' => '3.00',
            'recovered_meters' => '2.90',
            'rqd_meters' => '2.70',
            'rqd_percentage' => '90.00',
            'core_loss_meters' => '0.10',
            'logged_by' => $geologist->id,
            'logged_at' => now(),
            'geology_notes' => 'Roca competente, fracturas selladas',
        ]);

        Sanctum::actingAs($geologist);

        // 1. Listar core logs con rqd_summary consolidado
        $listRes = $this->getJson('/api/tenant/holes/POZO-01/core-logs?origin_id=orig-1');
        $listRes->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('rqd_summary.total_runs', 2)
            ->assertJsonPath('rqd_summary.total_drill_length', '6.00')
            ->assertJsonPath('rqd_summary.total_recovered_meters', '5.70')
            ->assertJsonPath('rqd_summary.recovery_percentage', '95.00')
            ->assertJsonPath('rqd_summary.total_rqd_meters', '5.20')
            ->assertJsonPath('rqd_summary.average_rqd_percentage', '86.66')
            ->assertJsonPath('rqd_summary.rqd_classification', 'Buena')
            ->assertJsonPath('rqd_summary.total_boxes', 2);

        // 2. Ver un registro específico
        $showRes = $this->getJson("/api/tenant/holes/POZO-01/core-logs/{$coreLog1->id}?origin_id=orig-1");
        $showRes->assertOk()
            ->assertJsonPath('data.id', $coreLog1->id)
            ->assertJsonPath('data.box_number', 'CAJA-01');

        // 3. Modificar notas y foto del cajón de testigos
        $updateRes = $this->patchJson("/api/tenant/holes/POZO-01/core-logs/{$coreLog1->id}?origin_id=orig-1", [
            'box_number' => 'CAJA-01-A',
            'geology_notes' => 'Presencia de pirita diseminada al 2%',
            'photo_url' => 'https://storage.undsurf.com/photos/box-01-a.jpg',
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.box_number', 'CAJA-01-A')
            ->assertJsonPath('data.geology_notes', 'Presencia de pirita diseminada al 2%')
            ->assertJsonPath('data.photo_url', 'https://storage.undsurf.com/photos/box-01-a.jpg');

        $this->assertDatabaseHas('tenant_core_logs', [
            'id' => $coreLog1->id,
            'box_number' => 'CAJA-01-A',
            'photo_url' => 'https://storage.undsurf.com/photos/box-01-a.jpg',
        ]);

        // 4. Eliminar el segundo registro
        $deleteRes = $this->deleteJson("/api/tenant/holes/POZO-01/core-logs/{$coreLog2->id}?origin_id=orig-1");
        $deleteRes->assertOk();

        $this->assertDatabaseMissing('tenant_core_logs', [
            'id' => $coreLog2->id,
        ]);
    }
}
