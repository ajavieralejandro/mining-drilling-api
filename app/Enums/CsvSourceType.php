<?php

namespace App\Enums;

enum CsvSourceType: string
{
    case Csv = 'csv';
    case Excel = 'excel';
}
