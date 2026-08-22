<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Facades\DB;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;

/**
 * Jalan lead menjadi pelanggan — temuan #58.
 *
 * A quotation REQUIRES an existing customer (QuotationStoreRequest), so before
 * this existed every prospect was typed twice: once as the lead, once again as
 * the customer — and the typo on the second pass is what made lead and
 * customer impossible to match up afterwards.
 */
class LeadService
{
    public function convertToCustomer(Lead $lead): Customer
    {
        return DB::transaction(function () use ($lead): Customer {
            // Idempoten — diputuskan pada baris yang DIBACA ULANG di dalam
            // transaksi, bukan pada instance milik pemanggil: dua instance
            // lead yang sama (bentuk klik-ganda) dulu menghasilkan dua baris
            // CUST-, yang kedua menimpa customer_id dan meyatimkan yang
            // pertama — persis duplikat merge-tangan yang komentar ini
            // janjikan tidak akan terjadi. lockForUpdate no-op di SQLite;
            // pembacaan ulangnya yang menjadi penjaga.
            /** @var Lead $lead */
            $lead = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            if ($lead->customer_id !== null) {
                return Customer::query()->findOrFail($lead->customer_id);
            }

            $customer = Customer::query()->create([
                // Nama pelanggan adalah badan usahanya; lead perorangan (tanpa
                // company_name) memakai nama kontaknya sendiri.
                'name' => $lead->company_name ?: $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'pic_name' => $lead->name,
                'pic_phone' => $lead->phone,
                'status' => 'active',
            ]);

            $lead->forceFill(['customer_id' => $customer->id])->save();

            return $customer;
        });
    }
}
