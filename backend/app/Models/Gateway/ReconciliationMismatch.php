<?php

namespace App\Models\Gateway;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment the daily settlement check could not match: the gateway reports another amount or status, or the ledger
 * entries do not add up. Written by the scheduler with no signed-in user and read by the super admin, so it has no
 * shop scope; shop_id is kept for filtering. One open row per payment and kind, until a person resolves it.
 *
 * @property Carbon|null $resolved_at
 * @property array<string, mixed>|null $details
 */
class ReconciliationMismatch extends Model
{
    public const KIND_AMOUNT = 'amount';

    public const KIND_STATUS = 'status';

    public const KIND_LEDGER = 'ledger';

    protected $fillable = [
        'shop_id', 'gateway_code', 'reference_type', 'reference_id', 'kind', 'expected_paisa', 'actual_paisa',
        'message', 'details', 'resolved_at', 'resolved_by', 'note',
    ];

    protected function casts(): array
    {
        return ['expected_paisa' => 'integer', 'actual_paisa' => 'integer', 'details' => 'array', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Records a mismatch unless the same one is already open, so a check that runs every day does not pile up copies.
     * Returns the row, or null when nothing new was written.
     */
    public static function flag(GatewayPayment $payment, string $kind, string $message, ?int $expected = null, ?int $actual = null, array $details = []): ?self
    {
        $open = self::where('reference_type', 'gateway_payment')->where('reference_id', $payment->id)->where('kind', $kind)->whereNull('resolved_at')->exists();

        if ($open) {
            return null;
        }

        return self::create([
            'shop_id' => $payment->shop_id, 'gateway_code' => $payment->gateway_code, 'reference_type' => 'gateway_payment', 'reference_id' => $payment->id,
            'kind' => $kind, 'expected_paisa' => $expected, 'actual_paisa' => $actual, 'message' => $message, 'details' => GatewayLog::scrub($details) ?: null,
        ]);
    }
}
