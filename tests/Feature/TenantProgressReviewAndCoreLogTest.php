<?php

namespace Tests\Feature;

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
}
