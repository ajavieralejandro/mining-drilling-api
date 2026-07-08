<?php

namespace App\Enums;

enum RoleInHole: string
{
    case Geologist = 'geologist';
    case Driller = 'driller';
    case Helper = 'helper';
    case Supervisor = 'supervisor';
    case Geotechnical = 'geotechnical';
}
