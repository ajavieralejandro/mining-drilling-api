<?php

namespace Database\Seeders;

use App\Models\ConnectorEnrollmentToken;
use App\Models\Tenant;
use App\Support\TokenHasher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ConnectorLabSeeder extends Seeder
{
    /**
     * Seeds a lab tenant. Enrollment plaintext is written only to the console once.
     * It is NEVER stored in the database.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['name' => 'Lab Tenant'],
            ['id' => (string) Str::ulid()],
        );

        $plaintext = TokenHasher::generate('enr');

        ConnectorEnrollmentToken::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'token_hash' => TokenHasher::hash($plaintext),
            'expires_at' => now()->addDays(7),
        ]);

        $this->command?->warn('LAB enrollment token (store securely, shown once):');
        $this->command?->line($plaintext);
        $this->command?->warn('Tenant ID: '.$tenant->id);
    }
}
