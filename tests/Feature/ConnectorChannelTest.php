<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\ConnectorEnrollmentToken;
use App\Models\ConnectorSession;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Support\TokenHasher;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectorChannelTest extends TestCase
{
    private string $demoToken = 'demo_test_token_canary';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connector.demo_internal_token' => $this->demoToken,
            'connector.online_ttl_seconds' => 60,
            'connector.demo_command_timeout_seconds' => 1,
            'connector.demo_poll_interval_ms' => 50,
        ]);
    }

    public function test_enrollment_valid_and_token_one_time(): void
    {
        [$tenant, $enrollment] = $this->makeEnrollmentToken();

        $first = $this->postJson('/api/connector/v1/enroll', [
            'protocol_version' => '1.0',
            'enrollment_token' => $enrollment,
            'connector_label' => 'lab-1',
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1', 'adapter.postgres'],
        ]);

        $first->assertCreated()
            ->assertJsonPath('protocol_version', '1.0')
            ->assertJsonPath('tenant_id', $tenant->id)
            ->assertJsonStructure(['connector_id', 'connector_token']);

        $this->assertDatabaseMissing('connector_enrollment_tokens', [
            'tenant_id' => $tenant->id,
            'consumed_at' => null,
        ]);

        $second = $this->postJson('/api/connector/v1/enroll', [
            'protocol_version' => '1.0',
            'enrollment_token' => $enrollment,
        ]);

        $second->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_session_heartbeat_and_online_status(): void
    {
        $ctx = $this->enrolledConnector();

        $session = $this->postJson('/api/connector/v1/sessions', [
            'protocol_version' => '1.0',
            'connector_id' => $ctx['connector_id'],
            'connector_token' => $ctx['connector_token'],
        ]);

        $session->assertOk()->assertJsonStructure(['session_id']);

        $hb = $this->withToken($ctx['connector_token'])->postJson('/api/connector/v1/heartbeat', [
            'protocol_version' => '1.0',
            'connector_id' => $ctx['connector_id'],
            'session_id' => $session->json('session_id'),
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1'],
            'status' => 'ok',
            'adapter_status' => ['postgres' => 'ok'],
        ]);

        $hb->assertOk()->assertJsonPath('status', 'ok');

        $connector = Connector::query()->findOrFail($ctx['connector_id']);
        $this->assertSame(Connector::STATUS_ONLINE, $connector->status);
        $this->assertNotNull($connector->last_seen_at);
        $this->assertTrue($connector->isOnline());
    }

    public function test_poll_delivers_command_and_result_completes_with_audit(): void
    {
        $ctx = $this->onlineConnector();

        $command = ConnectorCommand::create([
            'id' => (string) Str::ulid(),
            'request_id' => 'req_test_1',
            'correlation_id' => 'cor_test_1',
            'tenant_id' => $ctx['tenant_id'],
            'connector_id' => $ctx['connector_id'],
            'op' => 'drill_holes.list@1',
            'payload_json' => ['limit' => 10],
            'status' => ConnectorCommand::STATUS_PENDING,
            'deadline_at' => now()->addSeconds(30),
            'dispatched_at' => now(),
        ]);

        $poll = $this->withToken($ctx['connector_token'])->postJson('/api/connector/v1/poll', [
            'protocol_version' => '1.0',
            'connector_id' => $ctx['connector_id'],
            'session_id' => $ctx['session_id'],
            'wait_seconds' => 0,
        ]);

        $poll->assertOk()
            ->assertJsonPath('commands.0.request_id', 'req_test_1')
            ->assertJsonPath('commands.0.op', 'drill_holes.list@1');

        $this->assertSame(ConnectorCommand::STATUS_DELIVERED, $command->fresh()->status);

        $result = $this->withToken($ctx['connector_token'])->postJson('/api/connector/v1/results', [
            'protocol_version' => '1.0',
            'request_id' => 'req_test_1',
            'correlation_id' => 'cor_test_1',
            'connector_id' => $ctx['connector_id'],
            'tenant_id' => $ctx['tenant_id'],
            'op' => 'drill_holes.list@1',
            'status' => 'ok',
            'payload' => [
                'items' => [
                    ['id' => '1', 'code' => 'H-1', 'status' => 'in_progress'],
                ],
            ],
            'duration_ms' => 12,
        ]);

        $result->assertOk();
        $this->assertSame(ConnectorCommand::STATUS_COMPLETED, $command->fresh()->status);

        $this->assertDatabaseHas('platform_audit_logs', [
            'request_id' => 'req_test_1',
            'event' => 'completed',
        ]);
    }

    public function test_demo_list_holes_connector_offline(): void
    {
        $this->enrolledConnector(); // pending / not online heartbeat

        $response = $this->withToken($this->demoToken)->postJson('/api/internal/demo/list-holes', [
            'limit' => 10,
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'CONNECTOR_OFFLINE');
    }

    public function test_demo_list_holes_timeout(): void
    {
        $ctx = $this->onlineConnector();

        $response = $this->withToken($this->demoToken)->postJson('/api/internal/demo/list-holes', [
            'connector_id' => $ctx['connector_id'],
            'limit' => 5,
        ]);

        $response->assertStatus(504)
            ->assertJsonPath('error.code', 'TIMEOUT')
            ->assertJsonStructure(['request_id', 'correlation_id']);

        $this->assertDatabaseHas('platform_audit_logs', [
            'request_id' => $response->json('request_id'),
            'event' => 'timeout',
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'request_id' => $response->json('request_id'),
            'event' => 'dispatched',
        ]);
    }

    public function test_demo_list_holes_not_available_outside_local_or_testing(): void
    {
        app()->instance('env', 'production');

        try {
            $response = $this->withToken($this->demoToken)->postJson('/api/internal/demo/list-holes', [
                'limit' => 10,
            ]);

            $response->assertStatus(404)
                ->assertJsonPath('error.code', 'NOT_FOUND');
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_unauthorized_connector_bearer(): void
    {
        $this->postJson('/api/connector/v1/heartbeat', [
            'protocol_version' => '1.0',
            'connector_id' => 'x',
            'session_id' => 'y',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * P1 Paso 4 — Fase C.3: an active connector's own bearer is rejected
     * once it is replaced by an unrelated, well-formed string — proves
     * AuthenticateConnector actually checks the token value (hash lookup),
     * not merely its presence.
     */
    public function test_connector_bearer_wrong_token_is_rejected(): void
    {
        $ctx = $this->onlineConnector();

        $this->withToken('wrong-token-that-is-not-registered')
            ->postJson('/api/connector/v1/heartbeat', [
                'protocol_version' => '1.0',
                'connector_id' => $ctx['connector_id'],
                'session_id' => $ctx['session_id'],
                'status' => 'ok',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * P1 Paso 4 — Fase C.4: "Authorization: Bearer " with nothing after
     * it. Request::bearerToken() returns '' here (not null), so this
     * exercises AuthenticateConnector's separate empty-string branch,
     * not just the missing-header one already covered above.
     */
    public function test_connector_bearer_empty_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '])
            ->postJson('/api/connector/v1/heartbeat', [
                'protocol_version' => '1.0',
                'connector_id' => 'x',
                'session_id' => 'y',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * P1 Paso 4 — Fase C.5: a differently-schemed Authorization header
     * (Basic instead of Bearer) must not be treated as a token to look up
     * — Request::bearerToken() returns null for it, same as a missing
     * header, and AuthenticateConnector rejects it the same way.
     */
    public function test_connector_bearer_malformed_scheme_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('user:pass')])
            ->postJson('/api/connector/v1/heartbeat', [
                'protocol_version' => '1.0',
                'connector_id' => 'x',
                'session_id' => 'y',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * P1 Paso 4: confirms AuthenticateConnector actually protects /poll
     * and /results too, not just /heartbeat (which every other bearer
     * test above exercises) — the three sensitive endpoints P0's holes
     * flow depends on.
     */
    public function test_connector_bearer_required_on_poll_and_results_too(): void
    {
        $ctx = $this->onlineConnector();

        // onlineConnector() authenticates internally to reach STATUS_ONLINE
        // (via heartbeat), which leaves withToken()'s Authorization header
        // as this test's default for every subsequent call — drop it so
        // these two requests are genuinely unauthenticated.
        $this->withoutToken();

        $this->postJson('/api/connector/v1/poll', [
            'protocol_version' => '1.0',
            'connector_id' => $ctx['connector_id'],
            'session_id' => $ctx['session_id'],
        ])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHORIZED');

        $this->postJson('/api/connector/v1/results', [
            'protocol_version' => '1.0',
            'request_id' => 'req_whatever',
            'correlation_id' => 'cor_whatever',
            'connector_id' => $ctx['connector_id'],
            'tenant_id' => $ctx['tenant_id'],
            'op' => 'drill_holes.list@1',
            'status' => 'ok',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * P1 Paso 4 — Fase B: the secret must never appear in a response.
     * Only its SHA-256 (connector_token_hash) is ever persisted, and even
     * that must not serialize out of the model now that Connector::$hidden
     * covers it.
     */
    public function test_connector_token_hash_never_serializes(): void
    {
        $ctx = $this->onlineConnector();

        $connector = Connector::query()->findOrFail($ctx['connector_id']);

        $this->assertArrayNotHasKey('connector_token_hash', $connector->toArray());
        $this->assertStringNotContainsString('connector_token_hash', $connector->toJson());
    }

    public function test_control_plane_never_persists_client_dsn_canary(): void
    {
        $canary = 'postgres://CANARY_SECRET_USER:CANARY_SECRET_PASS@127.0.0.1:5432/client_db';

        $ctx = $this->onlineConnector();

        $this->withToken($ctx['connector_token'])->postJson('/api/connector/v1/heartbeat', [
            'protocol_version' => '1.0',
            'connector_id' => $ctx['connector_id'],
            'session_id' => $ctx['session_id'],
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1'],
            'status' => 'ok',
            'adapter_status' => ['postgres' => 'ok', 'note' => $canary],
        ])->assertOk();

        $this->assertDatabaseMissing('connectors', ['version' => $canary]);

        foreach (PlatformAuditLog::query()->get() as $log) {
            $encoded = json_encode($log->toArray());
            $this->assertStringNotContainsString('CANARY_SECRET_PASS', $encoded ?: '');
            $this->assertStringNotContainsString('CANARY_SECRET_USER', $encoded ?: '');
        }

        $example = file_get_contents(base_path('.env.example'));
        $this->assertStringNotContainsString('UNDSURF_PG_DSN', $example);
        $this->assertStringNotContainsString('PG_DSN', $example);
    }

    public function test_enrollment_does_not_store_plaintext_tokens(): void
    {
        [$tenant, $enrollment] = $this->makeEnrollmentToken();

        $response = $this->postJson('/api/connector/v1/enroll', [
            'protocol_version' => '1.0',
            'enrollment_token' => $enrollment,
        ])->assertCreated();

        $connectorToken = $response->json('connector_token');

        foreach (ConnectorEnrollmentToken::query()->get() as $row) {
            $this->assertNotSame($enrollment, $row->token_hash);
            $this->assertSame(TokenHasher::hash($enrollment), $row->token_hash);
        }

        $connector = Connector::query()->findOrFail($response->json('connector_id'));
        $this->assertSame(TokenHasher::hash($connectorToken), $connector->connector_token_hash);
        $this->assertNotSame($connectorToken, $connector->connector_token_hash);
        $this->assertSame($tenant->id, $connector->tenant_id);
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function makeEnrollmentToken(): array
    {
        $tenant = Tenant::create([
            'id' => (string) Str::ulid(),
            'name' => 'Test Tenant',
        ]);

        $plaintext = TokenHasher::generate('enr');

        ConnectorEnrollmentToken::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'token_hash' => TokenHasher::hash($plaintext),
            'expires_at' => now()->addDay(),
        ]);

        return [$tenant, $plaintext];
    }

    /**
     * @return array{tenant_id: string, connector_id: string, connector_token: string}
     */
    private function enrolledConnector(): array
    {
        [$tenant, $enrollment] = $this->makeEnrollmentToken();

        $response = $this->postJson('/api/connector/v1/enroll', [
            'protocol_version' => '1.0',
            'enrollment_token' => $enrollment,
            'connector_label' => 'lab',
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1'],
        ])->assertCreated();

        return [
            'tenant_id' => $tenant->id,
            'connector_id' => $response->json('connector_id'),
            'connector_token' => $response->json('connector_token'),
        ];
    }

    /**
     * @return array{tenant_id: string, connector_id: string, connector_token: string, session_id: string}
     */
    private function onlineConnector(): array
    {
        $ctx = $this->enrolledConnector();

        $session = $this->postJson('/api/connector/v1/sessions', [
            'protocol_version' => '1.0',
            'connector_id' => $ctx['connector_id'],
            'connector_token' => $ctx['connector_token'],
        ])->assertOk();

        $this->withToken($ctx['connector_token'])->postJson('/api/connector/v1/heartbeat', [
            'protocol_version' => '1.0',
            'connector_id' => $ctx['connector_id'],
            'session_id' => $session->json('session_id'),
            'version' => '0.1.0',
            'capabilities' => ['drill_holes.list@1'],
            'status' => 'ok',
        ])->assertOk();

        $ctx['session_id'] = $session->json('session_id');

        return $ctx;
    }
}
