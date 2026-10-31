<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\TenantSourceConfig;
use App\Services\Source\SourceCommandGateway;
use Illuminate\Support\Str;

class FakeSourceCommandGateway implements SourceCommandGateway
{
    public ?string $failure = null;

    /** @var list<string> */
    public array $operations = [];

    public function execute(
        Connector $connector,
        string $operation,
        array $payload,
        int $userId,
        ?string $correlationId,
    ): ConnectorCommand {
        $this->operations[] = $operation;
        $config = TenantSourceConfig::query()->findOrFail($payload['config_id']);
        $failed = $this->failure !== null;

        return ConnectorCommand::query()->create([
            'id' => (string) Str::ulid(),
            'request_id' => 'req_'.(string) Str::ulid(),
            'correlation_id' => $correlationId ?: 'cor_test',
            'tenant_id' => $connector->tenant_id,
            'connector_id' => $connector->id,
            'op' => $operation,
            'payload_json' => $payload,
            'status' => $failed ? ConnectorCommand::STATUS_FAILED : ConnectorCommand::STATUS_COMPLETED,
            'result_json' => [
                'applied' => ! $failed && $operation === 'source_config.apply@1',
                'version' => $config->version,
                'fingerprint' => $config->fingerprint,
                'engine' => $config->engine,
                'host' => $config->host,
                'port' => $config->port,
                'database' => $config->database_name,
                'tls_mode' => $config->tls_mode,
                'tls_verified' => ! $failed,
                'read_only' => true,
                'latency_ms' => 15,
            ],
            'error_code' => $this->failure,
            'deadline_at' => now()->addMinute(),
            'dispatched_at' => now(),
            'completed_at' => now(),
            'duration_ms' => 15,
        ]);
    }
}
