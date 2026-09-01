<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
    ];

    public function connectors(): HasMany
    {
        return $this->hasMany(Connector::class);
    }

    public function enrollmentTokens(): HasMany
    {
        return $this->hasMany(ConnectorEnrollmentToken::class);
    }
}
