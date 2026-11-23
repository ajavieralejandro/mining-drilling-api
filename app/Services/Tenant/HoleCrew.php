<?php

namespace App\Services\Tenant;

use App\Domain\Repositories\HoleRecordsStore;
use App\Support\TenantContext;

/**
 * The active crew of a hole as UndSurf user ids, read from the client
 * database. Policies use it to let crew members act on their own holes.
 */
class HoleCrew
{
    public function __construct(private readonly HoleRecordsStore $records) {}

    /**
     * @return list<int>
     */
    public function userIds(TenantContext $context, string $holeId, string $correlationId): array
    {
        $crew = $this->records->crew($context, $holeId, $correlationId);

        return array_values(array_filter(array_map(
            fn (array $a) => ctype_digit((string) ($a['user_id'] ?? '')) ? (int) $a['user_id'] : null,
            $crew['active'] ?? [],
        )));
    }
}
