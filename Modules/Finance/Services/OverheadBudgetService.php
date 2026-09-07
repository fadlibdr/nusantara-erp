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
 * FORWARD-ONLY. Menyetujui, menolak, membatalkan, atau membuang sebuah OVB
 * tidak memposting satu baris jurnal pun dan tidak menyentuh satu baris jurnal
 * pun yang sudah ada: sebuah anggaran adalah rencana, bukan transaksi. Yang
 * berubah saat OVB disetujui hanyalah BATAS yang dipakai membaca angka yang
 * sudah tercatat.
 *
 * DAN BATAS ITU BISA DITARIK KEMBALI (§ cancel, verifikasi F-2). Aturan "satu
 * per tahun" tanpa jalan keluar berarti satu tahun buku yang OVB-nya salah
 * ketik terkunci selamanya — dan kalimat penolakannya menyuruh orang
 * "membatalkan" sesuatu yang tidak punya tombol. Pembatalan mengembalikan
 * tahun itu ke keadaan "belum ada OVB disetujui" dan melepaskan slot indeks
 * uniknya, dengan alasan yang wajib dan tercatat.
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
     * Batalkan OVB yang SUDAH DISETUJUI — jalan keluar yang dijanjikan kalimat
     * penolakan "satu per tahun" (verifikasi F-2).
     *
     * Sebelum ini kalimat itu menyuruh operator "batalkan OVB/… lebih dulu",
     * dan diukur lewat HTTP tidak satu pun jalan itu ada: DELETE 422 (isEditable
     * hanya draft/rejected), reject 422 (hanya menerima submitted), PUT 422,
     * cancel 404 — tidak ada rutenya. Satu tahun buku yang OVB-nya salah ketik
     * terkunci selamanya.
     *
     * FORWARD-ONLY, DAN DI SINI ITU HAMPIR HAMPA: sebuah anggaran tidak pernah
     * memposting satu baris jurnal pun, jadi tidak ada yang perlu dibalik.
     * Yang berubah hanyalah BATAS yang dipakai membaca angka yang sudah
     * tercatat — dan tahun itu kembali menjadi "belum ada OVB disetujui",
     * keadaan TANPA_BATAS yang sudah punya kalimatnya sendiri.
     *
     * Alasan WAJIB, dan jejaknya baris `cancelled` di core_approvals — trail
     * yang sama yang ditulis submit/approve/reject, supaya riwayat dokumen
     * terbaca sebagai satu urutan (pola ArInvoiceService::cancel).
     */
    public function cancel(OverheadBudget $budget, User $by, string $reason): OverheadBudget
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new LogicException(
                "Pembatalan OVB {$budget->code} wajib menyebutkan alasan. Anggaran tahunan yang hilang "
                .'tanpa sebab tertulis tidak bisa dipertanggungjawabkan pada audit tahun itu.'
            );
        }

        if ($budget->status !== DocumentStatus::Approved) {
            throw new LogicException(
                "OVB {$budget->code} berstatus {$budget->status->value}, jadi tidak perlu dibatalkan — "
                .'yang belum disetujui cukup diubah, ditolak, atau dihapus. Pembatalan hanya untuk '
                .'anggaran yang sudah berlaku.'
            );
        }

        return DB::transaction(function () use ($budget, $by, $reason): OverheadBudget {
            $budget->forceFill([
                'status' => DocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ])->save();

            $budget->approvals()->create([
                'action' => 'cancelled',
                'user_id' => $by->id,
                'note' => $reason,
            ]);

            return $budget->refresh();
        });
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
        $measured = 0;

        foreach ($lines as $line) {
            $planned = (float) $line->amount;
            // Akun yang belum bermutasi sekali pun: null, bukan 0 — aturan yang
            // sama dengan bulan tanpa realisasi pada anggaran proyek.
            $actual = $actuals[$line->account_id] ?? null;

            $totalBudget = round($totalBudget + $planned, 2);
            $totalActual = round($totalActual + ($actual ?? 0.0), 2);
            $measured += $actual === null ? 0 : 1;

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

        /*
         * NOL AKUN BERMUTASI BUKAN REALISASI NOL RUPIAH (verifikasi F-2).
         * Setiap barisnya sudah menggaris dirinya sendiri ("—", tidak_terukur),
         * tetapi totalnya menjumlahkan null sebagai 0 dan mencetak "Rp 0 /
         * 0,0 % / Aman" hijau — tepat di bawah kalimat bantuan layarnya sendiri
         * yang berbunyi "bertanda —, bukan Rp 0". Terukur: OVB 2026 disetujui,
         * satu akun Rp 500.000.000, NOL jurnal terposting -> total_actual 0.0,
         * pct 0.0, state 'aman', rows[0]['actual'] NULL. Sekarang totalnya
         * mengikuti barisnya: TIDAK_TERUKUR, dan layar menggarisnya.
         *
         * Bila SEBAGIAN akun bermutasi, totalnya tetap angka — nol rupiah pada
         * akun yang belum bermutasi memang tidak menambah apa pun ke jumlahnya —
         * dan catatannya menyebut berapa akun yang belum terukur, supaya
         * persentase itu dibaca dengan tahu apa yang belum ada di dalamnya.
         */
        $unmeasured = $lines->count() - $measured;
        $nothingMeasured = $measured === 0;

        return [
            'period_year' => $year,
            'code' => $budget->code,
            'status' => $budget->status->value,
            'total_budget' => $totalBudget,
            'total_actual' => $nothingMeasured ? null : $totalActual,
            'pct' => $nothingMeasured ? null : WatchedThresholds::pct($totalActual, $totalBudget),
            'state' => $nothingMeasured
                ? WatchedThresholds::TIDAK_TERUKUR
                : WatchedThresholds::state($totalActual, $totalBudget > 0 ? $totalBudget : null, $warnPct),
            'warn_pct' => $warnPct,
            'rows' => $rows,
            'note' => sprintf(
                'Realisasi dibaca dari baris jurnal TERPOSTING bertanggal dalam %d pada akun yang '
                .'dianggarkan OVB %s (debit − kredit). Anggaran %s.%s',
                $year,
                $budget->code,
                Money::format($totalBudget, false),
                $this->measurementNote($nothingMeasured, $unmeasured, $lines->count()),
            ),
        ];
    }

    /** Kalimat tentang APA YANG BELUM TERUKUR — nol akun bermutasi, atau sebagian. */
    private function measurementNote(bool $nothingMeasured, int $unmeasured, int $accounts): string
    {
        if ($nothingMeasured) {
            return sprintf(
                ' Belum ada satu baris jurnal terposting pun pada %d akun yang dianggarkan, jadi '
                .'realisasinya BELUM TERUKUR — itu bukan hal yang sama dengan belanja nol rupiah.',
                $accounts,
            );
        }

        return $unmeasured === 0 ? '' : sprintf(
            ' %d dari %d akun belum bermutasi sekali pun tahun ini dan tidak menambah apa pun ke '
            .'jumlah di atas.',
            $unmeasured,
            $accounts,
        );
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
            .'Satu tahun hanya boleh punya satu anggaran yang berlaku — batalkan %s lebih dulu '
            .'(tombol "Batalkan OVB" pada dokumennya, alasan wajib) bila anggaran ini yang '
            .'menggantikannya.',
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
