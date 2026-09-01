<?php

namespace App\Console\Commands;

use App\Models\ConnectorEnrollmentToken;
use App\Models\Tenant;
use App\Support\TokenHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueConnectorEnrollmentToken extends Command
{
    protected $signature = 'connector:issue-enrollment-token {--tenant= : Tenant ULID} {--name=Lab Tenant : Tenant name if creating} {--hours=24 : Token TTL hours}';

    protected $description = 'Issue a one-time connector enrollment token (plaintext printed once; only hash stored)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenant = Tenant::query()->findOrFail($tenantId);
        } else {
            $tenant = Tenant::query()->firstOrCreate(
                ['name' => (string) $this->option('name')],
                ['id' => (string) Str::ulid()],
            );
        }

        $plaintext = TokenHasher::generate('enr');
        $hours = max(1, (int) $this->option('hours'));

        ConnectorEnrollmentToken::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'token_hash' => TokenHasher::hash($plaintext),
            'expires_at' => now()->addHours($hours),
        ]);

        $this->info('tenant_id='.$tenant->id);
        $this->warn('enrollment_token (one-time, not stored in plaintext):');
        $this->line($plaintext);

        return self::SUCCESS;
    }
}
