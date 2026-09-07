<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Satu kebijakan persetujuan per jenis dokumen (F-1).
 *
 * Dua puluh delapan jenis dokumen berjalan lewat submit → approve, dan sampai
 * paket ini hanya TIGA di antaranya punya aturan bernilai: PO (Rp 100 juta),
 * SPK (Rp 200 juta) dan keputusan pemenang (jenjang 100 juta / 1 miliar).
 * Dua puluh lima sisanya tidak punya ambang sama sekali — dan "tidak punya
 * ambang" HARUS terbaca sebagai aturan, bukan sebagai Rp 0: sebuah ambang nol
 * berarti setiap dokumen menuntut direktur, kebalikan persis dari keadaannya.
 *
 * LAYAR DIKIRIM DENGAN NILAI HARI INI. Bawaan tiap baris adalah nilai yang
 * MENGATUR jenis itu hari ini, jadi memasang paket ini tidak mengubah satu pun
 * keputusan; yang berubah hanya bahwa pemilik sekarang bisa mengubahnya (OQ-4
 * dan OQ-5 tetap keputusan pemilik — ROADMAP §5 baris 12).
 *
 * DUA MODE.
 *   single_director — satu persetujuan, tetapi pada/di atas ambang persetujuan
 *       itu harus datang dari pemegang <awalan>.approve-director. Ini mekanisme
 *       PO/SPK hari ini (needs_director_approval + DirectorApproval).
 *   extra_level     — pada/di atas ambang dibutuhkan penyetuju BERBEDA yang
 *       kedua (dan pada/di atas ambang tingkat-3, yang ketiga), tiap tingkat
 *       di atas satu wajib pemegang -director. Ini mekanisme jenjang P2
 *       (ApprovalLevels) yang dipakai keputusan pemenang.
 *
 * AMBANG INKLUSIF DI BAWAH. `amount >= threshold` — bacaan yang sama dengan
 * needs_director_approval ((float) $total >= threshold) dan dengan semantik
 * bracket ApprovalLevels (`to` eksklusif, jadi nilai TEPAT di batas jatuh ke
 * tingkat yang lebih tinggi). Ketiganya harus sepakat, dan uji kesetaraan
 * enam nilai di sekitar ambang memakukannya.
 *
 * TIGA TABEL YANG MEMBAWA GERBANGNYA SENDIRI. prc_purchase_orders,
 * scm_subcontracts dan scm_subcontract_addenda punya kolom
 * needs_director_approval: modul merekalah yang menegakkan ambangnya, dan
 * ROADMAP eksplisit bahwa gerbang itu TIDAK dimigrasikan. Maka untuk ketiganya
 * kelas ini hanya MEMBACA (ambangnya kunci setelan yang sama persis yang
 * dibaca model itu, jadi mengubahnya di layar benar-benar berlaku) dan
 * modenya dikunci pada single_director — menawarkan extra_level di sana
 * berarti layar menjanjikan sesuatu yang tidak ada yang menegakkan.
 */
final class ApprovalPolicy
{
    public const MODE_SINGLE_DIRECTOR = 'single_director';

    public const MODE_EXTRA_LEVEL = 'extra_level';

    /** @var list<string> */
    public const MODES = [self::MODE_SINGLE_DIRECTOR, self::MODE_EXTRA_LEVEL];

    /**
     * Versi bentuk stempel. Dinaikkan hanya bila arti sebuah field berubah;
     * pembaca yang menemukan versi yang tidak dikenalnya mundur ke resolusi
     * langsung, bukan salah membaca stempel lama.
     */
    public const STAMP_VERSION = 1;

    /**
     * Jenis yang ambangnya BUKAN miliknya sendiri, melainkan milik jenis lain.
     *
     * Addendum SPK menghitung needs_director_approval-nya terhadap
     * Subcontract::directorApprovalThreshold() — nilai SPK SESUDAH addendum
     * dibanding ambang SPK (lihat SubcontractAddendum::submit dan alasannya di
     * sana). Memberi addendum kunci setelannya sendiri akan memasang sel yang
     * bisa diedit dan tidak ada yang membacanya; barisnya karena itu memantul
     * ke baris SPK dan ditandai "mengikuti" di layar.
     *
     * @var array<string, string>
     */
    public const FOLLOWS = ['subcontract_addendum' => 'subcontract'];

    /**
     * Kolom nilai yang dipindai untuk dokumen yang tidak menyatakan
     * approvalAmount()-nya sendiri, urutan yang sama dengan ApprovalQueue —
     * satu daftar, supaya nilai yang dicap pada baris `submitted` adalah nilai
     * yang sama dengan yang dibaca kotak masuk.
     */
    private const AMOUNT_KEYS = ApprovalQueue::AMOUNT_KEYS;

    /** @var array<string, bool>|null memo per proses: tabel yang punya kolom needs_director_approval */
    private static ?array $ownGate = null;

    /** @var array<string, bool>|null memo per proses: jenis yang punya nilai rupiah untuk diukur */
    private static ?array $measurable = null;

    /** @var bool|null memo per proses: core_approvals.policy sudah ada? */
    private static ?bool $hasPolicyColumn = null;

    private function __construct(
        public readonly string $type,
        public readonly string $prefix,
        public readonly string $label,
        public readonly ?float $threshold,
        public readonly string $mode,
        public readonly ?float $thirdLevelThreshold,
    ) {}

    /**
     * "purchase_order" untuk Modules\Procurement\Models\PurchaseOrder.
     *
     * snake_case dari nama kelas, dan itu BUKAN kebetulan: kunci yang sudah
     * dikirim aplikasi ini — approvals.purchase_order.threshold_two_level dan
     * approvals.subcontract.threshold_two_level — persis bentuk itu, jadi baris
     * matriks PO dan SPK memakai kunci yang SUDAH ADA, bukan kunci kembar yang
     * bisa berbeda pendapat dengan gerbangnya. 28 jenis diperiksa: tidak ada
     * dua yang bertabrakan.
     */
    public static function slugFor(object|string $document): ?string
    {
        $class = is_string($document) ? $document : $document::class;

        return ApprovableDocuments::knows($class) ? Str::snake(class_basename($class)) : null;
    }

    /** Jenis yang ambangnya benar-benar dibaca untuk $type (dirinya sendiri, kecuali FOLLOWS). */
    public static function settingType(string $type): string
    {
        return self::FOLLOWS[$type] ?? $type;
    }

    /**
     * Tiga kunci setelan satu baris matriks — sudah memantul lewat FOLLOWS.
     *
     * @return array{threshold: string, mode: string, third_level_threshold: string}
     */
    public static function keysFor(string $type): array
    {
        $owner = self::settingType($type);

        return [
            'threshold' => "approvals.{$owner}.threshold_two_level",
            'mode' => "approvals.{$owner}.mode",
            'third_level_threshold' => "approvals.{$owner}.third_level_threshold",
        ];
    }

    /**
     * Kebijakan efektif satu jenis: override setelan bila ada, bawaan
     * config/erp.php bila tidak.
     */
    public static function forType(string $type): self
    {
        $entry = self::registryEntryFor($type);
        $owner = self::settingType($type);

        // Dibaca dengan interpolasi HARFIAH, bukan lewat variabel yang sudah
        // dirakit: SettingServiceTest memindai sumber untuk pembacaan Erp::
        // dan memperlakukan bagian sebelum "{$" sebagai awalan yang tercakup.
        // Kunci yang dirakit di tempat lain tidak akan terlihat pemindai itu,
        // dan seluruh sub-pohon approvals.* akan tampak "tidak ada yang
        // membaca".
        $threshold = Erp::setting("approvals.{$owner}.threshold_two_level");
        $third = Erp::setting("approvals.{$owner}.third_level_threshold");
        $mode = (string) (Erp::setting("approvals.{$owner}.mode") ?: self::MODE_SINGLE_DIRECTOR);

        if (! in_array($mode, self::MODES, true) || self::modeIsLocked($type)) {
            // Mode yang tidak dikenal (baris yang ditulis instalasi lama) dan
            // tiga tabel bergerbang sendiri sama-sama jatuh ke perilaku hari
            // ini, bukan ke mode yang tidak ada yang menegakkan.
            $mode = self::MODE_SINGLE_DIRECTOR;
        }

        return new self(
            $type,
            $entry['prefix'] ?? '',
            $entry['label'] ?? 'Dokumen',
            self::amount($threshold),
            $mode,
            $mode === self::MODE_EXTRA_LEVEL ? self::amount($third) : null,
        );
    }

    public static function forDocument(object $document): ?self
    {
        $type = self::slugFor($document);

        return $type === null ? null : self::forType($type);
    }

    /**
     * Slug 28 jenis dokumen, urutan registri — tanpa membaca setelan apa pun.
     *
     * @return list<string>
     */
    public static function documentTypes(): array
    {
        $types = [];

        foreach (array_keys(ApprovableDocuments::all()) as $class) {
            $type = self::slugFor($class);

            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Kebijakan efektif 28 jenis, urutan registri.
     *
     * MEMBACA SETELAN — jangan panggil dari definitions() (lihat alasannya di
     * SettingService::approvalMatrixGroup).
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $policies = [];

        foreach (array_keys(ApprovableDocuments::all()) as $class) {
            $type = self::slugFor($class);

            if ($type !== null) {
                $policies[$type] = self::forType($type);
            }
        }

        return $policies;
    }

    /**
     * Benar bila tabel jenis ini membawa needs_director_approval — modulnya
     * sendiri yang menegakkan ambangnya, dan Core tidak boleh menggerbangi
     * ulang dokumen yang sudah bergerbang (dua penolakan untuk satu aturan,
     * dengan dua kalimat yang bisa berbeda).
     */
    public static function modeIsLocked(string $type): bool
    {
        if (self::$ownGate === null) {
            self::$ownGate = [];

            foreach (ApprovableDocuments::all() as $class => $entry) {
                $slug = self::slugFor($class);

                if ($slug === null) {
                    continue;
                }

                try {
                    $table = (new $class)->getTable();
                    self::$ownGate[$slug] = Schema::hasColumn($table, 'needs_director_approval');
                } catch (\Throwable) {
                    // Modul yang tabelnya belum ada (migrasi berjalan) bukan
                    // alasan untuk menebak: tanpa kolom, Core yang menegakkan.
                    self::$ownGate[$slug] = false;
                }
            }
        }

        return self::$ownGate[$type] ?? false;
    }

    /**
     * SEBUAH AMBANG BUTUH SEBUAH ANGKA UNTUK DIUKUR — dan tiga belas dari dua
     * puluh delapan jenis tidak punya satu pun.
     *
     * Diukur dari skema 7 Sep 2026: prj_bast, prj_baselines, prj_work_permits,
     * prj_overtime_permits, prj_gate_passes, prj_progress_measurements,
     * eng_work_permits_ipp, qc_inspections, crm_contract_change_orders,
     * prc_purchase_requisitions, inv_stock_adjustments, scm_handovers dan
     * hr_leave_requests tidak membawa satu pun kolom nilai. Sebuah izin kerja
     * lapangan tidak berharga rupiah — dan itu bukan kekurangan yang perlu
     * ditambal, itu memang jenis dokumennya.
     *
     * Maka baris-baris itu tidak mendapat sel ambang sama sekali; layar
     * mencetak aturannya ("tanpa nilai rupiah"). Menawarkan kotak isian di
     * sana berarti menawarkan kendali yang tidak akan pernah berbunyi —
     * kegagalan yang sama persis dengan needs_director_approval yang dulu
     * dicap, ditampilkan, dan tidak dibaca siapa pun saat menyetujui.
     *
     * Tiga jalan menjadi terukur, dalam urutan ini:
     *   1. model menyatakan approvalLadderKey() — kontraknya menuntut
     *      approvalAmount() sendiri (keputusan pemenang: awarded_amount);
     *   2. tabelnya membawa salah satu kolom nilai ApprovalQueue::AMOUNT_KEYS;
     *   3. tabelnya membawa needs_director_approval — modulnya sendiri yang
     *      menghitung nilainya (addendum SPK mengukur nilai SPK SESUDAH
     *      addendum, angka yang tidak ada di kolom mana pun).
     */
    public static function hasMeasurableAmount(string $type): bool
    {
        if (self::$measurable === null) {
            self::$measurable = [];

            foreach (ApprovableDocuments::all() as $class => $entry) {
                $slug = self::slugFor($class);

                if ($slug === null) {
                    continue;
                }

                try {
                    $model = new $class;
                    $table = $model->getTable();

                    $found = method_exists($model, 'approvalLadderKey') && $model->approvalLadderKey() !== null;

                    if (! $found) {
                        foreach (self::AMOUNT_KEYS as $column) {
                            if (Schema::hasColumn($table, $column)) {
                                $found = true;
                                break;
                            }
                        }
                    }

                    self::$measurable[$slug] = $found || Schema::hasColumn($table, 'needs_director_approval');
                } catch (\Throwable) {
                    // Tabel yang belum ada (migrasi berjalan): jangan menebak
                    // "terukur" — sebuah ambang yang ditawarkan salah lebih
                    // buruk daripada sebuah ambang yang tidak ditawarkan.
                    self::$measurable[$slug] = false;
                }
            }
        }

        return self::$measurable[$type] ?? false;
    }

    /** Memo skema per proses — tes membangun ulang basis data per kelas. */
    public static function flushSchemaMemo(): void
    {
        self::$ownGate = null;
        self::$measurable = null;
        self::$hasPolicyColumn = null;
    }

    /**
     * Berapa penyetuju BERBEDA yang dituntut nilai ini.
     *
     * single_director selalu 1: tuntutan direkturnya adalah tentang SIAPA yang
     * menyetujui, bukan BERAPA orang.
     */
    public function levelsFor(?float $amount): int
    {
        if ($this->mode !== self::MODE_EXTRA_LEVEL || $this->threshold === null || $amount === null) {
            return 1;
        }

        if ($amount < $this->threshold) {
            return 1;
        }

        if ($this->thirdLevelThreshold !== null && $amount >= $this->thirdLevelThreshold) {
            return 3;
        }

        return 2;
    }

    /**
     * Benar bila SATU-SATUNYA persetujuan dokumen ini harus datang dari
     * pemegang <awalan>.approve-director (mode single_director pada/di atas
     * ambang). Untuk extra_level tuntutan direktur dibawa oleh tingkat ke-2 ke
     * atas dan bukan oleh fungsi ini.
     */
    public function directorRequiredForSingleApproval(?float $amount): bool
    {
        return $this->mode === self::MODE_SINGLE_DIRECTOR
            && $this->threshold !== null
            && $amount !== null
            && $amount >= $this->threshold;
    }

    public function directorPermission(): ?string
    {
        return $this->prefix === '' ? null : "{$this->prefix}.approve-director";
    }

    /**
     * Stempel yang ditulis pada baris `submitted`.
     *
     * Yang dicap bukan hanya kebijakannya melainkan HASILNYA (levels,
     * director): saat menyetujui tidak ada lagi yang perlu dihitung ulang,
     * jadi tidak ada jalan bagi sebuah suntingan untuk mengubah tuntutan
     * dokumen yang sudah terbang. Nilai dan ambangnya ikut dicap untuk kalimat
     * penolakan dan untuk jejak yang dibaca manusia.
     *
     * @return array<string, mixed>
     */
    public function stampFor(?float $amount): array
    {
        return [
            'v' => self::STAMP_VERSION,
            'type' => $this->type,
            'prefix' => $this->prefix,
            'amount' => $amount,
            'threshold' => $this->threshold,
            'mode' => $this->mode,
            'third_level_threshold' => $this->thirdLevelThreshold,
            'levels' => $this->levelsFor($amount),
            'director' => $this->directorRequiredForSingleApproval($amount),
        ];
    }

    /**
     * Nilai yang kebijakan diukur terhadapnya.
     *
     * Model yang ikut jenjang WAJIB menyatakan approvalAmount()-nya sendiri
     * (kontrak Approvable::approvalLadderKey), jadi untuk model itu nilainya
     * diambil dari sana. Selebihnya kolom nilai dipindai — daftar yang sama
     * dengan kotak masuk. null bila dokumen memang tidak punya nilai: sebuah
     * dokumen tanpa rupiah bukan dokumen bernilai nol.
     */
    public static function amountOf(Model $document): ?float
    {
        if (method_exists($document, 'approvalLadderKey') && $document->approvalLadderKey() !== null) {
            return (float) $document->approvalAmount();
        }

        $attributes = $document->getAttributes();

        foreach (self::AMOUNT_KEYS as $key) {
            if (isset($attributes[$key]) && is_numeric($attributes[$key])) {
                return (float) $attributes[$key];
            }
        }

        return null;
    }

    /**
     * Stempel pada pengajuan TERAKHIR dokumen ini, atau null.
     *
     * null berarti salah satu dari dua hal, dan keduanya berakhir di jalur
     * lama: dokumen diajukan sebelum paket ini (maju-saja — tidak ada stempel
     * yang ditulis surut), atau kolomnya belum ada.
     *
     * @return array<string, mixed>|null
     */
    public static function stampedFor(Model $document): ?array
    {
        if (! self::approvalsCarryAPolicyColumn()) {
            return null;
        }

        $raw = DB::table('core_approvals')
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->where('action', 'submitted')
            ->orderByDesc('id')
            ->value('policy');

        if ($raw === null || $raw === '') {
            return null;
        }

        $stamp = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($stamp) || ($stamp['v'] ?? null) !== self::STAMP_VERSION) {
            return null;
        }

        return $stamp;
    }

    /**
     * Setiap izin persetujuan direktur yang ada — satu per awalan berdokumen.
     *
     * Diturunkan dari registri dan TIDAK membaca satu pun setelan: dipanggil
     * dari jalur tulis Pengaturan, dan sebuah pembacaan setelan di sana akan
     * menghangatkan memo resolver di tengah sebuah penulisan.
     *
     * @return list<string>
     */
    public static function directorPermissions(): array
    {
        $names = [];

        foreach (ApprovableDocuments::all() as $entry) {
            $names["{$entry['prefix']}.approve-director"] = true;
        }

        return array_keys($names);
    }

    /**
     * Baris registri satu jenis (prefix, label) — tanpa membaca setelan.
     *
     * @return array{prefix?: string, label?: string, resource?: string}
     */
    public static function documentEntry(string $type): array
    {
        return self::registryEntryFor($type);
    }

    /**
     * Apakah core_approvals sudah punya kolom `policy` (migrasi 000198).
     *
     * Dimemo per proses karena stampedFor() dipanggil DUA KALI pada tiap
     * persetujuan — sekali dari requiredApprovalLevels(), sekali dari
     * assertStampedDirectorLevel() — dan Schema::hasColumn di MySQL adalah
     * kueri information_schema, bukan pemeriksaan gratis.
     */
    private static function approvalsCarryAPolicyColumn(): bool
    {
        return self::$hasPolicyColumn ??= Schema::hasColumn('core_approvals', 'policy');
    }

    /** @return array{prefix?: string, label?: string} */
    private static function registryEntryFor(string $type): array
    {
        foreach (ApprovableDocuments::all() as $class => $entry) {
            if (self::slugFor($class) === $type) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * Rupiah dari sebuah nilai setelan. String kosong dan null sama-sama
     * "tanpa ambang" — sebuah setelan yang dikosongkan di layar tiba sebagai
     * null, dan tidak boleh menjadi 0.
     */
    private static function amount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
