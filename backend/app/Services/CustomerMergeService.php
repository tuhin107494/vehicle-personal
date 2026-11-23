<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\InvestorSale;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class CustomerMergeService
{
    /**
     * Merges duplicate entries of one customer (entered again, often under another phone number) into the primary one:
     * their sales, buy-backs, bookings and investor sales move to the primary customer, with every payment and
     * installment on them. A detail the primary is missing (father's name, NID, address, photo, a second number) is
     * taken from a duplicate. The duplicates are then deleted (soft: the rows stay in the database).
     *
     * @param  int[]  $duplicateIds
     */
    public function merge(Customer $primary, array $duplicateIds): Customer
    {
        return DB::transaction(function () use ($primary, $duplicateIds) {
            $primary = Customer::lockForUpdate()->findOrFail($primary->id);
            $ids = array_values(array_unique(array_map('intval', $duplicateIds)));

            if (in_array($primary->id, $ids, true)) {
                throw new BusinessRuleException('The main customer cannot also be one of the duplicates.');
            }

            $duplicates = Customer::lockForUpdate()->whereIn('id', $ids)->get();

            if ($duplicates->count() !== count($ids)) {
                throw new BusinessRuleException('One of the duplicate customers was not found.');
            }

            Sale::whereIn('customer_id', $ids)->update(['customer_id' => $primary->id]);
            Sale::whereIn('additional_customer_id', $ids)->update(['additional_customer_id' => $primary->id]);
            // the same person cannot be their own additional customer
            Sale::where('customer_id', $primary->id)->where('additional_customer_id', $primary->id)->update(['additional_customer_id' => null]);
            Purchase::whereIn('customer_id', $ids)->update(['customer_id' => $primary->id]);
            Booking::whereIn('customer_id', $ids)->update(['customer_id' => $primary->id]);
            InvestorSale::whereIn('customer_id', $ids)->update(['customer_id' => $primary->id]);

            $fill = [];
            $phones = array_filter([$primary->phone, $primary->alt_phone]);

            foreach ($duplicates as $d) {
                foreach (['father_name', 'nid', 'address', 'photo'] as $field) {
                    if (blank($primary->{$field}) && blank($fill[$field] ?? null) && filled($d->{$field})) {
                        $fill[$field] = $d->{$field};
                    }
                }

                // the duplicate's number becomes the second number when there is none yet
                foreach ([$d->phone, $d->alt_phone] as $phone) {
                    if (filled($phone) && ! in_array($phone, $phones, true) && blank($primary->alt_phone) && blank($fill['alt_phone'] ?? null)) {
                        $fill['alt_phone'] = $phone;
                        $phones[] = $phone;
                    }
                }

                if (filled($d->notes)) {
                    $fill['notes'] = trim(($fill['notes'] ?? $primary->notes ?? '')."\n".$d->notes);
                }
            }

            if ($fill) {
                $primary->forceFill($fill)->save();
            }

            foreach ($duplicates as $d) {
                $d->delete();
            }

            return $primary->refresh();
        });
    }
}
