<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One edit of a payment or advance: the fields that changed, each as {field, from, to}. */
class TransactionEdit extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'type', 'subject_id', 'user_id', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
