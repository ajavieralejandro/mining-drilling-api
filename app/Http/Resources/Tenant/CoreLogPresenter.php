<?php

namespace App\Http\Resources\Tenant;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Renders core runs read from the client database with the keys the former
 * TenantCoreLogResource used, and turns the connector's sums into the RQD
 * summary the web shows (recovery %, average RQD, Deere class).
 */
class CoreLogPresenter
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
     * @param  array<string, mixed>  $totals  sums over every run of the hole
     * @return array<string, mixed>
     */
    public static function summary(array $totals): array
    {
        $runs = (int) ($totals['total_runs'] ?? 0);
        $drill = sprintf('%.2f', $totals['total_drill_length'] ?? 0);
        $recovered = sprintf('%.2f', $totals['total_recovered_meters'] ?? 0);
        $rqd = sprintf('%.2f', $totals['total_rqd_meters'] ?? 0);
        $loss = sprintf('%.2f', $totals['total_core_loss_meters'] ?? 0);

        if ($runs === 0) {
            return [
                'total_runs' => 0,
                'total_drill_length' => '0.00',
                'total_recovered_meters' => '0.00',
                'recovery_percentage' => '0.00',
                'total_rqd_meters' => '0.00',
                'average_rqd_percentage' => '0.00',
                'rqd_classification' => 'Sin datos',
                'total_core_loss_meters' => '0.00',
                'total_boxes' => 0,
            ];
        }

        $averageRqd = self::percentage($rqd, $drill);

        return [
            'total_runs' => $runs,
            'total_drill_length' => $drill,
            'total_recovered_meters' => $recovered,
            'recovery_percentage' => self::percentage($recovered, $drill),
            'total_rqd_meters' => $rqd,
            'average_rqd_percentage' => $averageRqd,
            'rqd_classification' => self::deere((float) $averageRqd),
            'total_core_loss_meters' => $loss,
            'total_boxes' => (int) ($totals['total_boxes'] ?? 0),
        ];
    }

    /**
     * Part over whole, truncated to two places and capped at 100.
     */
    private static function percentage(string $part, string $whole): string
    {
        if (bccomp($whole, '0.00', 2) <= 0) {
            return '0.00';
        }
        $value = bcmul(bcdiv($part, $whole, 4), '100.00', 2);

        return bccomp($value, '100.00', 2) > 0 ? '100.00' : $value;
    }

    /**
     * Rock quality by RQD, Deere (1968).
     */
    private static function deere(float $rqd): string
    {
        return match (true) {
            $rqd >= 90.0 => 'Excelente',
            $rqd >= 75.0 => 'Buena',
            $rqd >= 50.0 => 'Regular',
            $rqd >= 25.0 => 'Mala',
            default => 'Muy Mala',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, User>
     */
    private static function users(array $items): Collection
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            array_filter(array_column($items, 'logged_by'), 'is_numeric'),
        )));

        return User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private static function render(array $item, string $tenantId, ?string $originId, Collection $users): array
    {
        $loggedBy = is_numeric($item['logged_by'] ?? null) ? (int) $item['logged_by'] : null;
        $user = $loggedBy !== null ? $users->get($loggedBy) : null;
        $decimal = fn (string $key) => sprintf('%.2f', $item[$key] ?? 0);

        return [
            'id' => $item['id'] ?? null,
            'tenant_id' => $tenantId,
            'origin_id' => $originId,
            'hole_id' => $item['hole_id'] ?? null,
            'box_number' => $item['box_number'] ?? null,
            'run_from' => $decimal('run_from'),
            'run_to' => $decimal('run_to'),
            'drill_length' => $decimal('drill_length'),
            'recovered_meters' => $decimal('recovered_meters'),
            'rqd_meters' => $decimal('rqd_meters'),
            'rqd_percentage' => $decimal('rqd_percentage'),
            'core_loss_meters' => $decimal('core_loss_meters'),
            'geology_notes' => $item['geology_notes'] ?? null,
            'photo_url' => $item['photo_url'] ?? null,
            'logged_by' => $loggedBy,
            'logged_by_user' => $user ? (new UserResource($user))->resolve() : null,
            'logged_at' => $item['logged_at'] ?? null,
            'created_at' => $item['created_at'] ?? null,
        ];
    }
}
