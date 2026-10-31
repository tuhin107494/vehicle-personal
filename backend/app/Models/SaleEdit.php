<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One edit of a sale: the fields that changed, each as {field, from, to}. */
class SaleEdit extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'sale_id', 'user_id', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
