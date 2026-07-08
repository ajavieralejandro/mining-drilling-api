<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Geologist = 'geologist';
    case Driller = 'driller';
    case Helper = 'helper';
  // geotechnical: MVP role — may be split or renamed in a future iteration
    case Geotechnical = 'geotechnical';

    public function canViewAll(): bool
    {
        return in_array($this, [
            self::Admin,
            self::Supervisor,
            self::Geologist,
            self::Geotechnical,
        ], true);
    }
}
