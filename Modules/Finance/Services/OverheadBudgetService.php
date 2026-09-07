<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Money;
use Modules\Core\Support\WatchedThresholds;
use Modules\Finance\Models\OverheadBudget;

/**
 * OVB — anggaran overhead per tahun buku, dan realisasinya dari buku besar.
 *
 * SATU YANG DISETUJUI PER TAHUN, DIJAGA DUA KALI. Di sini, dengan kalimat yang
 * menyebut kode anggaran yang sudah berdiri (supaya orangnya tahu harus
 * membatalkan yang mana), dan di indeks unik parsial/kolom generated migrasi
 * 001500 sebagai jaring terakhir — lockForUpdate() adalah no-op diam di SQLite,
 * jadi dua persetujuan yang tiba bersamaan hanya bisa dihentikan oleh basis
 * datanya. QueryException dari indeks itu diterjemahkan kembali menjadi
 * kalimat yang sama, bukan 500.
 *
 * REALISASI DARI JURNAL YANG SUDAH ADA, BUKAN BUKU KEDUA. Untuk tiap akun yang
 * dianggarkan: debit − kredit pada baris jurnal TERPOSTING bertanggal di dalam
 * tahun itu. Akun overhead bersaldo normal debit, jadi selisih itu adalah
 * belanjanya; sebuah pembalik (kredit) mengurangi realisasi persis sebagaimana
 * ia mengurangi saldo buku besarnya. Tidak ada daftar "akun overhead" yang
 * dikarang di kode — yang dianggarkan adalah akun yang DIPILIH pemilik.
 *
 * FORWARD-ONLY. Menyetujui, menolak, atau membuang sebuah OVB tidak memposting
 * satu baris jurnal pun dan tidak menyentuh satu baris jurnal pun yang sudah
 * ada: sebuah anggaran adalah rencana, bukan transaksi. Yang berubah saat OVB
 * disetujui hanyalah BATAS yang dipakai membaca angka yang sudah tercatat.
 */
class OverheadBudgetService
{
    public function create(array $data, ?User $by = null): OverheadBudget
    {
        return DB::transaction(function () use ($data, $by): OverheadBudget {
            /** @var OverheadBudget $budget */
            $budget = OverheadBudget::query()->create([
                'code' => $data['code'] ?? null,
                'period_year' => (int) $data['period_year'],
                'status' => DocumentStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by?->id,
            ]);

            if (array_key_exists('lines', $data)) {
                $this->replaceLines($budget, $data['lines'] ?? []);
            }

            return $budget->refresh();
        });
    }

    public function update(OverheadBudget $budget, array $data): OverheadBudget
    {
        $this->assertEditable($budget, 'diubah');

        return DB::transaction(function () use ($budget, $data): OverheadBudget {
            $budget->fill([
                'period_year' => isset($data['period_year']) ? (int) $data['period_year'] : $budget->period_year,
                'notes' => $data['notes'] ?? $budget->notes,
            ])->save();

            if (array_key_exists('lines', $data)) {
                $this->replaceLines($budget, $data['lines'] ?? []);
            }

            return $budget->refresh();
        });
    }

    /**
     * Ganti seluruh rincian akun. Wholesale, seperti RapService::replaceItems:
     * pemanggil yang hanya bermaksud mengubah catatan tidak boleh diam-diam
     * mengosongkan anggarannya.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function replaceLines(OverheadBudget $budget, array $lines): OverheadBudget
    {
        $this->assertEditable($budget, 'ditulis ulang');

        return DB::transaction(function () use ($budget, $lines): OverheadBudget {
            $budget->lines()->delete();

            foreach ($lines as $line) {
                $budget->lines()->create([
                    'account_id' => (int) $line['account_id'],
                    'amount' => round((float) ($line['amount'] ?? 0), 2),
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $budget->forceFill([
                'total_amount' => round((float) $budget->lines()->sum('amount'), 2),
            ])->save();

            return $budget->refresh();
        });
    }

    public function submit(OverheadBudget $budget, ?User $by = null): OverheadBudget
    {
        if ($budget->lines()->count() === 0) {
            throw new LogicException(
                "OVB {$budget->code} belum punya satu akun pun. Sebuah anggaran tanpa rincian akun tidak "
                .'bisa dibandingkan dengan realisasi apa pun — isi akun dan nilainya lebih dulu.'
            );
        }

        $this->assertNoApprovedRival($budget, 'diajukan');

        return $budget->submit($by);
    }

    /**
     * Persetujuan — dan satu-satunya tempat aturan "satu per tahun" berarti
     * sesuatu, karena di sinilah statusnya menjadi approved.
     */
    public function approve(OverheadBudget $budget, User $by, ?string $note = null): OverheadBudget
    {
        $this->assertNoApprovedRival($budget, 'disetujui');

        try {
            return $budget->approve($by, $note);
        } catch (QueryException $e) {
            // Jaring terakhir indeks unik: dua persetujuan yang tiba bersamaan.
            if ($this->isApprovedYearClash($e)) {
                throw new LogicException($this->rivalSentence($budget, 'disetujui'));
            }

            throw $e;
        }
    }

    public function reject(OverheadBudget $budget, User $by, ?string $note = null): OverheadBudget
    {
        return $budget->reject($by, $note);
    }

    /**
     * Anggaran vs realisasi overhead satu tahun buku.
     *
     * @return array<string, mixed>
     */
    public function realisation(int $year): array
    {
        $budget = $this->approvedFor($year);
        $warnPct = WatchedThresholds::warnPct('overhead_budget_pct');

        if ($budget === null) {
            return [
                'period_year' => $year,
                'code' => null,
                'status' => null,
                'total_budget' => null,
                // Tanpa OVB tidak ada satu akun pun yang disebut, jadi tidak
                // ada yang bisa dijumlahkan — null, bukan "Rp 0 overhead",
                // yang jelas keliru untuk perusahaan yang tetap membayar sewa.
                'total_actual' => null,
                'pct' => null,
                'state' => WatchedThresholds::TANPA_BATAS,
                'warn_pct' => $warnPct,
                'rows' => [],
                'note' => "Tahun buku {$year} belum punya OVB yang disetujui. Realisasi overhead tetap "
                    .'tercatat di buku besar, tetapi tidak ada anggaran yang bisa dilampaui — jadi tidak '
                    .'ada persentase untuk ditampilkan, dan angka 0 % di sini akan mengarang batas yang '
                    .'tidak pernah ditetapkan siapa pun.',
            ];
        }

        $lines = $budget->lines()->with('account')->get();
        $actuals = $this->postedByAccount($year, $lines->pluck('account_id')->all());

        $rows = [];
        $totalBudget = 0.0;
        $totalActual = 0.0;

        foreach ($lines as $line) {
            $planned = (float) $line->amount;
            // Akun yang belum bermutasi sekali pun: null, bukan 0 — aturan yang
            // sama dengan bulan tanpa realisasi pada anggaran proyek.
            $actual = $actuals[$line->account_id] ?? null;

            $totalBudget = round($totalBudget + $planned, 2);
            $totalActual = round($totalActual + ($actual ?? 0.0), 2);

            $rows[] = [
                'account_id' => (int) $line->account_id,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'budget' => $planned,
                'actual' => $actual,
                'variance' => $actual === null ? null : round($planned - $actual, 2),
                'pct' => WatchedThresholds::pct($actual, $planned),
                'state' => WatchedThresholds::state($actual, $planned > 0 ? $planned : null, $warnPct),
                'notes' => $line->notes,
            ];
        }

        return [
            'period_year' => $year,
            'code' => $budget->code,
            'status' => $budget->status->value,
            'total_budget' => $totalBudget,
            'total_actual' => $totalActual,
            'pct' => WatchedThresholds::pct($totalActual, $totalBudget),
            'state' => WatchedThresholds::state($totalActual, $totalBudget > 0 ? $totalBudget : null, $warnPct),
            'warn_pct' => $warnPct,
            'rows' => $rows,
            'note' => sprintf(
                'Realisasi dibaca dari baris jurnal TERPOSTING bertanggal dalam %d pada akun yang '
                .'dianggarkan OVB %s (debit − kredit). Anggaran %s.',
                $year,
                $budget->code,
                Money::format($totalBudget, false),
            ),
        ];
    }

    public function approvedFor(int $year): ?OverheadBudget
    {
        return OverheadBudget::query()
            ->where('period_year', $year)
            ->where('status', DocumentStatus::Approved->value)
            ->first();
    }

    // ---------------------------------------------------------------- internal

    private function assertEditable(OverheadBudget $budget, string $action): void
    {
        if (! $budget->status->isEditable()) {
            throw new LogicException("OVB {$budget->code} tidak dapat {$action} selama statusnya {$budget->status->value}.");
        }
    }

    private function assertNoApprovedRival(OverheadBudget $budget, string $action): void
    {
        $rival = OverheadBudget::query()
            ->where('period_year', $budget->period_year)
            ->where('status', DocumentStatus::Approved->value)
            ->where('id', '!=', $budget->id)
            ->first();

        if ($rival !== null) {
            throw new LogicException($this->rivalSentence($budget, $action, $rival->code));
        }
    }

    private function rivalSentence(OverheadBudget $budget, string $action, ?string $rivalCode = null): string
    {
        $rivalCode ??= $this->approvedFor((int) $budget->period_year)?->code ?? 'yang sudah ada';

        return sprintf(
            'Tahun buku %d sudah punya anggaran overhead yang disetujui (%s), jadi OVB %s tidak dapat %s. '
            .'Satu tahun hanya boleh punya satu anggaran yang berlaku — batalkan %s lebih dulu bila '
            .'anggaran ini yang menggantikannya.',
            $budget->period_year,
            $rivalCode,
            $budget->code,
            $action,
            $rivalCode,
        );
    }

    private function isApprovedYearClash(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'fin_overhead_budgets_approved_year_unique');
    }

    /**
     * @param  array<int, int>  $accountIds
     * @return array<int, float>
     */
    private function postedByAccount(int $year, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->whereIn('l.account_id', $accountIds)
            ->where('j.status', 'posted')
            ->whereNull('j.deleted_at')
            /*
             * SETENGAH TERBUKA, bukan whereBetween. SQLite menyimpan kolom
             * date sebagai '2026-12-31 00:00:00', dan string itu SORTIR SESUDAH
             * '2026-12-31' — sebuah BETWEEN akan membuang setiap jurnal yang
             * bertanggal 31 Desember, yaitu justru hari tersibuk buku besar.
             * Footgun yang sama yang didokumentasikan DanglingDocuments.
             */
            ->where('j.journal_date', '>=', $year.'-01-01')
            ->where('j.journal_date', '<', ($year + 1).'-01-01')
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->account_id] = round((float) $row->debit - (float) $row->credit, 2);
        }

        return $totals;
    }
}
