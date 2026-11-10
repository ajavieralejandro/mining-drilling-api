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
}
