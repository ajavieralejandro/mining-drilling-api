<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds two tenants and one user with an active membership in each, for the
 * distributed-data vertical-slice demo and its tenant-isolation tests. Not
 * part of the default DatabaseSeeder chain (run explicitly), same
 * convention as ConnectorLabSeeder.
 */
class TenantVerticalSliceSeeder extends Seeder
{
    public function run(): void
    {
        $tenantA = Tenant::query()->firstOrCreate(
            ['name' => 'Minera Demo A'],
            ['id' => (string) Str::ulid()],
        );
        $tenantB = Tenant::query()->firstOrCreate(
            ['name' => 'Minera Demo B'],
            ['id' => (string) Str::ulid()],
        );

        $userA = User::query()->updateOrCreate(
            ['email' => 'supervisor-a@undsurf.test'],
            [
                'name' => 'Supervisor Minera A',
                'password' => Hash::make('password'),
                'role' => UserRole::Supervisor,
                'active' => true,
            ],
        );
        $userB = User::query()->updateOrCreate(
            ['email' => 'supervisor-b@undsurf.test'],
            [
                'name' => 'Supervisor Minera B',
                'password' => Hash::make('password'),
                'role' => UserRole::Supervisor,
                'active' => true,
            ],
        );

        Membership::query()->updateOrCreate(
            ['user_id' => $userA->id, 'tenant_id' => $tenantA->id],
            ['id' => (string) Str::ulid(), 'role' => 'supervisor', 'status' => Membership::STATUS_ACTIVE],
        );
        Membership::query()->updateOrCreate(
            ['user_id' => $userB->id, 'tenant_id' => $tenantB->id],
            ['id' => (string) Str::ulid(), 'role' => 'supervisor', 'status' => Membership::STATUS_ACTIVE],
        );

        $this->command?->info('Tenant A: '.$tenantA->id.' — supervisor-a@undsurf.test / password');
        $this->command?->info('Tenant B: '.$tenantB->id.' — supervisor-b@undsurf.test / password');
    }
}
