<?php

namespace App\Enums;

enum RiskStatus: string
{
    case Open = 'open';
    case Mitigated = 'mitigated';
    case Closed = 'closed';
}
