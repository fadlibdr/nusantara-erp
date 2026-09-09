<?php

namespace Modules\Assets\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\Maintenance;
use Modules\Core\Support\WatchedThresholds;

/**
 * SERVIS ALAT MENURUT JAM OPERASI (F-7) — satu tempat, dipakai setiap layar.
 *
 * Pemicu servis di ERP ini ada DUA dan keduanya berdiri sendiri: next_due_date
 * (kalender, diawasi WatchedDeadlines sejak paket tenggat) dan
 * next_due_hour_meter (jam operasi, migrasi 000545). Yang mana pun tercapai
 * lebih dulu, servisnya jatuh tempo — kelas ini menjawab sisi JAM-nya, dan
 * setiap baris yang dipulangkannya membawa sisi tanggalnya juga, supaya tidak
 * ada satu permukaan pun yang bisa menyiratkan hanya satu pemicu yang berlaku.
 *
 * =====================================================================
 * DUA DEFINISI YANG HARUS DITULIS, KARENA KEDUANYA BISA DIPILIH SALAH
 * =====================================================================
 *
 * (1) "PEMBACAAN HOUR-METER TERAKHIR" = PEMBACAAN TERTINGGI YANG TERCATAT,
 *     bukan yang terbaru menurut (log_date, id).
 *
 *     Tiga kandidat ada: menurut log_date, menurut id, atau menurut NILAI
 *     tertinggi. Yang dipilih adalah nilai tertinggi, dan alasannya satu
 *     kalimat: METER TIDAK PERNAH BERJALAN MUNDUR. Sebuah pembacaan yang
 *     lebih rendah dari pembacaan sebelumnya hanya punya dua sebab nyata —
 *     meterannya diganti (mesin baru menghitung dari 0 lagi) atau operator
 *     salah ketik satu digit (337 untuk 3.375) — dan TIDAK SATU PUN dari
 *     keduanya berarti mesinnya berjalan lebih sedikit. Kalau yang dipakai
 *     adalah "yang terbaru", satu digit yang salah ketik langsung MEMBATALKAN
 *     jatuh tempo servis alat itu: 5.120 jam yang sudah lewat target 5.000
 *     berubah menjadi 512 jam yang "masih 4.488 jam lagi", dan layar menjadi
 *     tenang persis pada alat yang paling perlu dilihat. Itu cara paling
 *     murah membuat alat terlewat servisnya, dan kelas ini menolak
 *     menyediakannya.
 *
 *     EquipmentLogService sudah menegakkan meter maju di sisi TULIS, tetapi
 *     hanya DI DALAM satu mobilisasi (penjaga monotonnya berlingkup
 *     deployment). Satu alat melewati banyak mobilisasi, dan penggantian
 *     meter justru terjadi di antara dua mobilisasi — jadi di sisi BACA
 *     jaminan itu tidak ada, dan definisi ini yang menggantikannya.
 *
 *     Pembacaan TERBARU tetap dibawa setiap baris (latest_reading), dan bila
 *     ia lebih rendah dari yang tertinggi, catatan barisnya MENGATAKANNYA.
 *     Yang ditolak adalah membiarkan angka yang turun mendiamkan alarm —
 *     bukan menyembunyikan bahwa angkanya turun.
 *
 * (2) "next_due_hour_meter YANG BERLAKU" = milik catatan perawatan TERBARU
 *     alat itu (menurut maintenance_date, lalu id), bukan target terkecil
 *     yang belum terlampaui.
 *
 *     Alasannya: BARIS YANG SAMA yang dibaca pemicu tanggal. WatchedDeadlines
 *     memilih baris perawatan terbaru per aset (latest_per_group) dengan
 *     alasan "mencatat servis 14 Jun harus mendiamkan pengingat yang
 *     ditinggalkan servis sebelumnya" — dan alasan itu tidak berubah kalau
 *     satuannya jam. Kartu servis terbaru MENGGANTIKAN rencana sebelumnya;
 *     memilih "target terkecil yang belum terlampaui" akan menghidupkan lagi
 *     target yang sudah digantikan mekanik, dan — lebih buruk — membuat dua
 *     pemicu pada SATU tabel membaca DUA baris yang berbeda.
 *
 *     Akibatnya sengaja tajam: catatan perawatan terbaru yang lupa mengisi
 *     target jam membuat alatnya TANPA_BATAS ("batas belum disetel"), bukan
 *     diam-diam mewarisi target lama. Itu pola alarm_when_date_missing milik
 *     pemicu tanggal, diterjemahkan ke jam: satu kolom yang lupa diisi harus
 *     terlihat, bukan tertutup oleh angka dari kartu servis yang lalu.
 *
 * =====================================================================
 * TIDAK TERUKUR BUKAN NOL — DAN ADA TIGA CARA SEBUAH ALAT TIDAK TERUKUR
 * =====================================================================
 *
 * 0 jam berarti "mesin baru, meterannya masih nol". "Tidak ada yang tahu"
 * adalah keadaan yang lain sama sekali, dan ia punya TIGA sebab yang berbeda
 * jalan keluarnya — jadi tiga kalimat yang berbeda, bukan satu "—":
 *
 *   BELUM_DIMOBILISASI  alat belum pernah ditempatkan ke proyek mana pun,
 *                       jadi belum ada mobilisasi yang bisa menampung log;
 *   TANPA_LOG           mobilisasinya ada, satu log pun belum ditulis;
 *   LOG_TANPA_JAM       lognya ada, tetapi hour_meter-nya NULL pada semuanya
 *                       — kolom itu nullable karena mengisi solar tanpa
 *                       mencatat jam kerja adalah kejadian biasa di lapangan.
 *
 * Ketiganya memulangkan keadaan TIDAK_TERUKUR (kosakata WatchedThresholds),
 * dan ketiganya DIGARIS di layar — tidak pernah digambar sebagai 0 jam.
 *
 * =====================================================================
 * ASET YANG DILEPAS KELUAR DARI PENGAWASAN
 * =====================================================================
 *
 * Aturan yang sama persis dengan pemicu tanggal (WatchedDeadlines entri
 * maintenance_next_due): status 'disposed' dan baris yang dihapus lunak
 * keluar dari daftar. Alat yang sudah dijual tidak lagi butuh servis, dan
 * alarm yang tidak menyisakan tindakan adalah kebisingan. Dua pemicu, satu
 * aturan — dan MaintenanceHourMeterDueTest memaku bahwa keduanya benar-benar
 * menjatuhkan aset yang sama.
 *
 * =====================================================================
 * SIAPA YANG MASUK DAFTAR
 * =====================================================================
 *
 * Alat yang punya target jam ATAU punya pembacaan jam. Scaffolding set dan
 * rak server tidak punya hour meter dan tidak akan pernah punya; membariskan
 * mereka sebagai "belum terukur" selamanya adalah persis kebisingan yang
 * aturan "masih urusan seseorang" milik WatchedDeadlines ada untuk mencegah.
 * Sebuah alat masuk daftar begitu SESEORANG menyatakan ia diukur dengan jam —
 * dengan menyetel targetnya, atau dengan menulis satu pembacaan.
 */
class MaintenanceDueService
{
    /**
     * Kunci config ambang peringatan, dalam JAM sebelum target.
     *
     * Literal yang sama dideklarasikan entri registri di Core
     * (WatchedThresholds 'maintenance_hour_meter'), karena Core tidak boleh
     * mengimpor modul fitur untuk membaca konstanta ini. Bahwa keduanya
     * menunjuk angka yang sama dipaku ThresholdHourMeterTest — kalau tidak,
     * layar Ambang dan kartu aset bisa menyebut alat yang sama "Aman" dan
     * "Mendekati batas" pada hari yang sama.
     */
    public const WARN_MARGIN_KEY = 'maintenance_hour_meter';

    /** Bawaan ambang peringatan: 50 jam sebelum target (owner decision F-7). */
    public const WARN_MARGIN_DEFAULT = 50.0;

    public const BELUM_DIMOBILISASI = 'belum_dimobilisasi';

    public const TANPA_LOG = 'tanpa_log';

    public const LOG_TANPA_JAM = 'log_tanpa_jam';

    /**
     * Bulan pendek untuk kalimat yang disusun kelas ini.
     *
     * Salinan kelima di repo ini (WatchedDeadlines, FormPrintService,
     * DocumentPdfService, AssetFormService) dan karena alasan mereka:
     * APP_LOCALE 'en' tanpa direktori lang/, jadi menjangkau
     * translatedFormat() Carbon berarti memindahkan locale SELURUH aplikasi
     * ke 'id' dan membawa setiap pesan validasi ikut pindah.
     */
    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Berapa jam sebelum target sebuah alat mulai diperingatkan.
     *
     * JAM, BUKAN PERSEN — dan itu keputusan yang harus ditulis. Registri
     * ambang menghakimi sisi rupiah dengan persentase ("90 % anggaran
     * terpakai"), dan persentase TIDAK BISA dipindahkan ke meter kumulatif:
     * meteran tidak pernah mulai dari nol pada servis terakhir. Terukur pada
     * data demo: Doosan DX225LCA berada di 3.375,5 jam; dengan target 3.500
     * jam, peringatan 90 % menyala pada 3.150 jam — 350 jam sebelum jatuh
     * tempo, lebih panjang dari SATU interval servis penuh (250 jam), jadi
     * alarmnya sudah menyala sebelum servis sebelumnya selesai. Alat yang
     * sama pada 12.000 jam dengan target 12.250 akan menyala pada 11.025 —
     * 1.225 jam, lima interval. Ambang persen memberi tenggang yang membesar
     * mengikuti UMUR alat, bukan mengikuti sisa jatah servisnya.
     *
     * 50 jam kira-kira lima sampai enam hari kerja alat berat (8-10 jam/hari)
     * — cukup untuk memesan sparepart dan menjadwalkan mekanik, dan sama
     * panjangnya untuk alat baru maupun alat tua.
     */
    public function warnMarginHours(): float
    {
        return WatchedThresholds::warnMargin(self::WARN_MARGIN_KEY, self::WARN_MARGIN_DEFAULT);
    }

    /**
     * Keadaan jam-servis satu aset, atau null bila aset itu tidak diukur
     * dengan jam sama sekali (tidak punya target dan tidak punya pembacaan).
     *
     * @return array<string, mixed>|null
     */
    public function forAsset(Asset $asset): ?array
    {
        return $this->rows($asset->id)[0] ?? null;
    }

    /**
     * Baris registri ambang (WatchedThresholds entri 'maintenance_hour_meter').
     *
     * Bentuk baris registri: subject/name/actual/limit/note/link. Keadaannya
     * TIDAK dihitung di sini — WatchedThresholds::measure() yang menghitung,
     * satu tempat untuk seluruh registri, supaya "tepat pada target" tidak
     * bisa mendarat di dua sisi berbeda di dua layar. Yang dipasok kelas ini
     * adalah kedua ANGKA-nya dan kalimatnya.
     *
     * @return array<int, array<string, mixed>>
     */
    public function thresholdRows(): array
    {
        return array_map(static fn (array $row): array => [
            'subject' => $row['asset_code'],
            'name' => $row['asset_name'],
            'actual' => $row['reading'],
            'limit' => $row['next_due_hour_meter'],
            'note' => $row['note'],
            'link' => 'd/assets/assets/'.$row['asset_id'],
        ], $this->rows());
    }

    /**
     * Satu baris per alat yang diukur dengan jam, urut kode aset.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(?int $assetId = null): array
    {
        $assets = Asset::query()
            ->where('status', '!=', AssetStatus::Disposed->value)
            ->when($assetId !== null, fn ($query) => $query->whereKey($assetId))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($assets->isEmpty()) {
            return [];
        }

        $ids = $assets->pluck('id')->all();
        $margin = $this->warnMarginHours();
        $maintenances = $this->newestMaintenancePerAsset($ids);
        $deploymentCounts = $this->deploymentCountPerAsset($ids);
        $readings = $this->readingsPerAsset($ids);

        $rows = [];

        foreach ($assets as $asset) {
            $row = $this->compose(
                $asset,
                $maintenances[$asset->id] ?? null,
                (int) ($deploymentCounts[$asset->id] ?? 0),
                $readings[$asset->id] ?? ['log_count' => 0, 'reading_count' => 0, 'high' => null,
                    'high_date' => null, 'latest' => null, 'latest_date' => null],
                $margin,
            );

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    // ---------------------------------------------------------------- internal

    /**
     * Catatan perawatan TERBARU per aset — baris yang sama yang dibaca
     * WatchedDeadlines untuk pemicu tanggal (definisi 2 di docblock kelas).
     *
     * orderBy(maintenance_date, id) lalu keyBy: yang terakhir menang, yaitu
     * yang terbaru. Pola yang sama dengan WatchedThresholds::rapVersusContract.
     * SoftDeletes model membuang baris yang dihapus tanpa klausa tambahan —
     * baris perawatan yang dihapus tidak menggantikan apa pun, aturan yang
     * sama dengan soft_deletes pada latest_per_group.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Maintenance>
     */
    private function newestMaintenancePerAsset(array $ids)
    {
        return Maintenance::query()
            ->whereIn('asset_id', $ids)
            ->orderBy('maintenance_date')
            ->orderBy('id')
            ->get(['id', 'code', 'asset_id', 'maintenance_date', 'next_due_date', 'next_due_hour_meter'])
            ->keyBy('asset_id');
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, int>
     */
    private function deploymentCountPerAsset(array $ids)
    {
        return Deployment::query()
            ->whereIn('asset_id', $ids)
            ->groupBy('asset_id')
            ->selectRaw('asset_id, count(*) as deployment_count')
            ->pluck('deployment_count', 'asset_id');
    }

    /**
     * Ringkasan pembacaan per aset — TIGA KUERI AGREGAT, bukan seluruh
     * registernya.
     *
     * Versi pertama kelas ini mengambil SETIAP baris log milik aset-aset yang
     * diawasi lalu menghitungnya di PHP. Terukur pada 24.007 pembacaan (bentuk
     * armada kontraktor sekitar dua tahun: ~50 alat x 250 hari kerja): seluruh
     * armada 803 ms dan KARTU SATU ALAT 147 ms — pada register yang hanya bisa
     * MEMBESAR, karena ia append-only dan tidak punya pintu hapus. Sesudah
     * agregasi, pada berkas dan mesin yang sama: 17,0 ms dan 4,0 ms (median
     * dari lima jalan sesudah pemanasan; rentang 15,5-21,6 dan 3,8-5,0).
     *
     * Yang dibutuhkan hanya lima angka per aset, dan ketiganya bisa ditanyakan
     * langsung: berapa log seluruhnya, berapa yang mengisi hour meter,
     * pembacaan TERTINGGI, TANGGAL pembacaan tertinggi itu, dan pembacaan
     * TERBARU. Semuanya lewat mobilisasi yang masih hidup saja (whereNull
     * deleted_at) — sikap yang sama dengan Asset::equipmentLogs dan kartu aset:
     * pembacaan milik mobilisasi yang dihapus seseorang tidak boleh kembali
     * lewat pintu belakang dan menghakimi servis sebuah alat.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function readingsPerAsset(array $ids): array
    {
        // (1) Cacah dan puncaknya. COUNT(hour_meter) menghitung yang BUKAN
        // null — itulah yang membedakan "belum ada log" dari "ada log, tetapi
        // tidak satu pun mengisi hour meter" (dua dari tiga sebab tidak
        // terukur, dan dua jalan keluar yang berbeda).
        $summary = $this->logQuery($ids)
            ->groupBy('ast_deployments.asset_id')
            ->selectRaw('ast_deployments.asset_id as asset_id, COUNT(*) as log_count, '
                .'COUNT(ast_equipment_logs.hour_meter) as reading_count, '
                .'MAX(ast_equipment_logs.hour_meter) as high, '
                // Tanggal pembacaan TERBARU ikut di agregat yang sama: CASE
                // WHEN membuang baris tanpa angka jam, jadi log BBM yang lebih
                // baru tidak menggeser tanggal ini.
                .'MAX(CASE WHEN ast_equipment_logs.hour_meter IS NULL THEN NULL '
                .'ELSE ast_equipment_logs.log_date END) as latest_date')
            ->get();

        $rows = [];

        foreach ($summary as $row) {
            $rows[(int) $row->asset_id] = [
                'log_count' => (int) $row->log_count,
                'reading_count' => (int) $row->reading_count,
                // Nilai MENTAH dipertahankan untuk kueri (2): kolomnya decimal,
                // dan membandingkan string yang dipulangkan driver dengan
                // kolomnya sendiri adalah perbandingan yang persis — sebuah
                // float 3.375,5 yang dibulatkan ulang tidak dijamin persis.
                'high_raw' => $row->high,
                'high' => $row->high === null ? null : (float) $row->high,
                'high_date' => null,
                'latest' => null,
                // Nilai MENTAH lagi, dan untuk alasan yang sama: kolom `date`
                // tersimpan "2024-04-24 00:00:00" pada baris yang ditulis
                // Eloquent dan "2024-04-24" pada baris yang ditulis seeder,
                // dan yang dibandingkan kueri berikutnya harus bentuk yang
                // PERSIS dipulangkan MAX() dari kolom itu sendiri.
                'latest_date_raw' => $row->latest_date,
                'latest_date' => $row->latest_date === null ? null : substr((string) $row->latest_date, 0, 10),
            ];
        }

        $withReadings = array_filter($rows, static fn (array $row): bool => $row['high'] !== null);

        if ($withReadings === []) {
            return $rows;
        }

        // (2) TANGGAL puncaknya: yang TERAKHIR kali angka itu terbaca, bukan
        // yang pertama. Meter yang berhenti di angka yang sama (alat menganggur
        // sebulan) berbunyi "tertinggi sejak 1 Jul" pada register yang dibaca
        // lagi 31 Jul — kalimat yang membuat pembacanya mengira register itu
        // berhenti diisi.
        $dates = $this->logQuery(array_keys($withReadings))
            ->where(static function ($query) use ($withReadings): void {
                foreach ($withReadings as $assetId => $row) {
                    $query->orWhere(static fn ($pair) => $pair
                        ->where('ast_deployments.asset_id', $assetId)
                        ->where('ast_equipment_logs.hour_meter', $row['high_raw']));
                }
            })
            ->groupBy('ast_deployments.asset_id')
            ->selectRaw('ast_deployments.asset_id as asset_id, MAX(ast_equipment_logs.log_date) as high_date')
            ->get();

        foreach ($dates as $row) {
            $rows[(int) $row->asset_id]['high_date'] = substr((string) $row->high_date, 0, 10);
        }

        // (3) Pembacaan TERBARU menurut (log_date, id) — nilainya, bukan
        // hanya tanggalnya. Dua kueri agregat, BUKAN satu subkueri berkorelasi:
        // pola whereNotExists milik WatchedDeadlines ditulis lebih dulu di sini
        // dan TERUKUR 1.065 ms sendirian pada 24.007 pembacaan (dua aggregat di
        // atas: 9,4 ms dan 6,6 ms). Yang dilakukannya sekarang: id terbesar
        // pada tanggal terbaru tiap aset, lalu ambil barisnya lewat kunci
        // primer.
        $latestIds = $this->logQuery(array_keys($withReadings))
            ->whereNotNull('ast_equipment_logs.hour_meter')
            ->where(static function ($query) use ($withReadings): void {
                foreach ($withReadings as $assetId => $row) {
                    $query->orWhere(static fn ($pair) => $pair
                        ->where('ast_deployments.asset_id', $assetId)
                        ->where('ast_equipment_logs.log_date', $row['latest_date_raw']));
                }
            })
            ->groupBy('ast_deployments.asset_id')
            ->selectRaw('ast_deployments.asset_id as asset_id, MAX(ast_equipment_logs.id) as log_id')
            ->pluck('log_id', 'asset_id');

        if ($latestIds->isNotEmpty()) {
            $values = DB::table('ast_equipment_logs')
                ->whereIn('id', $latestIds->all())
                ->pluck('hour_meter', 'id');

            foreach ($latestIds as $assetId => $logId) {
                $rows[(int) $assetId]['latest'] = isset($values[$logId]) ? (float) $values[$logId] : null;
            }
        }

        return $rows;
    }

    /** Log BBM & jam alat aset-aset ini, lewat mobilisasi yang masih hidup. */
    private function logQuery(array $ids): Builder
    {
        return DB::table('ast_equipment_logs')
            ->join('ast_deployments', 'ast_deployments.id', '=', 'ast_equipment_logs.deployment_id')
            ->whereNull('ast_deployments.deleted_at')
            ->whereIn('ast_deployments.asset_id', $ids);
    }

    /**
     * @param  array<string, mixed>  $reading  ringkasan readingsPerAsset()
     * @return array<string, mixed>|null
     */
    private function compose(Asset $asset, ?Maintenance $maintenance, int $deployments, array $reading, float $margin): ?array
    {
        $high = $reading['high'];
        $highDate = $reading['high_date'];
        $latest = $reading['latest'];
        $latestDate = $reading['latest_date'];
        $readingCount = (int) $reading['reading_count'];
        $logCount = (int) $reading['log_count'];

        $limit = $maintenance?->next_due_hour_meter === null ? null : (float) $maintenance->next_due_hour_meter;

        // Tidak diukur dengan jam sama sekali: tidak ada target, tidak ada
        // pembacaan. Bukan "belum terukur" — bukan alat berjam.
        if ($limit === null && $high === null) {
            return null;
        }

        $state = WatchedThresholds::state($high, $limit, 0.0, $margin);
        $unmeasuredReason = $high !== null ? null : match (true) {
            $deployments === 0 => self::BELUM_DIMOBILISASI,
            $logCount === 0 => self::TANPA_LOG,
            default => self::LOG_TANPA_JAM,
        };

        return [
            'asset_id' => $asset->id,
            'asset_code' => $asset->code,
            'asset_name' => $asset->name,
            'reading' => $high,
            'reading_date' => $highDate,
            'latest_reading' => $latest,
            'latest_reading_date' => $latestDate,
            // Meter yang turun: pembacaan terbaru lebih rendah dari yang
            // tertinggi. Dibawa sebagai fakta tersendiri supaya layar bisa
            // menyebutkannya tanpa membandingkan dua angka sendiri.
            'meter_went_backwards' => $latest !== null && $high !== null && $latest < $high,
            'reading_count' => $readingCount,
            'log_count' => $logCount,
            'deployment_count' => $deployments,
            'unmeasured_reason' => $unmeasuredReason,
            'next_due_hour_meter' => $limit,
            'remaining_hours' => $high === null || $limit === null ? null : round($limit - $high, 3),
            'maintenance_id' => $maintenance?->id,
            'maintenance_code' => $maintenance?->code,
            'maintenance_date' => $maintenance?->maintenance_date?->toDateString(),
            // Pemicu KEDUA, dibawa berdampingan di setiap baris: sebuah alat
            // bisa jatuh tempo menurut jam sementara tanggalnya masih jauh,
            // dan tidak ada permukaan yang boleh menampilkan satu tanpa yang
            // lain (perangkap C paket ini).
            'next_due_date' => $maintenance?->next_due_date?->toDateString(),
            'state' => $state,
            'state_label' => WatchedThresholds::stateLabel($state),
            'warn_margin_hours' => $margin,
            'note' => $this->note($maintenance, $high, $highDate, $latest, $latestDate, $readingCount, $logCount, $deployments, $unmeasuredReason),
        ];
    }

    /**
     * Kalimat baris — sisi yang diukur, sisi batasnya, dan pemicu tanggalnya.
     *
     * Aturan §24 dipegang di sini: sebuah baris yang kehilangan DUA sisi
     * menyebut keduanya, supaya satu keadaan tidak pernah menyembunyikan
     * kekurangan yang lain.
     */
    private function note(
        ?Maintenance $maintenance,
        ?float $high,
        ?string $highDate,
        ?float $latest,
        ?string $latestDate,
        int $readingCount,
        int $logCount,
        int $deployments,
        ?string $unmeasuredReason,
    ): string {
        $parts = [];

        if ($unmeasuredReason !== null) {
            $parts[] = match ($unmeasuredReason) {
                self::BELUM_DIMOBILISASI => 'Alat ini belum pernah dimobilisasi, jadi belum ada mobilisasi yang bisa menampung log jam',
                self::TANPA_LOG => $deployments.' mobilisasi tercatat, tetapi belum ada satu log BBM & jam alat pun',
                default => $logCount.' log tercatat, tetapi tidak satu pun mengisi hour meter (log BBM tanpa jam kerja)',
            };
        } else {
            $parts[] = 'Pembacaan tertinggi '.$this->hours($high).' jam pada '.$this->tanggal($highDate)
                .' dari '.$readingCount.' pembacaan';

            if ($latest !== null && $high !== null && $latest < $high) {
                $parts[] = 'pembacaan TERAKHIR ('.$this->tanggal($latestDate).') justru lebih rendah, '.$this->hours($latest)
                    .' jam — meter diganti atau salah ketik; yang dihakimi tetap yang tertinggi, karena mesin tidak berjalan mundur';
            }
        }

        if ($maintenance === null) {
            $parts[] = 'belum ada catatan perawatan sama sekali, jadi target jam servis berikutnya belum disetel';
        } elseif ($maintenance->next_due_hour_meter === null) {
            $parts[] = 'catatan perawatan terbaru '.$maintenance->code.' belum menyebut target jam servis berikutnya';
        } else {
            $parts[] = 'target dari '.$maintenance->code.' (servis '.$this->tanggal($maintenance->maintenance_date?->toDateString()).')';
        }

        if ($maintenance !== null) {
            $parts[] = $maintenance->next_due_date === null
                ? 'pemicu tanggal: belum dijadwalkan'
                : 'pemicu tanggal: '.$this->tanggal($maintenance->next_due_date->toDateString());
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    /** "2026-07-31" -> "31 Jul 2026". Tanggal yang dibaca orang Indonesia. */
    private function tanggal(?string $iso): string
    {
        if ($iso === null) {
            return '—';
        }

        [$year, $month, $day] = array_map('intval', explode('-', substr($iso, 0, 10)));

        return $day.' '.self::BULAN[$month - 1].' '.$year;
    }

    /**
     * Angka jam sebagaimana operator menulisnya — pemisah Indonesia, nol
     * ekor dibuang (1.200,5, tidak pernah 1.200,500). Potongan yang sama
     * dengan EquipmentLogService::meter dan cast qty FormPrintService, jadi
     * penolakan register, kartu cetak, dan kalimat ini mengeja satu angka
     * dengan satu cara.
     */
    public function hours(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }
}
