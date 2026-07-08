<?php

namespace App\Enums;

enum CsvImportStatus: string
{
    case Previewed = 'previewed';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}
