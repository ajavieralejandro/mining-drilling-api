<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantConnectionTest extends TestCase
{
    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/tenant/connection')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_user_without_membership_is_rejected(): void
    {
        $user = User::factory()->create(['role' => UserRole::Supervisor, 'active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/tenant/connection')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP');
    }

    public function test_inactive_membership_is_rejected(): void
    {
        [$user] = $this->makeUserWithMembership(Membership::STATUS_INACTIVE);
        Sanctum::actingAs($user);

        $this->getJson('/api/tenant/connection')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP');
    }

    public function test_inactive_user_is_rejected(): void
    {
        [$user] = $this->makeUserWithMembership();
        $user->forceFill(['active' => false])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/tenant/connection')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'USER_INACTIVE');
    }

    public function test_tenant_without_connector_returns_an_empty_list(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/tenant/connection?tenant_id=someone-else');

        $response->assertOk()
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.tenant.name', $tenant->name)
            ->assertJsonPath('data.connectors', []);

        $payload = $response->json();
        $this->assertSame(['data'], array_keys($payload));
        $this->assertEqualsCanonicalizing(
            ['tenant', 'connectors', 'can_manage_source', 'observed_at'],
            array_keys($payload['data']),
        );
        $this->assertFalse($payload['data']['can_manage_source']);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($payload['data']['tenant']));
    }

    public function test_only_the_authenticated_tenant_connectors_are_returned(): void
    {
        config(['connector.online_ttl_seconds' => 60]);

        [$user, $tenant] = $this->makeUserWithMembership();
        $own = $this->makeConnector($tenant, [
            'label' => 'gateway-propio',
            'status' => Connector::STATUS_ONLINE,
            'capabilities' => ['adapter.mysql', 'drill_holes.list@1'],
            'last_seen_at' => now(),
        ]);

        $otherTenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Otra organizacion']);
        $foreign = $this->makeConnector($otherTenant, [
            'label' => 'gateway-ajeno',
            'status' => Connector::STATUS_ONLINE,
            'capabilities' => ['adapter.postgres'],
            'last_seen_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/tenant/connection?connector_id='.$foreign->id);

        $response->assertOk();
        $connectors = $response->json('data.connectors');
        $this->assertCount(1, $connectors);
        $this->assertSame($own->id, $connectors[0]['id']);
        $this->assertSame('gateway-propio', $connectors[0]['label']);
        $this->assertTrue($connectors[0]['communicating']);
        $this->assertSame('mysql', $connectors[0]['source_engine']);
        $this->assertArrayNotHasKey('installation_location', $connectors[0]);
        $this->assertArrayNotHasKey('capabilities', $connectors[0]);
        $this->assertArrayNotHasKey('connector_token_hash', $connectors[0]);

        $encoded = $response->getContent();
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($foreign->id, $encoded);
        $this->assertStringNotContainsString($own->connector_token_hash, $encoded);
        $this->assertStringNotContainsString('enrollment', strtolower($encoded));
    }

    public function test_missing_engine_is_omitted_and_a_stale_heartbeat_is_offline(): void
    {
        config(['connector.online_ttl_seconds' => 60]);

        [$user, $tenant] = $this->makeUserWithMembership();
        $this->makeConnector($tenant, [
            'label' => null,
            'status' => Connector::STATUS_ONLINE,
            'capabilities' => ['drill_holes.list@1'],
            'last_seen_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($user);

        $connector = $this->getJson('/api/tenant/connection')
            ->assertOk()
            ->json('data.connectors.0');

        $this->assertFalse($connector['communicating']);
        $this->assertSame(Connector::STATUS_ONLINE, $connector['status']);
        $this->assertArrayNotHasKey('source_engine', $connector);
        $this->assertArrayNotHasKey('installation_location', $connector);
        $this->assertNotNull($connector['last_seen_at']);
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function makeUserWithMembership(string $status = Membership::STATUS_ACTIVE): array
    {
        $tenant = Tenant::create([
            'id' => (string) Str::ulid(),
            'name' => 'Organizacion '.Str::random(4),
        ]);
        $user = User::factory()->create([
            'role' => UserRole::Supervisor,
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConnector(Tenant $tenant, array $overrides = []): Connector
    {
        return Connector::create(array_merge([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'label' => 'connector',
            'status' => Connector::STATUS_OFFLINE,
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1'],
            'connector_token_hash' => hash('sha256', Str::random(40)),
            'last_seen_at' => null,
        ], $overrides));
    }
}
