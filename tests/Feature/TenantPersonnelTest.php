<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connector\CommandDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantPersonnelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithRole(string $role = 'admin', string $membershipRole = 'admin'): array
    {
        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Test']);
        $user = User::create([
            'name' => 'Operator '.Str::random(4),
            'email' => strtolower(Str::random(8)).'@undsurf.test',
            'role' => $role,
            'password' => Hash::make('secret123'),
            'active' => true,
        ]);

        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $membershipRole,
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $user->email,
        ]);

        return [$user, $tenant, $membership];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/tenant/personnel')->assertUnauthorized();
        $this->postJson('/api/tenant/personnel', [])->assertUnauthorized();
    }

    public function test_driller_cannot_manage_personnel(): void
    {
        [$driller] = $this->makeUserWithRole('driller', 'driller');
        Sanctum::actingAs($driller);

        $this->postJson('/api/tenant/personnel', [
            'name' => 'Nuevo Perforista',
            'email' => 'nuevo@mina.test',
            'role' => 'driller',
        ])->assertForbidden();

        $this->patchJson('/api/tenant/personnel/1', [
            'role' => 'supervisor',
        ])->assertForbidden();

        $this->deleteJson('/api/tenant/personnel/1')->assertForbidden();
    }

    public function test_admin_can_list_personnel(): void
    {
        [$admin, $tenant] = $this->makeUserWithRole('admin', 'admin');

        // Create a driller member in the same tenant
        $drillerUser = User::create([
            'name' => 'Pedro Perforista',
            'email' => 'pedro@mina.test',
            'role' => 'driller',
            'password' => Hash::make('secret'),
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $drillerUser->id,
            'tenant_id' => $tenant->id,
            'role' => 'driller',
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => 'pedro@mina.test',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/tenant/personnel')->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $names = array_column($data, 'name');
        $this->assertContains('Pedro Perforista', $names);
    }

    public function test_admin_can_create_personnel_and_syncs_to_connector_with_adr04_purge(): void
    {
        [$admin, $tenant] = $this->makeUserWithRole('admin', 'admin');

        $connector = Connector::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'label' => 'test-connector',
            'status' => 'active',
            'connector_token_hash' => hash('sha256', 'dummy-token'),
            'last_seen_at' => now(),
        ]);

        $mockCommand = ConnectorCommand::create([
            'id' => (string) Str::ulid(),
            'request_id' => 'req_'.(string) Str::ulid(),
            'correlation_id' => 'cor_'.(string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'connector_id' => $connector->id,
            'op' => 'personnel.sync@1',
            'payload_json' => ['test' => true],
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'deadline_at' => now()->addMinutes(1),
        ]);

        $dispatcher = \Mockery::mock(CommandDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($c, $op, $payload) use ($connector) {
                // VERIFICACIÓN DE SEGURIDAD: La contraseña NUNCA viaja al conector
                return $c->id === $connector->id
                    && $op === 'personnel.sync@1'
                    && $payload['email'] === 'carlos@mina.test'
                    && $payload['role'] === 'driller'
                    && ! isset($payload['password']);
            })
            ->andReturn($mockCommand);

        $dispatcher->shouldReceive('waitForResult')->once()->andReturn($mockCommand);
        $this->app->instance(CommandDispatcher::class, $dispatcher);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tenant/personnel', [
            'name' => 'Carlos Perforista',
            'email' => 'carlos@mina.test',
            'role' => 'driller',
            'external_hr_id' => 'LEG-001',
        ])->assertCreated();

        $this->assertTrue($response->json('connector_synced'));
        $this->assertNotEmpty($response->json('temporary_password'));

        // Verificar que el usuario y membership se crearon en UndSurf
        $newUser = User::where('email', 'carlos@mina.test')->first();
        $this->assertNotNull($newUser);
        $this->assertEquals('driller', $newUser->role->value ?? (string) $newUser->role);

        $membership = Membership::where('user_id', $newUser->id)->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($membership);
        $this->assertEquals('active', $membership->status);
        $this->assertEquals('LEG-001', $membership->external_hr_id);

        // VERIFICACIÓN ADR-04: El payload y el result deben estar PURGADOS (vacío / null) en la base de datos
        $mockCommand->refresh();
        $this->assertEmpty($mockCommand->payload_json, 'ADR-04: payload_json debe haber sido purgado tras entrega');
        $this->assertNull($mockCommand->result_json, 'ADR-04: result_json debe haber sido purgado tras entrega');
    }

    public function test_admin_can_update_personnel_role(): void
    {
        [$admin, $tenant] = $this->makeUserWithRole('admin', 'admin');

        $user = User::create([
            'name' => 'Ana Ríos',
            'email' => 'ana@mina.test',
            'role' => 'geologist',
            'password' => Hash::make('secret'),
            'active' => true,
        ]);
        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'geologist',
            'status' => Membership::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/tenant/personnel/'.$user->id, [
            'role' => 'supervisor',
            'name' => 'Ana Ríos (Supervisor)',
        ])->assertOk();

        $this->assertEquals('supervisor', $response->json('data.role'));

        $user->refresh();
        $this->assertEquals('Ana Ríos (Supervisor)', $user->name);
        $this->assertEquals('supervisor', $user->role->value ?? (string) $user->role);
    }

    public function test_admin_can_deactivate_personnel_and_tokens_are_revoked(): void
    {
        [$admin, $tenant] = $this->makeUserWithRole('admin', 'admin');

        $worker = User::create([
            'name' => 'Diego Soto',
            'email' => 'diego@mina.test',
            'role' => 'helper',
            'password' => Hash::make('secret'),
            'active' => true,
        ]);
        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $worker->id,
            'tenant_id' => $tenant->id,
            'role' => 'helper',
            'status' => Membership::STATUS_ACTIVE,
        ]);

        // Simular token emitido para el trabajador
        $token = $worker->createToken('test-device')->plainTextToken;
        $this->assertCount(1, $worker->tokens);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson('/api/tenant/personnel/'.$worker->id, [
            'reason' => 'Fin de contrato laboral',
        ])->assertOk();

        $this->assertEquals('inactive', $response->json('data.status'));

        $membership->refresh();
        $this->assertEquals(Membership::STATUS_INACTIVE, $membership->status);
        $this->assertEquals('Fin de contrato laboral', $membership->deactivation_reason);

        // Tokens de Sanctum deben estar revocados inmediatamente
        $this->assertCount(0, $worker->tokens()->get());
    }
}
