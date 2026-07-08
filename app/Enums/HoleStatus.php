<?php

namespace App\Enums;

enum HoleStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
    case Risk = 'risk';
    case Cancelled = 'cancelled';
}
