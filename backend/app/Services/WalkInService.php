<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\WalkIn;
use Illuminate\Support\Facades\DB;

class WalkInService
{
    /** Makes a customer from a walk-in. What the walk-in held now lives on the customer, so the walk-in is removed. */
    public function convert(WalkIn $walkIn, array $customerData): Customer
    {
        return DB::transaction(function () use ($walkIn, $customerData) {
            // a double click must not make two customers: the second call finds the walk-in gone and gets a 404
            $walkIn = WalkIn::lockForUpdate()->findOrFail($walkIn->id);

            $customer = Customer::create($customerData);
            $walkIn->delete();

            return $customer;
        });
    }
}
