<?php

namespace Modules\Crm\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\MethodLibraryEntry;
use Modules\Core\Models\NumberSequence;
use Modules\Crm\Enums\ChangeOrderType;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\RkkDocument;
use Modules\Crm\Models\TenderPackage;
use Modules\Crm\Models\TkdnWorksheet;
use Modules\Crm\Services\RkkService;
use Modules\Crm\Services\TenderPackageService;
use Modules\Crm\Services\TkdnService;

class CrmDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCustomers();
        $this->seedLeads();
        $this->seedQuotations();
        $this->seedContracts();
        $this->seedChangeOrders();
        $this->seedTenderPackage();
        $this->seedTkdnWorksheet();
        $this->seedRkk();
        $this->linkQuotationMethod();
        $this->syncNumberSequences();
    }

    /**
     * P7 — TND/2026/VIII/0001, berkas lelang LEAD-0002 (smart campus UCN).
     *
     * LEAD-0002 dipilih karena sudah bersumber 'tender' dan catatannya sudah
     * berbunyi "Aanwijzing dijadwalkan Agustus 2026" — berkas ini menjadikan
     * kalimat itu data. Registernya memuat terbitan asli PLUS addendum ke-1,
     * karena aturan yang dijaga service adalah urutan addendum, dan register
     * satu-baris tidak pernah melatihnya.
     */
    private function seedTenderPackage(): void
    {
        $lead = Lead::query()->where('code', 'LEAD-0002')->first();

        if ($lead === null) {
            return;
        }

        $package = TenderPackage::withTrashed()->updateOrCreate(
            ['code' => 'TND/2026/VIII/0001'],
            [
                'lead_id' => $lead->id,
                'title' => 'Pengadaan Smart Campus Universitas Cendekia Nusantara (4 Gedung)',
                'owner_name' => 'Universitas Cendekia Nusantara',
                'tender_number' => '014/PAN-PBJ/UCN/VIII/2026',
                'registered_at' => '2026-08-03',
                'submission_deadline' => '2026-09-04',
                'aanwijzing_date' => '2026-08-12',
                'aanwijzing_notes' => 'Penjelasan pekerjaan daring. Pertanyaan peserta tentang '
                    .'spesifikasi switch dijawab lewat Addendum I; jadwal pemasukan tetap.',
            ],
        );

        $package->documents()->delete();

        foreach ([
            ['sort_order' => 1, 'title' => 'Dokumen Pemilihan', 'chapter' => 'Bab I–IX',
                'issued_date' => '2026-08-03', 'addendum_no' => null,
                'notes' => 'Diunduh dari LPSE, lengkap dengan lampiran spesifikasi teknis.'],
            ['sort_order' => 2, 'title' => 'Berita Acara Pemberian Penjelasan (Aanwijzing)',
                'chapter' => null, 'issued_date' => '2026-08-12', 'addendum_no' => null, 'notes' => null],
            ['sort_order' => 3, 'title' => 'Addendum I Dokumen Pemilihan — spesifikasi switch & jadwal',
                'chapter' => 'Bab IV & XII', 'issued_date' => '2026-08-14', 'addendum_no' => 1,
                'notes' => 'Mengubah spesifikasi switch 24 port menjadi managed PoE.'],
        ] as $document) {
            $package->documents()->create($document);
        }

        // Checklist SENGAJA belum lengkap: dua butir belum tercentang, supaya
        // demo menunjukkan lembar yang MASIH KURANG — sebuah checklist demo
        // yang semuanya tercentang tidak pernah memperlihatkan gunanya.
        app(TenderPackageService::class)->setChecklist($package, [
            ['key' => 'surat_penawaran', 'checked' => true, 'notes' => 'Meterai 10.000 terpasang'],
            ['key' => 'jaminan_penawaran', 'checked' => true, 'notes' => 'Bank garansi 3% dari HPS'],
            ['key' => 'pakta_integritas', 'checked' => true],
            ['key' => 'formulir_kualifikasi', 'checked' => true],
            ['key' => 'akta_perusahaan', 'checked' => true],
            ['key' => 'izin_usaha', 'checked' => true],
            ['key' => 'sbu', 'checked' => true],
            ['key' => 'npwp_spt', 'checked' => true],
            ['key' => 'neraca', 'checked' => true],
            ['key' => 'pengalaman', 'checked' => true],
            ['key' => 'metode_pelaksanaan', 'checked' => true, 'notes' => 'MTD/2026/0003'],
            ['key' => 'jadwal_pelaksanaan', 'checked' => true],
            ['key' => 'daftar_personil', 'checked' => true, 'notes' => 'Cetak F/SBD'],
            ['key' => 'daftar_peralatan', 'checked' => true, 'notes' => 'Cetak F/DA'],
            ['key' => 'rkk', 'checked' => true, 'notes' => 'RKK/2026/VIII/0001'],
            ['key' => 'daftar_kuantitas_harga', 'checked' => true],
            ['key' => 'formulir_tkdn', 'checked' => true, 'notes' => 'TKD/2026/VIII/0001'],
            // daftar_subkontraktor, analisa_harga_satuan, daftar_upah_bahan_alat
            // sengaja dibiarkan belum tercentang.
        ]);
    }

    /**
     * P7 — TKD/2026/VIII/0001 atas QTN/2026/II/0002, dan SATU BARIS PENAWARAN
     * SENGAJA DIBIARKAN BELUM DINILAI.
     *
     * Itulah seluruh alasan lembar demo ini ada. Aturan yang paling mahal pada
     * paket ini adalah "baris tanpa uraian biaya BELUM DINILAI — bukan 0%,
     * bukan 100%", dan lembar demo yang setiap barisnya terisi tidak pernah
     * memperlihatkan cakupan bekerja. Di sini cakupannya di bawah 100%, dan
     * layar serta lembar cetaknya harus mengatakannya.
     */
    private function seedTkdnWorksheet(): void
    {
        $quotation = Quotation::query()->where('code', 'QTN/2026/II/0002')->with('items')->first();

        if ($quotation === null || $quotation->items->count() < 2) {
            return;
        }

        $package = TenderPackage::query()->where('code', 'TND/2026/VIII/0001')->first();

        $worksheet = TkdnWorksheet::withTrashed()->updateOrCreate(
            ['code' => 'TKD/2026/VIII/0001'],
            [
                'quotation_id' => $quotation->id,
                'tender_package_id' => $package?->id,
                'notes' => 'Penghitungan TKDN Jasa mengikuti Permenperin 35/2025 Pasal 14 dan '
                    .'Lampiran IV huruf B. Baris penawaran yang belum diuraikan biayanya '
                    .'BELUM DINILAI dan tidak dihitung sebagai 0%.',
            ],
        );

        $first = $quotation->items->first();

        // Baris komponen lewat service, bukan create() langsung: aturan kolom
        // penentu per kelompok biaya hidup di sana, dan seeder yang melewatinya
        // bisa menyimpan baris yang layar tidak akan pernah bisa menyimpan.
        app(TkdnService::class)->replaceItems($worksheet, [
            ['quotation_item_id' => $first->id, 'cost_group' => 'tenaga_kerja',
                'description' => 'Teknisi ELV & supervisor (WNI)', 'amount' => 420_000_000,
                'nationality' => 'wni'],
            ['quotation_item_id' => $first->id, 'cost_group' => 'tenaga_kerja',
                'description' => 'Commissioning engineer principal (WNA)', 'amount' => 80_000_000,
                'nationality' => 'wna'],
            ['quotation_item_id' => $first->id, 'cost_group' => 'alat_kerja',
                'description' => 'Fusion splicer & OTDR (buatan luar negeri, milik sendiri)',
                'amount' => 60_000_000, 'made_in' => 'ln', 'owned_by' => 'dn'],
            ['quotation_item_id' => $first->id, 'cost_group' => 'alat_kerja',
                'description' => 'Perancah & genset (buatan dalam negeri)',
                'amount' => 40_000_000, 'made_in' => 'dn', 'owned_by' => 'dn'],
            ['quotation_item_id' => $first->id, 'cost_group' => 'jasa_umum',
                'description' => 'Asuransi CAR & K3L (penyedia dalam negeri)',
                'amount' => 90_000_000, 'provider_origin' => 'dn'],
            ['quotation_item_id' => $first->id, 'cost_group' => 'jasa_umum',
                'description' => 'Lisensi VMS (penyedia luar negeri)',
                'amount' => 110_000_000, 'provider_origin' => 'ln'],
        ]);
    }

    /**
     * P7 — RKK/2026/VIII/0001 atas paket lelang itu.
     *
     * Tautan IBPRP dan baris biaya SMKK dicari LEWAT KODE dan dilewati dengan
     * anggun bila modul pemiliknya belum diseed (CONVENTIONS §8). Ini bukan
     * kehati-hatian berlebihan: register risiko milik ProjectsDatabaseSeeder
     * dan baris RAB milik EstimationDatabaseSeeder, dan sebuah seeder yang
     * menautkan id yang belum ada akan menyeed tautan menggantung — persis yang
     * ditolak RkkService pada jalur mana pun yang lain.
     */
    private function seedRkk(): void
    {
        $package = TenderPackage::query()->where('code', 'TND/2026/VIII/0001')->first();

        if ($package === null) {
            return;
        }

        $rkk = RkkDocument::withTrashed()->updateOrCreate(
            ['code' => 'RKK/2026/VIII/0001'],
            [
                'tender_package_id' => $package->id,
                'title' => 'RKK Penawaran — Smart Campus Universitas Cendekia Nusantara',
                'policy' => 'PT Nusantara Karya Integrasi berkomitmen melaksanakan pekerjaan tanpa '
                    .'kecelakaan kerja yang menyebabkan hari kerja hilang, mematuhi peraturan '
                    .'keselamatan konstruksi yang berlaku, dan melibatkan seluruh pekerja dalam '
                    .'identifikasi bahaya di tempat kerjanya masing-masing.',
                'program' => 'Induksi K3 bagi setiap pekerja baru, toolbox meeting harian sebelum '
                    .'mulai kerja, inspeksi keselamatan mingguan bersama pengawas, dan pelatihan '
                    .'bekerja di ketinggian bagi pekerja penarikan kabel backbone.',
            ],
        );

        $service = app(RkkService::class);

        /*
         * IBPRP: register PRJ-2026-001 dipakai sebagai DASAR penilaian risiko
         * penawaran ini, dan lembarnya menyebutkannya.
         *
         * Sebuah RKK penawaran belum punya proyek — pekerjaannya belum
         * dimenangkan — jadi IBPRP-nya memang disusun dari register pekerjaan
         * sejenis yang sudah berjalan. Yang tidak boleh adalah mencetak
         * bahaya-bahaya itu seolah dinilai untuk pekerjaan INI: project_id
         * disimpan, dan F/RKK mencetaknya pada baris SUMBER REGISTER IBPRP.
         *
         * Dicari lewat register-nya, bukan lewat kode proyek yang dipatok:
         * proyek demo mana yang punya baris IBPRP adalah keputusan
         * ProjectsDatabaseSeeder, dan seeder ini tidak boleh pecah ketika
         * keputusan itu berubah.
         *
         * KEMBARAN: ProjectsDatabaseSeeder::completeRkkIbprpLinks menjalankan
         * pemilihan yang HURUF DEMI HURUF SAMA (pola AST-0007, CONVENTIONS
         * §8), karena Crm diseed SEBELUM Projects — pada seed segar register
         * di bawah ini masih kosong, blok ini tidak menaut apa-apa, dan sisi
         * Projects-lah yang melengkapinya begitu barisan registernya ada.
         * Blok ini tetap tinggal untuk jalur seed ulang. SIAPA PUN YANG
         * MENGUBAH PEMILIHANNYA WAJIB MENGUBAH KEDUA SEEDER;
         * RkkSeederLinkageTest yang berbunyi bila salah satu sisi bergeser.
         */
        if (Schema::hasTable('prj_risk_register') && Schema::hasTable('prj_projects')) {
            $source = DB::table('prj_risk_register')
                ->whereNull('deleted_at')
                ->orderBy('project_id')
                ->value('project_id');

            $entryIds = $source === null ? [] : DB::table('prj_risk_register')
                ->where('project_id', $source)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->limit(5)
                ->pluck('id')
                ->all();

            if ($entryIds !== []) {
                $rkk->forceFill(['project_id' => $source])->save();
                $service->syncIbprpLinks($rkk->refresh(), $entryIds);
            }
        }

        /*
         * Biaya SMKK: HANYA baris RAB yang benar-benar berbunyi K3/SMKK.
         *
         * RAB demo hari ini tidak punya bagian "Pekerjaan Penerapan SMKK", jadi
         * tidak ada yang ditaut dan F/RKK mencetak kalimat kosongnya. Itu
         * jawaban yang benar. Menautkan baris "Pekerjaan Persiapan" agar demo
         * terlihat penuh akan menyatakan bahwa baris itu adalah biaya
         * keselamatan, yang tidak kita ketahui — dan menambahkan bagian SMKK ke
         * RAB demo adalah pekerjaan seeder Estimation, bukan seeder ini.
         */
        if (Schema::hasTable('est_boq_items')) {
            $boqRow = DB::table('est_boq_items')
                ->where(function ($query): void {
                    $query->where('description', 'like', '%K3%')
                        ->orWhere('description', 'like', '%SMKK%')
                        ->orWhere('description', 'like', '%keselamatan%');
                })
                ->orderBy('id')
                ->first();

            if ($boqRow !== null) {
                $rkk->forceFill(['boq_id' => $boqRow->boq_id])->save();
                $service->syncSmkkCosts($rkk, [[
                    'boq_item_id' => $boqRow->id,
                    'category' => 'Penyiapan RKK & perlengkapan keselamatan',
                ]]);
            }
        }
    }

    /**
     * P7 — QTN/2026/I/0001 menunjuk metode pelaksanaannya.
     *
     * Dicari lewat kode dan dilewati bila CoreDatabaseSeeder belum jalan.
     * Ditunjuk ke MTD/2026/0002 (versi BERLAKU), bukan ke 0001 yang sudah
     * digantikan — persis rujukan yang akan ditolak QuotationService bila
     * seseorang mengetiknya dari layar.
     */
    private function linkQuotationMethod(): void
    {
        $method = MethodLibraryEntry::query()
            ->where('code', 'MTD/2026/0002')
            ->whereNull('superseded_by_id')
            ->first();

        if ($method === null) {
            return;
        }

        Quotation::query()
            ->where('code', 'QTN/2026/I/0001')
            ->update(['method_library_id' => $method->id]);
    }

    /**
     * ADDENDUM I & II on CTR/2026/I/0001 — a pekerjaan tambah and the pekerjaan
     * kurang that pays for it, of EQUAL value, both approved.
     *
     * They exist because P3's opname ceiling is "volume kontrak + CCO disetujui"
     * and the demo had no approved change order at all: every ceiling in the
     * demo was the bare BOQ, the register screen (Variasi Kontrak) was empty,
     * and F/BATK printed nothing. The volume half lives in
     * prj_contract_variations, seeded by ProjectsDatabaseSeeder — this is only
     * the money-and-signature half, which is Crm's to seed.
     *
     * WHY A PAIR, AND WHY THEY CANCEL. CONVENTIONS §8 pins this contract at
     * Rp 48,5 M, and BOQ/2026/0001's grand total is that same number — several
     * modules' demo stories rest on the two being equal. An approved addendum
     * that moved the value would break that canon (and the DEMO would then
     * disagree with the document that defines it), while a single addendum
     * worth Rp 0 is a document the create screen refuses outright
     * (ContractChangeOrderController: value_change not_in:0). A tambah of
     * Rp 84.592.000 offset by a kurang of the same amount is what really
     * happens on site, is legal on both screens, and leaves the contract worth
     * exactly what it was signed for.
     *
     * WRITTEN APPROVED, NOT APPROVED THROUGH THE SERVICE — the seedBaseline
     * rule: maker-checker needs two people and a seeder is nobody. Running
     * ContractChangeOrderService::approve twice would end at the same
     * Rp 48,5 M, and the only thing it would additionally write is
     * original_value, which is set here for exactly that reason. The runtime
     * approve path (and the contract value it moves) is covered by
     * tests/Feature/Crm/ContractChangeOrderTest.
     */
    private function seedChangeOrders(): void
    {
        $contract = Contract::query()->where('code', 'CTR/2026/I/0001')->first();

        if ($contract === null || $contract->status !== DocumentStatus::Approved) {
            return;
        }

        if (ContractChangeOrder::query()->where('contract_id', $contract->id)->exists()) {
            return; // idempotent: never mint a second pair on a re-run
        }

        $approver = User::query()->where('email', 'direktur@nusantara.test')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($approver === null) {
            return; // Iam not seeded yet — skip gracefully (CONVENTIONS §8)
        }

        // Rp 84.592.000 = 800 m3 galian tanah basement at BOQ B.1's own unit
        // price of Rp 105.740/m3. The volume itself is recorded against this
        // change order by ProjectsDatabaseSeeder; the number here is what the
        // parties signed for it.
        $amount = 84_592_000.0;

        $addenda = [
            [
                'customer_ref' => 'ADD-I/GSP/2026',
                'change_date' => '2026-03-12',
                'title' => 'Addendum I — tambah volume galian tanah basement 800 m3',
                'description' => 'Muka air tanah lebih tinggi daripada data penyelidikan tanah, sehingga galian '
                    .'basement bertambah 800 m3 dengan harga satuan kontrak (item BOQ B.1).',
                'value_change' => $amount,
            ],
            [
                'customer_ref' => 'ADD-II/GSP/2026',
                'change_date' => '2026-03-12',
                'title' => 'Addendum II — kurang lingkup pekerjaan MEP lainnya',
                'description' => 'Sebagian lingkup pekerjaan MEP lainnya (item lump sum BOQ C.5) dikeluarkan dari '
                    .'kontrak senilai tambahan galian pada Addendum I, sehingga nilai kontrak tidak berubah.',
                'value_change' => -$amount,
            ],
        ];

        foreach ($addenda as $data) {
            $order = ContractChangeOrder::query()->create([
                'contract_id' => $contract->id,
                'change_date' => $data['change_date'],
                'title' => $data['title'],
                'description' => $data['description'],
                'reason' => 'kondisi_lapangan',
                'change_type' => ChangeOrderType::TambahKurang,
                'value_change' => $data['value_change'],
                'ppn_change' => round($data['value_change'] * (float) $contract->ppn_rate / 100, 2),
                'customer_ref' => $data['customer_ref'],
                'status' => DocumentStatus::Approved,
            ]);

            $order->approvals()->create([
                'action' => 'approved',
                'user_id' => (int) $approver,
                'note' => 'Addendum ditandatangani bersama pemilik dan Konsultan MK.',
            ]);
        }

        // What ContractChangeOrderService::approve backfills on the first
        // approved amendment: the value this contract started at. The pair
        // cancels, so it is also the value it carries now.
        $contract->forceFill(['original_value' => (float) $contract->value])->save();
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
                    // T3.6: all three seeded contracts are worth exactly their
                    // quotation's DPP (48,5 M / 9,8 M / 480 jt = the summed
                    // lines above), so there is no difference to explain.
                    'value_change_reason' => null,
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
        // P7 menambah TND/TKD/RKK ke daftar yang sama: masing-masing satu baris
        // kanon bernomor 0001, jadi counter-nya digeser lewat ambang yang sama.
        foreach (['QTN', 'CTR', 'TND', 'TKD', 'RKK'] as $type) {
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
