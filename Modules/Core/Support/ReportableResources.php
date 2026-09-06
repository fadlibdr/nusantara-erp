<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Resource yang boleh dilaporkan "Laporan Bebas" (Fase 1 / P1-F, T4.2).
 *
 * Dalam selera ModuleCounts / WatchedDeadlines / UserPreferences / SpaWidgets:
 * SATU daftar deklaratif, jadi resource berikutnya adalah satu entri array —
 * tidak pernah endpoint baru, tidak pernah kolom baru. TIGA ATURAN yang sama
 * seperti saudara-saudaranya:
 *
 *  - DB::table, literal string, tanpa mengimpor modul fitur. Core adalah modul
 *    yang dipakai semua modul lain; `use Modules\Finance\Enums\CostCategory` di
 *    sini membalik arah ketergantungan itu. Nama tabel dan nama kolom di bawah
 *    DIPAKU ReportableResourcesTest terhadap skema hidup, jadi penggantian nama
 *    di lane tim lain menjatuhkan uji — bukan diam-diam mengosongkan laporan.
 *  - DEGRADASI PER ENTRI. Tabel belum ada (dua tim lain sedang bermigrasi di
 *    repositori ini, dan itu normal) → entri TIDAK ADA di katalog. Izin tidak
 *    dipegang → entri TIDAK ADA. Tidak pernah 500, dan tidak pernah laporan
 *    kosong yang tampak seperti "memang tidak ada datanya".
 *  - SATU KUERI per jalankan. Tidak ada join, tidak ada subkueri, tidak ada
 *    kolom turunan yang butuh model.
 *
 * KENAPA WHITELIST DAN BUKAN "jalankan apa yang dikirim klien". Endpoint
 * `POST core/reports/run` menerima nama resource, nama kolom, dimensi, agregat
 * dan saringan dari peramban. Tanpa daftar ini, itu adalah permukaan injeksi
 * SQL berbentuk fitur laporan. Dengan daftar ini, yang bisa ditanyakan
 * seseorang persis yang tertulis di bawah — dan yang tertulis di bawah adalah
 * kolom layar daftar yang ia sudah boleh lihat.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * "KOLOM = KOLOM LAYAR DAFTAR", DAN KENAPA ITU TIDAK BISA HARFIAH
 *
 * ROADMAP menuliskan syaratnya "kolom = kolom layar daftar — dipaku uji". Bila
 * dibaca harfiah sebagai "salin `columns[]` dari schema.js", syarat itu SALAH
 * untuk 59 dari 90 layar daftar: dua pertiga katalog memuat jalur relasi
 * (`vendor.name`) atau medan yang dihitung kelas Resource (`outstanding`,
 * `project_code`, `is_current`) — bukan kolom tabel, jadi tidak ada satu kueri
 * pun yang bisa memproduksinya.
 *
 * Maka registri ini memenuhinya dalam satu-satunya bentuk yang bisa BENAR dan
 * bisa DIPAKU: `columns` dikunci dengan kunci kolom layar daftar, DALAM URUTAN
 * LAYAR, dan SETIAP kunci hadir — termasuk yang tidak bisa dilayani SQL. Setiap
 * kunci berakhir di salah satu dari tiga nasib, dan tidak ada nasib keempat:
 *
 *   1. DIPETAKAN   — `select` menunjuk kolom tabel dasar dengan nama yang sama.
 *   2. DIGANTIKAN  — `select` menunjuk kolom lain yang membawa arti yang sama
 *                    (`customer.name` → `customer_id` + `lookup`), dan SPA
 *                    menuliskan labelnya dengan `labelFor()` — fungsi yang SAMA
 *                    dengan yang dipakai layar daftarnya.
 *   3. DITOLAK     — tanpa `select`, dengan `why_not`: satu kalimat yang dibaca
 *                    orangnya di pemilih kolom, di tempat ia mencari kolom itu.
 *
 * ReportableResourcesTest memaku kesetaraan kunci DAN urutannya terhadap
 * schema.js di kedua arah, jadi kolom yang ditambahkan ke layar tanpa
 * diputuskan di sini menjatuhkan uji.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Kunci per entri:
 *   label        — nama resource-nya, apa adanya (sama dengan label layar).
 *   permission   — izin yang HARUS dipegang; DAFTAR berarti "salah satu cukup"
 *                  (`def.viewPerm` di schema.js pun boleh larik). Literal:
 *                  menurunkannya dari namespace memberi 'finance.view', izin
 *                  yang tidak pernah dicetak PermissionSeeder — tidak ada galat,
 *                  entri hanya tak terlihat bagi semua orang termasuk admin.
 *   table        — satu tabel. Laporan tidak pernah menyentuh tabel kedua.
 *   soft_deletes — apakah tabelnya punya deleted_at. DB::table MELEWATI scope
 *                  SoftDeletes, jadi nilai ini yang menentukan apakah runner
 *                  menambahkan whereNull — dan ia DIPAKU sama dengan skema
 *                  hidup, karena menebak salah berarti laporan menghitung
 *                  dokumen yang sudah dibuang (true yang salah) atau 500 (false
 *                  yang salah).
 *   date_column  — kolom jendela tanggal, SAMA dengan `dateColumn:` yang
 *                  dideklarasikan controller layar itu (dipaku uji terhadap
 *                  meta endpoint-nya sendiri); null = layar itu memang tidak
 *                  menawarkan jendela tanggal, dan laporannya juga tidak.
 *   columns      — di atas.
 *   filters      — saringan yang boleh dipakai; KUNCInya identifier dari sini,
 *                  NILAInya selalu binding.
 *   why          — kenapa resource INI yang masuk delapan, dan resource mana
 *                  yang digesernya.
 *
 * `enum` WAJIB pada kolom berjenis enum ATAU status. Tanpa itu layar menulis
 * nilai mentah basis data ('approved', 'available') di tempat layar daftarnya
 * menulis 'Disetujui' dan 'Tersedia' — layar daftar mendapatkannya dari
 * `status_label` yang dikirim kelas Resource-nya, dan laporan ini tidak lewat
 * Resource sama sekali. Dipaku ReportableResourcesTest.
 *
 * `dimension`: false | 'value' (enum/status/teks kardinalitas rendah) | 'key'
 * (FK integer, dilabeli lewat lookup) | 'date' (boleh diember day|month|year).
 * `measure`: true hanya untuk kolom yang PENJUMLAHANNYA berarti — uang dan
 * hitungan. Persen dan progres sengaja false: menjumlahkan persen tidak
 * menghasilkan persen, dan rata-ratanya pun bukan rata-rata berbobot.
 *
 * @phpstan-type Column array{label: string, type: string, select?: string, lookup?: string, enum?: string, dimension: string|false, measure: bool, buckets?: list<string>, why_not?: string}
 * @phpstan-type Entry array{label: string, permission: list<string>, table: string, soft_deletes: bool, date_column: ?string, columns: array<string, Column>, filters: array<string, array{label: string, column: string, kind: string, lookup?: string, enum?: string}>, why: string}
 */
final class ReportableResources
{
    /** @var array<string, bool> */
    private static array $schemaMemo = [];

    /** Kolom kode dokumen: satu kelompok per baris, jadi tidak pernah dimensi. */
    private const CODE_NOT_A_DIMENSION = 'Kode unik per dokumen: mengelompokkan menurutnya memberi satu kelompok per baris, '
        .'dan laporan itu adalah layar daftarnya sendiri.';

    /** Teks bebas: kardinalitasnya tak terbatas, jadi bukan dimensi. */
    private const FREE_TEXT_NOT_A_DIMENSION = 'Teks bebas: hampir setiap baris berbeda, jadi mengelompokkan menurutnya '
        .'menabrak plafon 200 kelompok tanpa memberi tahu apa pun.';

    /**
     * Delapan resource (keputusan pemilik ledger #4), dikunci dengan kunci
     * RESOURCES schema.js.
     *
     * @return array<string, Entry>
     */
    public static function entries(): array
    {
        return [
            'crm/contracts' => [
                'label' => 'Kontrak',
                'permission' => ['crm.view'],
                'table' => 'crm_contracts',
                'soft_deletes' => true,
                'date_column' => 'sign_date',
                'columns' => [
                    'code' => ['label' => 'Kode', 'type' => 'code', 'select' => 'code',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    /*
                     * Kolom layar ini membawa `sub: 'customer.name'`, dan yang
                     * dipetakan di sini adalah JUDULnya saja (nasib 1) — bukan
                     * nasib 2. Penggantian "kolom relasi → kunci FK-nya" yang
                     * ditulis CONVENTIONS §18 terjadi di fin/ar-invoices, di
                     * mana kolom LAYARnya sendiri bernama `customer.name`;
                     * di sini kolom layarnya bernama `title`, dan menggantinya
                     * dengan customer_id berarti kolom berlabel "Judul" yang
                     * mengembalikan pelanggan.
                     *
                     * Akibatnya "nilai kontrak per pelanggan" TIDAK tersedia
                     * dari sumber ini; pelanggan masuk hanya sebagai saringan
                     * di bawah. Itu harga dari aturan "kolom = kolom layar
                     * daftar", dan disebut di sini supaya yang mencarinya
                     * berhenti mencari (verifikasi kedua P1-F: sampai 6 Sep
                     * 2026 komentar ini menjanjikan penggantian yang tidak
                     * pernah dilakukan entri ini).
                     */
                    'title' => ['label' => 'Judul', 'type' => 'text', 'select' => 'title',
                        'dimension' => false, 'measure' => false, 'why_not' => self::FREE_TEXT_NOT_A_DIMENSION],
                    'scope_type' => ['label' => 'Lingkup', 'type' => 'enum', 'enum' => 'scopeType',
                        'select' => 'scope_type', 'dimension' => 'value', 'measure' => false],
                    'sign_date' => ['label' => 'Tgl TTD', 'type' => 'date', 'select' => 'sign_date',
                        'dimension' => 'date', 'buckets' => ['day', 'month', 'year'], 'measure' => false],
                    'value' => ['label' => 'Nilai (DPP)', 'type' => 'currency', 'select' => 'value',
                        'dimension' => false, 'measure' => true],
                    'status' => ['label' => 'Status', 'type' => 'status', 'enum' => 'documentStatus',
                        'select' => 'status', 'dimension' => 'value', 'measure' => false],
                ],
                'filters' => [
                    'customer_id' => ['label' => 'Pelanggan', 'column' => 'customer_id', 'kind' => 'key', 'lookup' => 'customers'],
                    'scope_type' => ['label' => 'Lingkup', 'column' => 'scope_type', 'kind' => 'enum', 'enum' => 'scopeType'],
                    'status' => ['label' => 'Status', 'column' => 'status', 'kind' => 'enum', 'enum' => 'documentStatus'],
                ],
                'why' => 'Buku pesanan: nilai kontrak per lingkup per bulan tanda tangan adalah laporan yang ditanyakan '
                    .'direktur dengan namanya sendiri, dan keenam kolom layarnya kolom tabel dasar kecuali satu sub. '
                    .'Menggeser crm/quotations, yang nilainya belum tentu jadi pekerjaan.',
            ],

            'projects' => [
                'label' => 'Proyek',
                'permission' => ['prj.view'],
                'table' => 'prj_projects',
                'soft_deletes' => true,
                // Layar Proyek tidak menawarkan jendela tanggal (ProjectController
                // memanggil listing() tanpa dateColumn), jadi laporannya juga tidak.
                'date_column' => null,
                'columns' => [
                    'code' => ['label' => 'Kode', 'type' => 'code', 'select' => 'code',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    'name' => ['label' => 'Nama proyek', 'type' => 'text', 'select' => 'name',
                        'dimension' => false, 'measure' => false, 'why_not' => self::FREE_TEXT_NOT_A_DIMENSION],
                    'type' => ['label' => 'Jenis', 'type' => 'enum', 'enum' => 'projectType', 'select' => 'type',
                        'dimension' => 'value', 'measure' => false],
                    'contract_value' => ['label' => 'Nilai kontrak', 'type' => 'currency', 'select' => 'contract_value',
                        'dimension' => false, 'measure' => true],
                    /*
                     * Progres BUKAN ukuran. Menjumlahkan persen tiga proyek
                     * memberi 180 %, dan merata-ratakannya memberi angka yang
                     * memperlakukan proyek Rp 40 M sama beratnya dengan proyek
                     * Rp 400 jt — persis kesalahan yang kartu EVM ada untuk
                     * menghindarinya.
                     */
                    'actual_progress_pct' => ['label' => 'Progres', 'type' => 'progress', 'select' => 'actual_progress_pct',
                        'dimension' => false, 'measure' => false,
                        'why_not' => 'Persen tidak bisa dijumlahkan, dan rata-rata sederhananya memperlakukan proyek '
                            .'Rp 40 M sama beratnya dengan proyek Rp 400 jt. Pakai EVM untuk kinerja portofolio.'],
                    'status' => ['label' => 'Status', 'type' => 'status', 'enum' => 'projectStatus',
                        'select' => 'status', 'dimension' => 'value', 'measure' => false],
                ],
                'filters' => [
                    'status' => ['label' => 'Status', 'column' => 'status', 'kind' => 'enum', 'enum' => 'projectStatus'],
                    'type' => ['label' => 'Jenis', 'column' => 'type', 'kind' => 'enum', 'enum' => 'projectType'],
                    'customer_id' => ['label' => 'Pelanggan', 'column' => 'customer_id', 'kind' => 'key', 'lookup' => 'customers'],
                    'province' => ['label' => 'Provinsi', 'column' => 'province', 'kind' => 'text'],
                ],
                'why' => 'Dimensi yang dipakai setiap modul lain untuk mengiris dirinya, dan satu-satunya layar di '
                    .'katalog tanpa satu pun kolom relasi maupun kolom hitungan: nilai kontrak per jenis per status '
                    .'adalah salinan kolom yang harfiah.',
            ],

            'finance/ar-invoices' => [
                'label' => 'Invoice Termin (AR)',
                'permission' => ['fin.view'],
                'table' => 'fin_ar_invoices',
                'soft_deletes' => true,
                'date_column' => 'invoice_date',
                'columns' => [
                    'code' => ['label' => 'Kode', 'type' => 'code', 'select' => 'code',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    'customer.name' => ['label' => 'Pelanggan', 'type' => 'rel', 'lookup' => 'customers',
                        'select' => 'customer_id', 'dimension' => 'key', 'measure' => false],
                    'invoice_date' => ['label' => 'Tgl invoice', 'type' => 'date', 'select' => 'invoice_date',
                        'dimension' => 'date', 'buckets' => ['day', 'month', 'year'], 'measure' => false],
                    'due_date' => ['label' => 'Jatuh tempo', 'type' => 'date', 'select' => 'due_date',
                        'dimension' => 'date', 'buckets' => ['day', 'month', 'year'], 'measure' => false],
                    'total' => ['label' => 'Total', 'type' => 'currency', 'select' => 'total',
                        'dimension' => false, 'measure' => true],
                    /*
                     * DITOLAK, dan ini penolakan yang paling penting di berkas
                     * ini. `outstanding` BUKAN `total - amount_paid`:
                     * ArInvoice::outstanding() mengembalikan 0 untuk invoice
                     * yang DIBATALKAN, dan selisih naifnya menaruh angka penuh
                     * di sebelah lencana "Dibatalkan" lalu mengirim penagihan
                     * mengejar uang yang sudah tidak ada. Aturan itu punya
                     * pemilik (modelnya), dan menyalinnya ke sini sebagai
                     * ekspresi SQL adalah salinan kedua yang bisa berselisih —
                     * penyimpangan yang paling mahal menurut CONVENTIONS §16.
                     */
                    'outstanding' => ['label' => 'Sisa', 'type' => 'currency',
                        'dimension' => false, 'measure' => false,
                        'why_not' => 'Sisa tagihan bukan "total dikurangi dibayar": invoice yang dibatalkan sisanya nol, '
                            .'dan aturan itu tinggal di modelnya. Laporan ini menawarkan Total dan menyaring per Status; '
                            .'untuk umur piutang pakai Keuangan › Laporan › Umur Piutang, yang menghitungnya di server.'],
                    'status' => ['label' => 'Status', 'type' => 'status', 'enum' => 'documentStatus',
                        'select' => 'status', 'dimension' => 'value', 'measure' => false],
                ],
                'filters' => [
                    'customer_id' => ['label' => 'Pelanggan', 'column' => 'customer_id', 'kind' => 'key', 'lookup' => 'customers'],
                    'project_id' => ['label' => 'Proyek', 'column' => 'project_id', 'kind' => 'key', 'lookup' => 'projects'],
                    'status' => ['label' => 'Status', 'column' => 'status', 'kind' => 'enum', 'enum' => 'documentStatus'],
                ],
                'why' => 'Invoice per pelanggan per bulan adalah laporan lintas peran bernilai tertinggi di sistem ini, '
                    .'dan ia dikirim BERSAMA penolakan kolom Sisa-nya — sebuah katalog yang menyembunyikan bahwa ia '
                    .'tidak bisa menjawab "sisa piutang" lebih buruk daripada yang mengatakannya.',
            ],

            'finance/project-costs' => [
                'label' => 'Biaya Proyek',
                'permission' => ['fin.view'],
                'table' => 'fin_project_costs',
                // SATU-SATUNYA dari delapan yang tabelnya TIDAK punya deleted_at.
                // false di sini bukan kelalaian, dan uji memakunya terhadap skema.
                'soft_deletes' => false,
                'date_column' => 'cost_date',
                'columns' => [
                    'cost_date' => ['label' => 'Tanggal', 'type' => 'date', 'select' => 'cost_date',
                        'dimension' => 'date', 'buckets' => ['day', 'month', 'year'], 'measure' => false],
                    'project_id' => ['label' => 'Proyek', 'type' => 'rel', 'lookup' => 'projects',
                        'select' => 'project_id', 'dimension' => 'key', 'measure' => false],
                    'cost_category' => ['label' => 'Kategori', 'type' => 'enum', 'enum' => 'costCategory',
                        'select' => 'cost_category', 'dimension' => 'value', 'measure' => false],
                    'description' => ['label' => 'Keterangan', 'type' => 'text', 'select' => 'description',
                        'dimension' => false, 'measure' => false, 'why_not' => self::FREE_TEXT_NOT_A_DIMENSION],
                    'reference_type' => ['label' => 'Sumber', 'type' => 'text', 'select' => 'reference_type',
                        'dimension' => 'value', 'measure' => false],
                    'amount' => ['label' => 'Jumlah', 'type' => 'currency', 'select' => 'amount',
                        'dimension' => false, 'measure' => true],
                ],
                'filters' => [
                    'project_id' => ['label' => 'Proyek', 'column' => 'project_id', 'kind' => 'key', 'lookup' => 'projects'],
                    'cost_category' => ['label' => 'Kategori', 'column' => 'cost_category', 'kind' => 'enum', 'enum' => 'costCategory'],
                ],
                'why' => 'Satu-satunya tabel fakta murni di katalog — satu kolom uang, satu tanggal, satu enum, satu '
                    .'kunci proyek, nol kolom turunan — sehingga "biaya per kategori per bulan untuk satu proyek" '
                    .'dijawab tanpa satu pun kompromi. Inilah entri yang membuktikan fiturnya bekerja.',
            ],

            'procurement/purchase-orders' => [
                'label' => 'Pesanan Pembelian (PO)',
                'permission' => ['prc.view'],
                'table' => 'prc_purchase_orders',
                'soft_deletes' => true,
                'date_column' => 'order_date',
                'columns' => [
                    'code' => ['label' => 'Kode', 'type' => 'code', 'select' => 'code',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    'vendor.name' => ['label' => 'Vendor', 'type' => 'rel', 'lookup' => 'vendors',
                        'select' => 'vendor_id', 'dimension' => 'key', 'measure' => false],
                    'project_id' => ['label' => 'Proyek', 'type' => 'rel', 'lookup' => 'projects',
                        'select' => 'project_id', 'dimension' => 'key', 'measure' => false],
                    'order_date' => ['label' => 'Tgl PO', 'type' => 'date', 'select' => 'order_date',
                        'dimension' => 'date', 'buckets' => ['day', 'month', 'year'], 'measure' => false],
                    'total' => ['label' => 'Total', 'type' => 'currency', 'select' => 'total',
                        'dimension' => false, 'measure' => true],
                    'status' => ['label' => 'Status', 'type' => 'status', 'enum' => 'documentStatus',
                        'select' => 'status', 'dimension' => 'value', 'measure' => false],
                ],
                'filters' => [
                    'vendor_id' => ['label' => 'Vendor', 'column' => 'vendor_id', 'kind' => 'key', 'lookup' => 'vendors'],
                    'project_id' => ['label' => 'Proyek', 'column' => 'project_id', 'kind' => 'key', 'lookup' => 'projects'],
                    'status' => ['label' => 'Status', 'column' => 'status', 'kind' => 'enum', 'enum' => 'documentStatus'],
                ],
                'why' => 'Belanja per vendor per bulan, dan ia menjangkau audiens yang tidak punya fin.view sama sekali: '
                    .'tanpa entri ini staf pengadaan tidak punya satu pun laporan uang.',
            ],

            'subcontract/subcontracts' => [
                'label' => 'SPK Subkontraktor',
                'permission' => ['scm.view'],
                'table' => 'scm_subcontracts',
                'soft_deletes' => true,
                'date_column' => 'start_date',
                'columns' => [
                    'code' => ['label' => 'Kode', 'type' => 'code', 'select' => 'code',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    'title' => ['label' => 'Pekerjaan', 'type' => 'text', 'select' => 'title',
                        'dimension' => false, 'measure' => false, 'why_not' => self::FREE_TEXT_NOT_A_DIMENSION],
                    'project_id' => ['label' => 'Proyek', 'type' => 'rel', 'lookup' => 'projects',
                        'select' => 'project_id', 'dimension' => 'key', 'measure' => false],
                    'value' => ['label' => 'Nilai SPK', 'type' => 'currency', 'select' => 'value',
                        'dimension' => false, 'measure' => true],
                    'pph_rate' => ['label' => 'PPh final', 'type' => 'percent', 'select' => 'pph_rate',
                        'dimension' => 'value', 'measure' => false,
                        'why_not' => 'Tarif pajak boleh DIKELOMPOKKAN (ada beberapa tarif saja), tetapi tidak boleh '
                            .'dijumlahkan: jumlah tarif bukan tarif.'],
                    'status' => ['label' => 'Status', 'type' => 'status', 'enum' => 'documentStatus',
                        'select' => 'status', 'dimension' => 'value', 'measure' => false],
                ],
                'filters' => [
                    'project_id' => ['label' => 'Proyek', 'column' => 'project_id', 'kind' => 'key', 'lookup' => 'projects'],
                    'vendor_id' => ['label' => 'Subkontraktor', 'column' => 'vendor_id', 'kind' => 'key', 'lookup' => 'vendors'],
                    'status' => ['label' => 'Status', 'column' => 'status', 'kind' => 'enum', 'enum' => 'documentStatus'],
                ],
                'why' => 'Komitmen SPK per proyek per skema PPh: satu kolom uang dan satu enum pajak, keduanya kolom '
                    .'tabel dasar, dan satu-satunya layar Subkontrak yang memenuhi syarat.',
            ],

            'hr/employees' => [
                'label' => 'Karyawan',
                'permission' => ['hr.view'],
                'table' => 'hr_employees',
                'soft_deletes' => true,
                // Layar Karyawan sengaja tanpa jendela tanggal (EmployeeController
                // menuliskan alasannya: join_date tidak dirender di daftarnya).
                'date_column' => null,
                'columns' => [
                    'code' => ['label' => 'Kode', 'type' => 'code', 'select' => 'code',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    'name' => ['label' => 'Nama', 'type' => 'text', 'select' => 'name',
                        'dimension' => false, 'measure' => false, 'why_not' => self::FREE_TEXT_NOT_A_DIMENSION],
                    'department' => ['label' => 'Departemen', 'type' => 'enum', 'enum' => 'department',
                        'select' => 'department', 'dimension' => 'value', 'measure' => false],
                    'employment_type' => ['label' => 'Status kerja', 'type' => 'enum', 'enum' => 'employmentType',
                        'select' => 'employment_type', 'dimension' => 'value', 'measure' => false],
                    'ptkp_status' => ['label' => 'PTKP', 'type' => 'text', 'select' => 'ptkp_status',
                        'dimension' => 'value', 'measure' => false],
                    'base_salary' => ['label' => 'Gaji pokok', 'type' => 'currency', 'select' => 'base_salary',
                        'dimension' => false, 'measure' => true],
                    'status' => ['label' => 'Status', 'type' => 'status', 'enum' => 'employeeStatus',
                        'select' => 'status', 'dimension' => 'value', 'measure' => false],
                ],
                'filters' => [
                    'department' => ['label' => 'Departemen', 'column' => 'department', 'kind' => 'enum', 'enum' => 'department'],
                    'employment_type' => ['label' => 'Status kerja', 'column' => 'employment_type', 'kind' => 'enum', 'enum' => 'employmentType'],
                    'status' => ['label' => 'Status', 'column' => 'status', 'kind' => 'enum', 'enum' => 'employeeStatus'],
                ],
                'why' => 'Jumlah karyawan dan gaji pokok per departemen × status kerja: tiga enum nyata di satu tabel, '
                    .'melayani SDM, Keuangan dan direktur dari satu entri. Satu-satunya entri tanpa dimensi periode, '
                    .'dan itu mencerminkan layarnya.',
            ],

            'assets/assets' => [
                'label' => 'Aset',
                'permission' => ['ast.view'],
                'table' => 'ast_assets',
                'soft_deletes' => true,
                'date_column' => 'acquisition_date',
                'columns' => [
                    'code' => ['label' => 'Kode', 'type' => 'code', 'select' => 'code',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    'name' => ['label' => 'Nama aset', 'type' => 'text', 'select' => 'name',
                        'dimension' => false, 'measure' => false, 'why_not' => self::FREE_TEXT_NOT_A_DIMENSION],
                    'ownership' => ['label' => 'Kepemilikan', 'type' => 'enum', 'enum' => 'assetOwnership',
                        'select' => 'ownership', 'dimension' => 'value', 'measure' => false],
                    'serial_no' => ['label' => 'No. seri', 'type' => 'code', 'select' => 'serial_no',
                        'dimension' => false, 'measure' => false, 'why_not' => self::CODE_NOT_A_DIMENSION],
                    'acquisition_cost' => ['label' => 'Harga perolehan', 'type' => 'currency', 'select' => 'acquisition_cost',
                        'dimension' => false, 'measure' => true],
                    /*
                     * Nilai buku alat SEWA adalah NULL, bukan nol — alat itu
                     * tidak ada di neraca kita, dan layarnya menuliskannya '—'.
                     * SUM atas kelompok yang seluruhnya sewa karena itu
                     * mengembalikan NULL, dan runner meneruskannya sebagai null
                     * dengan hitungan barisnya di sebelahnya: "ada barisnya,
                     * angkanya tidak ada". Menulis 0 di sana menaruh alat sewa
                     * di neraca.
                     */
                    'book_value' => ['label' => 'Nilai buku', 'type' => 'currency', 'select' => 'book_value',
                        'dimension' => false, 'measure' => true],
                    'current_project_id' => ['label' => 'Proyek', 'type' => 'rel', 'lookup' => 'projects',
                        'select' => 'current_project_id', 'dimension' => 'key', 'measure' => false],
                    'status' => ['label' => 'Status', 'type' => 'status', 'enum' => 'assetStatus',
                        'select' => 'status', 'dimension' => 'value', 'measure' => false],
                ],
                'filters' => [
                    'ownership' => ['label' => 'Kepemilikan', 'column' => 'ownership', 'kind' => 'enum', 'enum' => 'assetOwnership'],
                    'status' => ['label' => 'Status', 'column' => 'status', 'kind' => 'enum', 'enum' => 'assetStatus'],
                    'current_project_id' => ['label' => 'Proyek', 'column' => 'current_project_id', 'kind' => 'key', 'lookup' => 'projects'],
                ],
                'why' => 'Himpunan kolom polos terlebar di katalog (dua kolom uang plus dua enum), dan nilai buku NULL '
                    .'untuk alat sewa membuatnya kasus uji terbaik untuk aturan "sel kosong, bukan 0" yang menjadi '
                    .'syarat paket ini.',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::entries());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::entries());
    }

    /**
     * Entri ini ADA di katalog dan tabelnya benar-benar terpasang di server ini.
     *
     * Separuh kedua aturan degradasi registri, dalam satu tempat. Sampai
     * verifikasi kedua P1-F pemeriksaan itu ditulis ulang di dalam
     * `ReportController::run()` saja (lewat `for()`), sehingga jalur KEDUA yang
     * menjalankan kueri yang sama — XLSX laporan tersimpan — tidak memilikinya
     * dan menjawab 500 dengan pesan SQL mentah (nama berkas basis data ikut
     * tercetak) untuk sumber yang tabelnya belum dimigrasi. Yang memakai ini
     * mengubahnya menjadi kalimat.
     */
    public static function installed(string $key): bool
    {
        return self::has($key) && self::tableExists(self::definition($key)['table']);
    }

    /**
     * @return Entry
     *
     * @throws \InvalidArgumentException bila kuncinya bukan resource katalog
     */
    public static function definition(string $key): array
    {
        $entry = self::entries()[$key] ?? null;

        if ($entry === null) {
            throw new \InvalidArgumentException(sprintf('Resource laporan "%s" tidak dikenal.', $key));
        }

        return $entry;
    }

    /**
     * Katalog yang boleh dibaca $user — urut katalog. Entri yang izinnya tidak
     * dipegang atau tabelnya belum ada TIDAK MUNCUL, dengan alasan yang sama
     * seperti ModuleCounts: sebuah laporan kosong untuk tabel yang tidak ada
     * adalah kebohongan yang tampak seperti "memang tidak ada datanya".
     *
     * @return array<string, Entry>
     */
    public static function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $out = [];

        foreach (self::entries() as $key => $entry) {
            if (! self::allows($user, $entry)) {
                continue;
            }

            if (! self::tableExists($entry['table'])) {
                continue;
            }

            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Izin resource: DAFTAR yang berarti "salah satu cukup" — bentuk yang sama
     * dengan `viewPerm` larik di schema.js, yang dibaca session.can().
     *
     * @param  Entry  $entry
     */
    public static function allows(User $user, array $entry): bool
    {
        foreach ($entry['permission'] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Memo per proses, seperti ModuleCounts: uji membangun ulang skema per
     * kelas, jadi memo yang menyeberangi batas itu melaporkan tabel yang sudah
     * tidak ada — flushSchemaMemo() dipanggil ErpTestCase::setUp.
     */
    private static function tableExists(string $table): bool
    {
        if (! array_key_exists($table, self::$schemaMemo)) {
            self::$schemaMemo[$table] = Schema::hasTable($table);
        }

        return self::$schemaMemo[$table];
    }

    public static function flushSchemaMemo(): void
    {
        self::$schemaMemo = [];
    }
}
