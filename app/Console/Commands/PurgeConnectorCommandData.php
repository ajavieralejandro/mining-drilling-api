<?php

namespace App\Console\Commands;

use App\Models\ConnectorCommand;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-04 safety net. Callers purge a command as soon as they read its
 * result; this catches the ones a crashed request or an abandoned wait left
 * behind. A grace period keeps a command that a request is still reading.
 */
class PurgeConnectorCommandData extends Command
{
    protected $signature = 'connector:purge-command-data {--grace=120 : Seconds a closed command keeps its data}';

    protected $description = 'Remove operational payloads and results left on closed connector commands';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(max(0, (int) $this->option('grace')));
        $purged = 0;

        ConnectorCommand::query()
            ->where('op', 'not like', 'source_config.%')
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(function (Builder $closed) use ($cutoff): void {
                        $closed->whereIn('status', [
                            ConnectorCommand::STATUS_COMPLETED,
                            ConnectorCommand::STATUS_FAILED,
                            ConnectorCommand::STATUS_TIMEOUT,
                        ])->where('completed_at', '<', $cutoff);
                    })
                    ->orWhere('deadline_at', '<', $cutoff);
            })
            ->chunkById(200, function ($commands) use (&$purged): void {
                foreach ($commands as $command) {
                    if ($command->payload_json === [] && $command->result_json === null) {
                        continue;
                    }

                    $command->purgeOperationalData();
                    $purged++;
                }
            });

        $this->info("purged={$purged}");

        return self::SUCCESS;
    }
}
