<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** A shop's own wording for one due-alert stage. A stage with no row uses the built-in text (App\Services\Sms\SmsTemplates). */
class SmsTemplate extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'key',
        'body_bn',
        'body_en',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
