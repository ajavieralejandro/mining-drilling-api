<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-04: operational data only passes through connector_commands. It is
 * encrypted while it waits there and removed once it has been read.
 */
class ConnectorCommandDataTest extends TestCase
{
    public function test_payload_and_result_are_encrypted_at_rest(): void
    {
        $command = $this->command('progress.create@1', [
            'payload_json' => ['hole_id' => '1001', 'note' => 'Falla en la caja 12'],
            'result_json' => ['item' => ['id' => 'p-1', 'depth_to' => 48.5]],
        ]);

        $raw = DB::table('connector_commands')->where('id', $command->id)->first();

        $this->assertStringNotContainsString('Falla en la caja 12', (string) $raw->payload_json);
        $this->assertStringNotContainsString('48.5', (string) $raw->result_json);
        $this->assertSame('Falla en la caja 12', $command->fresh()->payload_json['note']);
        $this->assertSame(48.5, $command->fresh()->result_json['item']['depth_to']);
    }

    public function test_purge_keeps_the_envelope_and_drops_the_data(): void
    {
        $command = $this->command('drill_holes.list@1');

        $command->purgeOperationalData();

        $stored = $command->fresh();
        $this->assertSame([], $stored->payload_json);
        $this->assertNull($stored->result_json);
        $this->assertSame(ConnectorCommand::STATUS_COMPLETED, $stored->status);
        $this->assertSame('drill_holes.list@1', $stored->op);
        $this->assertNotNull($stored->request_id);
    }

    public function test_source_config_commands_keep_their_data(): void
    {
        $command = $this->command('source_config.test@1', [
            'payload_json' => ['config_id' => 'cfg-1'],
            'result_json' => ['latency_ms' => 12],
        ]);

        $command->purgeOperationalData();

        $this->assertSame(['config_id' => 'cfg-1'], $command->fresh()->payload_json);
        $this->assertSame(['latency_ms' => 12], $command->fresh()->result_json);
    }

    public function test_scheduled_purge_clears_old_commands_and_spares_recent_ones(): void
    {
        $old = $this->command('drill_holes.list@1', ['completed_at' => now()->subMinutes(5)]);
        $recent = $this->command('drill_holes.list@1', ['completed_at' => now()->subSeconds(10)]);
        $abandoned = $this->command('personnel.sync@1', [
            'status' => ConnectorCommand::STATUS_PENDING,
            'completed_at' => null,
            'deadline_at' => now()->subMinutes(5),
        ]);
        $source = $this->command('source_config.apply@1', ['completed_at' => now()->subMinutes(5)]);

        $this->artisan('connector:purge-command-data')
            ->expectsOutput('purged=2')
            ->assertSuccessful();

        $this->assertNull($old->fresh()->result_json);
        $this->assertSame([], $abandoned->fresh()->payload_json);
        $this->assertNotNull($recent->fresh()->result_json);
        $this->assertNotNull($source->fresh()->result_json);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function command(string $op, array $overrides = []): ConnectorCommand
    {
        $tenant = Tenant::create(['id' => (string) Str::ulid(), 'name' => 'Minera '.Str::random(4)]);
        $connector = Connector::create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'label' => 'lab',
            'status' => 'active',
            'connector_token_hash' => hash('sha256', 'dummy-token-'.Str::random(8)),
            'last_seen_at' => now(),
        ]);

        return ConnectorCommand::create(array_merge([
            'id' => (string) Str::ulid(),
            'request_id' => 'req_'.(string) Str::ulid(),
            'correlation_id' => 'cor_'.(string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'connector_id' => $connector->id,
            'op' => $op,
            'payload_json' => ['limit' => 10],
            'status' => ConnectorCommand::STATUS_COMPLETED,
            'result_json' => ['items' => [['id' => '1001']]],
            'deadline_at' => now()->addMinute(),
            'dispatched_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ], $overrides));
    }
}
