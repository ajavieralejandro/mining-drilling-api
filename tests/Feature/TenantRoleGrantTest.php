<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Tenant\RoleGrantGuard;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nadie se da (ni le da a otro) acceso de administración por la puerta de
 * grupos o personal: así terminó una cuadrilla con super_admin.
 */
class TenantRoleGrantTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Norte']);
    }

    public function test_a_supervisor_cannot_give_a_group_super_admin_or_admin(): void
    {
        $supervisor = $this->member($this->tenant, 'supervisor');
        Sanctum::actingAs($supervisor);

        foreach (['super_admin', 'admin'] as $slug) {
            $this->postJson('/api/tenant/groups', [
                'name' => "Cuadrilla {$slug}",
                'user_ids' => [$supervisor->id],
                'role_ids' => [$this->systemRole($slug)->id],
            ])->assertStatus(422)->assertJsonValidationErrors('role_ids');
        }
        $this->assertSame(0, UserGroup::count(), 'no group is created');
        $this->assertSame(24, count($supervisor->fresh()->permissions($this->tenant->id)));
    }

    public function test_a_supervisor_still_builds_crews_with_operational_roles(): void
    {
        $supervisor = $this->member($this->tenant, 'supervisor');
        $driller = $this->member($this->tenant, 'driller');
        Sanctum::actingAs($supervisor);

        $this->postJson('/api/tenant/groups', [
            'name' => 'Cuadrilla Norte',
            'user_ids' => [$driller->id],
            'role_ids' => [$this->systemRole('driller')->id, $this->systemRole('geologist')->id],
        ])->assertCreated();
    }

    public function test_a_supervisor_cannot_add_admin_to_an_existing_group(): void
    {
        $supervisor = $this->member($this->tenant, 'supervisor');
        $group = UserGroup::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuadrilla Sur']);
        $group->users()->sync([$supervisor->id]);
        Sanctum::actingAs($supervisor);

        $this->putJson("/api/tenant/groups/{$group->id}", ['role_ids' => [$this->systemRole('super_admin')->id]])
            ->assertStatus(422)->assertJsonValidationErrors('role_ids');
        $this->assertSame(0, $group->roles()->count());
    }

    public function test_groups_only_take_members_and_roles_of_the_organization(): void
    {
        $supervisor = $this->member($this->tenant, 'supervisor');
        $otra = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Otra minera']);
        $outsider = $this->member($otra, 'driller');
        $foreignRole = Role::create(['name' => 'Ajeno', 'slug' => 'ajeno', 'tenant_id' => $otra->id, 'description' => null, 'is_system' => false]);
        Sanctum::actingAs($supervisor);

        $this->postJson('/api/tenant/groups', ['name' => 'Con ajeno', 'user_ids' => [$outsider->id]])
            ->assertStatus(422)->assertJsonValidationErrors('user_ids');
        $this->postJson('/api/tenant/groups', ['name' => 'Con rol ajeno', 'role_ids' => [$foreignRole->id]])
            ->assertStatus(422)->assertJsonValidationErrors('role_ids');
    }

    public function test_a_supervisor_cannot_promote_someone_to_admin(): void
    {
        $supervisor = $this->member($this->tenant, 'supervisor');
        $driller = $this->member($this->tenant, 'driller');
        Sanctum::actingAs($supervisor);

        $this->patchJson("/api/tenant/personnel/{$driller->id}", ['role' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->postJson('/api/tenant/personnel', ['name' => 'Nuevo admin', 'email' => 'nuevo.admin@minera.test', 'role' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertNull(User::where('email', 'nuevo.admin@minera.test')->first());
    }

    public function test_a_custom_role_with_administration_permissions_needs_them(): void
    {
        $supervisor = $this->member($this->tenant, 'supervisor');
        $role = Role::create(['name' => 'Jefe IT', 'slug' => 'jefe_it', 'tenant_id' => $this->tenant->id, 'description' => null, 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('name', ['source_config.manage', 'personnel.view'])->pluck('id'));
        $guard = app(RoleGrantGuard::class);

        $this->assertFalse($guard->canGrant($supervisor, $this->context($supervisor, 'supervisor'), $role->load('permissions')));
        $this->assertTrue($guard->canGrant($supervisor, $this->context($supervisor, 'supervisor'), $this->systemRole('driller')->load('permissions')));
    }

    public function test_the_admin_grants_admin_but_only_a_super_admin_grants_super_admin(): void
    {
        $admin = $this->member($this->tenant, 'admin');
        $guard = app(RoleGrantGuard::class);
        $context = $this->context($admin, 'admin');

        $guard->assertCanGrantSlug($admin, $context, 'admin', 'role');
        $this->assertFalse($guard->canGrant($admin, $context, $this->systemRole('super_admin')));

        $admin->forceFill(['is_super_admin' => true])->save();
        $this->assertTrue($guard->canGrant($admin->fresh(), $context, $this->systemRole('super_admin')));

        $supervisor = $this->member($this->tenant, 'supervisor');
        $this->expectException(ValidationException::class);
        $guard->assertCanGrantSlug($supervisor, $this->context($supervisor, 'supervisor'), 'admin', 'role');
    }

    private function systemRole(string $slug): Role
    {
        return Role::whereNull('tenant_id')->where('slug', $slug)->firstOrFail();
    }

    private function context(User $user, string $role): TenantContext
    {
        $membershipId = (string) Membership::where('user_id', $user->id)->value('id');

        return new TenantContext($this->tenant->id, $user->id, $membershipId, $role);
    }

    private function member(Tenant $tenant, string $slug): User
    {
        $user = User::create([
            'name' => "Usuario {$slug}",
            'email' => "{$slug}.".Str::random(6).'@minera.test',
            'role' => $slug,
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => $slug,
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $user->email,
        ]);
        $membership->roles()->sync([$this->systemRole($slug)->id]);

        return $user;
    }
}
