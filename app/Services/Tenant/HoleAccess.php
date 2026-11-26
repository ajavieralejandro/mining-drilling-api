<?php

namespace App\Services\Tenant;

use App\Domain\Repositories\HoleRecordsStore;
use App\Domain\Repositories\TenantHoleRepositoryInterface;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\GatewayException;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Which holes a person may read (plan 15, block C).
 *
 * - The admin and whoever holds drill_holes.view_all see every hole.
 * - Whoever holds only drill_holes.view_assigned sees the holes where they
 *   are in the active crew, read from the client database.
 * - Anyone else sees no hole.
 */
class HoleAccess
{
    public function __construct(
        private readonly HoleRecordsStore $records,
        private readonly HoleCrew $crew,
        private readonly TenantHoleRepositoryInterface $holes,
    ) {}

    public function seesAll(User $user, TenantContext $context): bool
    {
        return $user->isAdmin()
            || $user->isSuperAdmin()
            || $context->role === UserRole::Admin->value
            || $user->hasPermission('drill_holes.view_all', $context->tenantId);
    }

    public function seesAssigned(User $user, TenantContext $context): bool
    {
        return $this->seesAll($user, $context)
            || $user->hasPermission('drill_holes.view_assigned', $context->tenantId);
    }

    /** One hole: asks the connector for its crew only when the answer depends on it. */
    public function canSee(User $user, TenantContext $context, string $holeId, string $correlationId): bool
    {
        if ($this->seesAll($user, $context)) {
            return true;
        }
        if (! $this->seesAssigned($user, $context)) {
            return false;
        }

        return in_array($user->id, $this->crew->userIds($context, $holeId, $correlationId), true);
    }

    /**
     * For controllers that read one hole's data: a 403 response when the
     * person may not see the hole, the connector's error when the crew lookup
     * fails, null when the request may go on.
     */
    public function denyUnlessVisible(User $user, TenantContext $context, string $holeId, string $correlationId): ?JsonResponse
    {
        try {
            if ($this->canSee($user, $context, $holeId, $correlationId)) {
                return null;
            }
        } catch (GatewayException $e) {
            return $e->toResponse();
        }

        return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Este pozo no está asignado a tu cuadrilla.']], 403);
    }

    /**
     * The person's assigned holes, newest assignment first, without
     * repeats. Null when the person sees every hole.
     *
     * @return list<string>|null
     *
     * @throws GatewayException UNSUPPORTED_OP on a connector without assignments.by_user@1
     */
    public function assignedHoleIds(User $user, TenantContext $context, string $correlationId): ?array
    {
        if ($this->seesAll($user, $context)) {
            return null;
        }
        if (! $this->seesAssigned($user, $context)) {
            return [];
        }

        $active = $this->records->assignmentsOf($context, $user->id, $correlationId)['active'] ?? [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $a) => isset($a['hole_id']) ? (string) $a['hole_id'] : null,
            $active,
        ))));
    }

    /**
     * Like assignedHoleIds(), but on a connector without
     * assignments.by_user@1 it checks the crew of the first holes of the
     * usual list instead (up to 50, one lookup each).
     *
     * @return list<string>|null null when the person sees every hole
     */
    public function visibleHoleIds(User $user, TenantContext $context, string $correlationId): ?array
    {
        try {
            return $this->assignedHoleIds($user, $context, $correlationId);
        } catch (GatewayException $e) {
            if ($e->errorCode !== 'UNSUPPORTED_OP') {
                throw $e;
            }
        }

        $items = $this->holes->list($context, $correlationId, 50)['items'] ?? [];

        return array_values(array_map(
            fn (array $item) => (string) $item['id'],
            $this->filterVisible($user, $context, $items, $correlationId),
        ));
    }

    /**
     * Mine-hole links (`hole_id`) limited to the visible holes.
     *
     * @param  list<array<string, mixed>>  $links
     * @param  list<string>  $visible
     * @return list<array<string, mixed>>
     */
    public static function onlyLinks(array $links, array $visible): array
    {
        return array_values(array_filter($links, fn (array $l) => in_array((string) ($l['hole_id'] ?? ''), $visible, true)));
    }

    /**
     * Projects → mines → holes keeping only the mines and projects that hold a
     * visible hole, with their counts recomputed.
     *
     * @param  list<array<string, mixed>>  $projects
     * @param  list<string>  $visible
     * @return list<array<string, mixed>>
     */
    public static function onlyHierarchy(array $projects, array $visible): array
    {
        $out = [];
        foreach ($projects as $project) {
            $mines = [];
            foreach ($project['mines'] ?? [] as $mine) {
                $mine['holes'] = self::onlyLinks($mine['holes'] ?? [], $visible);
                $mine['holes_count'] = count($mine['holes']);
                if ($mine['holes_count'] > 0) {
                    $mines[] = $mine;
                }
            }
            if ($mines === []) {
                continue;
            }
            $project['mines'] = $mines;
            $project['mines_count'] = count($mines);
            $project['holes_count'] = array_sum(array_column($mines, 'holes_count'));
            $out[] = $project;
        }

        return $out;
    }

    /**
     * Keeps the items whose `id` the person may see. Used as a fallback on an
     * older connector: one crew lookup per item, so callers keep the list short.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function filterVisible(User $user, TenantContext $context, array $items, string $correlationId): array
    {
        if ($this->seesAll($user, $context)) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            fn (array $item) => isset($item['id']) && $this->canSee($user, $context, (string) $item['id'], $correlationId),
        ));
    }
}
