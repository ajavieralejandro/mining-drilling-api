<?php

namespace App\Http\Resources\Tenant;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Renders a progress report read from the client database with the same
 * keys TenantHoleProgressLogResource used, so the web does not change. Who
 * reported and who reviewed come from UndSurf's users table (the client
 * database only stores the user id).
 */
class ProgressReportPresenter
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function many(array $items, string $tenantId, ?string $originId): array
    {
        $users = self::users($items);

        return array_map(fn (array $item) => self::render($item, $tenantId, $originId, $users), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function one(array $item, string $tenantId, ?string $originId): array
    {
        return self::render($item, $tenantId, $originId, self::users([$item]));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, User>
     */
    private static function users(array $items): Collection
    {
        $ids = [];
        foreach ($items as $item) {
            foreach (['user_id', 'reviewed_by'] as $key) {
                if (isset($item[$key]) && is_numeric($item[$key])) {
                    $ids[] = (int) $item[$key];
                }
            }
        }

        return User::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private static function render(array $item, string $tenantId, ?string $originId, Collection $users): array
    {
        $depthFrom = sprintf('%.2f', $item['depth_from'] ?? 0);
        $depthTo = sprintf('%.2f', $item['depth_to'] ?? 0);
        $userId = self::intOrNull($item['user_id'] ?? null);
        $reviewerId = self::intOrNull($item['reviewed_by'] ?? null);
        $user = $userId !== null ? $users->get($userId) : null;
        $reviewer = $reviewerId !== null ? $users->get($reviewerId) : null;

        return [
            'id' => $item['id'] ?? null,
            'tenant_id' => $tenantId,
            'operational_state_id' => null,
            'origin_id' => $originId,
            'hole_id' => $item['hole_id'] ?? null,
            'user_id' => $userId,
            'depth_from' => $depthFrom,
            'depth_to' => $depthTo,
            'depth_current' => sprintf('%.2f', $item['depth_current'] ?? $item['depth_to'] ?? 0),
            'advance_meters' => bcsub($depthTo, $depthFrom, 2),
            'shift' => $item['shift'] ?? null,
            'hours_worked' => isset($item['hours_worked']) ? sprintf('%.2f', $item['hours_worked']) : null,
            'status' => $item['status'] ?? 'pending_review',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => $item['reviewed_at'] ?? null,
            'review_notes' => $item['review_notes'] ?? null,
            'idempotency_key' => $item['idempotency_key'] ?? null,
            'crew_snapshot' => $item['crew_snapshot'] ?? null,
            'logged_at' => $item['logged_at'] ?? null,
            'observations' => $item['observations'] ?? null,
            'user' => $user ? (new UserResource($user))->resolve() : null,
            'reviewer' => $reviewer ? (new UserResource($reviewer))->resolve() : null,
            'created_at' => $item['created_at'] ?? null,
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
