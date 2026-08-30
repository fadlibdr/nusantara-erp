<?php

namespace Modules\Core\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Models\Setting;

/**
 * Runtime overrides for config/erp.php.
 *
 * Statutory numbers (PPN, PPh final konstruksi, BPJS rates and caps, PTKP-driven
 * payroll parameters) change by regulation, and an operator must be able to update
 * them without a deploy. config/erp.php stays the shipped default; anything an
 * administrator edits is stored in core_settings under the same dotted key and wins.
 *
 * Editing a parameter only affects documents created afterwards — quotations,
 * contracts, POs, SPK and invoices all snapshot their rates at creation time, so
 * history is never rewritten.
 *
 * Reading: one lookup per unit of work
 * ------------------------------------
 * The override map is loaded at most once per SettingService instance and then
 * memoised, so a request or job reading 60 parameters costs one lookup, not 60.
 * That memo is safe because CoreServiceProvider binds this class scoped(): the
 * instance — and with it the memo — is dropped at every unit-of-work boundary,
 * so it cannot outlive one HTTP request or one queued job. See that provider for
 * where those boundaries are, with the framework source that enforces them, and
 * for the one case that has to opt in by calling flush() itself.
 *
 * Writing: every write invalidates every reader
 * ---------------------------------------------
 * set() and flush() drop the memo of the writing instance and forget the shared
 * cache entry. On a cache store shared between processes (redis, memcached,
 * database, file — the stores an installation running a queue actually uses)
 * that single forget reaches every process, so the next unit of work in every
 * process reloads from core_settings. CACHE_TTL is what bounds staleness when
 * the store is private to each process instead.
 *
 * Writing: what the registry enforces
 * -----------------------------------
 * set() validates the value against the registry entry for that key, using the
 * very rules validationRules() hands the HTTP layer. A rule only the controller
 * applied was not a rule: this service is the API other code calls, and
 * set('documents.PO', 'PO-{N4}') used to be accepted here and reproduce the
 * January numbering collision. invalidOverrides() reports rows an installation
 * stored before that check existed; it never deletes operator data.
 */
class SettingService
{
    /**
     * Cache key of the override map.
     *
     * The `.v2` suffix is not decoration. Earlier builds of this class wrote a
     * map under the bare key below, and under `<bare key>:<version stamp>`, with
     * different lifetimes — at least one of them without a TTL. Reusing the bare
     * key would let an installation upgrading from such a build serve an entry
     * written under rules that no longer hold, before any write has happened to
     * clear it. A distinct key cannot: on the first read after the upgrade there
     * is simply nothing there, and the map is loaded from core_settings.
     */
    public const CACHE_KEY = 'core.settings.overrides.v2';

    /** Bare key used by earlier builds. Only ever forgotten, never read. */
    private const LEGACY_CACHE_KEY = 'core.settings.overrides';

    /**
     * Lifetime of the cached override map.
     *
     * On a shared cache store this is only a backstop: flush() forgets the entry
     * outright, so a write is visible to every process's next unit of work
     * regardless of the TTL. It becomes the actual bound on staleness when the
     * configured store is private to each process (CACHE_STORE=array, or an
     * apc-backed store in a worker), because there a forget cannot cross a
     * process boundary and the entry has to expire on its own.
     *
     * The bound is real: a fresh instance per unit of work always consults the
     * cache, so an expiring entry is genuinely re-read. (The version-stamped
     * design this replaced returned the memo before consulting the cache, so
     * under a private store — where the stamp never moved — the TTL bounded
     * nothing at all.)
     */
    private const CACHE_TTL = 60;

    /** @var array<string, mixed>|null memo of the override map for this instance */
    private ?array $overrides = null;

    /**
     * Editable parameters, grouped for the settings screen. `key` is the path
     * inside config/erp.php; `type` drives both validation and the UI control.
     *
     * @return array<string, array{label: string, description: string, settings: list<array<string, mixed>>}>
     */
    public function definitions(): array
    {
        return [
            'tax' => [
                'label' => 'Pajak',
                'description' => 'Tarif statutori. Dokumen menyimpan tarif yang berlaku saat dibuat, '
                    .'jadi perubahan di sini hanya berlaku untuk dokumen baru.',
                'settings' => [
                    [
                        'key' => 'tax.ppn_rate',
                        'label' => 'Tarif PPN efektif',
                        'type' => 'percent',
                        'help' => 'PMK 131/2024: 12% atas DPP nilai lain 11/12 = 11% efektif untuk '
                            .'barang/jasa non-mewah. Simpan sebagai tarif efektif.',
                    ],
                    [
                        /*
                         * Tarif UU, bukan tarif efektif. e-Faktur melaporkan DPP
                         * nilai lain = PPN / tarif UU, jadi keduanya harus
                         * bergerak bersama: 11% efektif = 12% UU x 11/12. Naik
                         * ke 12% penuh berarti mengubah keduanya, dan tanpa
                         * baris ini yang satu bisa diubah lewat layar sementara
                         * yang lain menunggu deploy.
                         */
                        'key' => 'tax.ppn_headline_rate',
                        'label' => 'Tarif PPN menurut undang-undang',
                        'type' => 'percent',
                        'help' => 'Dipakai ekspor e-Faktur untuk menurunkan DPP nilai lain dari nilai '
                            .'PPN. Sepasang dengan tarif efektif di atas: 11% efektif = 12% UU x 11/12.',
                    ],
                ],
            ],

            /*
             * Deliberately NOT here: tax.pph23_services_rate (M7).
             *
             * It was editable on this screen and honoured by nothing. Its only
             * reader is Modules\Finance\Database\Seeders\TaxSeeder, and that
             * reader calls config('erp.tax.pph23_services_rate') — the shipped
             * default — not this resolver, so an override never reached it even
             * on a re-seed. What actually withholds PPh 23 at runtime is the
             * rate on the fin_taxes row with code PPH23 (ApBillService resolves
             * pph_tax_id and calls Tax::amountOn()), and that row is maintained
             * on its own screen, Master Data > Pajak.
             *
             * So the key is a seed default, in the same class as a migration's
             * column default, and by this class's own contract those keep
             * reading config() and are not runtime parameters. It stays in
             * config/erp.php for TaxSeeder; it must not be advertised here,
             * where editing it would silently do nothing and would contradict
             * the fin_taxes row that is really in force.
             *
             * Contrast tax.ppn_rate, which is also seeded into fin_taxes but IS
             * read at runtime (QuotationService, ContractService, PoService,
             * ArInvoiceService, SubcontractService), and
             * tax.pph_final_construction.*, read at runtime by
             * PphConstructionScheme::rate(). Both legitimately belong here.
             */

            'pph_construction' => [
                'label' => 'PPh Final Jasa Konstruksi (PP 9/2022)',
                'description' => 'Tarif per klasifikasi usaha dan sertifikasi. Dipakai saat SPK subkon '
                    .'dibuat, lalu di-snapshot pada SPK tersebut.',
                'settings' => [
                    ['key' => 'tax.pph_final_construction.pelaksanaan_kecil_bersertifikat', 'label' => 'Pelaksanaan — kualifikasi kecil, bersertifikat', 'type' => 'percent'],
                    ['key' => 'tax.pph_final_construction.pelaksanaan_bersertifikat', 'label' => 'Pelaksanaan — bersertifikat (menengah/besar)', 'type' => 'percent'],
                    ['key' => 'tax.pph_final_construction.pelaksanaan_tanpa_sertifikat', 'label' => 'Pelaksanaan — tanpa sertifikat', 'type' => 'percent'],
                    ['key' => 'tax.pph_final_construction.perancangan_bersertifikat', 'label' => 'Perancangan/pengawasan — bersertifikat', 'type' => 'percent'],
                    ['key' => 'tax.pph_final_construction.perancangan_tanpa_sertifikat', 'label' => 'Perancangan/pengawasan — tanpa sertifikat', 'type' => 'percent'],
                    ['key' => 'tax.pph_final_construction.terintegrasi_bersertifikat', 'label' => 'Terintegrasi — bersertifikat', 'type' => 'percent'],
                    ['key' => 'tax.pph_final_construction.terintegrasi_tanpa_sertifikat', 'label' => 'Terintegrasi — tanpa sertifikat', 'type' => 'percent'],
                    // P4 — dibaca runtime oleh LaborPphScheme::rate() saat SP3
                    // mandor dibuat, lalu di-snapshot pada SP3 tersebut (pola
                    // yang sama dengan tarif PP 9/2022 di atas).
                    [
                        'key' => 'tax.pph_final_umkm_rate',
                        'label' => 'PPh Final UMKM (PP 55/2022) — upah mandor',
                        'type' => 'percent',
                    ],
                    [
                        'key' => 'cashflow.termin_collection_days',
                        'label' => 'Asumsi lama penagihan termin (hari)',
                        'type' => 'integer',
                        'min' => 0,
                        'max' => 365,
                        'help' => 'Dipakai proyeksi arus kas 90 hari: termin siap-tagih yang belum '
                            .'ber-invoice diasumsikan menjadi kas sekian hari sejak tanggal pemicunya. '
                            .'Angka ini tercetak pada asumsi laporannya.',
                    ],
                ],
            ],

            'bpjs' => [
                'label' => 'BPJS & Lembur',
                'description' => 'Iuran dan plafon upah ditinjau pemerintah setiap tahun. Perubahan '
                    .'berlaku pada perhitungan payroll berikutnya.',
                'settings' => [
                    ['key' => 'payroll.bpjs.kesehatan.company', 'label' => 'Kesehatan — iuran perusahaan', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.kesehatan.employee', 'label' => 'Kesehatan — iuran karyawan', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.kesehatan.salary_cap', 'label' => 'Kesehatan — plafon upah', 'type' => 'currency'],
                    ['key' => 'payroll.bpjs.jht.company', 'label' => 'JHT — iuran perusahaan', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jht.employee', 'label' => 'JHT — iuran karyawan', 'type' => 'percent', 'help' => 'JHT tidak memakai plafon upah.'],
                    ['key' => 'payroll.bpjs.jp.company', 'label' => 'JP — iuran perusahaan', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jp.employee', 'label' => 'JP — iuran karyawan', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jp.salary_cap', 'label' => 'JP — plafon upah', 'type' => 'currency'],
                    [
                        'key' => 'payroll.bpjs.jkk.default_risk_class',
                        'label' => 'JKK — kelas risiko',
                        'type' => 'select',
                        'options' => [
                            ['value' => 1, 'label' => 'Kelas 1 — risiko sangat rendah'],
                            ['value' => 2, 'label' => 'Kelas 2 — risiko rendah'],
                            ['value' => 3, 'label' => 'Kelas 3 — risiko sedang'],
                            ['value' => 4, 'label' => 'Kelas 4 — risiko tinggi'],
                            ['value' => 5, 'label' => 'Kelas 5 — risiko sangat tinggi'],
                        ],
                        'help' => 'Konstruksi umumnya kelas 3 ke atas.',
                    ],
                    ['key' => 'payroll.bpjs.jkk.rates.1', 'label' => 'JKK — tarif kelas 1', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jkk.rates.2', 'label' => 'JKK — tarif kelas 2', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jkk.rates.3', 'label' => 'JKK — tarif kelas 3', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jkk.rates.4', 'label' => 'JKK — tarif kelas 4', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jkk.rates.5', 'label' => 'JKK — tarif kelas 5', 'type' => 'percent'],
                    ['key' => 'payroll.bpjs.jkm.company', 'label' => 'JKM — iuran perusahaan', 'type' => 'percent'],
                    [
                        'key' => 'payroll.overtime.divisor',
                        'label' => 'Pembagi upah lembur per jam',
                        'type' => 'integer',
                        'min' => 1,
                        'max' => 400,
                        'help' => 'Kepmenaker 102/2004: upah sejam = 1/173 × upah sebulan.',
                    ],
                    [
                        'key' => 'hr.leave.annual_days',
                        'label' => 'Hak cuti tahunan (hari kerja)',
                        'type' => 'integer',
                        'min' => 12,
                        'max' => 30,
                        'help' => 'UU 13/2003 Pasal 79: sekurang-kurangnya 12 hari kerja setelah 12 bulan masa kerja — 12 adalah lantai hukum, min di sini menjaganya.',
                    ],
                    [
                        'key' => 'hr.leave.carry_over',
                        'label' => 'Sisa cuti terbawa ke tahun berikutnya',
                        'type' => 'boolean',
                        'help' => 'Bawaan: hangus di ulang tahun masuk kerja. Bila aktif, hanya sisa satu tahun terakhir yang terbawa.',
                    ],
                    [
                        'key' => 'hr.leave.workweek_days',
                        'label' => 'Hari kerja per pekan (hitung cuti)',
                        'type' => 'integer',
                        'min' => 5,
                        'max' => 6,
                        'help' => '6 = hanya Minggu libur (rezim proyek); 5 = Sabtu juga tidak memotong saldo cuti.',
                    ],
                ],
            ],

            'projects' => [
                'label' => 'Proyek & Persetujuan',
                'description' => 'Nilai bawaan saat dokumen baru dibuat, ambang persetujuan berjenjang, '
                    .'dan ambang pengendalian biaya proyek.',
                'settings' => [
                    [
                        'key' => 'projects.default_retention_pct',
                        'label' => 'Retensi bawaan',
                        'type' => 'percent',
                        'help' => 'Dipakai bila kontrak/SPK/proyek tidak menyebut retensi sendiri.',
                    ],
                    [
                        'key' => 'projects.working_hours_per_day',
                        'label' => 'Jam kerja per hari',
                        'type' => 'integer',
                        'min' => 1,
                        'max' => 24,
                        'help' => 'Mengubah man-days pada laporan harian menjadi man-hours pada statistik K3. '
                            .'Pola 6 hari kerja = 7 jam, pola 5 hari kerja = 8 jam.',
                    ],
                    [
                        'key' => 'projects.cpi_coverage_min_pct',
                        'label' => 'Ambang cakupan biaya untuk CPI',
                        'type' => 'percent',
                        'help' => 'Setiap kategori biaya yang dianggarkan di RAP harus mencatat realisasi '
                            .'minimal sekian persen dari anggaran berjalannya (anggaran kategori × progres '
                            .'rencana) sebelum EVM menandai CPI sebagai andal; di bawahnya CPI berstatus '
                            .'"biaya belum lengkap". Menurunkannya melemahkan penjagaan itu.',
                    ],
                    [
                        'key' => 'projects.material_variance_pct_threshold',
                        'label' => 'Varian material — ambang persen',
                        'type' => 'percent',
                        'help' => 'Baris varian ditandai melewati ambang bila selisihnya melebihi ambang '
                            .'persen INI dan ambang rupiah sekaligus — dua-duanya, supaya selisih besar '
                            .'pada nilai kecil tidak berisik.',
                    ],
                    [
                        'key' => 'projects.material_variance_value_threshold',
                        'label' => 'Varian material — ambang rupiah',
                        'type' => 'currency',
                        'help' => 'Pasangan ambang persen di atas; keduanya harus terlampaui sekaligus '
                            .'supaya selisih persen kecil pada paket bernilai besar tetap tertangkap.',
                    ],
                    [
                        'key' => 'projects.material_variance_always_show_value',
                        'label' => 'Varian material — selalu ditandai di atas',
                        'type' => 'currency',
                        'help' => 'Selisih sebesar ini selalu ditandai, berapa pun persennya. Layar varian '
                            .'mencetak aturan ambang ini apa adanya, jadi mengubah angka di sini mengubah '
                            .'kalimat yang dibaca pengawas lapangan.',
                    ],
                    [
                        'key' => 'approvals.purchase_order.threshold_two_level',
                        'label' => 'PO wajib persetujuan direktur di atas',
                        'type' => 'currency',
                    ],
                    [
                        'key' => 'approvals.subcontract.threshold_two_level',
                        'label' => 'SPK wajib persetujuan direktur di atas',
                        'type' => 'currency',
                    ],
                    [
                        'key' => 'approvals.segregation_of_duties',
                        'label' => 'Wajib pemisahan tugas (maker-checker)',
                        'type' => 'boolean',
                        'help' => 'Menolak persetujuan dokumen oleh orang yang mengajukannya sendiri, termasuk pembayaran '
                            .'keluar. Matikan hanya bila perusahaan memang tidak punya petugas kedua — riwayat '
                            .'persetujuan tetap mencatat bahwa pengaju dan penyetujunya orang yang sama.',
                    ],
                ],
            ],

            /*
             * Two-way contract for this group:
             *   notifications.email_enabled  NotificationService::emailEnabled()
             */
            'notifications' => [
                'label' => 'Notifikasi',
                'description' => 'Pemberitahuan persetujuan dokumen. Pemberitahuan di dalam aplikasi '
                    .'selalu aktif; email hanya dikirim bila server email sudah disetel.',
                'settings' => [
                    [
                        'key' => 'notifications.email_enabled',
                        'label' => 'Kirim juga lewat email',
                        'type' => 'boolean',
                        'help' => 'Nyalakan hanya setelah MAIL_MAILER di .env diarahkan ke server email '
                            .'sungguhan. Pada pemasangan baru nilainya "log", sehingga menyalakan ini '
                            .'hanya menuliskan isi pemberitahuan ke berkas log.',
                    ],
                ],
            ],

            /*
             * Two-way contract for this group:
             *   reconciliation.match_date_window_days
             *       BankStatementMatchService::matchDateWindowDays()
             */
            'reconciliation' => [
                'label' => 'Rekonsiliasi Bank',
                'description' => 'Seberapa jauh tanggal mutasi bank boleh berbeda dari tanggal dokumen '
                    .'ERP ketika sistem mengusulkan padanan.',
                'settings' => [
                    [
                        'key' => 'reconciliation.match_date_window_days',
                        'label' => 'Rentang pencarian padanan (hari)',
                        'type' => 'integer',
                        'min' => 1,
                        'max' => 30,
                        'help' => 'Transfer antarbank dan kliring cek butuh beberapa hari, jadi 0 hari '
                            .'tidak akan mengusulkan apa pun. Rentang yang terlalu lebar membuat semua '
                            .'pembayaran menjadi calon dan peringkatnya kehilangan arti.',
                    ],
                ],
            ],

            /*
             * Two-way contract for this group:
             *   cashflow.termin_collection_days  CashFlowService::projectTermins()
             */
            'cashflow' => [
                'label' => 'Proyeksi Arus Kas',
                'description' => 'Asumsi yang dipakai proyeksi arus kas mingguan.',
                'settings' => [
                    [
                        'key' => 'cashflow.termin_collection_days',
                        'label' => 'Lag penagihan termin (hari)',
                        'type' => 'integer',
                        'min' => 0,
                        'max' => 365,
                        'help' => 'Termin siap tagih dianggap ditagih hari ini dan uangnya diterima '
                            .'sekian hari kemudian; termin terjadwal dihitung pada jatuh temponya '
                            .'ditambah lag yang sama. Layar proyeksi mencetak asumsi ini apa adanya.',
                    ],
                ],
            ],

            /*
             * The two-way contract for this group, checked against the source:
             * every accounting.* key a service reads through Erp:: at runtime is
             * listed here, and every key listed here has such a reader.
             *
             *   accounting.inventory_account          StockService (receipt / issue / opname)
             *   accounting.grn_clearing_account       StockService::receiptCreditLeg (PO)
             *   accounting.receipt_accrual_account    StockService::receiptCreditLeg (vendor, no PO)
             *   accounting.stock_variance_account     StockService::postAdjustmentJournal (opname)
             *   accounting.opening_balance_account    StockService::receiptCreditLeg (no counterparty)
             *   accounting.purchase_variance_account  ApBillService::threeWayMatchLines
             *   accounting.purchase_advance_account   ApBillService::advanceAccountCode
             *
             * Deliberately NOT here, and the ONE key in this file that is read at
             * runtime without being editable: accounting.perpetual_inventory
             * (StockService::ledgerPostingEnabled, ApBillService::debitAccountCode),
             * audit A2.
             *
             * It is not a parameter, it is the election of an accounting method,
             * and the two methods disagree about where the value of on-hand stock
             * lives. One flip of a checkbox therefore corrupted the ledger in
             * whichever direction it was flipped: on-at-receipt/off-later strands
             * the purchase in 1-1400 with the stock sub-ledger at zero and
             * expenses it nowhere, ever; off-at-receipt/on-later expenses it twice
             * and drives 1-1400 negative. Neither is recoverable by the engine,
             * because each posting was right under the method in force when it was
             * made — a genuine change of method needs a stock revaluation booked by
             * an accountant at a fiscal-period boundary. It lives in config/erp.php
             * only, where changing it takes a deploy, and `php artisan
             * erp:inventory-method-check` reports whether a change is safe first.
             * The full argument is in the comment on the key there.
             *
             * Withdrawing it from this registry is also what makes it unwritable:
             * set() refuses a key it does not describe, so neither the service, a
             * seeder, a console command nor PUT /api/core/settings can store an
             * override for it any more. A row stored BEFORE the withdrawal is still
             * honoured by the resolver — upgrading must not silently switch a
             * company's method — and is reported by invalidOverrides() and by the
             * command above.
             */
            'accounting' => [
                'label' => 'Akun Jurnal Otomatis',
                'description' => 'Kode akun COA yang dipakai mesin jurnal persediaan dan tagihan '
                    .'vendor. Akun harus ada di bagan akun dan berstatus dapat diposting.',
                'settings' => [
                    ['key' => 'accounting.inventory_account', 'label' => 'Persediaan', 'type' => 'account'],
                    [
                        'key' => 'accounting.grn_clearing_account',
                        'label' => 'Penerimaan barang belum ditagih (GR/IR)',
                        'type' => 'account',
                        'help' => 'Didebit saat tagihan vendor atas PO yang barangnya sudah diterima.',
                    ],
                    [
                        'key' => 'accounting.stock_variance_account',
                        'label' => 'Selisih persediaan (opname)',
                        'type' => 'account',
                        'help' => 'Menampung selisih hasil stock opname, kerusakan dan kehilangan.',
                    ],
                    [
                        'key' => 'accounting.receipt_accrual_account',
                        'label' => 'Penerimaan tanpa PO (akrual)',
                        'type' => 'account',
                        'help' => 'Penerimaan barang tanpa PO tidak punya tagihan yang akan '
                            .'mengosongkan GR/IR, jadi kewajibannya diakru di akun ini.',
                    ],
                    [
                        'key' => 'accounting.opening_balance_account',
                        'label' => 'Saldo awal persediaan',
                        'type' => 'account',
                        'help' => 'Dikredit saat barang diterima tanpa PO dan tanpa vendor — stok awal '
                            .'saat sistem mulai dipakai, temuan opname masuk, atau pengembalian dari '
                            .'lapangan. Penerimaan seperti itu bukan pembelian: tidak ada lawan '
                            .'transaksi dan tidak ada kewajiban, jadi lawannya adalah ekuitas. '
                            .'Mengarahkannya ke akun beban seperti 6-4400 akan melaporkan seluruh '
                            .'persediaan awal perusahaan sebagai keuntungan operasional. Akun ini '
                            .'ditutup sekali ke Modal Disetor / Laba Ditahan oleh akuntan.',
                    ],
                    [
                        'key' => 'accounting.purchase_variance_account',
                        'label' => 'Selisih harga pembelian',
                        'type' => 'account',
                        'help' => 'Selisih antara nilai barang yang diterima (harga GRN) dan nilai '
                            .'yang ditagih vendor (harga PO).',
                    ],
                    [
                        'key' => 'accounting.purchase_advance_account',
                        'label' => 'Uang muka pembelian',
                        'type' => 'account',
                        'help' => 'Akun aset yang didebit saat tagihan uang muka (DP) atas PO '
                            .'disetujui, lalu dikreditkan kembali saat tagihan final atas PO '
                            .'yang sama disetujui. Bukan akun beban.',
                    ],
                ],
            ],

            'documents' => [
                'label' => 'Penomoran Dokumen',
                'description' => 'Token: {Y} tahun 4 digit · {M2} bulan 2 digit · {RM} bulan romawi · '
                    .'{N3}/{N4}/{N5} nomor urut · {PROJ} kode proyek (opsional). Urutan direset per '
                    .'jenis per tahun, jadi setiap format wajib memuat {Y} dan salah satu token nomor '
                    .'urut. {PROJ} membelah urutan per proyek dan hanya untuk jenis dokumen yang '
                    .'selalu menunjuk proyek — jenis tanpa proyek akan menolak menerbitkan nomor. '
                    .'Mengubah format tidak menomori ulang dokumen lama.',
                'settings' => array_map(
                    fn (string $type): array => [
                        'key' => "documents.{$type}",
                        'label' => self::DOCUMENT_LABELS[$type] ?? $type,
                        'type' => 'document_format',
                    ],
                    array_keys(self::DOCUMENT_LABELS),
                ),
            ],
        ];
    }

    private const DOCUMENT_LABELS = [
        'QTN' => 'Penawaran', 'CTR' => 'Kontrak', 'CCO' => 'Pekerjaan tambah-kurang', 'PRJ' => 'Proyek', 'BOQ' => 'BOQ / RAB',
        'RAP' => 'RAP', 'PR' => 'Permintaan pembelian', 'RFQ' => 'RFQ (banding penawaran)', 'CTI' => 'Pengajuan cuti', 'PO' => 'Pesanan pembelian',
        'GRN' => 'Penerimaan barang', 'ISS' => 'Pengeluaran barang', 'TRF' => 'Transfer stok',
        'ADJ' => 'Penyesuaian stok', 'SPK' => 'SPK subkontraktor', 'ADS' => 'Addendum SPK', 'CLM' => 'Opname subkon',
        'INV' => 'Invoice termin', 'BIL' => 'Tagihan vendor', 'RCV' => 'Penerimaan pembayaran',
        'PAY' => 'Pengeluaran pembayaran', 'JV' => 'Voucher jurnal', 'BST' => 'Rekening koran',
        'PYR' => 'Payroll',
        'TKT' => 'Tiket layanan', 'BAST' => 'BAST', 'DRP' => 'Laporan harian',
        'IKL' => 'Izin kerja lapangan', 'ILB' => 'Izin kerja lembur', 'IMK' => 'Izin masuk/keluar material',
        'SVC' => 'Kontrak layanan', 'AST' => 'Aset', 'DEP' => 'Mobilisasi aset',
        'DPR' => 'Penyusutan', 'MTC' => 'Perawatan aset', 'PM' => 'Kunjungan preventif',
        'K3' => 'Insiden K3', 'POC' => 'Pengakuan pendapatan',
        'SDS' => 'Persetujuan gambar (SDS)', 'SMS' => 'Persetujuan material (SMS)',
        'TRM' => 'Transmittal', 'IPP' => 'Ijin pelaksanaan pekerjaan',
        'QCI' => 'Inspeksi mutu (QCI)', 'NCR' => 'Laporan ketidaksesuaian (NCR)',
        'BAN' => 'BA Negosiasi', 'AWD' => 'Keputusan pemenang', 'PBL' => 'Rencana pengadaan',
        'OPN' => 'Opname progres owner', 'BAPP' => 'BAPP per zona', 'BSK' => 'BAST subkontraktor',
        'SP3' => 'SP3 mandor (induk)', 'OPM' => 'Opname mandor',
        'PPK' => 'PPK alat & jasa', 'PPKB' => 'Tagihan periode PPK',
        'HSE' => 'Formulir K3 harian',
        'TND' => 'Paket tender', 'TKD' => 'Lembar TKDN', 'RKK' => 'RKK penawaran',
        'MTD' => 'Pustaka metode kerja',
    ];

    /**
     * Flat map of every editable key to its definition.
     *
     * @return array<string, array<string, mixed>>
     */
    public function editableKeys(): array
    {
        $map = [];

        foreach ($this->definitions() as $groupKey => $group) {
            foreach ($group['settings'] as $setting) {
                $map[$setting['key']] = $setting + ['group' => $groupKey];
            }
        }

        return $map;
    }

    /**
     * Effective value: the stored override when present, the config/erp.php
     * default otherwise.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return config("erp.{$key}", $default);
    }

    /**
     * The shipped default, ignoring any override — what "reset" restores.
     */
    public function default(string $key, mixed $fallback = null): mixed
    {
        return config("erp.{$key}", $fallback);
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->overrides());
    }

    /**
     * Store an override. A null value removes it, restoring the config default.
     *
     * @throws InvalidArgumentException when the key is not editable, or the
     *                                  value fails the registry rule for its type
     */
    public function set(string $key, mixed $value): void
    {
        $this->assertValid($key, $value);

        // P8 — riwayat tarif (D5): tarif efektif SEBELUM tulisan, dibaca di
        // sini karena set() adalah satu-satunya jalur tulis Pengaturan. Yang
        // direkam hanya kunci PPN/PPh final (RateHistoryService::tracks), dan
        // rekaman itu murni riwayat — snapshot per dokumen tetap sumber angka.
        $rates = app(RateHistoryService::class);
        $oldEffective = $rates->tracks($key) ? $this->get($key) : null;

        if ($value === null) {
            Setting::query()->where('key', $key)->delete();
        } else {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => $this->editableKeys()[$key]['group']],
            );
        }

        $this->flush();

        if ($rates->tracks($key)) {
            // Efektif SESUDAH: nilai yang baru ditulis, atau default pabrik
            // bila reset. Dihitung, bukan dibaca ulang lewat get() — set()
            // meninggalkan cache dingin, dan pembacaan di sini akan diam-diam
            // menghangatkannya kembali (SettingServiceTest memaku itu).
            $rates->record($key, $oldEffective, $value ?? $this->default($key), Auth::id());
        }
    }

    /**
     * Apply many overrides at once.
     *
     * Every value is validated before any of them is written, so a batch whose
     * fourth key is malformed cannot leave the first three applied. The HTTP
     * path wraps this in a transaction as well; a direct service caller gets the
     * guarantee without one.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assertValid((string) $key, $value);
        }

        foreach ($values as $key => $value) {
            $this->set((string) $key, $value);
        }
    }

    /**
     * Refuse a key outside the registry, or a value its registry entry rejects.
     *
     * The rules are the same array validationRules() hands the FormRequest, so
     * the service and the HTTP layer cannot drift: anything the API accepts this
     * accepts, and the reverse. What stays HTTP-only is documented on
     * validationRules().
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(string $key, mixed $value): void
    {
        $definition = $this->editableKeys()[$key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Setting [{$key}] is not editable.");
        }

        // null is "reset to the shipped default"; every rule set is nullable.
        if ($value === null) {
            return;
        }

        $validator = Validator::make(['value' => $value], ['value' => $this->rulesFor($definition)]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(sprintf(
                'Setting [%s] rejected value %s: %s',
                $key,
                $this->describe($value),
                implode(' ', $validator->errors()->all()),
            ));
        }
    }

    /**
     * The offending value, rendered for a log line.
     *
     * json_encode rather than var_export: a rejected value may contain the very
     * newline or control character that got it rejected, and one refusal must
     * not become several log lines. Truncated, because a value may be long.
     */
    private function describe(mixed $value): string
    {
        if (! is_scalar($value)) {
            return get_debug_type($value);
        }

        return Str::limit((string) json_encode($value), 80);
    }

    /**
     * Every stored override that its own registry entry would refuse today.
     *
     * An installation may hold a row written before the rule that governs it
     * existed, because set() used to check editability and nothing else:
     * documents.PO = 'PO-{N4}' could be stored through the service API and would
     * then sit there invisibly until the January it breaks numbering. Such a row
     * needs a way to be found. This reports; it never repairs and never deletes,
     * because the value is operator data and only an operator knows what it was
     * meant to be. (The dataset shipped in database/database.sqlite holds no
     * stored overrides at all, so it has none of these.)
     *
     * Read straight from core_settings rather than through the memo: a health
     * check must see what is stored, not what a cache happens to hold.
     *
     * @return list<array{key: string, value: mixed, group: string|null, reason: string}>
     */
    public function invalidOverrides(): array
    {
        if (! Schema::hasTable('core_settings')) {
            return [];
        }

        $editable = $this->editableKeys();
        $problems = [];

        foreach (Setting::query()->orderBy('key')->get() as $row) {
            $key = (string) $row->key;

            if (! array_key_exists($key, $editable)) {
                $problems[] = [
                    'key' => $key,
                    'value' => $row->value,
                    'group' => $row->group,
                    'reason' => "Setting [{$key}] is not editable.",
                ];

                continue;
            }

            try {
                $this->assertValid($key, $row->value);
            } catch (InvalidArgumentException $e) {
                $problems[] = [
                    'key' => $key,
                    'value' => $row->value,
                    'group' => $row->group,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $problems;
    }

    /**
     * Invalidate this instance's memo and the shared cache entry.
     *
     * On a store shared between processes the forget is what carries a write
     * across the process boundary: the entry is gone, so every process's next
     * unit of work reloads from core_settings. It does NOT reach a memo already
     * held by another live instance — that memo is bounded by the scoped binding
     * instead, i.e. by the end of the request or job holding it.
     */
    public function flush(): void
    {
        $this->overrides = null;

        Cache::forget(self::CACHE_KEY);

        // Housekeeping only, and cheap: writes are an operator saving a screen,
        // not a hot path. Nothing reads the legacy key any more, so this exists
        // purely so an upgraded installation does not carry an entry an earlier
        // build left there — possibly without a TTL — for the rest of its life.
        Cache::forget(self::LEGACY_CACHE_KEY);
    }

    /**
     * Every editable parameter with its current and default value, grouped for
     * the settings screen.
     */
    public function overview(): array
    {
        $groups = [];

        foreach ($this->definitions() as $groupKey => $group) {
            $settings = [];

            foreach ($group['settings'] as $definition) {
                $key = $definition['key'];

                $settings[] = array_merge($definition, [
                    'group' => $groupKey,
                    'value' => $this->get($key),
                    'default' => $this->default($key),
                    'is_overridden' => $this->isOverridden($key),
                ]);
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'description' => $group['description'],
                'settings' => $settings,
            ];
        }

        return $groups;
    }

    /**
     * The override map, resolved through three layers.
     *
     *  1. a per-instance memo, so a request reading 60 parameters costs one
     *     lookup rather than 60;
     *  2. the cache store, under one stable key;
     *  3. the core_settings table — at most one row per editable key, so the
     *     fallback is a single SELECT of a few dozen tiny rows, not a scan.
     *
     * Nothing is re-validated per lookup. Freshness comes from the fact that the
     * instance holding the memo is scoped: the container drops it at every unit
     * of work boundary, so the next request or job starts at layer 2 and sees
     * whatever the last write left there. Re-checking a shared stamp on every
     * lookup bought nothing that the scoped binding does not already give, and
     * cost one cache read — under CACHE_STORE=database, one DB query — per
     * parameter: 2,400 queries for a 200-payslip payroll run.
     *
     * A unit of work therefore reads one consistent snapshot of the parameters
     * from beginning to end. For a payroll or invoicing run that is the property
     * you want; per-lookup re-validation gave the opposite, letting one run
     * compute half its payslips at the old rate and half at the new one.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->overrides = $cached;
        }

        // The table is absent while the very first migration batch runs. Never
        // cache OR memoise that: it is a transient state of the schema, not of
        // the data, and the migrate command is one long-lived process that
        // outlives the transition.
        if (! Schema::hasTable('core_settings')) {
            return [];
        }

        $map = Setting::query()->pluck('value', 'key')->all();

        Cache::put(self::CACHE_KEY, $map, self::CACHE_TTL);

        return $this->overrides = $map;
    }

    /**
     * A document format must carry BOTH a sequence token and the year, and may
     * be built only out of known tokens and a safe literal alphabet.
     *
     * Required tokens. NumberSequence is keyed on [type, year], so the counter
     * restarts at 1 every January. A format such as 'PO-{N4}' therefore produces
     * PO-0001 again on 1 January of the following year and collides with the
     * previous year's code on the unique code column — the document simply
     * cannot be saved (M6). Requiring {Y} is the only thing that makes the reset
     * safe. The two lookaheads (rather than a linear pattern) let the tokens
     * appear in any order.
     *
     * Anchoring. \A … \z, not ^ … $: `$` also matches immediately before a final
     * newline, so 'PO/{Y}/{N4}\nEVIL-LINE' satisfied the old pattern and would
     * have been stored — and rendered — as a two-line document code.
     *
     * Alphabet. The body alternation admits either one literal character from a
     * conservative set (letters, digits, / . _ - and space, all of which are safe
     * in a code column, a filename and a URL path) or one of the tokens
     * DocumentNumberService actually substitutes. That is what refuses
     * 'PO/{Y}/{N4}<script>alert(1)</script>' — a document code is echoed in the
     * UI and in printed documents, and markup has no business in one. It also
     * refuses an invented token such as {FOO}, which would otherwise survive
     * substitution verbatim and appear in every code of that type.
     *
     * The operator-facing explanation lives in UpdateSettingsRequest::messages()
     * under the generic 'regex' key — document_format is the only rule in this
     * registry that uses regex.
     */
    public const DOCUMENT_FORMAT_PATTERN =
        '/\A(?=.*\{Y\})(?=.*\{N[345]\})(?:[A-Za-z0-9\/._ -]|\{(?:Y|M2|RM|N[345]|PROJ)\})+\z/';

    /**
     * Validation rules for a bulk update, derived from the registry so the API
     * and the UI can never drift apart.
     *
     * @return array<string, mixed>
     */
    public function validationRules(): array
    {
        $rules = ['settings' => ['required', 'array', 'min:1']];

        foreach ($this->editableKeys() as $key => $definition) {
            // Escaped, because the setting keys themselves contain dots.
            $rules['settings.'.str_replace('.', '\.', $key)] = $this->rulesFor($definition);
        }

        return $rules;
    }

    /**
     * The rule list for one registry entry.
     *
     * Single source for both ends: validationRules() decorates these with the
     * field names the HTTP layer needs, assertValid() applies them to one value.
     * Every type in the registry is enforced here, so the service refuses a bad
     * percent, integer, select option, boolean, account code, free string or
     * document format on its own — no caller can bypass a rule by going around
     * the controller.
     *
     * Two checks deliberately stay in UpdateSettingsRequest, because neither is
     * expressible from Core:
     *
     *  - an account code must name a postable row of fin_accounts. Core sits
     *    above every module in the dependency graph (ARCHITECTURE.md: every
     *    module depends on Core, never the reverse) and Finance is optional, so
     *    Core must not query fin_accounts. The type check — a string of at most
     *    20 characters — is enforced here; existence and postability are checked
     *    where the Finance tables are legitimately visible.
     *  - the friendly Indonesian, field-attributed error messages. That is a UX
     *    concern; the exception this service throws is for programmers.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function rulesFor(array $definition): array
    {
        return match ($definition['type']) {
            'percent' => ['nullable', 'numeric', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? 100)],
            'currency' => ['nullable', 'numeric', 'min:0'],
            'integer' => ['nullable', 'integer', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? 1000000)],
            'select' => ['nullable', 'in:'.implode(',', Arr::pluck($definition['options'] ?? [], 'value'))],
            // notifications.email_enabled and approvals.segregation_of_duties.
            // Without this arm a checkbox would fall through to the default and
            // be validated as a string, so "false" would store as truthy text.
            'boolean' => ['nullable', 'boolean'],
            'account' => ['nullable', 'string', 'max:20'],
            'document_format' => ['nullable', 'string', 'max:60', 'regex:'.self::DOCUMENT_FORMAT_PATTERN],
            default => ['nullable', 'string', 'max:255'],
        };
    }
}
