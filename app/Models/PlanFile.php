<?php

namespace App\Models;

use App\Enums\PlanFileType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlanFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'drilling_plan_id',
        'name',
        'type',
        'version',
        'description',
        'file_path',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => PlanFileType::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DrillingPlan::class, 'drilling_plan_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
