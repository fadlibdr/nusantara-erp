<?php

namespace Modules\Crm\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Core\Models\NumberSequence;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Quotation;

class CrmDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCustomers();
        $this->seedLeads();
        $this->seedQuotations();
        $this->seedContracts();
        $this->syncNumberSequences();
    }

    private function seedCustomers(): void
    {
        $customers = [
            [
                'code' => 'CUST-0001',
                'name' => 'PT Graha Sentosa Propertindo',
                'legal_name' => 'PT Graha Sentosa Propertindo',
                'npwp' => '01.234.567.8-011.000',
                'is_pkp' => true,
                'billing_address' => 'Graha Sentosa Tower Lt. 12, Jl. TB Simatupang Kav. 18',
                'city' => 'Jakarta Selatan',
                'province' => 'DKI Jakarta',
                'phone' => '021-7591-8800',
                'email' => 'procurement@grahasentosa.co.id',
                'pic_name' => 'Hendra Gunawan',
                'pic_phone' => '0811-9002-341',
                'payment_term_days' => 30,
                'status' => 'active',
            ],
            [
                'code' => 'CUST-0002',
                'name' => 'PT Bank Artha Nusantara',
                'legal_name' => 'PT Bank Artha Nusantara Tbk',
                'npwp' => '01.345.678.9-091.000',
                'is_pkp' => true,
                'billing_address' => 'Menara Artha Lt. 5, Jl. Jend. Sudirman Kav. 34',
                'city' => 'Jakarta Pusat',
                'province' => 'DKI Jakarta',
                'phone' => '021-5290-1100',
                'email' => 'vendor.mgmt@bankartha.co.id',
                'pic_name' => 'Maya Puspita',
                'pic_phone' => '0812-8455-702',
                'payment_term_days' => 45,
                'status' => 'active',
            ],
            [
                'code' => 'CUST-0003',
                'name' => 'RS Medika Husada',
                'legal_name' => 'PT Medika Husada Utama',
                'npwp' => '02.456.789.0-424.000',
                'is_pkp' => false,
                'billing_address' => 'Jl. Raya Serpong No. 88',
                'city' => 'Tangerang Selatan',
                'province' => 'Banten',
                'phone' => '021-5312-4400',
                'email' => 'umum@rsmedikahusada.co.id',
                'pic_name' => 'dr. Fajar Nugroho',
                'pic_phone' => '0813-1120-556',
                'payment_term_days' => 30,
                'status' => 'active',
            ],
        ];

        foreach ($customers as $customer) {
            Customer::withTrashed()->updateOrCreate(
                ['code' => $customer['code']],
                $customer,
            );
        }
    }

    private function seedLeads(): void
    {
        // Owner is optional: assign the first user if the IAM module is seeded.
        $ownerId = User::query()->orderBy('id')->value('id');

        $leads = [
            [
                'code' => 'LEAD-0001',
                'name' => 'Rudi Hartanto',
                'company_name' => 'PT Sinar Abadi Land',
                'source' => 'referral',
                'phone' => '0817-5502-914',
                'email' => 'rudi.hartanto@sinarabadiland.co.id',
                'need_summary' => 'Pembangunan apartemen 12 lantai (2 tower) di BSD, target mulai konstruksi Q1 2027.',
                'estimated_value' => 65000000000,
                'status' => 'qualified',
                'user_id' => $ownerId,
                'notes' => 'Sudah site visit; menunggu DED final dari konsultan perencana.',
            ],
            [
                'code' => 'LEAD-0002',
                'name' => 'Ir. Bambang Setiawan',
                'company_name' => 'Universitas Cendekia Nusantara',
                'source' => 'tender',
                'phone' => '0815-8677-230',
                'email' => 'bambang.setiawan@ucn.ac.id',
                'need_summary' => 'Smart campus: backbone fiber, WiFi, CCTV, dan akses kontrol untuk 4 gedung kampus.',
                'estimated_value' => 4500000000,
                'status' => 'contacted',
                'user_id' => $ownerId,
                'notes' => 'Aanwijzing dijadwalkan Agustus 2026; siapkan pra-proposal.',
            ],
        ];

        foreach ($leads as $lead) {
            Lead::withTrashed()->updateOrCreate(
                ['code' => $lead['code']],
                $lead,
            );
        }
    }

    private function seedQuotations(): void
    {
        $ppnRate = (float) config('erp.tax.ppn_rate', 11.0);

        $quotations = [
            [
                'code' => 'QTN/2026/I/0001',
                'customer_code' => 'CUST-0001',
                'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
                'scope_type' => 'construction',
                'valid_until' => '2026-02-15',
                'won_at' => '2026-01-22 10:00:00',
                'notes' => 'Penawaran sesuai gambar tender dan BQ dari konsultan QS.',
                'items' => [
                    ['description' => 'Pekerjaan persiapan & preliminaries', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 3000000000],
                    ['description' => 'Pekerjaan struktur (pondasi, kolom, balok, plat 8 lantai)', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 21000000000],
                    ['description' => 'Pekerjaan arsitektur & finishing', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 15500000000],
                    ['description' => 'Pekerjaan MEP (mekanikal, elektrikal, plumbing)', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 9000000000],
                ],
            ],
            [
                'code' => 'QTN/2026/II/0002',
                'customer_code' => 'CUST-0002',
                'title' => 'Instalasi ELV & ICT 12 Kantor Cabang Bank Artha Nusantara',
                'scope_type' => 'system_integration',
                'valid_until' => '2026-03-15',
                'won_at' => '2026-02-18 14:30:00',
                'notes' => 'Termasuk supply, instalasi, testing & commissioning per cabang.',
                'items' => [
                    ['description' => 'Instalasi CCTV & access control kantor cabang', 'qty' => 12, 'unit' => 'site', 'unit_price' => 350000000],
                    ['description' => 'Structured cabling & jaringan LAN/WAN kantor cabang', 'qty' => 12, 'unit' => 'site', 'unit_price' => 250000000],
                    ['description' => 'Upgrade ruang server kantor pusat (rack, UPS, environment monitoring)', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1800000000],
                    ['description' => 'Testing, commissioning & pelatihan', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 800000000],
                ],
            ],
            [
                'code' => 'QTN/2026/III/0003',
                'customer_code' => 'CUST-0003',
                'title' => 'Kontrak Pemeliharaan CCTV & Akses Kontrol RS Medika Husada',
                'scope_type' => 'maintenance',
                'valid_until' => '2026-04-10',
                'won_at' => '2026-03-13 09:00:00',
                'notes' => 'Kunjungan preventif bulanan, respons korektif 1x24 jam, 12 bulan.',
                'items' => [
                    ['description' => 'Pemeliharaan sistem CCTV (kunjungan preventif bulanan)', 'qty' => 12, 'unit' => 'bulan', 'unit_price' => 25000000],
                    ['description' => 'Pemeliharaan akses kontrol & alarm', 'qty' => 12, 'unit' => 'bulan', 'unit_price' => 15000000],
                ],
            ],
        ];

        foreach ($quotations as $data) {
            $customer = Customer::query()->where('code', $data['customer_code'])->first();

            if (! $customer) {
                continue;
            }

            // Real math: line amounts -> subtotal -> dpp -> ppn -> total.
            $lines = [];
            $subtotal = 0.0;

            foreach ($data['items'] as $i => $item) {
                $amount = round($item['qty'] * $item['unit_price'], 2);
                $subtotal = round($subtotal + $amount, 2);
                $lines[] = [
                    'line_no' => $i + 1,
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $amount,
                ];
            }

            $dpp = $subtotal; // no discount on seeded quotations
            $ppnAmount = round($dpp * $ppnRate / 100, 2);

            $quotation = Quotation::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'customer_id' => $customer->id,
                    'lead_id' => null,
                    'title' => $data['title'],
                    'scope_type' => $data['scope_type'],
                    'valid_until' => $data['valid_until'],
                    'subtotal' => $subtotal,
                    'discount_amount' => 0,
                    'dpp' => $dpp,
                    'ppn_rate' => $ppnRate,
                    'ppn_amount' => $ppnAmount,
                    'total' => round($dpp + $ppnAmount, 2),
                    'status' => 'approved',
                    'revision' => 0,
                    'won_at' => $data['won_at'],
                    'lost_at' => null,
                    'lost_reason' => null,
                    'notes' => $data['notes'],
                ],
            );

            $quotation->items()->delete();

            foreach ($lines as $line) {
                $quotation->items()->create($line);
            }
        }
    }

    private function seedContracts(): void
    {
        $ppnRate = (float) config('erp.tax.ppn_rate', 11.0);

        $contracts = [
            [
                'code' => 'CTR/2026/I/0001',
                'customer_code' => 'CUST-0001',
                'quotation_code' => 'QTN/2026/I/0001',
                'contract_number_customer' => 'SPK/GSP/2026/017',
                'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
                'scope_type' => 'construction',
                'value' => 48500000000, // Rp 48.5 M (DPP)
                'sign_date' => '2026-01-26',
                'start_date' => '2026-02-02',
                'end_date' => '2027-07-31',
                'retention_pct' => 5,
                'warranty_months' => 12,
                'status' => 'approved',
                'termins' => [
                    ['name' => 'DP 20%', 'percent' => 20, 'billing_condition' => 'Uang muka setelah penandatanganan kontrak dan penyerahan jaminan uang muka.', 'billed_at' => '2026-02-05'],
                    ['name' => 'Progress 50%', 'percent' => 30, 'billing_condition' => 'Progres fisik kumulatif mencapai 50% (BAP progres disetujui MK).', 'billed_at' => null],
                    ['name' => 'Progress 80%', 'percent' => 30, 'billing_condition' => 'Progres fisik kumulatif mencapai 80% (BAP progres disetujui MK).', 'billed_at' => null],
                    ['name' => 'BAST 15%', 'percent' => 15, 'billing_condition' => 'Serah terima pertama pekerjaan (BAST I) 100%.', 'billed_at' => null],
                    ['name' => 'Retensi 5%', 'percent' => 5, 'billing_condition' => 'Selesai masa pemeliharaan 12 bulan (BAST II).', 'billed_at' => null],
                ],
            ],
            [
                'code' => 'CTR/2026/II/0002',
                'customer_code' => 'CUST-0002',
                'quotation_code' => 'QTN/2026/II/0002',
                'contract_number_customer' => 'PO-BAN/2026/0233',
                'title' => 'Instalasi ELV & ICT 12 Kantor Cabang Bank Artha Nusantara',
                'scope_type' => 'system_integration',
                'value' => 9800000000, // Rp 9.8 M (DPP)
                'sign_date' => '2026-02-20',
                'start_date' => '2026-03-02',
                'end_date' => '2026-12-18',
                'retention_pct' => 5,
                'warranty_months' => 12,
                'status' => 'approved',
                'termins' => [
                    ['name' => 'DP 30%', 'percent' => 30, 'billing_condition' => 'Uang muka setelah PO diterbitkan dan kickoff meeting.', 'billed_at' => '2026-03-06'],
                    ['name' => 'Progress 40%', 'percent' => 40, 'billing_condition' => 'Instalasi selesai di 8 dari 12 kantor cabang (BAP per cabang).', 'billed_at' => null],
                    ['name' => 'BAST 25%', 'percent' => 25, 'billing_condition' => 'Testing & commissioning seluruh cabang selesai, BAST ditandatangani.', 'billed_at' => null],
                    ['name' => 'Retensi 5%', 'percent' => 5, 'billing_condition' => 'Selesai masa garansi 12 bulan.', 'billed_at' => null],
                ],
            ],
            [
                'code' => 'CTR/2026/III/0003',
                'customer_code' => 'CUST-0003',
                'quotation_code' => 'QTN/2026/III/0003',
                'contract_number_customer' => 'PKS/RSMH/2026/008',
                'title' => 'Pemeliharaan CCTV & Akses Kontrol RS Medika Husada',
                'scope_type' => 'maintenance',
                'value' => 480000000, // Rp 480 jt / tahun (DPP)
                'sign_date' => '2026-03-16',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'retention_pct' => 0, // no retention on maintenance contracts
                'warranty_months' => 0,
                'status' => 'approved',
                // A calendar schedule: these come due because a quarter starts,
                // not because a milestone is certified, so they carry due_date.
                // Triwulan II fell due on 01-07-2026 and was never invoiced —
                // Rp 120 juta that no screen could report as late until the
                // billing queue could read this column.
                'termins' => [
                    ['name' => 'Triwulan I 25%', 'percent' => 25, 'billing_condition' => 'Ditagihkan awal triwulan I setelah laporan PM bulan berjalan.', 'due_date' => '2026-04-01', 'billed_at' => '2026-04-06'],
                    ['name' => 'Triwulan II 25%', 'percent' => 25, 'billing_condition' => 'Ditagihkan awal triwulan II setelah laporan PM bulan berjalan.', 'due_date' => '2026-07-01', 'billed_at' => null],
                    ['name' => 'Triwulan III 25%', 'percent' => 25, 'billing_condition' => 'Ditagihkan awal triwulan III setelah laporan PM bulan berjalan.', 'due_date' => '2026-10-01', 'billed_at' => null],
                    ['name' => 'Triwulan IV 25%', 'percent' => 25, 'billing_condition' => 'Ditagihkan awal triwulan IV setelah laporan PM bulan berjalan.', 'due_date' => '2027-01-01', 'billed_at' => null],
                ],
            ],
        ];

        foreach ($contracts as $data) {
            $customer = Customer::query()->where('code', $data['customer_code'])->first();

            if (! $customer) {
                continue;
            }

            $quotationId = Quotation::query()->where('code', $data['quotation_code'])->value('id');

            $value = round((float) $data['value'], 2);
            $ppnAmount = round($value * $ppnRate / 100, 2);

            $contract = Contract::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'customer_id' => $customer->id,
                    'quotation_id' => $quotationId,
                    'contract_number_customer' => $data['contract_number_customer'],
                    'title' => $data['title'],
                    'scope_type' => $data['scope_type'],
                    'value' => $value,
                    'ppn_rate' => $ppnRate,
                    'ppn_amount' => $ppnAmount,
                    'total_with_ppn' => round($value + $ppnAmount, 2),
                    'sign_date' => $data['sign_date'],
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'retention_pct' => $data['retention_pct'],
                    'warranty_months' => $data['warranty_months'],
                    'status' => $data['status'],
                ],
            );

            $contract->termins()->delete();

            $count = count($data['termins']);
            $allocated = 0.0;

            foreach ($data['termins'] as $index => $termin) {
                // Last termin absorbs the rounding residue so the schedule sums exactly to the value.
                $amount = $index === $count - 1
                    ? round($value - $allocated, 2)
                    : round($value * $termin['percent'] / 100, 2);
                $allocated = round($allocated + $amount, 2);

                $contract->termins()->create([
                    'termin_no' => $index + 1,
                    'name' => $termin['name'],
                    'percent' => $termin['percent'],
                    'amount' => $amount,
                    'billing_condition' => $termin['billing_condition'],
                    // Progress termins have no due date on purpose: their trigger
                    // is a milestone, and inventing a calendar date for them
                    // would put rows nobody owes into the billing queue.
                    'due_date' => $termin['due_date'] ?? null,
                    'billed_at' => $termin['billed_at'],
                ]);
            }
        }
    }

    /**
     * Seeded codes use explicit sequence numbers 1-3; move the 2026 counters past
     * them so runtime-generated QTN/CTR numbers never collide with the canon.
     */
    private function syncNumberSequences(): void
    {
        foreach (['QTN', 'CTR'] as $type) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => 2026],
                ['last_number' => 0],
            );

            if ((int) $sequence->last_number < 3) {
                $sequence->update(['last_number' => 3]);
            }
        }
    }
}
