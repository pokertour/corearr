<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSetting extends Model
{
    protected $fillable = [
        'service_name',
        'base_url',
        'api_key',
        'username',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'api_key',
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
