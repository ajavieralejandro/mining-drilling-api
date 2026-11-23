<?php

namespace App\Http\Resources\Tenant;

/**
 * Turns the connector's project, mine and hole-link rows into the shape
 * the web already consumes (the one TenantProjectResource,
 * TenantMineResource and TenantMineHoleResource used to produce).
 */
final class OrganizationPresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function project(array $row, string $tenantId): array
    {
        $out = [
            'id' => $row['id'],
            'tenant_id' => $tenantId,
            'code' => $row['code'] ?? null,
            'name' => $row['name'] ?? null,
            'status' => $row['status'] ?? null,
            'description' => $row['description'] ?? null,
            'created_by' => self::userId($row['created_by'] ?? null),
            'mines_count' => (int) ($row['mines_count'] ?? 0),
            'holes_count' => (int) ($row['holes_count'] ?? 0),
        ];
        if (isset($row['mines']) && is_array($row['mines'])) {
            $out['mines'] = self::mines($row['mines'], $tenantId);
        }
        $out['created_at'] = $row['created_at'] ?? null;
        $out['updated_at'] = $row['updated_at'] ?? null;

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function projects(array $rows, string $tenantId): array
    {
        return array_map(fn (array $r) => self::project($r, $tenantId), $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function mine(array $row, string $tenantId): array
    {
        $project = is_array($row['project'] ?? null) ? $row['project'] : null;

        $out = [
            'id' => $row['id'],
            'tenant_id' => $tenantId,
            'project_id' => $row['project_id'] ?? null,
            'code' => $row['code'] ?? null,
            'name' => $row['name'] ?? null,
            'location' => $row['location'] ?? null,
            'status' => $row['status'] ?? null,
            'description' => $row['description'] ?? null,
            'created_by' => self::userId($row['created_by'] ?? null),
            'project' => $project ? [
                'id' => $project['id'] ?? null,
                'code' => $project['code'] ?? null,
                'name' => $project['name'] ?? null,
                'status' => $project['status'] ?? null,
            ] : null,
            'holes_count' => (int) ($row['holes_count'] ?? 0),
        ];
        if (isset($row['holes']) && is_array($row['holes'])) {
            $out['holes'] = self::holeLinks($row['holes'], $tenantId);
        }
        $out['created_at'] = $row['created_at'] ?? null;
        $out['updated_at'] = $row['updated_at'] ?? null;

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function mines(array $rows, string $tenantId): array
    {
        return array_map(fn (array $r) => self::mine($r, $tenantId), $rows);
    }

    /**
     * A hole has at most one mine, so the hole id identifies the link.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function holeLink(array $row, string $tenantId): array
    {
        $mine = is_array($row['mine'] ?? null) ? $row['mine'] : null;
        $holeId = (string) $row['hole_id'];

        return [
            'id' => $holeId,
            'tenant_id' => $tenantId,
            'mine_id' => $row['mine_id'] ?? null,
            'origin_id' => $row['origin_id'] ?? null,
            'hole_id' => $holeId,
            'hole_code' => ($row['hole_code'] ?? null) ?: $holeId,
            'drill_hole_id' => null,
            'status' => $row['status'] ?? null,
            'notes' => $row['notes'] ?? null,
            'assigned_by' => self::userId($row['assigned_by'] ?? null),
            'mine' => $mine ? [
                'id' => $mine['id'] ?? null,
                'code' => $mine['code'] ?? null,
                'name' => $mine['name'] ?? null,
                'location' => $mine['location'] ?? null,
                'project_id' => $mine['project_id'] ?? null,
            ] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function holeLinks(array $rows, string $tenantId): array
    {
        return array_map(fn (array $r) => self::holeLink($r, $tenantId), $rows);
    }

    private static function userId(mixed $id): int|string|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ctype_digit((string) $id) ? (int) $id : (string) $id;
    }
}
