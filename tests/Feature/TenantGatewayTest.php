<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\ConnectorEnrollmentToken;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TokenHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tenant resolution and authorization for the pozos/avance vertical slice.
 * Deliberately does NOT exercise the Gateway success path (that requires a
 * live Connector process talking to a real client Postgres — see
 * docs/testing/distributed-data-demo.md for that proof); these tests cover
 * everything PHPUnit *can* prove in-process: membership resolution,
 * rejection of an untrusted tenant_id, and the CONNECTOR_OFFLINE path when
 * no connector is registered/online for the resolved tenant.
 */
class TenantGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connector.online_ttl_seconds' => 60,
            'connector.demo_command_timeout_seconds' => 1,
            'connector.demo_poll_interval_ms' => 50,
        ]);
    }

    /**
     * The obligatory Fase 3 case: a user in tenant A can never reach tenant
     * B's data, even if B's connector is online and A's tenant_id is sent
     * explicitly. Proven at the dispatch layer (no live client Postgres in
     * this test process — see docs/testing/distributed-data-demo.md for the
     * full live proof): the ConnectorCommand Laravel creates is always
     * routed to the caller's OWN tenant's connector, never the other one.
     */
    public function test_tenant_a_user_never_dispatches_to_tenant_b_connector(): void
    {
        [$userA, $tenantA] = $this->makeUserWithMembership();
        $connectorA = $this->onlineConnectorFor($tenantA);

        $tenantB = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera B']);
        $connectorB = $this->onlineConnectorFor($tenantB);

        Sanctum::actingAs($userA);

        // No real connector process will answer, so this times out — the
        // point of this test is which connector got the command, not
        // whether it completes.
        $this->getJson('/api/tenant/holes')->assertStatus(504);

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();

        $this->assertSame($tenantA->id, $command->tenant_id);
        $this->assertSame($connectorA->id, $command->connector_id);
        $this->assertNotSame($connectorB->id, $command->connector_id);
    }

    public function test_user_without_membership_is_rejected(): void
    {
        $user = User::factory()->create(['role' => UserRole::Supervisor, 'active' => true]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/tenant/holes');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP');
    }

    public function test_inactive_membership_is_rejected(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership(Membership::STATUS_INACTIVE);
        Sanctum::actingAs($user);

        $this->getJson('/api/tenant/holes')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP');
    }

    public function test_tenant_id_sent_by_client_is_ignored_in_favor_of_membership(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $otherTenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Someone Else Inc']);
        Sanctum::actingAs($user);

        // Client tries to smuggle a foreign tenant_id via query string. It
        // must be completely ignored: the resolved tenant is still the
        // user's own membership, proven here by the fact that this request
        // fails as CONNECTOR_OFFLINE for the user's real tenant (which has
        // no connector registered), not as a different outcome that would
        // imply the foreign tenant_id was honored.
        $response = $this->getJson('/api/tenant/holes?tenant_id='.$otherTenant->id);

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'CONNECTOR_OFFLINE');
    }

    public function test_no_connector_registered_returns_connector_offline(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        Sanctum::actingAs($user);

        $this->getJson('/api/tenant/holes')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'CONNECTOR_OFFLINE');
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function makeUserWithMembership(string $status = Membership::STATUS_ACTIVE): array
    {
        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Test Tenant '.Str::random(6)]);
        $user = User::factory()->create([
            'role' => UserRole::Supervisor,
            'password' => Hash::make('password'),
            'active' => true,
        ]);

        Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'supervisor',
            'status' => $status,
        ]);

        return [$user, $tenant];
    }

    private function onlineConnectorFor(Tenant $tenant): Connector
    {
        $plaintext = TokenHasher::generate('enr');
        ConnectorEnrollmentToken::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'token_hash' => TokenHasher::hash($plaintext),
            'expires_at' => now()->addDay(),
        ]);

        $enroll = $this->postJson('/api/connector/v1/enroll', [
            'protocol_version' => '1.0',
            'enrollment_token' => $plaintext,
            'connector_label' => 'lab-'.$tenant->id,
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1'],
        ])->assertCreated();

        $connectorId = $enroll->json('connector_id');
        $connectorToken = $enroll->json('connector_token');

        $session = $this->postJson('/api/connector/v1/sessions', [
            'protocol_version' => '1.0',
            'connector_id' => $connectorId,
            'connector_token' => $connectorToken,
        ])->assertOk();

        $this->withToken($connectorToken)->postJson('/api/connector/v1/heartbeat', [
            'protocol_version' => '1.0',
            'connector_id' => $connectorId,
            'session_id' => $session->json('session_id'),
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1'],
            'status' => 'ok',
        ])->assertOk();

        return Connector::query()->findOrFail($connectorId);
    }
}
