<?php

namespace App\Services\Source;

use App\Models\Connector;
use App\Models\ConnectorCommand;

interface SourceCommandGateway
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        Connector $connector,
        string $operation,
        array $payload,
        int $userId,
        ?string $correlationId,
    ): ConnectorCommand;
}
