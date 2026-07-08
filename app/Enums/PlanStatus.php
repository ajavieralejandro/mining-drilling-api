<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
}
