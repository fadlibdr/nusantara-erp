<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Money;
use Modules\Core\Support\WatchedThresholds;
use Modules\Projects\Support\PlannedCurve;

/**
 * Anggaran vs realisasi — SATU implementasi, dibaca gerbang maupun layar.
 *
 * KENAPA KELAS INI ADA. Sebelum F-2 ada satu tempat yang tahu berapa sisa
 * anggaran sebuah proyek: BudgetGateService, di dalam sebuah metode privat,
 * dipanggil hanya saat sebuah PO/SPK diajukan. Layar portofolio yang menjawab
 * pertanyaan yang sama dengan SQL-nya sendiri akan menjadi jawaban KEDUA atas
 * satu pertanyaan, dan hari ketika keduanya berbeda adalah hari ketika seorang
 * manajer proyek membaca "sisa Rp 80 juta" lalu ditolak saat memesan Rp 50
 * juta. Maka aritmetikanya pindah ke sini dan gerbang MEMBACANYA — kesetaraan
 * yang struktural, bukan kebetulan, lalu dipaku dari luar oleh
 * BudgetPortfolioEqualityTest yang mengajukan PO sungguhan tepat di batasnya.
 *
 * DEFINISINYA TIDAK BERUBAH SATU RUPIAH PUN dari yang sudah berlaku:
 *
 *   anggaran   est_cost_budget_items dari RAP DISETUJUI terbaru proyek yang
 *              belum digantikan revisi lain (§ rapOf), dibelah subkon /
 *              non-subkon persis seperti gerbang membelahnya;
 *   realisasi  fin_project_costs — buku biaya proyek yang sudah ada, diisi
 *              tagihan vendor, payroll dan bon gudang. BUKAN buku kedua;
 *   komitmen   CommitmentService (PO disetujui dikurangi yang sudah ditagih,
 *              SPK dikurangi opname yang disetujui);
 *   sisa       anggaran − realisasi − komitmen.
 *
 * Semua DPP, tanpa PPN — satuan yang sama dengan CommitmentService dan
 * fin_project_costs.
 *
 * DAN KESETARAAN ITU PER SISI, BUKAN PER TOTAL. Gerbang tidak pernah membaca
 * sisa total: sebuah PO diukur terhadap sisa NON-SUBKON, sebuah SPK terhadap
 * sisa SUBKON. Maka setiap sisi membawa keadaan, persentase dan KALIMATNYA
 * sendiri (§ project → `sides`), dan permukaan tempat uang dibelanjakan
 * mencetak sisi yang menghakiminya. Sisa total tetap dipublikasikan — ia
 * jawaban atas "berapa anggaran proyek yang sudah habis" — tetapi tidak satu
 * kalimat pun boleh menyebutnya sebagai plafon gerbang: pada data demo total
 * menjanjikan Rp 1.697.500.000 sementara sisi non-subkon sudah −Rp 105.039.400
 * dan sebuah PO Rp 1 ditolak 422.
 *
 * ANGGARAN BULANAN MENJUMLAH TEPAT SEBESAR RAP-NYA, dan itu diserap pada
 * RUPIAHNYA (§ absorbRounding), bukan disimpulkan dari bobot yang berjumlah
 * 100 %: dua belas pembulatan ke sen tidak ikut tepat hanya karena bobotnya
 * tepat, dan sebuah tabel yang totalnya Rp 1.000.000.000,00 di bawah kalimat
 * "RAP (Rp 999.999.999,99)" mencetak dua angka RAP yang berbeda di satu layar.
 *
 * ANGGARAN BULANAN ADALAH TURUNAN, DAN LAYARNYA MENGATAKANNYA. Tidak ada
 * seorang pun yang mengetik anggaran bulan Maret: ia adalah total RAP dikalikan
 * bobot fase bulan itu pada BASELINE yang dibekukan (kurva PlannedCurve yang
 * sama dengan EVM — dua implementasi kurva akhirnya berselisih, dan bulan yang
 * diperselisihkan itulah yang tidak bisa dijelaskan siapa pun). Tanpa baseline
 * tidak ada bobot fase, dan tanpa bobot fase TIDAK ADA anggaran bulanan: selnya
 * DIGARIS dan barisnya menyebutkan sebabnya — tidak diratakan 1/12, tidak
 * ditaksir, tidak dinolkan.
 *
 * BULAN TANPA REALISASI = null, BUKAN 0. Sebuah 0 di kolom realisasi bulan
 * depan terbaca "anggaran terjaga"; yang benar adalah "belum ada apa-apa yang
 * tercatat". Sejak verifikasi F-2 aturan itu berlaku juga untuk TOTAL realisasi
 * proyek di portofolio: sebuah proyek yang belum punya satu baris
 * fin_project_costs pun digaris, bukan dicetak "Rp 0" di antara sel-sel yang
 * memang digaris. Yang tetap berupa angka adalah apa yang masuk ke ARITMETIKA
 * gerbang (sisa = anggaran − realisasi − komitmen): nol baris memang menambah
 * nol rupiah pada pengurangan itu, dan gerbang tidak pernah mencetak apa pun.
 */
class BudgetRealisationService
{
    /** Locale aplikasi 'en' (config/app.php); nama bulan Indonesia ditulis, tidak diterjemahkan runtime. */
    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __construct(private readonly CommitmentService $commitments) {}

    // ------------------------------------------------------------- satu sisi

    /**
     * Sisi anggaran satu proyek, dalam bentuk yang dibaca gerbang PO/SPK.
     *
     * `budget` null berarti tidak ada anggaran untuk dilampaui (modul Estimation
     * absen, atau proyek belum punya RAP disetujui) — dan gerbang DIAM, karena
     * menolak pembelian atas nama anggaran yang tidak ada hanyalah mengarang
     * blokir. `remaining` ikut null di situ, tidak pernah 0.
     *
     * @return array{budget: ?float, actual: float, committed: float, remaining: ?float}
     */
    public function side(int $projectId, bool $subcon): array
    {
        $budget = $this->rapBudget($projectId, $subcon);
        $actual = $this->actualCost($projectId, $subcon);
        $committed = $this->committed($projectId, $subcon);

        return [
            'budget' => $budget,
            'actual' => $actual,
            'committed' => $committed,
            'remaining' => $budget === null ? null : round($budget - $actual - $committed, 2),
        ];
    }

    // ------------------------------------------------------------ satu proyek

    /**
     * Kedua sisi + totalnya + keadaan ambangnya, untuk satu proyek.
     *
     * KENAPA `sides` ADA (verifikasi F-2). Gerbang tidak pernah menghakimi
     * TOTAL: sebuah PO diukur terhadap sisa NON-SUBKON dan sebuah SPK terhadap
     * sisa SUBKON. Muatan versi pertama hanya membawa keadaan, persentase dan
     * KALIMAT total, dan tiga permukaan tempat uang dibelanjakan mencetak
     * kalimat itu sambil menjanjikan bahwa gerbang menegakkannya. Terukur pada
     * salinan data demo: layar menjanjikan "sisa Rp 1.697.500.000" sementara
     * sisi non-subkonnya −Rp 105.039.400, dan sebuah PO Rp 1 ditolak 422. Maka
     * setiap sisi kini membawa keadaan, persentase dan kalimatnya SENDIRI —
     * kalimat yang memakai kata-kata yang sama dengan penolakan gerbang — dan
     * `worst_state` adalah sisi yang paling dekat ke batasnya, yaitu keadaan
     * yang menentukan apakah layar proyek menyalakan peringatannya.
     *
     * `pct` dan `state` TETAP total: itu jawaban jujur atas "berapa banyak
     * anggaran proyek ini yang sudah habis", angka yang sama yang dipublikasikan
     * registri ambang, dan mengubahnya menjadi "sisi terburuk" akan membuat satu
     * entri registri punya dua definisi.
     *
     * @return array<string, mixed>
     */
    public function project(int $projectId): array
    {
        $rap = $this->rapOf($projectId);
        $subcon = $this->side($projectId, true);
        $nonSubcon = $this->side($projectId, false);

        $budget = $rap === null ? null : round((float) $subcon['budget'] + (float) $nonSubcon['budget'], 2);
        $actual = round($subcon['actual'] + $nonSubcon['actual'], 2);
        $committed = round($subcon['committed'] + $nonSubcon['committed'], 2);
        $used = round($actual + $committed, 2);

        $warnPct = WatchedThresholds::warnPct('project_budget_pct');

        $sides = [
            'non_subcon' => $this->sideView('non_subcon', false, $nonSubcon, $rap?->code, $warnPct),
            'subcon' => $this->sideView('subcon', true, $subcon, $rap?->code, $warnPct),
        ];
        $worst = $rap === null ? null : $this->worstSide($sides);

        return [
            'project_id' => $projectId,
            'rap_code' => $rap?->code,
            'rap_revision' => $rap === null ? null : (int) ($rap->revision ?? 0),
            'budget' => $budget,
            'budget_subcon' => $subcon['budget'],
            'budget_non_subcon' => $nonSubcon['budget'],
            // NOL BARIS BIAYA = BELUM ADA YANG TERCATAT, bukan Rp 0 (verifikasi
            // F-2). Aturan yang sama yang sudah dipatuhi kolom realisasi per
            // bulan, dan yang dicetak layar portofolio sebagai kalimatnya
            // sendiri: "Kolom realisasi '—' berarti bulan itu belum punya satu
            // baris biaya pun. Itu bukan Rp 0." Terukur: PRJ-2026-002 tanpa
            // satu baris fin_project_costs pun mencetak "Rp 0" di antara tiga
            // sel yang benar-benar digaris.
            'actual' => $this->hasCostRows($projectId) ? $actual : null,
            'committed' => $committed,
            'used' => $used,
            'remaining' => $budget === null ? null : round($budget - $used, 2),
            'remaining_subcon' => $subcon['remaining'],
            'remaining_non_subcon' => $nonSubcon['remaining'],
            'pct' => WatchedThresholds::pct($used, $budget),
            'state' => WatchedThresholds::state($used, $budget, $warnPct),
            'warn_pct' => $warnPct,
            // Kalimat yang dibaca manusia, dibangun satu kali di server supaya
            // layar proyek, layar anggaran dan formulir PO tidak mengarang tiga
            // kalimat berbeda untuk satu keadaan.
            'sentence' => $this->sentence($budget, $used, $rap?->code, $sides),
            'sides' => $sides,
            'worst_side' => $worst['key'] ?? null,
            'worst_state' => $worst['state'] ?? WatchedThresholds::TANPA_BATAS,
        ];
    }

    /**
     * Kata-kata yang dipakai gerbang untuk menamai sisi yang dihakiminya.
     *
     * Dipakai BudgetGateService pada kalimat penolakannya DAN kalimat sisi di
     * sini, supaya penolakan dan peringatan tidak pernah menamai satu hal yang
     * sama dengan dua istilah berbeda.
     */
    public static function sideLabel(bool $subcon): string
    {
        return $subcon ? 'subkon' : 'non-subkon (material/upah/alat/overhead)';
    }

    public static function committedLabel(bool $subcon): string
    {
        return $subcon ? 'komitmen SPK berjalan' : 'komitmen PO berjalan';
    }

    public static function documentLabel(bool $subcon): string
    {
        return $subcon ? 'SPK' : 'PO';
    }

    // ------------------------------------------------------------- portofolio

    /**
     * Satu baris per proyek. Angka-angkanya PERSIS yang dibaca gerbang.
     *
     * @return array<int, array<string, mixed>>
     */
    public function portfolio(bool $includeClosed = false): array
    {
        $projects = DB::table('prj_projects')
            ->whereNull('deleted_at')
            ->when(! $includeClosed, fn ($query) => $query->where('status', '!=', 'closed'))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'status', 'contract_value']);

        $rows = [];

        foreach ($projects as $project) {
            $rows[] = [
                'project_code' => $project->code,
                'project_name' => $project->name,
                'status' => $project->status,
                // Kolom berbawaan 0: 0 berarti BELUM DICATAT, bukan kontrak nol
                // rupiah. Dikirim null supaya layar menggarisnya, bukan
                // mencetak "Rp 0" untuk nilai yang tidak diketahui siapa pun.
                'contract_value' => (float) $project->contract_value > 0 ? round((float) $project->contract_value, 2) : null,
            ] + $this->project((int) $project->id);
        }

        return $rows;
    }

    /**
     * Baris untuk registri Core (WatchedThresholds entri project_budget_pct).
     *
     * Inilah alasan mekanisme supply() ada: angkanya lahir di sini, tempat
     * gerbang membacanya, dan Core tidak perlu menyalin satu baris SQL pun.
     *
     * YANG DIKIRIM ADALAH SISI TERKETAT, bukan totalnya (verifikasi F-2).
     * Sebuah registri ambang menjawab "apa yang mendekati atau melewati
     * batasnya"; satu-satunya batas yang benar-benar ditegakkan adalah batas
     * per sisi, dan sebuah proyek yang totalnya 16,7 % terpakai sementara sisi
     * PO-nya sudah habis akan berbaris tenang di antara yang aman. Totalnya
     * tetap dicetak — pada catatan barisnya, dengan namanya sendiri.
     *
     * @return array<int, array<string, mixed>>
     */
    public function thresholdRows(): array
    {
        $rows = [];

        foreach ($this->portfolio() as $row) {
            $worst = $row['worst_side'] === null ? null : $row['sides'][$row['worst_side']];

            $rows[] = [
                'subject' => $row['project_code'],
                'name' => $row['project_name'],
                'actual' => $worst === null ? $row['used'] : $worst['used'],
                // Batas sisi terburuk APA ADANYA, nol termasuk. Sebuah RAP yang
                // menganggarkan Rp 0 untuk satu sisi MENYETEL batas sisi itu;
                // mengirimkannya sebagai "tidak ada batas" membuat sisi yang
                // sudah dibelanjakan di atas nol hilang dari registri
                // (verifikasi F-2 putaran 2). Yang benar-benar tidak punya
                // batas adalah proyek tanpa RAP — dan di sana $row['budget']
                // memang null.
                'limit' => $worst === null ? $row['budget'] : $worst['budget'],
                'note' => match (true) {
                    $worst === null => 'RAP disetujui belum ada, jadi tidak ada anggaran yang bisa dilampaui.',
                    (float) $worst['budget'] <= 0.0 && (float) $worst['used'] <= 0.0 => sprintf(
                        'RAP %s tidak menganggarkan satu rupiah pun untuk %s, dan belum ada yang terpakai '
                        .'di sisi itu — gerbang menolak setiap %s sampai pengajunya mengonfirmasi '
                        .'pelampauan. Seluruh proyek: %s dari %s.',
                        $row['rap_code'],
                        $worst['label'],
                        $worst['document'],
                        Money::format($row['used'], false),
                        Money::format($row['budget'], false),
                    ),
                    (float) $worst['budget'] <= 0.0 => sprintf(
                        'RAP %s tidak menganggarkan satu rupiah pun untuk %s, sementara %s sudah terpakai '
                        .'di sisi itu — gerbang menolak setiap %s berikutnya. Seluruh proyek: %s dari %s.',
                        $row['rap_code'],
                        $worst['label'],
                        Money::format($worst['used'], false),
                        $worst['document'],
                        Money::format($row['used'], false),
                        Money::format($row['budget'], false),
                    ),
                    default => sprintf(
                        'Sisi %s — realisasi %s + komitmen %s terhadap RAP %s. Seluruh proyek: %s dari %s.',
                        $worst['label'],
                        Money::format($worst['actual'], false),
                        Money::format($worst['committed'], false),
                        $row['rap_code'],
                        Money::format($row['used'], false),
                        Money::format($row['budget'], false),
                    ),
                },
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------- per bulan

    /**
     * Anggaran vs realisasi per bulan untuk satu proyek.
     *
     * @return array<string, mixed>
     */
    public function monthly(int $projectId): array
    {
        $rap = $this->rapOf($projectId);
        $rapTotal = $rap === null ? null : $this->rapTotal((int) $rap->id);
        $baseline = $this->baselineOf($projectId);
        $weights = $baseline === null ? [] : $this->monthlyWeights((int) $baseline->id);
        $actuals = $this->actualByMonth($projectId);

        $periods = array_values(array_unique(array_merge(array_keys($weights), array_keys($actuals))));
        sort($periods);

        $budgets = [];

        foreach ($periods as $period) {
            $weight = $weights[$period] ?? null;
            $budgets[$period] = ($weight === null || $rapTotal === null)
                ? null
                : round($rapTotal * $weight / 100, 2);
        }

        $budgets = $this->absorbRounding($budgets, $rapTotal);

        $rows = [];
        $cumBudget = 0.0;
        $cumActual = 0.0;
        $anyBudget = false;
        $anyActual = false;

        foreach ($periods as $period) {
            $weight = $weights[$period] ?? null;
            $budget = $budgets[$period];
            // Bulan tanpa satu baris biaya pun = null, bukan 0 (lihat docblock).
            $actual = $actuals[$period] ?? null;

            if ($budget !== null) {
                $cumBudget = round($cumBudget + $budget, 2);
                $anyBudget = true;
            }

            if ($actual !== null) {
                $cumActual = round($cumActual + $actual, 2);
                $anyActual = true;
            }

            $rows[] = [
                'period' => $period,
                'label' => $this->periodLabel($period),
                'weight_pct' => $weight,
                'budget' => $budget,
                'budget_state' => $this->monthBudgetState($baseline !== null, $weight, $rapTotal),
                'actual' => $actual,
                'variance' => ($budget === null || $actual === null) ? null : round($budget - $actual, 2),
                'cumulative_budget' => $anyBudget ? $cumBudget : null,
                'cumulative_actual' => $anyActual ? $cumActual : null,
            ];
        }

        // Identitas proyeknya ikut dalam muatan (verifikasi F-2): di KERTAS,
        // saringan proyek dan bilah tab disembunyikan @media print, jadi lembar
        // "Per bulan" yang dicetak dulu tidak menyebut proyek mana pun — 17
        // baris anggaran vs realisasi senilai Rp 24.250.000.000 tanpa satu
        // penanda pun tentang milik siapa angka itu.
        $project = $this->projectOf($projectId);

        return [
            'project_id' => $projectId,
            'project_code' => $project?->code,
            'project_name' => $project?->name,
            'rap_code' => $rap?->code,
            'rap_total' => $rapTotal,
            'baseline_code' => $baseline?->code,
            'baseline_revision' => $baseline === null ? null : (int) $baseline->revision_no,
            'derived' => $baseline !== null && $rapTotal !== null,
            'derivation' => $this->derivation($rap?->code, $rapTotal, $baseline?->code, $baseline?->revision_no),
            'rows' => $rows,
            'totals' => [
                'budget' => $anyBudget ? $cumBudget : null,
                'actual' => $anyActual ? $cumActual : null,
            ],
        ];
    }

    // ---------------------------------------------------------------- internal

    /**
     * RAP yang MENGATUR proyek ini hari ini: disetujui, revisi terbaru yang
     * belum digantikan.
     *
     * Definisi yang sama dengan BudgetGateService sebelum F-2 ("approved,
     * orderByDesc(id)") — sebuah revisi selalu ber-id lebih besar daripada yang
     * digantikannya, jadi klausa superseded_at menyempitkan tanpa mengubah
     * jawaban pada data yang belum pernah direvisi (dipaku
     * RapRevisionTest::test_an_approved_rap_without_revisions_answers_exactly_as_before).
     * Kolomnya baru ada sejak T2.5; sebelum itu klausanya dilewati.
     */
    private function rapOf(int $projectId): ?object
    {
        if (! Schema::hasTable('est_cost_budgets') || ! Schema::hasTable('est_cost_budget_items')) {
            return null;
        }

        return DB::table('est_cost_budgets')
            ->where('project_id', $projectId)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('deleted_at')
            ->when(
                Schema::hasColumn('est_cost_budgets', 'superseded_at'),
                fn ($query) => $query->whereNull('superseded_at'),
            )
            ->orderByDesc('id')
            ->first();
    }

    private function rapBudget(int $projectId, bool $subcon): ?float
    {
        $rap = $this->rapOf($projectId);

        if ($rap === null) {
            return null;
        }

        return round((float) DB::table('est_cost_budget_items')
            ->where('cost_budget_id', $rap->id)
            ->when(
                $subcon,
                fn ($query) => $query->where('cost_category', 'subcon'),
                fn ($query) => $query->where('cost_category', '!=', 'subcon'),
            )
            ->sum('amount'), 2);
    }

    private function rapTotal(int $rapId): float
    {
        return round((float) DB::table('est_cost_budget_items')->where('cost_budget_id', $rapId)->sum('amount'), 2);
    }

    private function actualCost(int $projectId, bool $subcon): float
    {
        if (! Schema::hasTable('fin_project_costs')) {
            return 0.0;
        }

        return round((float) DB::table('fin_project_costs')
            ->where('project_id', $projectId)
            ->when(
                $subcon,
                fn ($query) => $query->where('cost_category', 'subcon'),
                fn ($query) => $query->where('cost_category', '!=', 'subcon'),
            )
            ->sum('amount'), 2);
    }

    /** Kode dan nama proyek — untuk kepala kartu, dan untuk kertas. */
    private function projectOf(int $projectId): ?object
    {
        if (! Schema::hasTable('prj_projects')) {
            return null;
        }

        return DB::table('prj_projects')->where('id', $projectId)->first(['code', 'name']);
    }

    /**
     * Apakah buku biaya proyek ini punya satu baris pun.
     *
     * Sebuah COUNT, bukan sebuah SUM: nol baris ("belum ada yang tercatat") dan
     * baris-baris yang kebetulan berjumlah nol rupiah adalah dua fakta yang
     * berbeda, dan hanya yang pertama yang harus digaris. Yang MASUK KE
     * ARITMETIKA gerbang tetap 0.0 — sisa = anggaran − 0 − komitmen adalah
     * jawaban yang benar untuk proyek yang belum membelanjakan apa pun; yang
     * berubah hanyalah angka yang DICETAK di kolom Realisasi.
     */
    private function hasCostRows(int $projectId): bool
    {
        if (! Schema::hasTable('fin_project_costs')) {
            return false;
        }

        return DB::table('fin_project_costs')->where('project_id', $projectId)->exists();
    }

    private function committed(int $projectId, bool $subcon): float
    {
        $committed = $this->commitments->forProject($projectId);

        return (float) ($subcon ? $committed['subcontracts'] : $committed['purchase_orders']);
    }

    /**
     * Realisasi per bulan, dikunci 'YYYY-MM'.
     *
     * Dikelompokkan di PHP, bukan lewat DATE_FORMAT/strftime: dua driver, dua
     * dialek, dan tanggal yang tersimpan sebagai '2026-03-01 00:00:00' di
     * SQLite — substr(…, 0, 7) menjawab sama di keduanya untuk kedua bentuk
     * penyimpanan.
     *
     * @return array<string, float>
     */
    private function actualByMonth(int $projectId): array
    {
        if (! Schema::hasTable('fin_project_costs')) {
            return [];
        }

        $months = [];

        foreach (DB::table('fin_project_costs')->where('project_id', $projectId)->get(['cost_date', 'amount']) as $row) {
            $period = substr((string) $row->cost_date, 0, 7);
            $months[$period] = round(($months[$period] ?? 0.0) + (float) $row->amount, 2);
        }

        return $months;
    }

    /**
     * Baseline yang berlaku: disetujui dan belum digantikan — "rencana" milik
     * proyek, definisi yang sama dengan ProjectBaseline::isCurrent().
     */
    private function baselineOf(int $projectId): ?object
    {
        if (! Schema::hasTable('prj_baselines') || ! Schema::hasTable('prj_baseline_tasks')) {
            return null;
        }

        return DB::table('prj_baselines')
            ->where('project_id', $projectId)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Sisa pembulatan RUPIAH dipikul bulan terakhir yang punya anggaran.
     *
     * KENAPA BUKAN DI BOBOTNYA (verifikasi F-2). monthlyWeights sudah memaksa
     * jumlah BOBOT tepat 100 %, dan docblock-nya dulu menyimpulkan dari situ
     * bahwa "anggaran bulanan menjumlah tepat sebesar RAP-nya". Tidak: tiap
     * bulan dibulatkan ke sen SENDIRI-SENDIRI (round(rapTotal × bobot / 100, 2)),
     * dan sisa-sisa itu tidak pernah diserap. Terukur pada tujuh kasus, lima di
     * antaranya meleset: Rp 42.173.913.043 / 17 bulan menjumlah +Rp 0,01,
     * Rp 123.456.789,01 / 12 bulan −Rp 0,01, Rp 100 / 3 bulan −Rp 0,01, dan
     * Rp 999.999.999,99 / 17 bulan menjumlah Rp 1.000.000.000,00 — layar
     * mencetak kalimat penurunan "RAP … (Rp 999.999.999,99)" tepat di atas
     * tabel yang totalnya berbunyi satu miliar. Pola $remainder yang sama
     * dengan RapService::splitBudget.
     *
     * @param  array<string, ?float>  $budgets
     * @return array<string, ?float>
     */
    private function absorbRounding(array $budgets, ?float $rapTotal): array
    {
        if ($rapTotal === null) {
            return $budgets;
        }

        $sum = 0.0;
        $lastKey = null;

        foreach ($budgets as $period => $budget) {
            if ($budget === null) {
                continue;
            }

            $sum = round($sum + $budget, 2);
            $lastKey = $period;
        }

        if ($lastKey === null) {
            return $budgets;
        }

        $remainder = round($rapTotal - $sum, 2);

        if ($remainder !== 0.0) {
            $budgets[$lastKey] = round($budgets[$lastKey] + $remainder, 2);
        }

        return $budgets;
    }

    /**
     * Bobot fase per bulan, dalam PERSEN, dari kurva rencana baseline.
     *
     * Selisih kumulatif antar akhir bulan — persis kurva yang dibaca EVM, lewat
     * PlannedCurve yang sama. Bulan terakhir memikul sisa pembulatan BOBOT,
     * supaya jumlah bobot selalu tepat 100 %; sisa pembulatan RUPIAH-nya diserap
     * terpisah oleh absorbRounding(), karena 100 % bobot yang tepat tidak
     * membuat dua belas pembulatan ke sen ikut tepat.
     *
     * @return array<string, float>
     */
    private function monthlyWeights(int $baselineId): array
    {
        $tasks = DB::table('prj_baseline_tasks')
            ->where('baseline_id', $baselineId)
            ->where('is_leaf', true)
            ->get(['weight_pct', 'planned_start', 'planned_end']);

        if ($tasks->isEmpty()) {
            return [];
        }

        $points = PlannedCurve::monthlyPoints($tasks->map(fn ($task): array => [
            'weight_pct' => (float) $task->weight_pct,
            'planned_start' => $task->planned_start,
            'planned_end' => $task->planned_end,
        ])->all(), 0.0);

        $weights = [];
        $previous = 0.0;

        foreach ($points as $point) {
            $period = substr($point['period_end'], 0, 7);
            $weights[$period] = round($point['planned_pct'] - $previous, 6);
            $previous = $point['planned_pct'];
        }

        if ($weights !== []) {
            $lastKey = array_key_last($weights);
            $weights[$lastKey] = round($weights[$lastKey] + (100.0 - $previous), 6);
        }

        return $weights;
    }

    /**
     * Kenapa sel anggaran sebuah bulan kosong — satu dari tiga sebab, dan
     * masing-masing dicetak layar apa adanya.
     */
    private function monthBudgetState(bool $hasBaseline, ?float $weight, ?float $rapTotal): string
    {
        if (! $hasBaseline) {
            return 'tanpa_baseline';
        }

        if ($rapTotal === null) {
            return 'tanpa_rap';
        }

        return $weight === null ? 'di_luar_rentang_baseline' : 'turunan';
    }

    private function derivation(?string $rapCode, ?float $rapTotal, ?string $baselineCode, ?int $revision): string
    {
        if ($baselineCode === null) {
            return 'Anggaran bulanan TIDAK dihitung: proyek ini belum punya baseline yang disetujui, '
                .'jadi tidak ada bobot fase untuk membagi RAP ke bulan. Selnya digaris — meratakan RAP '
                .'per dua belas bulan akan mengarang rencana yang tidak pernah disetujui siapa pun.';
        }

        if ($rapCode === null) {
            return 'Anggaran bulanan TIDAK dihitung: proyek ini belum punya RAP yang disetujui, '
                .'jadi tidak ada total yang bisa dibagi menurut bobot fase baseline '.$baselineCode.'.';
        }

        return sprintf(
            'Anggaran bulanan = RAP %s (%s) × bobot fase baseline %s revisi %d. Angka TURUNAN — '
            .'tidak ada satu pun anggaran bulanan yang diketik orang, dan mengubah baseline atau RAP '
            .'mengubah seluruh kolomnya.',
            $rapCode,
            Money::format($rapTotal, false),
            $baselineCode,
            $revision ?? 0,
        );
    }

    /**
     * Satu sisi anggaran dalam bentuk yang dibaca LAYAR: keadaan, persentase
     * dan kalimatnya sendiri.
     *
     * @param  array{budget: ?float, actual: float, committed: float, remaining: ?float}  $side
     * @return array<string, mixed>
     */
    private function sideView(string $key, bool $subcon, array $side, ?string $rapCode, float $warnPct): array
    {
        $budget = $side['budget'];
        $used = round($side['actual'] + $side['committed'], 2);

        return [
            'key' => $key,
            'document' => self::documentLabel($subcon),
            'label' => self::sideLabel($subcon),
            'budget' => $budget,
            'actual' => $side['actual'],
            'committed' => $side['committed'],
            'used' => $used,
            'remaining' => $side['remaining'],
            'pct' => WatchedThresholds::pct($used, $budget),
            // Keadaan sisi dihitung aturan yang sama dengan setiap baris
            // registri — termasuk aturan batas Rp 0: dianggarkan nol dan sudah
            // dibelanjakan adalah LAMPAU, dianggarkan nol dan belum
            // dibelanjakan adalah TANPA_ANGGARAN. Aturan itu dulu hidup di sini
            // sebagai tambahan lokal, dan registri tidak ikut membacanya —
            // sehingga satu proyek mencetak dua keadaan di dua layar
            // (verifikasi F-2 putaran 2).
            'state' => WatchedThresholds::state($used, $budget, $warnPct),
            'sentence' => $this->sideSentence($subcon, $budget, $side, $used, $rapCode),
        ];
    }

    /**
     * Kalimat satu sisi, dengan kata-kata yang sama yang dipakai gerbang saat
     * menolak dokumen di sisi itu (BudgetGateService::assertWithinBudget).
     *
     * @param  array{budget: ?float, actual: float, committed: float, remaining: ?float}  $side
     */
    private function sideSentence(bool $subcon, ?float $budget, array $side, float $used, ?string $rapCode): string
    {
        $document = self::documentLabel($subcon);

        if ($budget === null) {
            return 'Proyek ini belum punya RAP yang disetujui, jadi tidak ada anggaran yang bisa dilampaui '
                .'— dan gerbang anggaran pada '.$document.' diam untuk proyek ini.';
        }

        if ($budget <= 0.0) {
            return sprintf(
                'RAP %s tidak menganggarkan satu rupiah pun untuk %s, jadi gerbang menolak setiap %s '
                .'proyek ini sampai pengajunya mengonfirmasi pelampauan%s.',
                $rapCode,
                self::sideLabel($subcon),
                $document,
                $used > 0.0 ? ' — dan '.Money::format($used, false).' sudah terpakai di sisi ini' : '',
            );
        }

        $pct = WatchedThresholds::pct($used, $budget);
        $remaining = (float) $side['remaining'];
        $terpakai = $pct === null ? 'tidak terhitung' : number_format($pct, 1, ',', '.').' %';

        if ($remaining < 0.0) {
            return sprintf(
                'Anggaran RAP %s %s; realisasi %s dan %s %s sudah melampaui anggaran sisi ini sebesar %s '
                .'— tidak ada DPP %s yang diterima gerbang tanpa konfirmasi pelampauan (%s terpakai).',
                self::sideLabel($subcon),
                Money::format($budget, false),
                Money::format($side['actual'], false),
                self::committedLabel($subcon),
                Money::format($side['committed'], false),
                self::overrunRupiah($remaining),
                $document,
                $terpakai,
            );
        }

        return sprintf(
            'Anggaran RAP %s %s; realisasi %s dan %s %s menyisakan %s — DPP terbesar dalam rupiah penuh '
            .'yang masih diterima gerbang tanpa konfirmasi pelampauan (%s terpakai).',
            self::sideLabel($subcon),
            Money::format($budget, false),
            Money::format($side['actual'], false),
            self::committedLabel($subcon),
            Money::format($side['committed'], false),
            self::ceilingRupiah($remaining),
            $terpakai,
        );
    }

    /**
     * Sebuah PLAFON dinyatakan dengan pembulatan KE BAWAH; sebuah PELAMPAUAN
     * dengan pembulatan KE ATAS. Dua arah, satu alasan: kalimat ini tidak boleh
     * menamai angka yang gerbangnya tolak, dan tidak boleh menyebut pelampauan
     * yang lebih kecil daripada yang sungguh terjadi.
     *
     * Money::format(…, false) memakai number_format(…, 0), yang membulatkan
     * SETENGAH KE ATAS. Terukur (verifikasi F-2 putaran 2): sisa
     * Rp 66.666.666,67 dicetak "Rp 66.666.667", lalu PO dengan DPP tepat
     * sebesar itu ditolak 422 — dengan kalimat penolakan yang menyebut
     * pelampauan "Rp 0", kalimat yang tidak bisa ditindaklanjuti siapa pun.
     */
    public static function ceilingRupiah(float $remaining): string
    {
        return Money::format(floor(round($remaining, 2)), false);
    }

    /** Pelampauan (sisa negatif) sebagai rupiah positif, dibulatkan KE ATAS. */
    public static function overrunRupiah(float $remaining): string
    {
        return Money::format(ceil(abs(round($remaining, 2))), false);
    }

    /**
     * Sisi yang paling dekat ke batasnya — keadaan yang menyalakan peringatan
     * di layar proyek. Lampau mengalahkan mendekati, mendekati mengalahkan
     * aman; di antara keadaan yang sama, persentase yang lebih tinggi menang.
     *
     * @param  array<string, array<string, mixed>>  $sides
     * @return array<string, mixed>|null
     */
    private function worstSide(array $sides): ?array
    {
        $worst = null;

        foreach ($sides as $side) {
            if ($worst === null) {
                $worst = $side;

                continue;
            }

            // Peringkat keadaan dari WatchedThresholds — satu definisi
            // "lebih buruk" untuk urutan registri dan pemilihan sisi ini,
            // supaya dua layar tidak bisa menemukan urutan masing-masing.
            $a = [WatchedThresholds::stateRank($side['state']), $side['pct'] ?? -1];
            $b = [WatchedThresholds::stateRank($worst['state']), $worst['pct'] ?? -1];

            if ($a > $b) {
                $worst = $side;
            }
        }

        return $worst;
    }

    /**
     * Kalimat proyek KESELURUHAN — dan ia menyebutkan kedua batas yang
     * benar-benar dihakimi gerbang.
     *
     * Versi pertama berhenti pada "sisa Rp X" dan tiga permukaan mencetaknya
     * sebagai plafon gerbang. Sisa total BUKAN plafon apa pun: ia jumlah dua
     * plafon yang dihakimi terpisah, dan pada data demo selisihnya sebuah PO
     * Rp 1 yang ditolak di bawah janji "sisa Rp 1.697.500.000".
     *
     * @param  array<string, array<string, mixed>>  $sides
     */
    private function sentence(?float $budget, float $used, ?string $rapCode, array $sides): string
    {
        if ($budget === null) {
            return 'Proyek ini belum punya RAP yang disetujui, jadi tidak ada anggaran yang bisa dilampaui '
                .'— dan gerbang anggaran pada PO/SPK diam untuk proyek ini.';
        }

        $pct = WatchedThresholds::pct($used, $budget);
        $remaining = round($budget - $used, 2);

        return sprintf(
            'Realisasi + komitmen %s dari anggaran RAP %s (%s) — %s terpakai, %s. '
            .'Gerbang menghakimi PER SISI: %s, %s.',
            Money::format($used, false),
            $rapCode,
            Money::format($budget, false),
            $pct === null ? 'tidak terhitung' : number_format($pct, 1, ',', '.').' %',
            // "sisa Rp 0" untuk proyek yang 140 % terpakai bukan "tidak
            // menjanjikan sisa negatif" — ia menyebut satu angka yang salah
            // (sisanya −Rp 40.000.000) tepat di sebelah persentase yang benar.
            $remaining < 0.0
                ? 'sudah lampau '.self::overrunRupiah($remaining)
                : 'sisa '.self::ceilingRupiah($remaining),
            $this->ceilingPhrase($sides['non_subcon']),
            $this->ceilingPhrase($sides['subcon']),
        );
    }

    /**
     * @param  array<string, mixed>  $side
     */
    private function ceilingPhrase(array $side): string
    {
        if ((float) $side['budget'] <= 0.0) {
            return $side['document'].' tidak dianggarkan';
        }

        $remaining = (float) $side['remaining'];

        // Ubin layar proyek mencetak pelampauan sisi ini sebagai
        // "PO melampaui Rp 105.039.400"; kalimat yang sama di pita di bawahnya
        // dulu berbunyi "PO menyisakan Rp 0" — dua angka untuk satu fakta, di
        // satu layar (verifikasi F-2 putaran 2).
        if ($remaining < 0.0) {
            return sprintf('%s melampaui %s', $side['document'], self::overrunRupiah($remaining));
        }

        return sprintf('%s menyisakan %s', $side['document'], self::ceilingRupiah($remaining));
    }

    private function periodLabel(string $period): string
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        return (self::BULAN[$month - 1] ?? (string) $month).' '.$year;
    }
}
