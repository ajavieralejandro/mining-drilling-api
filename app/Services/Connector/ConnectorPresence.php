<?php

namespace App\Services\Connector;

use App\Models\Connector;
use App\Models\ConnectorCommand;
use App\Models\ConnectorSession;
use Illuminate\Support\Str;

class ConnectorPresence
{
    public function markOnline(Connector $connector, ?string $version = null, ?array $capabilities = null): void
    {
        $connector->forceFill([
            'status' => Connector::STATUS_ONLINE,
            'last_seen_at' => now(),
            'version' => $version ?? $connector->version,
            'capabilities' => $capabilities ?? $connector->capabilities,
        ])->save();
    }

    public function refreshSessionHeartbeat(ConnectorSession $session): void
    {
        $session->forceFill([
            'last_heartbeat_at' => now(),
        ])->save();
    }

    public function markOfflineIfStale(Connector $connector): void
    {
        if ($connector->status === Connector::STATUS_REVOKED) {
            return;
        }

        if (! $connector->isOnline()) {
            if ($connector->status !== Connector::STATUS_OFFLINE) {
                $connector->forceFill(['status' => Connector::STATUS_OFFLINE])->save();
            }
        }
    }

    public function assertOnline(Connector $connector): bool
    {
        $this->markOfflineIfStale($connector);
        $connector->refresh();

        return $connector->isOnline();
    }
}
