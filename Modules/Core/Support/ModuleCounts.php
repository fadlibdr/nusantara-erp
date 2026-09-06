<?php

namespace Modules\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * SATU angka utama per modul — yang dipimpin ubin launcher `#/home` dan kepala
 * beranda modul `#/m/<prefix>` (Fase 1 / P1-C, T1C.2).
 *
 * Dalam selera WatchedDeadlines / AttachableDocuments / PrintableDocuments: satu
 * daftar deklaratif, jadi modul berikutnya adalah satu entri array. DUA ATURAN
 * yang sama seperti saudara-saudaranya:
 *
 *  - DB::table, literal string, tanpa mengimpor modul fitur. Core adalah modul
 *    yang dipakai semua modul lain; `use Modules\Quality\Enums\NcrStatus` di sini
 *    membalik arah ketergantungan itu. String status di bawah ('submitted',
 *    'open', 'under_correction', …) DIPAKU ModuleCountsTest, jadi penggantian
 *    nama di lane tim lain menjatuhkan uji, bukan diam-diam mengosongkan ubin.
 *  - DEGRADASI PER ENTRI. Tabel belum ada (dua tim lain sedang bermigrasi di
 *    repositori ini, dan itu normal) → entri TIDAK ADA. Kueri melempar → count
 *    null dengan peringatan di log. Tidak pernah 500: satu modul yang rusak
 *    tidak boleh menjatuhkan seluruh launcher.
 *
 * KENAPA "TIDAK ADA" DAN BUKAN 0. Sebuah 0 adalah pernyataan: "saya menghitung,
 * dan hasilnya nol". Untuk modul yang izinnya tidak dipegang, atau tabelnya
 * belum ada, kita tidak menghitung apa pun — dan "0 tiket terbuka" di layar
 * orang yang memang tidak boleh melihat tiket adalah kebohongan yang tampak
 * seperti kabar baik. SPA menulis '—' untuk entri yang tidak ada maupun untuk
 * count null; yang berbeda adalah apa yang kita KLAIM, bukan apa yang tampak.
 *
 * SATU KUERI PER ENTRI, dan itu batasan yang dipilih: blok ini ikut jawaban
 * dasbor (`?include=modules`) dan endpoint launcher, keduanya dibaca di ponsel
 * lapangan. Angka yang butuh join berlapis atau "baris terakhir per grup"
 * (mis. "aset jatuh tempo servis", yang aturan latest-per-asset-nya sudah
 * dimiliki WatchedDeadlines) SENGAJA tidak diambil di sini: menyalin aturan itu
 * ke tempat kedua adalah persis penyimpangan yang paling mahal.
 *
 * Kolom `deleted_at` diperiksa tangan di setiap kueri: DB::table melewati scope
 * SoftDeletes, jadi tanpa itu ubin menghitung dokumen yang sudah dibuang.
 *
 * @phpstan-type Entry array{label: string, unit: string, permission: ?string, tables: list<string>, count: callable(?User): ?int, why: string}
 */
final class ModuleCounts
{
    /** @var array<string, bool> */
    private static array $schemaMemo = [];

    /**
     * Satu entri per prefix grup NAV, DALAM URUTAN NAV (launcher menggambar
     * ubin dalam urutan ini). Kelengkapannya dipaku ModuleCountsTest terhadap
     * schema.js.
     *
     * Kunci per entri:
     *   label      — nama angkanya, apa adanya ("Proyek aktif").
     *   unit       — satuan untuk ubin ("7 proyek"); angka telanjang tidak menyebut apa.
     *   permission — izin yang HARUS dipegang; null = semua orang yang punya sesi.
     *   tables     — setiap tabel yang disentuh kueri; hilang satu → entri absen.
     *   count      — SATU kueri; null berarti "tidak tahu", tidak pernah 0 karangan.
     *   why        — kenapa INI angka utama modulnya, bukan angka lain.
     *
     * @return array<string, Entry>
     */
    public static function entries(): array
    {
        return [
            'ringkasan' => [
                'label' => 'Notifikasi belum dibaca',
                'unit' => 'notifikasi',
                'permission' => null,
                'tables' => ['core_notifications'],
                'why' => 'Ringkasan adalah modul MILIK PEMBACANYA (Dasbor, Tugas Saya, Tenggat, Kalender), jadi '
                    .'angkanya harus per orang. "Menunggu persetujuan Anda" akan lebih tepat, tetapi ApprovalQueue '
                    .'::pending memuat SETIAP dokumen submitted dari 28 registri untuk menerapkan aturan '
                    .'maker-checker — bukan satu kueri murah, dan bukan sesuatu yang boleh berjalan di setiap '
                    .'pembukaan launcher. Notifikasi belum dibaca dihitung dari indeks (user_id, read_at) yang '
                    .'memang dibuat untuk lencana lonceng.',
                'count' => static fn (?User $user): ?int => $user === null ? null : (int) DB::table('core_notifications')
                    ->where('user_id', $user->getKey())
                    ->whereNull('read_at')
                    ->count(),
            ],

            'crm' => [
                'label' => 'Prospek terbuka',
                'unit' => 'prospek',
                'permission' => 'crm.view',
                'tables' => ['crm_leads'],
                'why' => 'Pekerjaan modul Penjualan adalah memindahkan prospek maju; yang menang dan yang kalah '
                    .'sudah bukan pekerjaan. Cermin LeadStatus::isOpen() (won|lost keluar), bukan salah satu status.',
                'count' => static fn (): ?int => (int) DB::table('crm_leads')
                    ->whereNull('deleted_at')
                    ->whereNotIn('status', ['won', 'lost'])
                    ->count(),
            ],

            'est' => [
                'label' => 'RAB menunggu persetujuan',
                'unit' => 'RAB',
                'permission' => 'est.view',
                'tables' => ['est_boqs'],
                'why' => 'RAB yang tertahan di meja persetujuan menahan penawaran, PO dan RAP di belakangnya; '
                    .'jumlah AHSP atau BOQ total hanya menghitung isi lemari.',
                'count' => static fn (): ?int => (int) DB::table('est_boqs')
                    ->whereNull('deleted_at')
                    ->where('status', 'submitted')
                    ->count(),
            ],

            'eng' => [
                'label' => 'Gambar menunggu keputusan MK',
                'unit' => 'SDS',
                'permission' => 'eng.view',
                'tables' => ['eng_drawing_submittals'],
                'why' => 'Lapangan tidak boleh mulai sebelum MK menyetujui gambarnya, jadi inilah antrean yang '
                    .'menahan pekerjaan. `decision` null = belum diputus; `superseded_at` null membuang revisi '
                    .'yang sudah digantikan (register ini menandai, tidak menghapus).',
                'count' => static fn (): ?int => (int) DB::table('eng_drawing_submittals')
                    ->whereNull('deleted_at')
                    ->whereNull('decision')
                    ->whereNull('superseded_at')
                    ->count(),
            ],

            'prj' => [
                'label' => 'Proyek aktif',
                'unit' => 'proyek',
                'permission' => 'prj.view',
                'tables' => ['prj_projects'],
                'why' => 'Angka yang sama persis dengan ubin dasbor (DashboardController: status active|finishing) '
                    .'— dua layar yang berdebat tentang "berapa proyek berjalan" lebih buruk daripada satu layar '
                    .'tanpa angka. Kesetaraannya dipaku uji.',
                'count' => static fn (): ?int => (int) DB::table('prj_projects')
                    ->whereNull('deleted_at')
                    ->whereIn('status', ['active', 'finishing'])
                    ->count(),
            ],

            'qc' => [
                'label' => 'NCR terbuka',
                'unit' => 'NCR',
                'permission' => 'qc.view',
                'tables' => ['qc_ncr'],
                'why' => 'NCR terbuka MENAHAN inspeksi tahap berikutnya di lokasi itu dan menahan BAST I proyeknya '
                    .'— cermin NcrStatus::isOpen() (open|under_correction), dua string yang juga dibaca '
                    .'BastPrerequisiteService di balik Schema::hasTable karena Projects pun tidak boleh '
                    .'bergantung pada Quality.',
                'count' => static fn (): ?int => (int) DB::table('qc_ncr')
                    ->whereNull('deleted_at')
                    ->whereIn('status', ['open', 'under_correction'])
                    ->count(),
            ],

            'prc' => [
                'label' => 'PO menunggu persetujuan',
                'unit' => 'PO',
                'permission' => 'prc.view',
                'tables' => ['prc_purchase_orders'],
                'why' => '"PO terbuka" dalam arti sisa barang yang belum datang membutuhkan join ke baris PO dan '
                    .'penerimaan — layar "Baris PO Terbuka" yang memilikinya, dan menyalinnya ke sini berarti dua '
                    .'definisi "terbuka". Yang murah DAN menahan pekerjaan adalah PO yang diajukan dan belum '
                    .'diputus: selama itu barangnya tidak dipesan.',
                'count' => static fn (): ?int => (int) DB::table('prc_purchase_orders')
                    ->whereNull('deleted_at')
                    ->where('status', 'submitted')
                    ->count(),
            ],

            'inv' => [
                'label' => 'Item di bawah stok minimum',
                'unit' => 'item',
                'permission' => 'inv.view',
                'tables' => ['inv_stock_balances', 'inv_items', 'inv_warehouses'],
                'why' => 'Satu-satunya angka persediaan yang menuntut tindakan hari ini. Kueri ini adalah SALINAN '
                    .'StockService::lowStockAlerts() (per gudang × item, item nonaktif dan min 0 keluar) karena '
                    .'Core tidak boleh mengimpor Inventory — kesetaraan keduanya dipaku ModuleCountsTest, satu-'
                    .'satunya penjaga yang mungkin untuk sebuah salinan.',
                'count' => static fn (): ?int => (int) DB::table('inv_stock_balances as b')
                    ->join('inv_items as i', 'i.id', '=', 'b.item_id')
                    ->join('inv_warehouses as w', 'w.id', '=', 'b.warehouse_id')
                    ->whereNull('i.deleted_at')
                    ->whereNull('w.deleted_at')
                    ->where('i.is_active', true)
                    ->where('i.min_stock', '>', 0)
                    ->whereColumn('b.qty', '<', 'i.min_stock')
                    ->count(),
            ],

            'scm' => [
                'label' => 'Opname subkon menunggu persetujuan',
                'unit' => 'opname',
                'permission' => 'scm.view',
                'tables' => ['scm_progress_claims'],
                'why' => 'Opname yang belum disetujui menahan tagihan subkon DAN menahan BAST subkon '
                    .'(HandoverService menolak selagi ada opname yang belum diputus) — uang orang lain yang '
                    .'menunggu tanda tangan kita.',
                'count' => static fn (): ?int => (int) DB::table('scm_progress_claims')
                    ->whereNull('deleted_at')
                    ->where('status', 'submitted')
                    ->count(),
            ],

            'fin' => [
                'label' => 'Invoice termin belum lunas',
                'unit' => 'invoice',
                'permission' => 'fin.view',
                'tables' => ['fin_ar_invoices'],
                'why' => 'Sama persis dengan ubin dasbor ar_invoices.open_count (status approved, total − '
                    .'amount_paid > 0; pembatalan sudah keluar lewat status). Piutang, bukan utang: yang '
                    .'menentukan apakah gaji bulan depan terbayar adalah uang yang belum masuk.',
                'count' => static fn (): ?int => (int) DB::table('fin_ar_invoices')
                    ->whereNull('deleted_at')
                    ->where('status', 'approved')
                    ->whereRaw('total - amount_paid > 0')
                    ->count(),
            ],

            'hr' => [
                'label' => 'Cuti menunggu persetujuan',
                'unit' => 'pengajuan',
                'permission' => 'hr.view',
                'tables' => ['hr_leave_requests'],
                'why' => 'Satu-satunya antrean SDM yang orang lain tunggui jawabannya; jumlah karyawan adalah '
                    .'angka yang tidak berubah dari minggu ke minggu.',
                'count' => static fn (): ?int => (int) DB::table('hr_leave_requests')
                    ->whereNull('deleted_at')
                    ->where('status', 'submitted')
                    ->count(),
            ],

            'svc' => [
                'label' => 'Tiket belum selesai',
                'unit' => 'tiket',
                'permission' => 'svc.view',
                'tables' => ['svc_tickets'],
                'why' => 'Empat status sebelum resolved (open|assigned|in_progress|pending_customer): tiket yang '
                    .'menunggu pelanggan tetap milik kita sampai ia menjawab, jadi ia ikut dihitung; resolved dan '
                    .'closed tidak.',
                'count' => static fn (): ?int => (int) DB::table('svc_tickets')
                    ->whereNull('deleted_at')
                    ->whereIn('status', ['open', 'assigned', 'in_progress', 'pending_customer'])
                    ->count(),
            ],

            'ast' => [
                'label' => 'Aset dalam perawatan',
                'unit' => 'aset',
                'permission' => 'ast.view',
                'tables' => ['ast_assets'],
                'why' => 'Alat yang sedang tidak bisa dipakai — angka yang mengubah rencana hari ini. "Jatuh tempo '
                    .'servis" akan lebih tajam, tetapi aturannya (pembacaan perawatan TERAKHIR per aset) sudah '
                    .'dimiliki WatchedDeadlines lewat flag latest_per_group, dan menyalin aturan itu ke sini '
                    .'berarti dua tempat yang bisa berselisih tentang aset yang sama.',
                'count' => static fn (): ?int => (int) DB::table('ast_assets')
                    ->whereNull('deleted_at')
                    ->where('status', 'maintenance')
                    ->count(),
            ],

            'iam' => [
                'label' => 'Job gagal',
                'unit' => 'job',
                'permission' => 'core.update',
                'tables' => ['failed_jobs'],
                'why' => 'Pasangan layar "Antrean Gagal", dan bergerbang izin yang SAMA dengan layar itu '
                    .'(core.update) — bukan iam.view grupnya. Akibatnya disengaja: pemegang hr yang melihat grup '
                    .'Sistem karena iam.view mendapat ubin tanpa angka ("—"), karena job gagal memang bukan '
                    .'urusannya. Jumlah pengguna tidak dipakai: ia tidak pernah menuntut tindakan.',
                'count' => static fn (): ?int => (int) DB::table('failed_jobs')->count(),
            ],
        ];
    }

    /**
     * Angka yang boleh dilihat $user, urut NAV. Entri yang izinnya tidak
     * dipegang atau tabelnya belum ada TIDAK muncul; kueri yang melempar
     * muncul dengan count null.
     *
     * @return list<array{prefix: string, label: string, unit: string, count: ?int}>
     */
    public static function for(?User $user): array
    {
        $out = [];

        foreach (self::entries() as $prefix => $entry) {
            if ($user === null) {
                continue;
            }

            if ($entry['permission'] !== null && ! $user->can($entry['permission'])) {
                continue;
            }

            if (! self::tablesExist($entry['tables'])) {
                continue;
            }

            $out[] = [
                'prefix' => $prefix,
                'label' => $entry['label'],
                'unit' => $entry['unit'],
                'count' => self::safeCount($prefix, $entry, $user),
            ];
        }

        return $out;
    }

    /**
     * Memo per proses, seperti WatchedDeadlines: satu jawaban dasbor menanyakan
     * 16 tabel, dan Schema::hasTable adalah satu kueri ke katalog tiap kali.
     * Uji membangun ulang skema per kelas, jadi memo yang menyeberangi batas itu
     * melaporkan tabel yang sudah tidak ada — flushSchemaMemo() dipanggil
     * ErpTestCase::setUp.
     *
     * @param  list<string>  $tables
     */
    private static function tablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! array_key_exists($table, self::$schemaMemo)) {
                self::$schemaMemo[$table] = Schema::hasTable($table);
            }

            if (! self::$schemaMemo[$table]) {
                return false;
            }
        }

        return true;
    }

    public static function flushSchemaMemo(): void
    {
        self::$schemaMemo = [];
    }

    /**
     * @param  Entry  $entry
     */
    private static function safeCount(string $prefix, array $entry, User $user): ?int
    {
        try {
            return ($entry['count'])($user);
        } catch (Throwable $e) {
            // Kolom yang berganti nama di lane tim lain, indeks yang rusak, tabel
            // yang terkunci: satu modul tidak boleh menjatuhkan launcher. Yang
            // dilaporkan ke layar adalah "tidak tahu"; yang dilaporkan ke log
            // adalah alasannya, supaya "—" yang menetap bisa dilacak.
            Log::warning('ModuleCounts: hitungan modul gagal', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
