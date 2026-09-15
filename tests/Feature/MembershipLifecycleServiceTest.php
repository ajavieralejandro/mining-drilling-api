<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MembershipLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the Paso 1 scope from the 2026-09-12 audit: MembershipLifecycleService
 * as the only supported way to open/close a membership, the "at most one
 * active membership per user" invariant (both the app-layer lock and the DB
 * partial unique index), and the fail-safe User::activeMembership(). No HTTP
 * endpoint exists yet — this exercises the service directly.
 */
class MembershipLifecycleServiceTest extends TestCase
{
    private MembershipLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MembershipLifecycleService;
    }

    public function test_activate_creates_a_valid_active_membership(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate(
            user: $user,
            tenant: $tenant,
            role: 'supervisor',
            corporateEmail: 'worker@newmont-example.test',
            externalHrId: 'hr-001',
            actor: $actor,
        );

        $this->assertTrue($membership->isActive());
        $this->assertSame($tenant->id, $membership->tenant_id);
        $this->assertSame($user->id, $membership->user_id);
        $this->assertNotNull($membership->started_at);
        $this->assertNull($membership->ended_at);
        $this->assertTrue($user->fresh()->activeMembership()->is($membership));
    }

    public function test_corporate_email_is_normalized_trimmed_and_lowercased(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();

        $membership = $this->service->activate(
            user: $user,
            tenant: $tenant,
            role: 'supervisor',
            corporateEmail: '  Worker@Newmont-Example.TEST  ',
            externalHrId: null,
            actor: null,
        );

        $this->assertSame('worker@newmont-example.test', $membership->corporate_email);
    }

    public function test_cannot_create_a_second_active_membership_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $this->service->activate($user, $tenantA, 'supervisor', 'a@example.test', null, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEMBERSHIP_ALREADY_ACTIVE');

        $this->service->activate($user, $tenantB, 'supervisor', 'b@example.test', null, null);
    }

    public function test_inactive_historical_membership_does_not_block_a_new_active_one(): void
    {
        $user = User::factory()->create();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membershipA = $this->service->activate($user, $tenantA, 'supervisor', 'a@example.test', null, $actor);
        $this->service->deactivate($membershipA, $actor, 'Cambio de empresa');

        $membershipB = $this->service->activate($user, $tenantB, 'supervisor', 'b@example.test', null, $actor);

        $this->assertTrue($membershipB->isActive());
        $this->assertSame(Membership::STATUS_INACTIVE, $membershipA->fresh()->status);
        $this->assertTrue($user->fresh()->activeMembership()->is($membershipB));
    }

    public function test_deactivate_sets_status_ended_at_actor_and_reason(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);

        $closed = $this->service->deactivate($membership, $actor, 'Desvinculación confirmada por RR. HH.');

        $this->assertSame(Membership::STATUS_INACTIVE, $closed->status);
        $this->assertNotNull($closed->ended_at);
        $this->assertSame($actor->id, $closed->deactivated_by);
        $this->assertSame('Desvinculación confirmada por RR. HH.', $closed->deactivation_reason);
    }

    public function test_deactivate_revokes_every_token_of_the_affected_user(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);
        $user->createToken('mobile-1');
        $user->createToken('mobile-2');

        $this->assertSame(2, $user->tokens()->count());

        $this->service->deactivate($membership, $actor, 'Baja de personal');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_deactivate_does_not_revoke_tokens_of_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $tenant = $this->makeTenant();
        $otherTenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);
        $this->service->activate($otherUser, $otherTenant, 'supervisor', 'other@example.test', null, $actor);
        $otherUser->createToken('mobile-1');

        $this->service->deactivate($membership, $actor, 'Baja de personal');

        $this->assertSame(1, $otherUser->tokens()->count());
    }

    public function test_deactivate_does_not_delete_user_tenant_or_membership_row(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);
        $this->service->deactivate($membership, $actor, 'Baja de personal');

        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(Tenant::find($tenant->id));
        $this->assertNotNull(Membership::find($membership->id));
        $this->assertSame(Membership::STATUS_INACTIVE, Membership::find($membership->id)->status);
    }

    public function test_deactivate_does_not_reactivate_another_membership_implicitly(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);
        $this->service->deactivate($membership, $actor, 'Baja de personal');

        $this->assertNull($user->fresh()->activeMembership());
    }

    public function test_deactivate_is_idempotent_and_does_not_overwrite_original_closure(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $secondActor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);
        $firstClose = $this->service->deactivate($membership, $actor, 'Motivo original');

        $secondClose = $this->service->deactivate($membership->fresh(), $secondActor, 'Motivo distinto en un reintento');

        $this->assertSame($firstClose->ended_at->toISOString(), $secondClose->ended_at->toISOString());
        $this->assertSame('Motivo original', $secondClose->deactivation_reason);
        $this->assertSame($actor->id, $secondClose->deactivated_by);
    }

    public function test_cannot_deactivate_a_membership_belonging_to_another_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membershipA = $this->service->activate($userA, $tenantA, 'supervisor', 'a@example.test', null, $actor);
        $membershipB = $this->service->activate($userB, $tenantB, 'supervisor', 'b@example.test', null, $actor);

        $this->service->deactivate($membershipA, $actor, 'Baja de personal');

        $this->assertSame(Membership::STATUS_ACTIVE, $membershipB->fresh()->status);
    }

    public function test_empty_deactivation_reason_is_rejected(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);

        $this->expectException(InvalidArgumentException::class);

        $this->service->deactivate($membership, $actor, '   ');
    }

    public function test_audit_trail_does_not_contain_tokens_or_passwords(): void
    {
        $user = User::factory()->create();
        $tenant = $this->makeTenant();
        $actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->service->activate($user, $tenant, 'supervisor', 'a@example.test', null, $actor);
        $user->createToken('mobile-1');
        $this->service->deactivate($membership, $actor, 'Baja de personal');

        $logs = AuditLog::where('entity_type', Membership::class)->get();
        $this->assertGreaterThanOrEqual(2, $logs->count());

        $serialized = $logs->toJson();
        $this->assertStringNotContainsString('mobile-1', $serialized);
        $this->assertStringNotContainsString('password', strtolower($serialized));
        $this->assertStringNotContainsString('plainTextToken', $serialized);
    }

    public function test_transaction_rolls_back_when_activation_fails_partway(): void
    {
        $user = User::factory()->create();
        $bogusTenant = new Tenant(['id' => (string) Str::ulid(), 'name' => 'Nunca guardado']);

        $membershipsBefore = Membership::count();
        $auditBefore = AuditLog::count();

        try {
            $this->service->activate($user, $bogusTenant, 'supervisor', 'a@example.test', null, null);
            $this->fail('Expected a QueryException for a tenant_id with no matching tenant row.');
        } catch (QueryException) {
            // expected: the FK on memberships.tenant_id has no row to point to
        }

        $this->assertSame($membershipsBefore, Membership::count());
        $this->assertSame($auditBefore, AuditLog::count());
    }

    public function test_two_preexisting_active_memberships_never_resolve_silently_via_first(): void
    {
        $user = User::factory()->create();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        // Simulates data that predates this migration (or a manual DB
        // edit that bypassed the service) — the partial unique index
        // normally makes this state impossible to create through the app,
        // so it is dropped here on purpose to prove the application-layer
        // guard is real defense in depth, not dead code.
        DB::statement('DROP INDEX IF EXISTS memberships_one_active_per_user');

        DB::table('memberships')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenantA->id,
            'role' => 'supervisor',
            'status' => Membership::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('memberships')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenantB->id,
            'role' => 'supervisor',
            'status' => Membership::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MULTIPLE_ACTIVE_MEMBERSHIPS');

        $user->fresh()->activeMembership();
    }

    public function test_database_partial_unique_index_rejects_a_second_active_row_via_raw_insert(): void
    {
        $user = User::factory()->create();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        DB::table('memberships')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenantA->id,
            'role' => 'supervisor',
            'status' => Membership::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('memberships')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenantB->id,
            'role' => 'supervisor',
            'status' => Membership::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Tenant '.Str::random(8)]);
    }
}
