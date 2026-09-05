<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\ConnectorEnrollmentToken;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connector\CommandDispatcher;
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
     * Fase A/B/C (P1 Paso 2, correlation): "Generación" + proof that the
     * canonical id survives an error that happens *before* any
     * ConnectorCommand exists (CONNECTOR_OFFLINE is thrown by
     * resolveConnector(), ahead of dispatch()).
     */
    public function test_correlation_id_is_generated_when_client_sends_none(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/tenant/holes')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'CONNECTOR_OFFLINE');

        $correlationId = $response->json('correlation_id');

        $this->assertIsString($correlationId);
        $this->assertMatchesRegularExpression('/^cor_/', $correlationId);
        // No command was ever created for this failure, so there is
        // nothing to look up a request_id from — it must stay null rather
        // than being fabricated.
        $this->assertNull($response->json('request_id'));
    }

    /**
     * "Propagación": a client-supplied X-Correlation-Id reaches the actual
     * ConnectorCommand row — the same row PollController hands to the
     * Connector — and the same value comes back on the HTTP error response
     * once the command times out (proves the id also survives a failure
     * that happens *during* the wait for a result).
     */
    public function test_client_supplied_correlation_id_is_propagated_to_connector_command(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $this->onlineConnectorFor($tenant);
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Correlation-Id' => 'cor_custom_abc123'])
            ->getJson('/api/tenant/holes')
            ->assertStatus(504)
            ->assertJsonPath('error.code', 'TIMEOUT')
            ->assertJsonPath('correlation_id', 'cor_custom_abc123');

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();

        $this->assertSame('cor_custom_abc123', $command->correlation_id);
        $this->assertSame($command->request_id, $response->json('request_id'));
    }

    /**
     * Fase B validation rule: an entrant id that is not a bounded, safe
     * opaque token must never be trusted as-is — it is replaced by a
     * server-generated one instead of being rejected outright.
     */
    public function test_invalid_client_correlation_id_is_replaced_with_generated_one(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        Sanctum::actingAs($user);

        $tooLong = str_repeat('a', 65);

        $response = $this->withHeaders(['X-Correlation-Id' => $tooLong])
            ->getJson('/api/tenant/holes')
            ->assertStatus(503);

        $this->assertNotSame($tooLong, $response->json('correlation_id'));
        $this->assertMatchesRegularExpression('/^cor_/', $response->json('correlation_id'));
    }

    /**
     * "Retorno": once a Connector completes a command, the result carries
     * exactly the same correlation_id it was dispatched with — proven at
     * the CommandDispatcher level (the layer TenantHoleController's
     * happy path relies on), since a real completion requires a live
     * Connector process racing against the same blocking HTTP request,
     * which is out of reach for an in-process PHPUnit run (see the class
     * docblock above and docs/testing/distributed-data-demo.md for the
     * live proof of the 200 path).
     */
    public function test_dispatcher_preserves_correlation_id_through_completed_result(): void
    {
        [, $tenant] = $this->makeUserWithMembership();
        $connector = $this->onlineConnectorFor($tenant);

        $dispatcher = app(CommandDispatcher::class);

        $command = $dispatcher->dispatch(
            connector: $connector,
            op: 'drill_holes.list@1',
            payload: ['limit' => 5],
            actorType: 'user',
            actorId: '1',
            correlationId: 'cor_roundtrip_test',
        );

        // Simulate the Connector completing it, exactly as
        // ResultController would persist it.
        $command->forceFill([
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'result_json' => ['items' => [['id' => '1', 'code' => 'H-1', 'status' => 'in_progress']]],
            'completed_at' => now(),
        ])->save();

        $result = $dispatcher->waitForResult($command);

        $this->assertSame(ConnectorCommand::STATUS_COMPLETED, $result->status);
        $this->assertSame('cor_roundtrip_test', $result->correlation_id);
        $this->assertNotNull($result->request_id);
    }

    /**
     * P1 Paso 3 — Test 1 (baseline): with only tenant A in play, and no
     * spoofing attempted, the dispatched command targets tenant A's
     * connector. Confirms the happy (dispatch) path still works after
     * Paso 1/2 before piling adversarial input on top of it.
     */
    public function test_baseline_dispatch_targets_the_authenticated_users_own_tenant(): void
    {
        [$userA, $tenantA] = $this->makeUserWithMembership();
        $connectorA = $this->onlineConnectorFor($tenantA);
        Sanctum::actingAs($userA);

        $this->getJson('/api/tenant/holes')->assertStatus(504);

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();

        $this->assertSame($tenantA->id, $command->tenant_id);
        $this->assertSame($connectorA->id, $command->connector_id);
    }

    /**
     * P1 Paso 3 — Test 2 (query spoofing): tenant A user sends tenant B's
     * id via query string while BOTH connectors are online, so a leak
     * would be observable as B's connector receiving the command. Asserts
     * directly on the ConnectorCommand row, not just the HTTP status.
     */
    public function test_query_string_tenant_spoofing_does_not_change_dispatched_tenant(): void
    {
        [$userA, $tenantA, $connectorA, , $connectorB] = $this->setUpTenantAWithSiblingTenantB();
        Sanctum::actingAs($userA);

        $this->getJson('/api/tenant/holes?tenant_id='.$connectorB->tenant_id.'&organization_id='.$connectorB->tenant_id)
            ->assertStatus(504);

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();

        $this->assertSame($tenantA->id, $command->tenant_id);
        $this->assertSame($connectorA->id, $command->connector_id);
        $this->assertNotSame($connectorB->id, $command->connector_id);
    }

    /**
     * P1 Paso 3 — Test 3 (body spoofing): GET /api/tenant/holes never
     * calls Request::input()/all() (only ::query('limit')) — verified by
     * reading TenantHoleController before writing this test — so a JSON
     * body is not a real attack surface here. This test exercises that
     * real, current code path end-to-end anyway (an actual JSON body is
     * sent on the GET) rather than asserting it from reading the source,
     * confirming the body is inert in practice too.
     */
    public function test_json_body_tenant_spoofing_does_not_change_dispatched_tenant(): void
    {
        [$userA, $tenantA, $connectorA, , $connectorB] = $this->setUpTenantAWithSiblingTenantB();
        Sanctum::actingAs($userA);

        $this->json('GET', '/api/tenant/holes', ['tenant_id' => $connectorB->tenant_id])
            ->assertStatus(504);

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();

        $this->assertSame($tenantA->id, $command->tenant_id);
        $this->assertSame($connectorA->id, $command->connector_id);
    }

    /**
     * P1 Paso 3 — Test 4 (header spoofing): no header-based tenant
     * selector exists anywhere in the codebase (confirmed by a global
     * grep for X-Tenant/Tenant-Id/X-Organization/Organization-Id before
     * writing this test) — so this proves the negative concretely: three
     * plausible header names, all pointing at tenant B, have zero effect.
     */
    public function test_header_tenant_spoofing_does_not_change_dispatched_tenant(): void
    {
        [$userA, $tenantA, $connectorA, , $connectorB] = $this->setUpTenantAWithSiblingTenantB();
        Sanctum::actingAs($userA);

        $this->withHeaders([
            'X-Tenant-Id' => $connectorB->tenant_id,
            'Tenant-Id' => $connectorB->tenant_id,
            'X-Organization-Id' => $connectorB->tenant_id,
        ])->getJson('/api/tenant/holes')->assertStatus(504);

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();

        $this->assertSame($tenantA->id, $command->tenant_id);
        $this->assertSame($connectorA->id, $command->connector_id);
    }

    /**
     * P1 Paso 3 — Test 5 (most important): every spoofing surface at once
     * — query, JSON body, and headers, all naming tenant B and even B's
     * connector_id directly — while both tenants have an online
     * connector. Inspects the actual ConnectorCommand row Laravel created
     * (the row PollController would hand to a real Connector process),
     * not merely the HTTP status: it must name tenant A / connector A and
     * nothing belonging to B, in every field.
     */
    public function test_combined_spoofing_across_every_surface_still_targets_only_tenant_a(): void
    {
        [$userA, $tenantA, $connectorA, $tenantB, $connectorB] = $this->setUpTenantAWithSiblingTenantB();
        Sanctum::actingAs($userA);

        $this->withHeaders([
            'X-Tenant-Id' => $tenantB->id,
            'Tenant-Id' => $tenantB->id,
            'X-Organization-Id' => $tenantB->id,
        ])->json('GET', '/api/tenant/holes?tenant_id='.$tenantB->id.'&connector_id='.$connectorB->id, [
            'tenant_id' => $tenantB->id,
            'connector_id' => $connectorB->id,
        ])->assertStatus(504);

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();

        $this->assertSame($tenantA->id, $command->tenant_id);
        $this->assertSame($connectorA->id, $command->connector_id);
        $this->assertNotSame($tenantB->id, $command->tenant_id);
        $this->assertNotSame($connectorB->id, $command->connector_id);
    }

    /**
     * P1 Fase 2 — A: unauthenticated JSON Accept still uses the Gateway
     * error contract, not Sanctum's bare {message: Unauthenticated.}.
     */
    public function test_unauthenticated_holes_request_with_accept_json_returns_401(): void
    {
        $this->getJson('/api/tenant/holes')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('request_id', null);
    }

    /**
     * P1 Fase 2 — B: missing Accept must not redirect to route('login')
     * or render the debug HTML page (previously HTTP 500 text/html).
     */
    public function test_unauthenticated_holes_request_without_accept_still_returns_json_401(): void
    {
        $response = $this->get('/api/tenant/holes');

        $response->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $body = strtolower($response->getContent());
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('doctype', $body);
    }

    /**
     * P1 Fase 2 — C: ResolveRequestCorrelation now runs before auth, so a
     * 401 on /api/tenant/holes can still echo a valid client correlation id.
     * request_id stays null — no command exists yet.
     */
    public function test_unauthenticated_holes_request_preserves_correlation_id(): void
    {
        $this->withHeaders(['X-Correlation-Id' => 'cor_unauth_401'])
            ->getJson('/api/tenant/holes')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('correlation_id', 'cor_unauth_401')
            ->assertJsonPath('request_id', null);
    }

    /**
     * P1 Fase 2 — D: NO_ACTIVE_MEMBERSHIP now uses GatewayException, so the
     * correlation id resolved before auth is preserved on 403.
     */
    public function test_no_membership_error_includes_correlation_id(): void
    {
        $user = User::factory()->create(['role' => UserRole::Supervisor, 'active' => true]);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Correlation-Id' => 'cor_no_membership'])
            ->getJson('/api/tenant/holes')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP')
            ->assertJsonPath('correlation_id', 'cor_no_membership')
            ->assertJsonPath('request_id', null);
    }

    /**
     * P1 Fase 2 — G: a Connector result with status=failed / ADAPTER_ERROR
     * surfaces as 502 on the user request. Mapping for NOT_FOUND (404) and
     * INVALID_PAYLOAD (422) is unchanged and covered next to this.
     */
    public function test_connector_failed_result_returns_502(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $this->onlineConnectorFor($tenant);
        Sanctum::actingAs($user);

        $this->fakeDispatcherReturning($this->fakeCommand([
            'status' => ConnectorCommand::STATUS_FAILED,
            'error_code' => 'ADAPTER_ERROR',
            'result_json' => null,
        ]));

        $this->getJson('/api/tenant/holes')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'ADAPTER_ERROR')
            ->assertJsonPath('request_id', 'req_fake_result');
    }

    public function test_connector_not_found_result_still_returns_404(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $this->onlineConnectorFor($tenant);
        Sanctum::actingAs($user);

        $this->fakeDispatcherReturning($this->fakeCommand([
            'status' => ConnectorCommand::STATUS_FAILED,
            'error_code' => 'NOT_FOUND',
            'result_json' => null,
        ]));

        $this->getJson('/api/tenant/holes')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    /**
     * P1 Fase 2 — H: an unexpected RuntimeException (e.g. SQL) must not
     * leak into error.code / the JSON body. Laravel still receives it via
     * report(); the client only sees INTERNAL_ERROR.
     */
    public function test_unexpected_dispatch_exception_returns_internal_error_without_sql(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $this->onlineConnectorFor($tenant);
        Sanctum::actingAs($user);

        $dispatcher = \Mockery::mock(CommandDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException(
            'SQLSTATE[23000]: Integrity constraint violation: insert into connector_commands (id) values (1) /var/www/app'
        ));
        $dispatcher->shouldReceive('waitForResult')->never();
        $this->app->instance(CommandDispatcher::class, $dispatcher);

        $response = $this->withHeaders(['X-Correlation-Id' => 'cor_unexpected'])
            ->getJson('/api/tenant/holes');

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('correlation_id', 'cor_unexpected')
            ->assertJsonPath('request_id', null);

        $body = strtolower($response->getContent());
        $this->assertStringNotContainsString('sql', $body);
        $this->assertStringNotContainsString('select', $body);
        $this->assertStringNotContainsString('insert', $body);
        $this->assertStringNotContainsString('table', $body);
        $this->assertStringNotContainsString('database', $body);
        $this->assertStringNotContainsString('exception', $body);
        $this->assertStringNotContainsString('stack', $body);
        $this->assertStringNotContainsString('/var/www', $body);
        $this->assertStringNotContainsString('connector_commands', $body);
    }

    /**
     * P1 Fase 2 — I: status=ok with a structurally invalid list payload
     * must not collapse to 200 {data: []}.
     */
    public function test_completed_result_with_invalid_shape_returns_502(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $this->onlineConnectorFor($tenant);
        Sanctum::actingAs($user);

        $this->fakeDispatcherReturning($this->fakeCommand([
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'result_json' => ['not_items' => true],
        ]));

        $this->getJson('/api/tenant/holes')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'INVALID_RESULT')
            ->assertJsonPath('request_id', 'req_fake_result');
    }

    /**
     * P1 Fase 2 — J: in-process happy path for drill_holes.list@1. A live
     * Connector process cannot answer inside this PHPUnit worker (see class
     * docblock); the dispatcher is stubbed at waitForResult with the same
     * payload shape the Go adapter returns, so the controller contract is
     * proven: 200, data, request_id, correlation_id.
     */
    public function test_completed_list_result_returns_200_with_items(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $this->onlineConnectorFor($tenant);
        Sanctum::actingAs($user);

        $this->fakeDispatcherReturning($this->fakeCommand([
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'request_id' => 'req_happy_path',
            'correlation_id' => 'cor_happy_path',
            'result_json' => [
                'items' => [
                    ['id' => '1001', 'code' => 'H-1', 'status' => 'in_progress'],
                ],
            ],
        ]));

        $this->withHeaders(['X-Correlation-Id' => 'cor_happy_path'])
            ->getJson('/api/tenant/holes')
            ->assertOk()
            ->assertJsonPath('data.0.id', '1001')
            ->assertJsonPath('data.0.code', 'H-1')
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('request_id', 'req_happy_path')
            ->assertJsonPath('correlation_id', 'cor_happy_path');
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Connector, 3: Tenant, 4: Connector}
     */
    private function setUpTenantAWithSiblingTenantB(): array
    {
        [$userA, $tenantA] = $this->makeUserWithMembership();
        $connectorA = $this->onlineConnectorFor($tenantA);

        $tenantB = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera B']);
        $connectorB = $this->onlineConnectorFor($tenantB);

        return [$userA, $tenantA, $connectorA, $tenantB, $connectorB];
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

    private function fakeDispatcherReturning(ConnectorCommand $command): void
    {
        $dispatcher = \Mockery::mock(CommandDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn($command);
        $dispatcher->shouldReceive('waitForResult')->once()->andReturn($command);
        $this->app->instance(CommandDispatcher::class, $dispatcher);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeCommand(array $overrides = []): ConnectorCommand
    {
        $command = new ConnectorCommand;
        $command->forceFill(array_merge([
            'id' => (string) Str::ulid(),
            'request_id' => 'req_fake_result',
            'correlation_id' => 'cor_fake_result',
            'tenant_id' => (string) Str::ulid(),
            'connector_id' => (string) Str::ulid(),
            'op' => 'drill_holes.list@1',
            'payload_json' => ['limit' => 10],
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'result_json' => ['items' => []],
        ], $overrides));

        return $command;
    }
}
