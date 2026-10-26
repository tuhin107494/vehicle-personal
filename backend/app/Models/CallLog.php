<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $called_at
 */
class CallLog extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'kind',
        'ref_id',
        'called_by',
        'called_at',
        'meet_date',
        'note',
    ];

    protected function casts(): array
    {
        return ['called_at' => 'datetime', 'meet_date' => 'date:Y-m-d'];
    }
}
