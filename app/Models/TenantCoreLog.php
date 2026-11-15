<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCoreLog extends Model
{
    use HasUlids;

    protected $table = 'tenant_core_logs';

    protected $fillable = [
        'id',
        'tenant_id',
        'origin_id',
        'hole_id',
        'box_number',
        'run_from',
        'run_to',
        'drill_length',
        'recovered_meters',
        'rqd_meters',
        'rqd_percentage',
        'core_loss_meters',
        'logged_by',
        'logged_at',
        'geology_notes',
        'photo_url',
    ];

    protected function casts(): array
    {
        return [
            'run_from' => 'decimal:2',
            'run_to' => 'decimal:2',
            'drill_length' => 'decimal:2',
            'recovered_meters' => 'decimal:2',
            'rqd_meters' => 'decimal:2',
            'rqd_percentage' => 'decimal:2',
            'core_loss_meters' => 'decimal:2',
            'logged_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function loggedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function scopeForHole(Builder $query, string $tenantId, string $holeId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('hole_id', $holeId);
    }

    public function scopeForWell(Builder $query, string $tenantId, string $originId, string $holeId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->where('origin_id', $originId)
            ->where('hole_id', $holeId);
    }

    /**
     * Calcula métricas geológicas estándar de corrida y RQD (Rock Quality Designation)
     * RQD (%) = (Suma de trozos de testigo sano >= 10 cm / Largo perforado) * 100
     */
    public static function calculateMetrics(float|string $runFrom, float|string $runTo, float|string $recoveredMeters, float|string $rqdMeters): array
    {
        $drillLength = bcsub(sprintf('%.2f', $runTo), sprintf('%.2f', $runFrom), 2);
        $recovered = sprintf('%.2f', $recoveredMeters);
        $rqd = sprintf('%.2f', $rqdMeters);
        $coreLoss = bcsub($drillLength, $recovered, 2);

        $percentage = '0.00';
        if (bccomp($drillLength, '0.00', 2) > 0) {
            $ratio = bcdiv($rqd, $drillLength, 4);
            $percentage = bcmul($ratio, '100.00', 2);
            if (bccomp($percentage, '100.00', 2) > 0) {
                $percentage = '100.00';
            }
        }

        return [
            'drill_length' => $drillLength,
            'recovered_meters' => $recovered,
            'rqd_meters' => $rqd,
            'core_loss_meters' => bccomp($coreLoss, '0.00', 2) < 0 ? '0.00' : $coreLoss,
            'rqd_percentage' => $percentage,
        ];
    }

    /**
     * Calcula el resumen geomecánico acumulado de todos los testigos y RQD del pozo.
     */
    public static function calculateHoleRqdSummary(string $tenantId, string $originId, string $holeId): array
    {
        $logs = static::forWell($tenantId, $originId, $holeId)->get();

        $totalRuns = $logs->count();
        if ($totalRuns === 0) {
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

        $totalDrill = '0.00';
        $totalRecovered = '0.00';
        $totalRqd = '0.00';
        $totalCoreLoss = '0.00';
        $boxes = [];

        foreach ($logs as $log) {
            $totalDrill = bcadd($totalDrill, sprintf('%.2f', $log->drill_length), 2);
            $totalRecovered = bcadd($totalRecovered, sprintf('%.2f', $log->recovered_meters), 2);
            $totalRqd = bcadd($totalRqd, sprintf('%.2f', $log->rqd_meters), 2);
            $totalCoreLoss = bcadd($totalCoreLoss, sprintf('%.2f', $log->core_loss_meters), 2);

            if ($log->box_number !== null && trim($log->box_number) !== '') {
                $boxes[trim($log->box_number)] = true;
            }
        }

        $recoveryPercentage = '0.00';
        $averageRqd = '0.00';

        if (bccomp($totalDrill, '0.00', 2) > 0) {
            $recRatio = bcdiv($totalRecovered, $totalDrill, 4);
            $recoveryPercentage = bcmul($recRatio, '100.00', 2);
            if (bccomp($recoveryPercentage, '100.00', 2) > 0) {
                $recoveryPercentage = '100.00';
            }

            $rqdRatio = bcdiv($totalRqd, $totalDrill, 4);
            $averageRqd = bcmul($rqdRatio, '100.00', 2);
            if (bccomp($averageRqd, '100.00', 2) > 0) {
                $averageRqd = '100.00';
            }
        }

        return [
            'total_runs' => $totalRuns,
            'total_drill_length' => $totalDrill,
            'total_recovered_meters' => $totalRecovered,
            'recovery_percentage' => $recoveryPercentage,
            'total_rqd_meters' => $totalRqd,
            'average_rqd_percentage' => $averageRqd,
            'rqd_classification' => static::deereClassification((float) $averageRqd),
            'total_core_loss_meters' => $totalCoreLoss,
            'total_boxes' => count($boxes),
        ];
    }

    /**
     * Clasificación internacional geomecánica de roca según Deere (1968).
     */
    public static function deereClassification(float $rqd): string
    {
        if ($rqd >= 90.0) {
            return 'Excelente';
        }
        if ($rqd >= 75.0) {
            return 'Buena';
        }
        if ($rqd >= 50.0) {
            return 'Regular';
        }
        if ($rqd >= 25.0) {
            return 'Mala';
        }
        return 'Muy Mala';
    }
}
