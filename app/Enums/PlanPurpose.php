<?php

namespace App\Enums;

enum PlanPurpose: string
{
    case Infill = 'infill';
    case Ventilation = 'ventilation';
    case Exploration = 'exploration';
    case Production = 'production';
}
