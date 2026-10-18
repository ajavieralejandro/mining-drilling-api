<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ConnectorCommand;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestAccessRevalidationTest extends TestCase
{
    public function test_a_token_issued_before_deactivation_cannot_list_holes(): void
    {
        [$user] = $this->makeUserWithMembership();
        $token = $user->createToken('api-token')->plainTextToken;

        $user->forceFill(['active' => false])->save();

        $this->withToken($token)
            ->getJson('/api/tenant/holes')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->withToken($token)
            ->getJson('/api/tenant/holes/1001')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->withToken($token)
            ->getJson('/api/tenant/connection')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        $this->assertSame(0, ConnectorCommand::query()->count());
    }

    public function test_a_token_survives_a_direct_membership_close_but_cannot_dispatch(): void
    {
        [$user, $tenant] = $this->makeUserWithMembership();
        $token = $user->createToken('api-token')->plainTextToken;

        Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->update(['status' => Membership::STATUS_INACTIVE]);

        $this->withToken($token)
            ->getJson('/api/tenant/holes')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_ACTIVE_MEMBERSHIP');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertSame(0, ConnectorCommand::query()->count());
    }

    public function test_an_inactive_user_can_still_logout_the_current_token(): void
    {
        [$user] = $this->makeUserWithMembership();
        $token = $user->createToken('api-token')->plainTextToken;
        $user->forceFill(['active' => false])->save();

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertSame(0, $user->tokens()->count());

        $this->withToken($token)
            ->getJson('/api/tenant/holes')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function makeUserWithMembership(): array
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
            'status' => Membership::STATUS_ACTIVE,
        ]);

        return [$user, $tenant];
    }
}
