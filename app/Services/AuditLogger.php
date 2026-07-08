<?php

namespace App\Services;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class AuditLogger
{
    public static function log(
        Authenticatable|User|null $user,
        string $entityType,
        int $entityId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        AuditSource|string $source = AuditSource::System,
    ): AuditLog {
        if ($source instanceof AuditSource) {
            $source = $source->value;
        }

        return AuditLog::create([
            'user_id' => $user?->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'source' => $source,
            'created_at' => now(),
        ]);
    }
}
