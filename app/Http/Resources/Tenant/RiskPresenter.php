<?php

namespace App\Http\Resources\Tenant;

use App\Models\User;

/**
 * Risks read from the client database, with the names of who reported,
 * mitigated and closed them resolved from UndSurf's users (the client
 * database keeps only the ids).
 */
final class RiskPresenter
{
    private const PEOPLE = ['reported_by', 'mitigated_by', 'closed_by'];

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function many(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach (self::PEOPLE as $key) {
                if (ctype_digit((string) ($item[$key] ?? ''))) {
                    $ids[(int) $item[$key]] = true;
                }
            }
        }
        $names = $ids === [] ? [] : User::query()->whereIn('id', array_keys($ids))->pluck('name', 'id')->all();

        return array_map(fn (array $i) => self::render($i, $names), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function one(array $item): array
    {
        return self::many([$item])[0];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private static function render(array $item, array $names): array
    {
        $person = function (mixed $id) use ($names): ?array {
            if (! ctype_digit((string) ($id ?? ''))) {
                return null;
            }

            return ['id' => (int) $id, 'name' => $names[(int) $id] ?? null];
        };

        return [
            'id' => $item['id'],
            'hole_id' => $item['hole_id'] ?? null,
            'hole_code' => $item['hole_code'] ?? null,
            'level' => $item['level'] ?? null,
            'status' => $item['status'] ?? null,
            'body' => $item['body'] ?? '',
            'depth_detected' => $item['depth_detected'] ?? null,
            'critical_distance' => $item['critical_distance'] ?? null,
            'recommended_action' => $item['recommended_action'] ?? null,
            'reported_by' => $person($item['reported_by'] ?? null),
            'reported_at' => $item['reported_at'] ?? null,
            'mitigated_by' => $person($item['mitigated_by'] ?? null),
            'mitigated_at' => $item['mitigated_at'] ?? null,
            'mitigation_notes' => $item['mitigation_notes'] ?? null,
            'closed_by' => $person($item['closed_by'] ?? null),
            'closed_at' => $item['closed_at'] ?? null,
            'closing_notes' => $item['closing_notes'] ?? null,
        ];
    }
}
