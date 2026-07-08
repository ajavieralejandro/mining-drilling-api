<?php

namespace App\Enums;

enum ObservationType: string
{
    case Operational = 'operational';
    case Geology = 'geology';
    case Safety = 'safety';
    case General = 'general';
    case Risk = 'risk';
}
