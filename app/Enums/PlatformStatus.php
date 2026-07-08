<?php

namespace App\Enums;

enum PlatformStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';
    case Paused = 'paused';
}
