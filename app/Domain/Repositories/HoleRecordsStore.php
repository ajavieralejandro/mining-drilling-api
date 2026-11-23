<?php

namespace App\Domain\Repositories;

use App\Support\TenantContext;

/**
 * Operational record of a hole: depth state, daily progress reports and
 * their review. ADR-04: the data lives in the mining company's database and
 * UndSurf only relays it, so implementations must not persist it centrally.
 *
 * Business-rule failures surface as Illuminate ValidationException (422),
 * idempotency conflicts as HttpException 409 and connector failures as
 * App\Support\GatewayException. Every array returned carries `origin_id`.
 */
interface HoleRecordsStore
{
    /**
     * @return array{hole_status: string, state: array<string, mixed>|null, stats: array{total_logs: int, last_logged_at: ?string}, origin_id: ?string}
     */
    public function holeState(TenantContext $context, string $holeId, string $correlationId): array;

    /**
     * @return array<string, mixed> the new state, plus origin_id
     */
    public function initializeDepth(TenantContext $context, string $holeId, string $depth, ?string $notes, string $correlationId): array;

    /**
     * @param  array<string, mixed>  $report  normalized report (see TenantHoleProgressService)
     * @return array{item: array<string, mixed>, replayed: bool, origin_id: ?string}
     */
    public function recordProgress(TenantContext $context, string $holeId, array $report, string $correlationId): array;

    /**
     * @return array{items: list<array<string, mixed>>, total: int, totals: array{count: int, total_meters: string, holes_count: int}, origin_id: ?string}
     */
    public function queryProgress(TenantContext $context, ?string $holeId, ?string $status, int $page, int $perPage, string $correlationId): array;

    /**
     * @return array<string, mixed> the report, plus origin_id
     */
    public function getProgress(TenantContext $context, string $id, string $correlationId): array;

    /**
     * @return array<string, mixed> the reviewed report, plus origin_id
     */
    public function reviewProgress(TenantContext $context, string $id, string $action, ?string $notes, string $correlationId): array;

    /**
     * Core runs of the hole (loguera), newest run first, plus the sums over
     * every run of the hole.
     *
     * @return array{items: list<array<string, mixed>>, total: int, totals: array<string, mixed>, origin_id: ?string}
     */
    public function listCoreLogs(TenantContext $context, string $holeId, int $page, int $perPage, string $correlationId): array;

    /**
     * @param  array<string, mixed>  $run  run_from, run_to, recovered_meters, rqd_meters, logged_at and optional texts
     * @return array<string, mixed> the stored run, plus origin_id
     */
    public function createCoreLog(TenantContext $context, string $holeId, array $run, string $correlationId): array;

    /**
     * @return array<string, mixed> the run, plus origin_id
     */
    public function getCoreLog(TenantContext $context, string $holeId, string $id, string $correlationId): array;

    /**
     * @param  array<string, mixed>  $changes  only the keys present are changed; a null text clears it
     * @return array<string, mixed> the updated run, plus origin_id
     */
    public function updateCoreLog(TenantContext $context, string $holeId, string $id, array $changes, string $correlationId): array;

    public function deleteCoreLog(TenantContext $context, string $holeId, string $id, string $correlationId): void;

    /**
     * Crew of the hole: active assignments and the full history, newest first.
     *
     * @return array{active: list<array<string, mixed>>, history: list<array<string, mixed>>, origin_id: ?string}
     */
    public function crew(TenantContext $context, string $holeId, string $correlationId): array;

    /**
     * Changes the roles given in $roles (role => user id, null releases the
     * role). $mode is "crew" or "single" and decides which field a broken
     * crew rule is reported on.
     *
     * @param  array<string, int|null>  $roles
     * @param  array{notes?: ?string, assigned_from?: ?string, assigned_to?: ?string}  $extra
     * @return array{active: list<array<string, mixed>>, origin_id: ?string}
     */
    public function assignCrew(TenantContext $context, string $holeId, string $mode, array $roles, array $extra, string $correlationId): array;
}
