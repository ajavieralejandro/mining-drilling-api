<?php

namespace App\Enums;

enum PlanFileType: string
{
    case Powerpoint = 'powerpoint';
    case Excel = 'excel';
    case Pdf = 'pdf';
    case Image = 'image';
    case Result = 'result';
    case Other = 'other';
}
