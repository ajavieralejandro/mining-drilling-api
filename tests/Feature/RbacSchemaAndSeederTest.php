<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacSchemaAndSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_super_admin_flag(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->assertTrue($user->isSuperAdmin());

        $regular = User::factory()->create(['is_super_admin' => false]);
        $this->assertFalse($regular->isSuperAdmin());
    }

    public function test_rbac_seeder_populates_permissions_and_system_roles(): void
    {
        $this->seed(RbacSeeder::class);

        // Verifica existencia de permisos clave
        $this->assertDatabaseHas('permissions', ['name' => 'drill_holes.view_all']);
        $this->assertDatabaseHas('permissions', ['name' => 'progress.create']);
        $this->assertDatabaseHas('permissions', ['name' => 'roles.manage']);
        $this->assertDatabaseHas('permissions', ['name' => 'tenants.manage']);

        // Verifica roles del sistema
        $this->assertDatabaseHas('roles', ['slug' => 'super_admin', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['slug' => 'admin', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['slug' => 'supervisor', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['slug' => 'geologist', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['slug' => 'driller', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['slug' => 'helper', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['slug' => 'geotechnical', 'is_system' => true]);

        // Verifica que super_admin tiene todos los permisos
        $superAdminRole = Role::where('slug', 'super_admin')->firstOrFail();
        $totalPermissions = Permission::count();
        $this->assertEquals($totalPermissions, $superAdminRole->permissions()->count());

        // Verifica que el rol driller tiene progress.create pero NO drill_holes.update_technical_data
        $drillerRole = Role::where('slug', 'driller')->firstOrFail();
        $this->assertTrue($drillerRole->hasPermission('progress.create'));
        $this->assertFalse($drillerRole->hasPermission('drill_holes.update_technical_data'));

        // Verifica que el rol geologist tiene drill_holes.update_technical_data
        $geologistRole = Role::where('slug', 'geologist')->firstOrFail();
        $this->assertTrue($geologistRole->hasPermission('drill_holes.update_technical_data'));
    }

    public function test_can_create_custom_role_with_dynamic_permissions(): void
    {
        $this->seed(RbacSeeder::class);

        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera Los Andes']);

        // Crear un rol dinámico personalizado para este tenant
        $customRole = Role::create([
            'name' => 'Jefe de Guardia Perforación',
            'slug' => 'shift_boss',
            'tenant_id' => $tenant->id,
            'description' => 'Supervisión en terreno del turno nocturno',
            'is_system' => false,
        ]);

        $perms = Permission::whereIn('name', ['progress.view', 'progress.create', 'observations.create'])->get();
        $customRole->permissions()->sync($perms->pluck('id'));

        $this->assertCount(3, $customRole->fresh()->permissions);
        $this->assertTrue($customRole->fresh()->hasPermission('progress.create'));
        $this->assertFalse($customRole->fresh()->hasPermission('roles.manage'));
    }

    public function test_user_groups_and_membership_role_associations(): void
    {
        $this->seed(RbacSeeder::class);

        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera San Pedro']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Membresía activa
        $membership = Membership::create([
            'id' => (string) Str::ulid(),
            'user_id' => $user1->id,
            'tenant_id' => $tenant->id,
            'role' => 'driller',
            'status' => Membership::STATUS_ACTIVE,
        ]);

        $drillerRole = Role::where('slug', 'driller')->firstOrFail();
        $membership->roles()->attach($drillerRole->id);

        $this->assertTrue($membership->roles->contains('id', $drillerRole->id));

        // Crear Grupo de Usuarios
        $group = UserGroup::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cuadrilla Diamantina Noche',
            'description' => 'Operadores de perforación turno B',
        ]);

        $group->users()->attach([$user1->id, $user2->id]);
        $group->roles()->attach([$drillerRole->id]);

        $this->assertCount(2, $group->fresh()->users);
        $this->assertCount(1, $group->fresh()->roles);
        $this->assertTrue($user1->userGroups->contains('id', $group->id));
    }
}
