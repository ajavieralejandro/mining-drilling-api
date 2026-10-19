<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\ConnectorSession;
use App\Models\Membership;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\TenantSourceConfig;
use App\Models\TenantSourcePolicy;
use App\Models\User;
use App\Services\Source\SourceCommandGateway;
use App\Support\DestinationPolicy;
use App\Support\TokenHasher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantSourceConfigTest extends TestCase
{
    private const PASSWORD = 'clave-de-laboratorio-9f3a';

    public function test_fingerprint_matches_the_connector_vector(): void
    {
        $this->assertSame(
            DestinationPolicy::FINGERPRINT_VECTOR,
            DestinationPolicy::fingerprint('postgres', 'db.example.com', 5432, 'holes', 'public'),
        );
    }

    public function test_supervisor_cannot_view_or_change_the_source(): void
    {
        [$user, $tenant] = $this->makeUser($tenantRole = 'supervisor');
        $this->makeConnector($tenant, TokenHasher::generate('con'));
        Sanctum::actingAs($user);

        $this->getJson('/api/tenant/connection')
            ->assertOk()
            ->assertJsonPath('data.can_manage_source', false)
            ->assertJsonMissingPath('data.source');

        $this->postJson('/api/tenant/connection/source', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseCount('tenant_source_configs', 0);
    }

    public function test_another_organization_cannot_see_or_fetch_the_configuration(): void
    {
        [$adminA, $tenantA] = $this->makeUser('admin');
        $tokenA = TokenHasher::generate('con');
        $connectorA = $this->makeConnector($tenantA, $tokenA);
        [$adminB, $tenantB] = $this->makeUser('admin');
        $tokenB = TokenHasher::generate('con');
        $this->makeConnector($tenantB, $tokenB);
        $this->bindSuccess();

        Sanctum::actingAs($adminA);
        $saved = $this->postJson('/api/tenant/connection/source', $this->payload())->assertCreated();
        $configId = $saved->json('data.id');
        $host = $saved->json('data.host');

        Sanctum::actingAs($adminB);
        $foreign = $this->getJson('/api/tenant/connection?tenant_id='.$tenantA->id)->assertOk();
        $this->assertStringNotContainsString($host, $foreign->getContent());
        $this->assertStringNotContainsString(self::PASSWORD, $foreign->getContent());

        $this->withToken($tokenB)
            ->getJson('/api/connector/v1/source-config/'.$configId)
            ->assertNotFound();

        $this->withToken($tokenA)
            ->getJson('/api/connector/v1/source-config/'.$configId)
            ->assertOk()
            ->assertJsonPath('data.password', self::PASSWORD)
            ->assertJsonPath('data.host', '10.4.0.8');

        $this->assertSame($tenantA->id, TenantSourceConfig::query()->find($configId)->tenant_id);
        $this->assertNotSame($connectorA->tenant_id, $tenantB->id);
    }

    public function test_save_does_not_apply_and_response_and_logs_omit_the_password(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $this->makeConnector($tenant, TokenHasher::generate('con'));
        Sanctum::actingAs($user);
        $logged = '';
        Log::listen(function ($event) use (&$logged) {
            $logged .= json_encode($event);
        });

        $response = $this->postJson('/api/tenant/connection/source', $this->payload())
            ->assertCreated()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.password_configured', true)
            ->assertJsonPath('data.host', '10.4.0.8');

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::PASSWORD, $body);
        $this->assertStringNotContainsString(self::PASSWORD, $logged);
        $this->assertStringNotContainsString(self::PASSWORD, PlatformAuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString(self::PASSWORD, TenantSourceConfig::query()->get()->toJson());
        $this->assertDatabaseCount('connector_commands', 0);
    }

    public function test_failed_test_keeps_the_previous_origin(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $this->makeConnector($tenant, TokenHasher::generate('con'), Connector::STATUS_ONLINE);
        $active = $this->makeActive($tenant);
        Sanctum::actingAs($user);
        $this->bindFailure('AUTHENTICATION_FAILED');

        $this->postJson('/api/tenant/connection/source', $this->payload(['host' => '10.8.0.8', 'database' => 'otra']))
            ->assertCreated();
        $this->postJson('/api/tenant/connection/source/test')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'AUTHENTICATION_FAILED');

        $this->assertSame('active', $active->fresh()->state);
        $this->assertSame('draft', TenantSourceConfig::query()->where('host', '10.8.0.8')->value('state'));
        $command = ConnectorCommand::query()->first();
        $this->assertSame(['config_id' => TenantSourceConfig::query()->where('host', '10.8.0.8')->value('id')], $command->payload_json);
        $this->assertStringNotContainsString(self::PASSWORD, $command->toJson());
    }

    public function test_offline_connector_does_not_create_a_command_or_change_the_origin(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $this->makeConnector($tenant, TokenHasher::generate('con'), Connector::STATUS_OFFLINE);
        Sanctum::actingAs($user);

        $this->postJson('/api/tenant/connection/source', $this->payload())->assertCreated();
        $this->postJson('/api/tenant/connection/source/test')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'CONNECTOR_OFFLINE');

        $this->assertDatabaseCount('connector_commands', 0);
        $this->assertSame('draft', TenantSourceConfig::query()->value('state'));
    }

    public function test_apply_requires_a_successful_test_and_explicit_confirmation(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $connector = $this->makeConnector($tenant, TokenHasher::generate('con'), Connector::STATUS_ONLINE);
        Sanctum::actingAs($user);
        $fake = $this->bindSuccess();

        $this->postJson('/api/tenant/connection/source', $this->payload())->assertCreated();
        $this->postJson('/api/tenant/connection/source/apply', ['confirm' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TEST_REQUIRED');

        $this->postJson('/api/tenant/connection/source/test')->assertOk()->assertJsonPath('applied', false);
        $this->postJson('/api/tenant/connection/source/apply', ['confirm' => false])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CONFIRMATION_REQUIRED');

        $applied = $this->postJson('/api/tenant/connection/source/apply', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('data.state', 'active');

        $this->assertNotNull($applied->json('data.applied_at'));
        $this->assertSame('source_config.apply@1', $fake->operations[1]);
        $this->assertSame(1, TenantSourceConfig::query()->where('state', 'active')->count());
        $this->assertSame($connector->id, TenantSourceConfig::query()->where('state', 'active')->value('connector_id'));
    }

    public function test_cancel_discards_the_draft_and_keeps_the_active_origin(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $this->makeConnector($tenant, TokenHasher::generate('con'));
        $active = $this->makeActive($tenant);
        Sanctum::actingAs($user);

        $this->postJson('/api/tenant/connection/source', $this->payload(['host' => '10.9.0.4']))->assertCreated();
        $this->postJson('/api/tenant/connection/source/cancel')
            ->assertOk()
            ->assertJsonPath('data.applied', false);

        $this->assertSame('active', $active->fresh()->state);
        $this->assertSame('cancelled', TenantSourceConfig::query()->where('host', '10.9.0.4')->value('state'));
    }

    public function test_rejected_destinations_and_unverified_tls_are_not_stored(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $this->makeConnector($tenant, TokenHasher::generate('con'));
        Sanctum::actingAs($user);

        $this->postJson('/api/tenant/connection/source', $this->payload(['host' => '169.254.169.254']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DESTINATION_REJECTED');
        $this->postJson('/api/tenant/connection/source', $this->payload(['tls_mode' => 'disable']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson('/api/tenant/connection/source', $this->payload(['database' => 'undsurf']))
            ->assertStatus(422);
        $this->assertDatabaseCount('tenant_source_configs', 0);
    }

    public function test_heartbeat_report_is_visible_and_a_secret_field_is_discarded(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $token = TokenHasher::generate('con');
        $connector = $this->makeConnector($tenant, $token, Connector::STATUS_ONLINE);
        $session = ConnectorSession::query()->create([
            'id' => (string) Str::ulid(),
            'connector_id' => $connector->id,
            'status' => ConnectorSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/connector/v1/heartbeat', [
            'protocol_version' => '1.0',
            'connector_id' => $connector->id,
            'session_id' => $session->id,
            'source' => [
                'version' => 0,
                'origin' => 'env',
                'engine' => 'postgres',
                'host' => '10.4.0.8',
                'port' => 5432,
                'database' => 'holes',
                'username' => 'undersurf_ro',
                'tls_mode' => 'disable',
                'tls_configured' => false,
            ],
        ])->assertOk();

        Sanctum::actingAs($user);
        $this->getJson('/api/tenant/connection')
            ->assertOk()
            ->assertJsonPath('data.source.reported.host', '10.4.0.8')
            ->assertJsonPath('data.source.reported.tls_mode', 'disable')
            ->assertJsonPath('data.source.active', null);

        $this->withToken($token)->postJson('/api/connector/v1/heartbeat', [
            'protocol_version' => '1.0',
            'connector_id' => $connector->id,
            'session_id' => $session->id,
            'source' => [
                'host' => '10.9.9.9',
                'password' => self::PASSWORD,
            ],
        ])->assertOk();

        $stored = $connector->fresh()->reported_source;
        $this->assertSame('10.4.0.8', $stored['host']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($stored));
        $this->assertStringNotContainsString(self::PASSWORD, PlatformAuditLog::query()->get()->toJson());
    }

    public function test_private_database_is_allowed_and_loopback_follows_the_organization_policy(): void
    {
        [$user, $tenant] = $this->makeUser('admin');
        $this->makeConnector($tenant, TokenHasher::generate('con'));
        Sanctum::actingAs($user);

        $this->postJson('/api/tenant/connection/source', $this->payload(['host' => '10.4.0.8']))
            ->assertCreated();
        $this->postJson('/api/tenant/connection/source', $this->payload(['host' => '127.0.0.1']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DESTINATION_REJECTED');

        TenantSourcePolicy::query()->create([
            'tenant_id' => $tenant->id,
            'allow_loopback' => true,
            'allow_private' => true,
        ]);

        $this->postJson('/api/tenant/connection/source', $this->payload(['host' => '127.0.0.1']))
            ->assertCreated();
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function makeUser(string $role): array
    {
        $tenant = Tenant::query()->create([
            'id' => (string) Str::ulid(),
            'name' => 'Organizacion '.Str::lower($role),
        ]);
        $user = User::factory()->create([
            'role' => $role === 'admin' ? UserRole::Admin : UserRole::Supervisor,
            'active' => true,
        ]);
        Membership::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $role,
            'status' => Membership::STATUS_ACTIVE,
        ]);

        return [$user, $tenant];
    }

    private function makeConnector(Tenant $tenant, string $token, string $status = Connector::STATUS_OFFLINE): Connector
    {
        return Connector::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'label' => 'lab-connector',
            'status' => $status,
            'version' => '0.1.0',
            'capabilities' => ['adapter.postgres', 'drill_holes.list@1'],
            'connector_token_hash' => TokenHasher::hash($token),
            'last_seen_at' => $status === Connector::STATUS_ONLINE ? now() : null,
        ]);
    }

    private function makeActive(Tenant $tenant): TenantSourceConfig
    {
        $connector = Connector::query()->where('tenant_id', $tenant->id)->firstOrFail();

        return TenantSourceConfig::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'connector_id' => $connector->id,
            'version' => 1,
            'state' => TenantSourceConfig::STATE_ACTIVE,
            'display_name' => 'Base anterior',
            'engine' => 'postgres',
            'host' => '10.1.0.8',
            'port' => 5432,
            'database_name' => 'anterior',
            'schema_name' => 'public',
            'username' => 'undersurf_ro',
            'password_ciphertext' => encrypt('anterior-secreto'),
            'tls_mode' => 'verify-full',
            'fingerprint' => DestinationPolicy::fingerprint('postgres', '10.1.0.8', 5432, 'anterior', 'public'),
            'applied_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Base norte',
            'engine' => 'postgres',
            'host' => '10.4.0.8',
            'port' => 5432,
            'database' => 'holes',
            'schema' => 'public',
            'username' => 'undersurf_ro',
            'password' => self::PASSWORD,
            'tls_mode' => 'verify-full',
            'installation_location' => 'Sala de servidores de la mina',
        ], $overrides);
    }

    private function bindSuccess(): FakeSourceCommandGateway
    {
        $fake = new FakeSourceCommandGateway;
        $this->app->instance(SourceCommandGateway::class, $fake);

        return $fake;
    }

    private function bindFailure(string $code): void
    {
        $fake = new FakeSourceCommandGateway;
        $fake->failure = $code;
        $this->app->instance(SourceCommandGateway::class, $fake);
    }
}
