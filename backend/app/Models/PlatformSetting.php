<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One platform-wide setting. Read and written through App\Support\PlatformSettings, which encrypts the secret ones. */
class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];
}
