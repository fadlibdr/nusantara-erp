<?php

namespace Modules\Core\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setiap angka dalam sistem yang punya BATAS, dan keadaan tiap batas itu.
 *
 * Saudara WatchedDeadlines. Yang itu menjawab "tanggal apa yang lewat"; yang
 * ini menjawab "angka apa yang mendekati atau melewati batasnya" — anggaran
 * proyek terhadap RAP, RAP terhadap nilai kontrak, realisasi overhead terhadap
 * OVB tahun berjalan. Satu daftar deklaratif, jadi batas berikutnya yang layak
 * diawasi ditambahkan sebagai SATU entri array, bukan sebagai layar baru.
 *
 * LIMA KEADAAN, DAN TIGA DI ANTARANYA BUKAN ANGKA. Inilah seluruh alasan kelas
 * ini ada. Sebuah sel anggaran yang batasnya belum pernah disetel bukan 0 %,
 * dan sebuah proyek yang belum punya RAP bukan 100 % terpakai — keduanya
 * "tidak ada jawaban", dengan sebab yang BERBEDA, dan menyamakan keduanya
 * dengan nol adalah cara paling murah membuat layar anggaran berbohong:
 *
 *   AMAN           di bawah ambang peringatan;
 *   MENDEKATI      di ambang peringatan atau di atasnya, MASIH di bawah batas;
 *   LAMPAU         di batas atau melewatinya (tepat 100 % ada di sisi ini —
 *                  anggaran yang habis persis sudah tidak menyisakan apa pun);
 *   TANPA_BATAS    yang diukur ADA, batasnya tidak pernah disetel. Ini sebuah
 *                  ATURAN yang dicetak apa adanya, bukan nol dan bukan taksiran;
 *   TIDAK_TERUKUR  yang diukur sendiri belum ada. Mendahului TANPA_BATAS:
 *                  tanpa satu pun angka, "batasnya belum disetel" bukan
 *                  kalimat yang paling menolong. Catatan barisnya tetap
 *                  menyebut KEDUA sisi yang hilang, jadi satu keadaan tidak
 *                  pernah menyembunyikan kekurangan yang lain.
 *
 * ATURAN YANG SAMA DENGAN WatchedDeadlines: DB::table, literal string, tanpa
 * impor modul fitur — Core adalah modul yang dibergantungi semua orang.
 * Kolom dan tabel dijaga missingSchema(), jadi modul yang belum bermigrasi
 * menjadi baris SKIPPED, bukan QueryException.
 *
 * SATU ENTRI TIDAK BISA DIHITUNG DI SINI, DAN ITU DISENGAJA. `project_budget_pct`
 * mengukur realisasi + KOMITMEN terhadap RAP, dan aritmetika komitmen (PO
 * disetujui dikurangi yang sudah ditagih, SPK dikurangi opname) hidup di
 * Finance\Services\CommitmentService, tempat BudgetGateService membacanya
 * sebelum menolak sebuah PO. Menyalin SQL-nya ke sini berarti dua jawaban atas
 * satu pertanyaan — persis cacat yang paket F-2 tidak boleh kirim. Maka Core
 * MENDEKLARASIKAN entrinya (label, izin, tautan, ambang, keadaan) dan modul
 * pemilik angkanya MEMASOK barisnya lewat supply(); selama tidak ada yang
 * memasok, entri itu SKIPPED — bukan entri kosong yang terbaca "semua aman".
 */
class WatchedThresholds
{
    public const AMAN = 'aman';

    public const MENDEKATI = 'mendekati';

    public const LAMPAU = 'lampau';

    public const TANPA_BATAS = 'tanpa_batas';

    public const TIDAK_TERUKUR = 'tidak_terukur';

    /** Baris yang dibawa per entri — cukup untuk layar, terbatas. */
    public const MAX_ROWS = 50;

    /** Ambang peringatan bawaan bila config tidak menyebut entrinya. */
    private const DEFAULT_WARN_PCT = 90.0;

    /** @var array<string, Closure> pemasok baris per kunci entri */
    private static array $suppliers = [];

    /** @var array<string, array<string>|null> memo per proses: kolom per tabel */
    private static ?array $columns = null;

    /**
     * Satu entri per batas yang diawasi. Kunci:
     *
     *  key            identitas entri.
     *  label          judul di layar.
     *  measures       KALIMAT: apa yang diukur (bukan nama kolom).
     *  limit_source   KALIMAT: dari mana batasnya datang — sebuah dokumen yang
     *                 disetujui, sebuah kolom master, atau sebuah setelan.
     *                 Dicetak di layar supaya pembaca tahu siapa yang bisa
     *                 mengubah angka yang sedang menghakiminya.
     *  subject_word   satuan barisnya ("proyek", "tahun buku").
     *  unit           'rupiah' — satuan kedua sisinya.
     *  warn_key       kunci config erp.thresholds.* untuk ambang peringatan.
     *  permission     izin yang harus dipegang untuk MELIHAT entri ini.
     *  link           rute SPA yang dibuka barisnya.
     *  tables         tabel + kolom yang wajib ada; yang kurang → SKIPPED.
     *  rows           closure penghasil baris (Core menghitung sendiri), ATAU
     *  supplied_by    nama modul yang memasok barisnya lewat supply().
     *
     * Setiap baris yang dihasilkan: subject (kode), name, actual, limit, note.
     * pct dan state DIHITUNG di sini, satu tempat, supaya sebuah entri baru
     * tidak bisa menemukan aturan kejujurannya sendiri.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function entries(): array
    {
        return [
            [
                /*
                 * Berapa banyak anggaran proyek yang sudah habis — realisasi
                 * fin_project_costs DITAMBAH komitmen PO/SPK berjalan, terhadap
                 * RAP disetujui. Angka yang sama persis dengan yang dibaca
                 * BudgetGateService saat menolak sebuah PO, karena baris-baris
                 * ini datang DARI sana (lihat catatan kelas): kalau layar
                 * memberi tahu 87 % dan gerbang menolak dokumen berikutnya,
                 * salah satunya berbohong.
                 */
                'key' => 'project_budget_pct',
                'label' => 'Anggaran proyek terpakai',
                'measures' => 'Realisasi biaya proyek + komitmen PO/SPK berjalan',
                'limit_source' => 'Total RAP yang disetujui untuk proyek itu (revisi terbaru yang belum digantikan)',
                'subject_word' => 'proyek',
                'unit' => 'rupiah',
                'warn_key' => 'project_budget_pct',
                'permission' => 'fin.view',
                'link' => 'anggaran',
                'tables' => [],
                'supplied_by' => 'Finance',
            ],
            [
                /*
                 * Marjin rencana, dibaca dari sisi biayanya: RAP yang sudah
                 * memakan 90 % nilai kontrak menyisakan 10 % untuk seluruh
                 * risiko pelaksanaan. Batasnya adalah nilai kontrak proyek —
                 * dan kolom itu BERBAWAAN 0 (prj_projects.contract_value), jadi
                 * "0" di sana berarti belum dicatat, tidak pernah berarti
                 * kontrak senilai nol rupiah. Itulah baris TANPA_BATAS yang
                 * paling sering muncul di data nyata.
                 *
                 * Dihitung di Core apa adanya: dua tabel, satu penjumlahan,
                 * tidak ada aritmetika milik modul lain yang perlu disalin.
                 */
                'key' => 'rap_vs_kontrak_pct',
                'label' => 'RAP terhadap nilai kontrak',
                'measures' => 'Total baris RAP yang disetujui sebuah proyek',
                'limit_source' => 'Nilai kontrak proyek (DPP, tanpa PPN) pada master proyek',
                'subject_word' => 'proyek',
                'unit' => 'rupiah',
                'warn_key' => 'rap_vs_kontrak_pct',
                'permission' => 'est.view',
                'link' => 'anggaran',
                'tables' => [
                    'prj_projects' => ['code', 'name', 'contract_value', 'status', 'deleted_at'],
                    'est_cost_budgets' => ['code', 'project_id', 'status', 'deleted_at'],
                    'est_cost_budget_items' => ['cost_budget_id', 'amount'],
                ],
                'rows' => static fn (): array => self::rapVersusContract(),
            ],
            [
                /*
                 * Realisasi overhead perusahaan terhadap OVB tahun berjalan.
                 *
                 * Batasnya adalah dokumen yang DISETUJUI, satu per tahun buku
                 * (F-2/T2.4). Tahun tanpa OVB disetujui adalah TANPA_BATAS —
                 * belanja overheadnya tetap terjadi dan tetap terbaca di buku
                 * besar, yang tidak ada adalah angka yang boleh menghakiminya.
                 *
                 * Dihitung di Core: akun yang diukur adalah akun yang DIPILIH
                 * pemilik pada OVB-nya, jadi tidak ada daftar "akun overhead"
                 * yang perlu dikarang di sini, dan realisasinya adalah
                 * debit − kredit baris jurnal TERPOSTING tahun itu — sebuah
                 * penjumlahan, bukan aritmetika milik modul lain.
                 *
                 * Tahun yang dibaca adalah tahun berjalan menurut jam server:
                 * satu baris, karena "berapa persen anggaran tahun ini sudah
                 * terpakai" adalah satu-satunya pertanyaan yang masih bisa
                 * ditindaklanjuti — tahun lalu sudah tutup buku.
                 */
                'key' => 'overhead_budget_pct',
                'label' => 'Anggaran overhead terpakai',
                'measures' => 'Mutasi akun yang dianggarkan (debit − kredit) pada jurnal terposting tahun berjalan',
                'limit_source' => 'OVB — anggaran overhead yang disetujui untuk tahun buku itu (satu per tahun)',
                'subject_word' => 'tahun buku',
                'unit' => 'rupiah',
                'warn_key' => 'overhead_budget_pct',
                'permission' => 'fin.view',
                'link' => 'r/finance/overhead-budgets',
                'tables' => [
                    'fin_overhead_budgets' => ['code', 'period_year', 'status', 'deleted_at'],
                    'fin_overhead_budget_lines' => ['overhead_budget_id', 'account_id', 'amount'],
                    'fin_journals' => ['journal_date', 'status', 'deleted_at'],
                    'fin_journal_lines' => ['journal_id', 'account_id', 'debit', 'credit'],
                ],
                'rows' => static fn (): array => self::overheadVersusBudget(),
            ],
        ];
    }

    /**
     * Modul pemilik sebuah angka memasok barisnya sendiri.
     *
     * Dipanggil dari ServiceProvider modul itu (Finance), bukan dari sini —
     * arah ketergantungan yang sama dengan seluruh repo: fitur mengenal Core,
     * Core tidak mengenal fitur.
     *
     * @param  Closure(): array<int, array<string, mixed>>  $rows
     */
    public static function supply(string $key, Closure $rows): void
    {
        self::$suppliers[$key] = $rows;
    }

    /** Uji: satu proses menjalankan banyak aplikasi. */
    public static function flushSuppliers(): void
    {
        self::$suppliers = [];
    }

    public static function flushSchemaMemo(): void
    {
        self::$columns = null;
    }

    /**
     * Seluruh registri, dihitung.
     *
     * @return array{checked: int, skipped: list<array{key: string, reason: string}>, measures: list<array<string, mixed>>}
     */
    public static function scan(): array
    {
        $checked = 0;
        $skipped = [];
        $measures = [];

        foreach (self::entries() as $entry) {
            $reason = self::missingSchema($entry);

            if ($reason !== null) {
                $skipped[] = ['key' => $entry['key'], 'reason' => $reason];

                continue;
            }

            $checked++;
            $measures[] = self::measure($entry);
        }

        return ['checked' => $checked, 'skipped' => $skipped, 'measures' => $measures];
    }

    /**
     * Ambang peringatan entri, dalam persen. Owner decision #13 (ROADMAP §5):
     * 90 %. Config, bukan konstanta, karena angkanya milik pemilik.
     */
    public static function warnPct(string $warnKey): float
    {
        $configured = config('erp.thresholds.'.$warnKey);

        return is_numeric($configured) ? (float) $configured : self::DEFAULT_WARN_PCT;
    }

    /**
     * Keadaan satu baris. SATU tempat, dipakai baris yang dihitung Core maupun
     * baris yang dipasok modul lain — supaya "tepat 100 %" tidak pernah
     * mendarat di dua sisi yang berbeda pada dua layar.
     */
    public static function state(?float $actual, ?float $limit, float $warnPct): string
    {
        if ($actual === null) {
            return self::TIDAK_TERUKUR;
        }

        // <= 0 dan bukan hanya null: kolom nilai kontrak berbawaan 0, dan
        // membagi dengan nol untuk mendapatkan "tak hingga persen" adalah
        // karangan yang paling sulit dibantah di layar.
        if ($limit === null || $limit <= 0.0) {
            return self::TANPA_BATAS;
        }

        $pct = $actual / $limit * 100;

        if ($pct >= 100.0) {
            return self::LAMPAU;
        }

        return $pct >= $warnPct ? self::MENDEKATI : self::AMAN;
    }

    /** Persentase, atau null bila salah satu sisinya tidak ada. */
    public static function pct(?float $actual, ?float $limit): ?float
    {
        if ($actual === null || $limit === null || $limit <= 0.0) {
            return null;
        }

        return round($actual / $limit * 100, 2);
    }

    /**
     * Kalimat Indonesia satu baris untuk sebuah keadaan — dipakai layar dan
     * (kelak) badan pemberitahuan, jadi keduanya tidak pernah menamai keadaan
     * yang sama dengan dua kalimat berbeda.
     */
    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::AMAN => 'Aman',
            self::MENDEKATI => 'Mendekati batas',
            self::LAMPAU => 'Melampaui batas',
            self::TANPA_BATAS => 'Batas belum disetel',
            self::TIDAK_TERUKUR => 'Belum ada yang diukur',
            default => $state,
        };
    }

    /**
     * Alasan entri dilewati, atau null bila lengkap.
     */
    public static function missingSchema(array $entry): ?string
    {
        foreach ($entry['tables'] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return "tabel {$table} belum ada";
            }

            $missing = array_diff($columns, self::tableColumns($table) ?? []);

            if ($missing !== []) {
                return "kolom {$table}.".implode(', '.$table.'.', $missing).' belum ada';
            }
        }

        if (isset($entry['supplied_by']) && ! isset(self::$suppliers[$entry['key']])) {
            return "modul {$entry['supplied_by']} belum memasok ukurannya";
        }

        return null;
    }

    // ---------------------------------------------------------------- internal

    private static function measure(array $entry): array
    {
        $warnPct = self::warnPct($entry['warn_key']);

        $rows = isset($entry['supplied_by'])
            ? (self::$suppliers[$entry['key']])()
            : ($entry['rows'])();

        $finished = [];
        $states = [self::LAMPAU => 0, self::MENDEKATI => 0, self::AMAN => 0, self::TANPA_BATAS => 0, self::TIDAK_TERUKUR => 0];

        foreach ($rows as $row) {
            $actual = isset($row['actual']) ? (float) $row['actual'] : null;
            $limit = isset($row['limit']) && (float) $row['limit'] > 0 ? (float) $row['limit'] : null;
            $state = self::state($actual, $limit, $warnPct);
            $states[$state]++;

            $finished[] = [
                'subject' => $row['subject'],
                'name' => $row['name'] ?? null,
                'actual' => $actual,
                'limit' => $limit,
                'pct' => self::pct($actual, $limit),
                'state' => $state,
                'state_label' => self::stateLabel($state),
                'note' => $row['note'] ?? null,
                'link' => $row['link'] ?? $entry['link'],
            ];
        }

        // Yang paling dekat ke batasnya lebih dulu; baris tanpa persentase
        // turun ke bawah tanpa berpura-pura bernilai nol.
        usort($finished, static fn (array $a, array $b): int => ($b['pct'] ?? -1) <=> ($a['pct'] ?? -1));

        return [
            'key' => $entry['key'],
            'label' => $entry['label'],
            'measures' => $entry['measures'],
            'limit_source' => $entry['limit_source'],
            'subject_word' => $entry['subject_word'],
            'unit' => $entry['unit'],
            'warn_pct' => $warnPct,
            'permission' => $entry['permission'],
            'link' => $entry['link'],
            'counts' => $states,
            'total' => count($finished),
            'rows' => array_slice($finished, 0, self::MAX_ROWS),
        ];
    }

    /**
     * RAP disetujui vs nilai kontrak, per proyek yang masih berjalan.
     *
     * Proyek DITUTUP tidak ikut: anggarannya sudah sejarah, dan sebuah alarm
     * yang tidak menyisakan tindakan adalah kebisingan (aturan "masih urusan
     * seseorang" milik WatchedDeadlines).
     *
     * RAP yang dibaca: yang DISETUJUI, revisi terbaru yang belum digantikan —
     * definisi yang sama dengan BudgetGateService::rapBudget dan
     * ReportService::rapBudgetByCategory. Kolom superseded_at baru ada sejak
     * F-2/T2.5; sebelum itu klausanya dilewati, dan "terbaru menurut id" sudah
     * memilih baris yang sama.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rapVersusContract(): array
    {
        $projects = DB::table('prj_projects')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'closed')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'contract_value']);

        if ($projects->isEmpty()) {
            return [];
        }

        $budgets = DB::table('est_cost_budgets')
            ->whereIn('project_id', $projects->pluck('id')->all())
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->when(
                in_array('superseded_at', self::tableColumns('est_cost_budgets') ?? [], true),
                static fn ($query) => $query->whereNull('superseded_at'),
            )
            ->orderBy('id')
            ->get(['id', 'code', 'project_id'])
            // orderBy('id') + keyBy: yang terakhir menang, yaitu id terbesar.
            ->keyBy('project_id');

        $totals = $budgets->isEmpty() ? collect() : DB::table('est_cost_budget_items')
            ->whereIn('cost_budget_id', $budgets->pluck('id')->all())
            ->groupBy('cost_budget_id')
            ->selectRaw('cost_budget_id, SUM(amount) as total')
            ->pluck('total', 'cost_budget_id');

        $rows = [];

        foreach ($projects as $project) {
            $rap = $budgets[$project->id] ?? null;
            $contract = (float) $project->contract_value;
            $missing = [];

            if ($rap === null) {
                $missing[] = 'RAP disetujui belum ada';
            }

            if ($contract <= 0) {
                $missing[] = 'Nilai kontrak belum dicatat pada master proyek';
            }

            $rows[] = [
                'subject' => $project->code,
                'name' => $project->name,
                'actual' => $rap === null ? null : round((float) ($totals[$rap->id] ?? 0), 2),
                'limit' => $contract,
                'note' => $missing === []
                    ? 'Dari RAP '.$rap->code.'.'
                    : implode('; ', $missing).'.',
                'link' => 'd/projects/'.$project->id,
            ];
        }

        return $rows;
    }

    /**
     * Realisasi overhead tahun berjalan terhadap OVB yang disetujui.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function overheadVersusBudget(): array
    {
        $year = (int) date('Y');

        $budget = DB::table('fin_overhead_budgets')
            ->where('period_year', $year)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->first(['id', 'code']);

        if ($budget === null) {
            return [[
                'subject' => (string) $year,
                'name' => 'Tahun buku berjalan',
                // Belanja overheadnya nyata dan terbaca di buku besar; yang
                // tidak ada adalah akun-akun yang dianggarkan, jadi tidak ada
                // yang bisa dijumlahkan sebagai "realisasi overhead". null,
                // bukan Rp 0 — perusahaan ini tetap membayar sewa kantornya.
                'actual' => null,
                'limit' => null,
                'note' => 'Belum ada OVB yang disetujui untuk tahun buku ini; tanpa akun yang dianggarkan '
                    .'tidak ada realisasi overhead yang bisa dijumlahkan maupun batas yang bisa dilampaui.',
            ]];
        }

        $accountIds = DB::table('fin_overhead_budget_lines')
            ->where('overhead_budget_id', $budget->id)
            ->pluck('account_id')
            ->all();

        $limit = round((float) DB::table('fin_overhead_budget_lines')
            ->where('overhead_budget_id', $budget->id)
            ->sum('amount'), 2);

        // SETENGAH TERBUKA: kolom date tersimpan '2026-12-31 00:00:00' di
        // SQLite, dan string itu sortir SESUDAH '2026-12-31' — sebuah BETWEEN
        // membuang setiap jurnal 31 Desember.
        $actual = $accountIds === [] ? null : round((float) DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->whereIn('l.account_id', $accountIds)
            ->where('j.status', 'posted')
            ->whereNull('j.deleted_at')
            ->where('j.journal_date', '>=', $year.'-01-01')
            ->where('j.journal_date', '<', ($year + 1).'-01-01')
            ->sum(DB::raw('l.debit - l.credit')), 2);

        return [[
            'subject' => (string) $year,
            'name' => 'OVB '.$budget->code,
            'actual' => $actual,
            'limit' => $limit,
            'note' => $accountIds === []
                ? 'OVB '.$budget->code.' belum memuat satu akun pun, jadi belum ada yang bisa diukur.'
                : 'Realisasi dibaca dari '.count($accountIds).' akun yang dianggarkan OVB '.$budget->code.'.',
        ]];
    }

    /**
     * @return array<string>|null
     */
    private static function tableColumns(string $table): ?array
    {
        self::$columns ??= [];

        if (! array_key_exists($table, self::$columns)) {
            self::$columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : null;
        }

        return self::$columns[$table];
    }
}
