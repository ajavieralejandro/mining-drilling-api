<?php

namespace App\Models;

use App\Enums\CsvImportStatus;
use App\Enums\CsvSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImport extends Model
{
    protected $fillable = [
        'filename',
        'imported_by',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'errors',
        'strategy',
        'source_type',
    ];

    protected function casts(): array
    {
        return [
            'status' => CsvImportStatus::class,
            'source_type' => CsvSourceType::class,
            'errors' => 'array',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
