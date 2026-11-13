<?php

namespace App\Models\Gateway;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** One side of a double-entry posting. Every payment or refund writes entries whose debits and credits balance. */
class LedgerEntry extends Model
{
    use BelongsToShop;

    public const UPDATED_AT = null;

    public const ACCOUNT_REVENUE = 'platform:revenue';

    public const ACCOUNT_FEES = 'platform:fees';

    public const ACCOUNT_REFUNDS = 'platform:refunds';

    protected $fillable = ['shop_id', 'account', 'debit_paisa', 'credit_paisa', 'reference_type', 'reference_id', 'memo'];

    protected function casts(): array
    {
        return ['debit_paisa' => 'integer', 'credit_paisa' => 'integer', 'created_at' => 'datetime'];
    }

    /** The receivable account for one gateway, e.g. "gateway:bkash". */
    public static function gatewayAccount(string $code): string
    {
        return 'gateway:'.$code;
    }
}
