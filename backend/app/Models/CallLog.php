<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /** @return BelongsTo<User, $this> */
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    protected function casts(): array
    {
        return ['called_at' => 'datetime', 'meet_date' => 'date:Y-m-d'];
    }
}
