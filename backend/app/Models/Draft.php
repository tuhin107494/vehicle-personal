<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'type',
        'vehicle_id',
        'label',
        'data',
        'expires_on',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'expires_on' => 'date:Y-m-d'];
    }

    /** How a draft is sent to the app. */
    public function present(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'vehicle_id' => $this->vehicle_id, 'expires_on' => $this->expires_on?->toDateString(), 'label' => $this->label, 'data' => $this->data,
            'saved_by' => $this->creator?->name, 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Drafts as the booking list shows them: each with the vehicle it holds and the customer chosen so far,
     * looked up together so a long list stays a few queries.
     *
     * @param  \Illuminate\Support\Collection<int, Draft>  $drafts
     * @return \Illuminate\Support\Collection<int, array<string,mixed>>
     */
    public static function presentMany($drafts)
    {
        $vehicles = Vehicle::whereIn('id', $drafts->pluck('vehicle_id')->filter())->get(['id', 'chassis_no', 'brand', 'model', 'year', 'vehicle_type'])->keyBy('id');
        $customers = Customer::whereIn('id', $drafts->map(fn ($d) => $d->data['values']['party_id'] ?? null)->filter())->get(['id', 'name', 'phone', 'alt_phone'])->keyBy('id');

        return $drafts->map(fn (Draft $d) => $d->present() + [
            'vehicle' => $vehicles->get($d->vehicle_id)?->only(['id', 'chassis_no', 'brand', 'model', 'year', 'vehicle_type']),
            'customer' => $customers->get((int) ($d->data['values']['party_id'] ?? 0))?->only(['id', 'name', 'phone', 'alt_phone']),
        ])->values();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
