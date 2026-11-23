<?php

namespace App\Http\Resources\Tenant;

use App\Models\User;

/**
 * Observations read from the client database, with the author's name
 * resolved from UndSurf's users (the client database keeps only the id).
 */
final class ObservationPresenter
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function many(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $i) => ctype_digit((string) ($i['user_id'] ?? '')) ? (int) $i['user_id'] : null,
            $items,
        ))));
        $names = $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

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
        $userId = ctype_digit((string) ($item['user_id'] ?? '')) ? (int) $item['user_id'] : null;
        $type = is_array($item['type'] ?? null) ? $item['type'] : [];

        return [
            'id' => $item['id'],
            'hole_id' => $item['hole_id'] ?? null,
            'type' => [
                'id' => $type['id'] ?? null,
                'code' => $type['code'] ?? null,
                'name' => $type['name'] ?? null,
            ],
            'body' => $item['body'] ?? '',
            'depth' => $item['depth'] ?? null,
            'user' => $userId !== null ? ['id' => $userId, 'name' => $names[$userId] ?? null] : null,
            'created_at' => $item['created_at'] ?? null,
        ];
    }
}
