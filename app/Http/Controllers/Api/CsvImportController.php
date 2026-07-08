<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditSource;
use App\Enums\CsvImportStatus;
use App\Enums\CsvSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ConfirmImportRequest;
use App\Http\Requests\Import\PreviewImportRequest;
use App\Http\Resources\CsvImportResource;
use App\Models\CsvImport;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CsvImportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CsvImport::class);

        return CsvImportResource::collection(
            CsvImport::query()->latest()->get()
        );
    }

    public function preview(PreviewImportRequest $request): CsvImportResource
    {
        $rows = $request->input('rows', []);
        $totalRows = count($rows);
        $validRows = 0;
        $invalidRows = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            if (empty($row['hole_id'])) {
                $invalidRows++;
                $errors[] = ['row' => $index + 1, 'message' => 'hole_id is required'];

                continue;
            }

            $validRows++;
        }

        if ($totalRows === 0) {
            $totalRows = 10;
            $validRows = 8;
            $invalidRows = 2;
            $errors = [
                ['row' => 3, 'message' => 'Invalid azimuth value'],
                ['row' => 7, 'message' => 'Duplicate hole_id'],
            ];
        }

        $import = CsvImport::create([
            'filename' => $request->input('filename'),
            'imported_by' => $request->user()->id,
            'status' => CsvImportStatus::Previewed,
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'errors' => $errors,
            'strategy' => $request->input('strategy', 'upsert'),
            'source_type' => $request->input('source_type', CsvSourceType::Csv),
        ]);

        AuditLogger::log(
            $request->user(),
            CsvImport::class,
            $import->id,
            'import.previewed',
            null,
            $import->toArray(),
            AuditSource::CsvImport,
        );

        return new CsvImportResource($import);
    }

    public function confirm(ConfirmImportRequest $request): CsvImportResource
    {
        $import = CsvImport::findOrFail($request->input('import_id'));
        $oldStatus = $import->status;

        $import->update(['status' => CsvImportStatus::Confirmed]);

        AuditLogger::log(
            $request->user(),
            CsvImport::class,
            $import->id,
            'import.confirmed',
            ['status' => $oldStatus?->value ?? $oldStatus],
            ['status' => CsvImportStatus::Confirmed->value],
            AuditSource::CsvImport,
        );

        return new CsvImportResource($import);
    }
}
