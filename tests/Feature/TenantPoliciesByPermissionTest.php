<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantHoleAssignmentPolicy;
use App\Policies\TenantHoleProgressPolicy;
use App\Policies\TenantObservationPolicy;
use App\Policies\TenantPersonnelPolicy;
use App\Policies\TenantRiskPolicy;
use App\Policies\TenantRolePolicy;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan 15, etapa 2 (bloque B): las políticas del tenant deciden solo por
 * permisos. El nombre del rol ya no abre nada; el único atajo es el de admin.
 */
class TenantPoliciesByPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Norte']);
    }

    public function test_a_supervisor_without_permissions_gets_nothing_from_the_role_name(): void
    {
        Role::where('slug', 'supervisor')->whereNull('tenant_id')->firstOrFail()->permissions()->sync([]);
        [$user, $context] = $this->member('supervisor');

        $this->assertFalse(app(TenantPersonnelPolicy::class)->manage($user, $context));
        $this->assertFalse(app(TenantHoleAssignmentPolicy::class)->manage($user, $context));
        $this->assertFalse(app(TenantHoleProgressPolicy::class)->review($user, $context));
        $this->assertFalse(app(TenantHoleProgressPolicy::class)->initializeDepth($user, $context));
        $this->assertFalse(app(TenantHoleProgressPolicy::class)->create($user, $context, fn () => null));
        $this->assertFalse(app(TenantObservationPolicy::class)->create($user, $context, fn () => []));
        $this->assertFalse(app(TenantRiskPolicy::class)->report($user, $context, fn () => []));
    }

    public function test_the_seeded_supervisor_keeps_its_operational_rights_through_permissions(): void
    {
        [$user, $context] = $this->member('supervisor');

        $this->assertTrue(app(TenantPersonnelPolicy::class)->manage($user, $context));
        $this->assertTrue(app(TenantHoleAssignmentPolicy::class)->manage($user, $context));
        $this->assertTrue(app(TenantHoleProgressPolicy::class)->review($user, $context));
        $this->assertTrue(app(TenantHoleProgressPolicy::class)->create($user, $context, fn () => null), 'view_all reports on any hole');
        $this->assertTrue(app(TenantObservationPolicy::class)->create($user, $context, fn () => []));
        $this->assertTrue(app(TenantRiskPolicy::class)->report($user, $context, fn () => []));
        $this->assertTrue(app(TenantRolePolicy::class)->viewAny($user, $context));
        $this->assertFalse(app(TenantRolePolicy::class)->manage($user, $context));
    }

    public function test_a_custom_role_with_the_permissions_is_treated_like_the_system_role(): void
    {
        $role = Role::create([
            'name' => 'Jefe de turno',
            'slug' => 'jefe_turno',
            'tenant_id' => $this->tenant->id,
            'description' => 'Rol propio de la organización',
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::whereIn('name', ['personnel.manage', 'progress.approve'])->pluck('id'));
        [$user, $context] = $this->member('jefe_turno', $role);

        $this->assertTrue(app(TenantPersonnelPolicy::class)->manage($user, $context));
        $this->assertTrue(app(TenantHoleProgressPolicy::class)->review($user, $context));
        $this->assertFalse(app(TenantHoleAssignmentPolicy::class)->manage($user, $context));
    }

    public function test_the_technical_geologist_no_longer_manages_people_crews_or_roles(): void
    {
        [$user, $context] = $this->member('geologist');

        $this->assertFalse(app(TenantPersonnelPolicy::class)->manage($user, $context));
        $this->assertFalse(app(TenantHoleAssignmentPolicy::class)->manage($user, $context));
        $this->assertFalse(app(TenantRolePolicy::class)->viewAny($user, $context), 'personnel.view no longer opens the roles');
        $this->assertTrue(app(TenantHoleProgressPolicy::class)->review($user, $context));
        $this->assertTrue(app(TenantHoleProgressPolicy::class)->logCores($user, $context));
    }

    public function test_a_driller_reports_progress_only_where_he_is_the_active_driller(): void
    {
        [$user, $context] = $this->member('driller');
        $policy = app(TenantHoleProgressPolicy::class);

        $this->assertTrue($policy->create($user, $context, fn () => $user->id));
        $this->assertFalse($policy->create($user, $context, fn () => $user->id + 1));
        $this->assertFalse($policy->review($user, $context));
        $this->assertTrue(app(TenantObservationPolicy::class)->create($user, $context, fn () => [$user->id]));
        $this->assertFalse(app(TenantObservationPolicy::class)->create($user, $context, fn () => []));
    }

    public function test_a_helper_cannot_report_progress_even_on_his_own_hole(): void
    {
        [$user, $context] = $this->member('helper');

        $this->assertFalse(app(TenantHoleProgressPolicy::class)->create($user, $context, fn () => $user->id));
        $this->assertTrue(app(TenantRiskPolicy::class)->report($user, $context, fn () => [$user->id]));
    }

    public function test_the_admin_keeps_full_access(): void
    {
        [$user, $context] = $this->member('admin');

        $this->assertTrue(app(TenantPersonnelPolicy::class)->manage($user, $context));
        $this->assertTrue(app(TenantHoleProgressPolicy::class)->create($user, $context, fn () => null));
        $this->assertTrue(app(TenantRolePolicy::class)->manage($user, $context));
    }

    /** @return array{0: User, 1: TenantContext} */
    private function member(string $role, ?Role $customRole = null): array
    {
        $user = User::create([
            'name' => "Usuario {$role}",
            'email' => "{$role}@minera-norte.test",
            'role' => $customRole ? 'helper' : $role,
            'password' => Hash::make('password'),
            'active' => true,
        ]);
        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'role' => $role,
            'status' => Membership::STATUS_ACTIVE,
            'corporate_email' => $user->email,
        ]);
        if ($customRole) {
            $membership->roles()->attach($customRole->id);
        }

        return [$user, new TenantContext($this->tenant->id, $user->id, $membership->id, $role)];
    }
}
