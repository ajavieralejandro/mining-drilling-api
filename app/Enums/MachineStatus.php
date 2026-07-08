<?php

namespace App\Enums;

enum MachineStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case OutOfService = 'out_of_service';
}
