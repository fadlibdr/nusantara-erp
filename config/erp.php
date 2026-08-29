<?php

/*
 * Central ERP configuration: company profile defaults, document numbering,
 * Indonesian tax parameters, payroll (BPJS / overtime) parameters.
 *
 * Statutory rates change — review yearly against the latest PMK/PP/Kepmenaker.
 */
return [

    'company' => [
        'name' => env('ERP_COMPANY_NAME', 'PT Nusantara Karya Integrasi'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security / production hardening
    |--------------------------------------------------------------------------
    | force_https    — generate https:// URLs (behind a TLS-terminating proxy).
    | api_rate_limit — requests per minute for the named 'api' RateLimiter
    |                  (per authenticated user id, falling back to client IP).
    */
    'security' => [
        'force_https' => env('FORCE_HTTPS', false),
        'api_rate_limit' => env('API_RATE_LIMIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document numbering formats
    |--------------------------------------------------------------------------
    | Tokens: {Y} = 4-digit year, {M2} = 2-digit month, {RM} = roman month,
    | {N3}/{N4}/{N5} = zero-padded sequence. Sequences reset per type per year.
    | Consumed by Modules\Core\Services\DocumentNumberService.
    |
    | Every format MUST contain {Y} as well as a sequence token: the counter
    | restarts at 1 each January, so a format without the year re-issues the
    | previous year's codes and the unique code column rejects them. The settings
    | screen enforces this (SettingService::DOCUMENT_FORMAT_PATTERN); these
    | defaults have to satisfy the same rule.
    */
    'documents' => [
        'QTN' => 'QTN/{Y}/{RM}/{N4}',   // CRM quotation (penawaran)
        'CTR' => 'CTR/{Y}/{RM}/{N4}',   // CRM contract (kontrak/SPK dari customer)
        'CCO' => 'CCO/{Y}/{RM}/{N4}',   // Contract change order (pekerjaan tambah-kurang)
        'PRJ' => 'PRJ-{Y}-{N3}',        // Project code
        'BOQ' => 'BOQ/{Y}/{N4}',        // Bill of quantities / RAB
        'RAP' => 'RAP/{Y}/{N4}',        // Rencana Anggaran Pelaksanaan (cost budget)
        'PR' => 'PR/{Y}/{RM}/{N4}',    // Purchase requisition
        'RFQ' => 'RFQ/{Y}/{RM}/{N4}',   // Request for quotation (banding penawaran vendor)
        'PO' => 'PO/{Y}/{RM}/{N4}',    // Purchase order
        'GRN' => 'GRN/{Y}/{RM}/{N4}',   // Goods receipt note
        'ISS' => 'ISS/{Y}/{RM}/{N4}',   // Material issue (pengeluaran barang)
        'TRF' => 'TRF/{Y}/{RM}/{N4}',   // Stock transfer
        'ADJ' => 'ADJ/{Y}/{RM}/{N4}',   // Stock adjustment (opname)
        'SPK' => 'SPK/{Y}/{RM}/{N4}',   // Subcontract work order (SPK subkon)
        'ADS' => 'ADS/{Y}/{RM}/{N4}',   // Addendum SPK
        'CLM' => 'CLM/{Y}/{RM}/{N4}',   // Subcontractor progress claim (opname)
        'INV' => 'INV/{Y}/{RM}/{N4}',   // AR invoice (termin)
        'BIL' => 'BIL/{Y}/{RM}/{N4}',   // AP bill (tagihan vendor)
        'RCV' => 'RCV/{Y}/{RM}/{N4}',   // Payment received
        'PAY' => 'PAY/{Y}/{RM}/{N4}',   // Payment out
        'JV' => 'JV/{Y}/{M2}/{N4}',    // Journal voucher
        'BST' => 'BST/{Y}/{RM}/{N4}',   // Imported bank statement (rekening koran)
        'PYR' => 'PYR/{Y}/{M2}/{N3}',   // Payroll run
        'CTI' => 'CTI/{Y}/{RM}/{N4}',   // Pengajuan cuti/izin
        'TKT' => 'TKT-{Y}{M2}-{N4}',    // Service ticket
        'BAST' => 'BAST/{Y}/{RM}/{N3}',  // Berita acara serah terima
        'DRP' => 'DRP/{Y}/{M2}/{N4}',   // Daily report (laporan harian)
        'IKL' => 'IKL/{Y}/{RM}/{N4}',   // Izin kerja lapangan (P0-C)
        'ILB' => 'ILB/{Y}/{RM}/{N4}',   // Izin kerja lembur (P0-C)
        'IMK' => 'IMK/{Y}/{RM}/{N4}',   // Izin masuk/keluar material & peralatan (P0-C)
        'SVC' => 'SVC/{Y}/{RM}/{N4}',   // ServiceDesk maintenance contract
        'AST' => 'AST-{Y}-{N4}',        // Asset code ({Y} added: 'AST-{N4}' collided each January)
        'DEP' => 'DEP/{Y}/{RM}/{N3}',   // Asset deployment (mobilisasi)
        'DPR' => 'DPR/{Y}/{M2}/{N3}',   // Asset depreciation run
        'MTC' => 'MTC/{Y}/{RM}/{N3}',   // Asset maintenance record
        'PM' => 'PM/{Y}/{RM}/{N4}',    // Preventive maintenance visit
        'K3' => 'K3/{Y}/{RM}/{N3}',    // Register kecelakaan kerja (SMK3)
        'POC' => 'POC/{Y}/{M2}/{N3}',   // Pengakuan pendapatan PSAK 115 (persentase penyelesaian)
        'SDS' => 'SDS/{Y}/{RM}/{N4}',   // Pengajuan persetujuan shop drawing (P1-ENG)
        'SMS' => 'SMS/{Y}/{RM}/{N4}',   // Pengajuan persetujuan material (P1-ENG)
        'TRM' => 'TRM/{Y}/{RM}/{N4}',   // Transmittal dokumen (P1-ENG)
        'IPP' => 'IPP/{Y}/{RM}/{N4}',   // Ijin pelaksanaan pekerjaan (P1-ENG)
        'QCI' => 'QCI/{Y}/{RM}/{N4}',   // Inspeksi mutu (P1-QC)
        'NCR' => 'NCR/{Y}/{RM}/{N4}',   // Laporan ketidaksesuaian / non-conformance report (P1-QC)
        'BAN' => 'BAN/{Y}/{RM}/{N4}',   // Berita acara negosiasi vendor (P2)
        'AWD' => 'AWD/{Y}/{RM}/{N4}',   // Keputusan pemenang / award decision (P2 — AWD dipilih agar tidak bentrok BAP/BAPP zona P3)
        'PBL' => 'PBL/{Y}/{N4}',        // Rencana pengadaan / pola belanja (P2 — PBL dipilih agar tidak bentrok tipe RPB milik retur pembelian)
        'OPN' => 'OPN/{Y}/{RM}/{N4}',   // Opname progres ke pemilik (P3 — sisi pendapatan; CLM tetap milik opname subkon)
        'BAPP' => 'BAPP/{Y}/{RM}/{N4}',  // Berita acara pemeriksaan pekerjaan per zona (P3 — BAPP, kode yang disisakan AWD)
        'BSK' => 'BSK/{Y}/{RM}/{N4}',   // BAST subkontraktor I/II (P3 — BST sudah dipakai rekening koran, BAST milik serah terima owner)
        'SP3' => 'SP3/{Y}/{RM}/{N4}',   // SP3 Induk — SPK mandor upah borongan (P4; SPK tetap milik subkon)
        'OPM' => 'OPM/{Y}/{RM}/{N4}',   // Opname mandor (P4 — CLM milik opname subkon, OPN milik opname owner)
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxes
    |--------------------------------------------------------------------------
    */
    'tax' => [
        // PMK 131/2024: statutory 12% applied on DPP "nilai lain" (11/12 of price)
        // for non-luxury goods/services => 11% effective. Stored as the effective rate.
        'ppn_rate' => 11.0,

        // The statutory rate the faktur itself must state, against DPP nilai
        // lain. Documents and reports use the effective rate above; only the
        // e-Faktur export needs this one, to work back from the PPN charged to
        // the DPP the faktur has to carry (TaxExportService::fakturDpp). Keep
        // the two in step: ppn_rate / ppn_headline_rate IS the 11/12 factor.
        'ppn_headline_rate' => 12.0,

        // PPh 23 on services (jasa) when not covered by PPh final.
        'pph23_services_rate' => 2.0,

        /*
         * PPh final UMKM — PP 55/2022 Pasal 56 (melanjutkan PP 23/2018):
         * 0,5% dari peredaran bruto untuk WP dengan omzet <= Rp 4,8 miliar.
         * Dipakai P4 untuk upah mandor borongan per asumsi #3 (mandor
         * diperlakukan sebagai VENDOR dengan PPh final, bukan karyawan PPh
         * 21): seorang mandor borongan perorangan bukan pemberi jasa
         * konstruksi bersertifikat PP 9/2022, dan skema finalnya adalah
         * tarif UMKM ini. Snapshot per SP3 (scm_labor_contracts.pph_rate) —
         * mengubah angka ini tidak menulis ulang kontrak yang sudah ada.
         */
        'pph_final_umkm_rate' => 0.5,

        // PPh final jasa konstruksi — PP 9/2022 classifications.
        'pph_final_construction' => [
            'pelaksanaan_kecil_bersertifikat' => 1.75,
            'pelaksanaan_bersertifikat' => 2.65,
            'pelaksanaan_tanpa_sertifikat' => 4.00,
            'perancangan_bersertifikat' => 3.50,
            'perancangan_tanpa_sertifikat' => 6.00,
            'terintegrasi_bersertifikat' => 2.65,
            'terintegrasi_tanpa_sertifikat' => 4.00,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payroll parameters (review yearly)
    |--------------------------------------------------------------------------
    */
    'payroll' => [
        'bpjs' => [
            'kesehatan' => ['company' => 4.0, 'employee' => 1.0, 'salary_cap' => 12000000],
            'jht' => ['company' => 3.7, 'employee' => 2.0],
            'jp' => ['company' => 2.0, 'employee' => 1.0, 'salary_cap' => 10547400],
            'jkk' => [
                'default_risk_class' => 3, // construction is typically class 3+
                'rates' => [1 => 0.24, 2 => 0.54, 3 => 0.89, 4 => 1.27, 5 => 1.74],
            ],
            'jkm' => ['company' => 0.30],
        ],
        // Kepmenaker overtime: hourly wage = monthly wage / 173.
        'overtime' => ['divisor' => 173],
    ],

    /*
    |--------------------------------------------------------------------------
    | HR — cuti tahunan (UU 13/2003 Pasal 79 jo. PP 35/2021)
    |--------------------------------------------------------------------------
    */
    'hr' => [
        'leave' => [
            // 12 hari kerja per tahun hak, terbit setelah 12 bulan masa kerja,
            // dihitung dari ulang tahun join_date (LeaveService::balance).
            'annual_days' => 12,
            // Kebijakan bawaan: sisa saldo HANGUS di ulang tahun berikutnya.
            // true = sisa SATU tahun sebelumnya ikut terbawa — bukan akumulator
            // tanpa batas yang diam-diam menumbuhkan liabilitas cuti.
            'carry_over' => false,
            // 6 = pekan kerja enam hari (hanya Minggu libur — rezim lapangan);
            // 5 = Sabtu juga libur. Menentukan hitungan day_count cuti.
            'workweek_days' => 6,
        ],
    ],

    'projects' => [
        'default_retention_pct' => 5.0, // retensi ditahan sampai masa pemeliharaan selesai
        /*
         * Jam kerja per hari, dipakai untuk mengubah man-days pada laporan harian
         * menjadi man-hours pada statistik K3 (frequency / severity rate).
         * 7 jam adalah pola 6 hari kerja menurut UU Ketenagakerjaan; pola 5 hari
         * adalah 8 jam.
         */
        'working_hours_per_day' => 7.0,
        /*
         * Ambang cakupan realisasi biaya untuk CPI (persen). Setiap kategori
         * biaya yang dianggarkan di RAP harus mencatat realisasi minimal
         * sekian persen dari anggaran berjalannya (anggaran kategori x
         * planned_pct laporan) sebelum EVM mau menandai CPI sebagai andal.
         * Uji keberadaan saja pernah dikalahkan dengan Rp 4.000: satu baris
         * Rp 1.000 di tiap kategori kosong menghijaukan CPI 144x pada demo.
         */
        'cpi_coverage_min_pct' => 50.0,
        /*
         * Ambang laporan varian material (teori AHSP x volume BOQ vs bon
         * gudang). Sebuah baris ditandai "melewati ambang" bila selisihnya
         * melebihi persen INI DAN rupiah INI sekaligus — dua-duanya, supaya
         * selisih 40% pada pasir Rp 400 ribu tidak berisik sementara selisih
         * 0,5% pada paket besi Rp 12,44 miliar (= Rp 62 juta) tetap tertangkap;
         * atau bila selisihnya sendiri sudah mencapai always_show berapa pun
         * persennya. Layar varian mencetak aturan ini apa adanya dari jawaban
         * server, jadi mengubah angka di sini mengubah kalimat yang dibaca
         * pengawas lapangan.
         */
        'material_variance_pct_threshold' => 5.0,
        'material_variance_value_threshold' => 5_000_000,
        'material_variance_always_show_value' => 50_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rekonsiliasi bank
    |--------------------------------------------------------------------------
    | How far apart a bank movement and its ERP document may be dated before the
    | matcher stops proposing them as the same event. Inter-bank transfers and
    | cheques clear over several days, so nothing useful is proposed at 0; a very
    | wide window makes every payment a candidate and the ranking meaningless.
    */
    /*
    |--------------------------------------------------------------------------
    | Backup watch
    |--------------------------------------------------------------------------
    | Where deploy/backup-erp1.sh records the offsite-copy status, and how old
    | its last success may be before erp:backup-watch raises an in-app alarm.
    | Install-time constants, deliberately not on the settings screen: an
    | operator who can silence the backup alarm from a web form will.
    */
    'backup' => [
        'status_file' => env('ERP_BACKUP_STATUS_FILE', '/var/lib/erp1/offsite-status.json'),
        'offsite_max_age_days' => 3,
    ],

    'reconciliation' => [
        'match_date_window_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Proyeksi arus kas
    |--------------------------------------------------------------------------
    | Lag penagihan termin (hari): termin siap tagih dianggap ditagih hari ini
    | dan uangnya diterima sekian hari kemudian; termin terjadwal dihitung pada
    | jatuh temponya ditambah lag yang sama. Layar proyeksi mencetak asumsi ini
    | apa adanya dari jawaban server.
    */
    'cashflow' => [
        'termin_collection_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tutup buku / kalender fiskal
    |--------------------------------------------------------------------------
    | calendar_months_ahead          how far ahead fin:ensure-calendar creates
    |                                missing periods. Three means the 2027
    |                                calendar exists from 1 October 2026, long
    |                                before anybody dates a document into it.
    | reminder_days_after_period_end how long a period may sit ended and still
    |                                open before fin:close-watch nags the people
    |                                who can close it.
    |
    | Install-time constants, deliberately NOT on the settings screen — the same
    | reading as backup.* above: an operator who can silence the close reminder
    | from a web form will, and the reminder is the only thing that turns "we
    | forgot to close June" into something anybody notices.
    */
    'closing' => [
        'calendar_months_ahead' => 3,
        'reminder_days_after_period_end' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifikasi
    |--------------------------------------------------------------------------
    | In-app approval notifications are always on. Email is off until a real
    | mailer is configured: MAIL_MAILER defaults to "log", and turning this on
    | before that only writes approval traffic into the application log.
    */
    'notifications' => [
        'email_enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Akun jurnal otomatis (perpetual persediaan & hutang usaha)
    |--------------------------------------------------------------------------
    | COA codes StockService posts GRN, material issue and opname journals to,
    | and that ApBillService uses when a vendor bill is approved. Every code must
    | exist in the chart of accounts and be postable.
    |
    | Every ACCOUNT key below is read at runtime through Modules\Core\Support\Erp
    | and is therefore registered in SettingService::definitions()['accounting'],
    | and the reverse holds too; that two-way contract is spelled out in the
    | registry itself. accounting.perpetual_inventory is the one deliberate
    | exception — read at runtime, never editable — for the reason set out on it.
    */
    'accounting' => [
        /*
         * INVENTORY ACCOUNTING METHOD — an install-time election, not a preference.
         *
         * true  => perpetual. Every stock movement posts to the general ledger:
         *          a receipt debits persediaan (1-1400) against a clearing
         *          liability, an issue credits persediaan and debits project
         *          cost. Material becomes an expense when it is CONSUMED.
         * false => periodic. Stock is tracked in quantity only, no stock
         *          movement touches the ledger, and material becomes an expense
         *          when the VENDOR BILL is approved.
         *
         * This is not a setting and must not become one again. It elects an
         * accounting method, and the two methods disagree about where the value
         * of on-hand stock lives, so changing it once documents exist corrupts
         * the ledger in whichever direction it is changed. Measured on this
         * codebase, on one purchase of Rp 6.200.000:
         *
         *   on at receipt, off later   1-1400 keeps 6.200.000 against a stock
         *       sub-ledger of 0,00, while 5-1100 and the project realisasi both
         *       stay 0,00: the material is expensed nowhere, ever, because the
         *       issue that would have relieved 1-1400 no longer posts.
         *   off at receipt, on later   the bill already expensed the purchase to
         *       5-1100; the issue then debits 5-1100 a second time and credits a
         *       persediaan account that was never debited, giving 5-1100 =
         *       12.400.000 for a 6.200.000 purchase and 1-1400 = -6.200.000.
         *
         * Neither outcome is a defect the engine can prevent: each posting was
         * correct under the method in force when it was made. What a change of
         * method actually requires is a STOCK REVALUATION on one side of it —
         * capitalising stock that was expensed, or expensing stock that was
         * capitalised — booked at a fiscal-period boundary. That is an
         * accountant's judgement and an accountant's journal. Nothing in this
         * application does it for you, and nothing here pretends to.
         *
         * BEFORE editing this value, run
         *
         *     php artisan erp:inventory-method-check
         *
         * It reports what a change would break right now — goods receipts whose
         * recorded GR/IR or accrual credit no vendor bill has cleared yet, stock
         * movements already posted inside an open fiscal period, and stock on
         * hand still carrying value — and exits non-zero while any of those
         * holds. It only reports; it never migrates anything.
         *
         * Upgrade note: an installation that stored an override for this key
         * while it was still on the settings screen still has that row in
         * core_settings, and the resolver still honours it. That is deliberate —
         * upgrading must not silently switch a company's accounting method.
         * SettingService::invalidOverrides() and the command above both report
         * such a row; move its value here, then delete the row.
         */
        'perpetual_inventory' => true,

        'inventory_account' => '1-1400',       // Persediaan Material
        'grn_clearing_account' => '2-1150',    // Penerimaan Barang Belum Ditagih (GR/IR)
        'stock_variance_account' => '6-4400',  // Selisih Persediaan (opname/rusak/hilang)

        // Penerimaan tanpa PO tidak punya dokumen tagihan untuk mengosongkan GR/IR,
        // jadi diakru terpisah dan diselesaikan lewat tagihan manual / jurnal.
        'receipt_accrual_account' => '2-1600', // Beban Yang Masih Harus Dibayar

        // Selisih antara nilai barang yang benar-benar diterima (harga GRN) dan
        // nilai yang ditagih vendor (harga PO) pada pencocokan tiga arah.
        'purchase_variance_account' => '6-4500', // Selisih Harga Pembelian

        // Uang muka pembelian (DP 20-30% atas PO material adalah termin paling
        // umum di konstruksi). Tagihan uang muka mendebit akun aset ini; tagihan
        // final mengkreditkannya kembali dan hanya mengakui sisa hutangnya.
        // Dibaca ApBillService saat tagihan disetujui.
        'purchase_advance_account' => '1-1500', // Uang Muka Proyek

        /*
         * Counter-entry for stock that is already on hand when the system goes
         * live, and for any later receipt with NO purchase order and NO vendor
         * (found stock, a return from site).
         *
         * Such a receipt is not a purchase: no counterparty, no liability, no
         * profit-and-loss event. It therefore belongs to none of the three
         * credit routes the receipt engine has for a delivery — 2-1150 GR/IR,
         * 2-1600 accrual, 6-4400 selisih persediaan. Crediting 6-4400 reports the
         * whole of a company's opening stock as operating income in its go-live
         * year. The counter-entry of an opening balance is equity.
         *
         * 3-3100 is an intermediate equity account: it collects the counter-entry
         * of every opening balance while data migration runs, and is then closed
         * once to 3-1100 Modal Disetor / 3-2100 Laba Ditahan by an accountant —
         * a split only a human can decide, not a seeder and not a migration.
         *
         * Read by the receipt engine when a receipt has no counterparty, and by
         * InventoryDatabaseSeeder and the opening-balance data migration, so it
         * is registered on the settings screen like every other account here.
         */
        'opening_balance_account' => '3-3100', // Saldo Awal (ekuitas)
    ],

    /*
    |--------------------------------------------------------------------------
    | Pengadaan
    |--------------------------------------------------------------------------
    | evaluation_threshold — PO dengan total >= nilai ini dianggap "besar":
    | menutupnya (manual maupun otomatis saat terima penuh) memicu ajakan
    | mengisi evaluasi vendor — pesan penutupan + notifikasi ke pemegang
    | prc.create — bila vendornya belum dievaluasi 6 bulan terakhir. Default
    | disamakan dengan ambang persetujuan direktur PO di bawah: pembelian yang
    | cukup besar untuk butuh direktur cukup besar pula untuk berutang
    | evaluasi. Dibaca VendorEvaluationService::promptEvaluationIfDue dengan
    | default yang sama bila kunci ini absen.
    |
    | (Blok 'procurement' duplikat yang hanya memuat evaluation_threshold sudah
    | dihapus: PHP hanya menyimpan kemunculan terakhir sebuah kunci larik, jadi
    | blok pertama itu diam-diam terbuang — footgun laten pra-P2 yang ditutup.)
    |--------------------------------------------------------------------------
    | evaluation_threshold — PO dengan total >= nilai ini dianggap "besar":
    | menutupnya (manual maupun otomatis saat terima penuh) memicu ajakan
    | mengisi evaluasi vendor — pesan penutupan + notifikasi ke pemegang
    | prc.create — bila vendornya belum dievaluasi 6 bulan terakhir. Default
    | disamakan dengan ambang persetujuan direktur PO di bawah: pembelian yang
    | cukup besar untuk butuh direktur cukup besar pula untuk berutang
    | evaluasi. Dibaca VendorEvaluationService::promptEvaluationIfDue dengan
    | default yang sama bila kunci ini absen.
    */
    'procurement' => [
        'evaluation_threshold' => 100000000,

        /*
         * P2 — bobot penilaian penawaran berbobot (sistem nilai DAN 4.8).
         *
         * Lima aspek, bobot dalam PERSEN, JUMLAH WAJIB 100. Validasi jumlah
         * dilakukan SAAT BOOT (ProcurementServiceProvider memanggil
         * Procurement\Support\BidWeights::assertValidConfig, yang melempar
         * BidWeightConfigException) — config yang salah bobot tidak boleh sampai
         * ke tabulasi bertanda tangan. Skor harga TIDAK diinput: dihitung dari
         * rasio harga penawaran terhadap RAB (BidEvaluationService::hargaScore);
         * empat aspek lain (mutu, waktu, keuangan, k3) diinput 0–100 per vendor.
         */
        'bid_weights' => [
            'harga' => 50,
            'mutu' => 30,
            'waktu' => 5,
            'keuangan' => 10,
            'k3' => 5,
        ],

        /*
         * Kendali harga PO (temuan #34 tahap 2): baris PO ber-boq_item_id yang
         * harga satuannya melampaui harga BOQ beku lebih dari persen ini
         * memicu 422 konfirmasi-lanjut saat PO diajukan (PriceDeviationService).
         * Peringatan yang wajib diakui, bukan blokir.
         */
        'price_warning_pct' => 10,

        /*
         * Gate anggaran (temuan #33): perilaku saat DPP PO/SPK yang diajukan
         * melampaui sisa RAP (= anggaran - realisasi - komitmen berjalan; PO
         * diadu dengan sisi non-subkon RAP, SPK dengan sisi subkon).
         *   'warn'  (bawaan) wajib konfirmasi eksplisit pengaju;
         *   'block' 422 keras, konfirmasi tidak menolong;
         *   'off'   gate mati.
         * Nilai tak dikenal diperlakukan 'warn' oleh BudgetGateService — salah
         * ketik kebijakan tidak boleh diam-diam mematikan gate.
         */
        'budget_gate' => 'warn',
    ],

    'approvals' => [
        // Documents at or above this amount need a second (direktur) approval.
        //
        // PO and SPK keep the ORIGINAL two-level mechanism (needs_director_approval
        // stamped on submit + Procurement\Support\DirectorApproval enforced in
        // PoService/SubcontractService::approve): one approval, but at/above the
        // threshold that approval must be a prc/scm.approve-director holder, and
        // maker-checker still forbids the submitter. That mechanism ships unchanged
        // — every PurchaseOrderDirectorApprovalTest / SubcontractDirectorApprovalTest
        // assertion stays meaningful — so these two keys are LEFT AS THEY WERE.
        'purchase_order' => ['threshold_two_level' => 100000000],
        'subcontract' => ['threshold_two_level' => 200000000],

        /*
         * P2 — n-level approval ladder (generalised shared mechanism).
         *
         * A document type listed here opts into Core\Traits\Approvable's n-level
         * path: Approvable::requiredLevels() resolves the amount against this
         * ladder (Core\Support\ApprovalLevels), approve() records one 'approved'
         * row per DISTINCT user, and the document flips to 'approved' only when the
         * required number of distinct approvers is reached. Levels 2 and above
         * additionally require the module's <prefix>.approve-director permission —
         * this is what keeps the director right meaningful under the new counter,
         * exactly as DirectorApproval kept it for PO/SPK.
         *
         * Bracket semantics match the historical threshold: `to` is the EXCLUSIVE
         * upper bound of a bracket, so an amount AT the boundary falls into the
         * next (higher) bracket — an award of exactly Rp 100 juta needs 2 levels,
         * the same >= reading PO/SPK have always used at their own thresholds.
         *
         *   < Rp 100 juta         1 level  (any prc.approve holder)
         *   Rp 100 juta – 1 M     2 levels (2nd from a prc.approve-director holder)
         *   >= Rp 1 miliar        3 levels (2nd & 3rd from prc.approve-director)
         */
        'award_decision' => [
            'ladder' => [
                ['to' => 100000000, 'levels' => 1],
                ['to' => 1000000000, 'levels' => 2],
                ['to' => null, 'levels' => 3],
            ],
        ],

        /*
         * MAKER-CHECKER. Refuse an approval by the same person who submitted
         * the document — vendor bills, termin invoices, PO, SPK, opname,
         * payroll, and outgoing payments alike.
         *
         * Shipped ENFORCED, because the alternative is what the demo dataset
         * shows: one `finance` login could raise BIL/2026/III/0001 for
         * Rp 232.545.000 to a vendor of its own choosing, approve it, and pay
         * it — four clicks, and the approval trail it leaves reads exactly like
         * a properly approved bill.
         *
         * Turn it off only where the company genuinely has fewer people than it
         * has duties. Doing so hides nothing: core_approvals still records both
         * the submitted and the approved row, so a self-approval stays findable
         * afterwards with a single self-join whichever way this is set.
         */
        'segregation_of_duties' => true,
    ],
];
