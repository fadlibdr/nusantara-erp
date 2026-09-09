<?php

namespace Modules\Assets\Services;

use Illuminate\Support\Collection;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;
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
        $logs = $this->logsPerAsset($ids);

        $rows = [];

        foreach ($assets as $asset) {
            $row = $this->compose(
                $asset,
                $maintenances[$asset->id] ?? null,
                (int) ($deploymentCounts[$asset->id] ?? 0),
                $logs[$asset->id] ?? [],
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
     * Setiap log BBM & jam alat milik aset-aset ini, urut (log_date, id).
     *
     * LEWAT MOBILISASI YANG MASIH HIDUP saja (whereNull deleted_at): sikap
     * yang sama dengan Asset::equipmentLogs dan dengan kartu aset — pembacaan
     * milik mobilisasi yang dihapus seseorang tidak boleh kembali lewat
     * pintu belakang dan menghakimi servis sebuah alat.
     *
     * Log tanpa hour_meter IKUT DIAMBIL, dan itu perlu: tanpa mereka, "ada
     * log tetapi semuanya tanpa jam" (sebab ketiga tidak-terukur) tidak bisa
     * dibedakan dari "belum ada log sama sekali" (sebab kedua).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, object>>
     */
    private function logsPerAsset(array $ids): array
    {
        $logs = EquipmentLog::query()
            ->join('ast_deployments', 'ast_deployments.id', '=', 'ast_equipment_logs.deployment_id')
            ->whereNull('ast_deployments.deleted_at')
            ->whereIn('ast_deployments.asset_id', $ids)
            ->orderBy('ast_deployments.asset_id')
            ->orderBy('ast_equipment_logs.log_date')
            ->orderBy('ast_equipment_logs.id')
            ->get([
                'ast_equipment_logs.id',
                'ast_equipment_logs.log_date',
                'ast_equipment_logs.hour_meter',
                'ast_deployments.asset_id',
            ]);

        $byAsset = [];

        foreach ($logs as $log) {
            $byAsset[(int) $log->asset_id][] = $log;
        }

        return $byAsset;
    }

    /**
     * @param  array<int, object>  $logs
     * @return array<string, mixed>|null
     */
    private function compose(Asset $asset, ?Maintenance $maintenance, int $deployments, array $logs, float $margin): ?array
    {
        $meterLogs = array_values(array_filter($logs, static fn (object $log): bool => $log->hour_meter !== null));

        $high = null;
        $highDate = null;
        $latest = null;
        $latestDate = null;

        foreach ($meterLogs as $log) {
            $value = (float) $log->hour_meter;
            $date = $log->log_date?->toDateString();

            // >=, bukan >: kalau meterannya berhenti di angka yang sama
            // (alat menganggur), tanggal yang menolong adalah yang TERAKHIR
            // kali angka itu terbaca, bukan yang pertama.
            if ($high === null || $value >= $high) {
                $high = $value;
                $highDate = $date;
            }

            $latest = $value;
            $latestDate = $date;
        }

        $limit = $maintenance?->next_due_hour_meter === null ? null : (float) $maintenance->next_due_hour_meter;

        // Tidak diukur dengan jam sama sekali: tidak ada target, tidak ada
        // pembacaan. Bukan "belum terukur" — bukan alat berjam.
        if ($limit === null && $high === null) {
            return null;
        }

        $state = WatchedThresholds::state($high, $limit, 0.0, $margin);
        $unmeasuredReason = $high !== null ? null : match (true) {
            $deployments === 0 => self::BELUM_DIMOBILISASI,
            $logs === [] => self::TANPA_LOG,
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
            'reading_count' => count($meterLogs),
            'log_count' => count($logs),
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
            'note' => $this->note($maintenance, $high, $highDate, $latest, $latestDate, count($meterLogs), count($logs), $deployments, $unmeasuredReason),
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
