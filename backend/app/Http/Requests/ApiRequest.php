<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function shopExists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('shop_id', $this->user()->shop_id);
    }

    /** A vehicle type this user may use: a platform one or one their shop added. */
    protected function category(): Exists
    {
        return Rule::exists('vehicle_categories', 'key')->where(fn ($q) => $q->whereNull('shop_id')->orWhere('shop_id', $this->user()->shop_id));
    }

    protected function shopUnique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('shop_id', $this->user()->shop_id);
    }
}
