<?php

namespace App\Enums;

enum AuditSource: string
{
    case Mobile = 'mobile';
    case Web = 'web';
    case CsvImport = 'csv_import';
    case System = 'system';
}
