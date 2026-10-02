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
use App\Support\RequestCorrelation;
use App\Support\TokenHasher;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tokens are issued before the state change under test. Membership status
 * is updated directly so Sanctum tokens stay in the database: that is the
 * gap deactivate()'s token delete does not cover.
 */
class RequestAccessRevalidationTest extends TestCase
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

    public function test_active_user_with_active_membership_keeps_access_with_a_previously_issued_token(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->onlineConnectorFor($tenant);

        $this->fakeDispatcherReturning($this->fakeCommand([
            'tenant_id' => $tenant->id,
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'request_id' => 'req_still_allowed',
            'correlation_id' => 'cor_still_allowed',
            'result_json' => [
                'items' => [
                    ['id' => '1001', 'code' => 'H-1', 'status' => 'in_progress'],
                ],
            ],
        ]));

        $this->asToken($token)
            ->withHeaders(['X-Correlation-Id' => 'cor_still_allowed'])
            ->getJson('/api/tenant/holes')
            ->assertOk()
            ->assertJsonPath('data.0.id', '1001')
            ->assertJsonPath('request_id', 'req_still_allowed')
            ->assertJsonPath('correlation_id', 'cor_still_allowed');
    }

    public function test_login_still_issues_a_token_for_an_active_user(): void
    {
        [$user] = $this->makeUserWithMembership();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_deactivated_user_loses_access_on_the_next_request_without_dispatching(): void
    {
        [$user] = $this->makeUserWithMembership();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->rejectConnectorDispatch();

        $user->forceFill(['active' => false])->save();

        $this->asToken($token)
            ->withHeaders(['X-Correlation-Id' => 'cor_user_inactive'])
            ->getJson('/api/tenant/holes')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'USER_INACTIVE')
            ->assertJsonPath('error.message', 'This account is inactive.')
            ->assertJsonPath('correlation_id', 'cor_user_inactive')
            ->assertJsonPath('request_id', null);

        $this->assertSame(0, ConnectorCommand::query()->count());

        $this->asToken($token)->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'USER_INACTIVE')
            ->assertJsonPath('request_id', null)
            ->assertJsonPath('correlation_id', null);

        $this->asToken($token)->getJson('/api/drill-holes')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->asToken($token)->getJson('/api/drilling-plans')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->asToken($token)->getJson('/api/machines')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'USER_INACTIVE');
    }

    public function test_deactivated_membership_loses_tenant_access_without_dispatching(): void
    {
        [$user, $tenant, $membership] = $this->makeUserWithMembership();
        $token = $user->createToken('mobile')->plainTextToken;
        $this->rejectConnectorDispatch();

        $membership->forceFill(['status' => Membership::STATUS_INACTIVE])->save();

        $this->asToken($token)
            ->withHeaders(['X-Correlation-Id' => 'cor_membership_closed'])
            ->getJson('/api/tenant/holes')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP')
            ->assertJsonPath('correlation_id', 'cor_membership_closed')
            ->assertJsonPath('request_id', null);

        $this->assertSame(0, ConnectorCommand::query()->count());
        $this->assertSame($tenant->id, $membership->tenant_id);

        $this->asToken($token)->getJson('/api/auth/me')->assertOk();
        $this->asToken($token)->getJson('/api/machines')->assertOk();
    }

    public function test_user_without_membership_cannot_read_tenant_resources(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'active' => true,
        ]);
        $token = $user->createToken('mobile')->plainTextToken;
        $this->rejectConnectorDispatch();

        $this->asToken($token)
            ->withHeaders(['X-Correlation-Id' => 'cor_no_membership'])
            ->getJson('/api/tenant/holes')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP')
            ->assertJsonPath('correlation_id', 'cor_no_membership')
            ->assertJsonPath('request_id', null);

        $this->assertSame(0, ConnectorCommand::query()->count());
    }

    public function test_membership_of_another_tenant_does_not_grant_access_to_it(): void
    {
        [$user, $tenantB] = $this->makeUserWithMembership();
        $token = $user->createToken('mobile')->plainTextToken;

        $tenantA = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera A']);
        $this->onlineConnectorFor($tenantA);

        $this->asToken($token)
            ->getJson('/api/tenant/holes?tenant_id='.$tenantA->id)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'CONNECTOR_OFFLINE');

        $this->assertSame(0, ConnectorCommand::query()->count());
        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    public function test_deactivating_one_membership_does_not_block_another_authorized_tenant(): void
    {
        [$userA, $tenantA, $membershipA] = $this->makeUserWithMembership();
        $tokenA = $userA->createToken('mobile-a')->plainTextToken;

        [$userB, $tenantB] = $this->makeUserWithMembership();
        $tokenB = $userB->createToken('mobile-b')->plainTextToken;
        $this->onlineConnectorFor($tenantB);

        $membershipA->forceFill(['status' => Membership::STATUS_INACTIVE])->save();

        $this->asToken($tokenA)
            ->getJson('/api/tenant/holes')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP');

        $this->assertSame(0, ConnectorCommand::query()->where('tenant_id', $tenantA->id)->count());

        $this->asToken($tokenB)
            ->getJson('/api/tenant/holes')
            ->assertStatus(504)
            ->assertJsonPath('error.code', 'TIMEOUT');

        $command = ConnectorCommand::query()->where('op', 'drill_holes.list@1')->sole();
        $this->assertSame($tenantB->id, $command->tenant_id);
    }

    public function test_closing_a_historical_membership_does_not_block_the_active_tenant(): void
    {
        [$user, $tenantA, $membershipA] = $this->makeUserWithMembership(Membership::STATUS_INACTIVE);
        [$sameUser, $tenantB] = $this->makeUserWithMembership(Membership::STATUS_ACTIVE, $user);
        $this->assertTrue($sameUser->is($user));

        $token = $user->createToken('mobile')->plainTextToken;
        $this->onlineConnectorFor($tenantB);

        $membershipA->forceFill([
            'status' => Membership::STATUS_INACTIVE,
            'deactivation_reason' => 'Cierre reiterado',
        ])->save();

        $this->asToken($token)
            ->getJson('/api/tenant/holes?tenant_id='.$tenantA->id)
            ->assertStatus(504)
            ->assertJsonPath('error.code', 'TIMEOUT');

        $command = ConnectorCommand::query()->sole();
        $this->assertSame($tenantB->id, $command->tenant_id);
        $this->assertNotSame($tenantA->id, $command->tenant_id);
    }

    public function test_logout_still_revokes_the_current_token_when_the_user_is_inactive(): void
    {
        $user = User::factory()->create(['role' => UserRole::Supervisor, 'active' => true]);
        $token = $user->createToken('mobile')->plainTextToken;

        $user->forceFill(['active' => false])->save();

        $this->asToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertSame(0, $user->tokens()->count());

        $this->asToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_global_role_without_membership_keeps_legacy_routes_and_not_the_tenant_gateway(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'active' => true,
        ]);
        $token = $user->createToken('mobile')->plainTextToken;
        $this->rejectConnectorDispatch();

        $this->asToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->asToken($token)->getJson('/api/machines')->assertOk();
        $this->asToken($token)->getJson('/api/drilling-plans')->assertOk();
        $this->asToken($token)->getJson('/api/drill-holes')->assertOk();

        $this->asToken($token)->getJson('/api/tenant/holes')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP');

        $this->assertSame(0, ConnectorCommand::query()->count());
    }

    public function test_connector_channel_is_not_rejected_as_an_inactive_user(): void
    {
        $this->postJson('/api/connector/v1/heartbeat', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * The test process reuses one container, so a Sanctum guard or a
     * correlation binding from an earlier request must not leak into the next.
     */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance(RequestCorrelation::class);

        return $this->withToken($token);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Membership}
     */
    private function makeUserWithMembership(string $status = Membership::STATUS_ACTIVE, ?User $user = null): array
    {
        $tenant = Tenant::create([
            'id' => (string) Str::ulid(),
            'name' => 'Tenant '.Str::random(6),
        ]);

        $user ??= User::factory()->create([
            'role' => UserRole::Supervisor,
            'active' => true,
        ]);

        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'supervisor',
            'status' => $status,
        ]);

        return [$user, $tenant, $membership];
    }

    private function rejectConnectorDispatch(): void
    {
        $dispatcher = \Mockery::mock(CommandDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');
        $dispatcher->shouldNotReceive('waitForResult');
        $this->app->instance(CommandDispatcher::class, $dispatcher);
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
