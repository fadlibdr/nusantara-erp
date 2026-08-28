<?php

namespace Modules\Projects\Services;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Projects\Enums\BastType;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;

/**
 * P0-A: laporan harian dengan empat tabel baris FM-10-12.
 *
 * Aturan kejujuran yang ditegakkan file ini, bukan di tempat lain:
 *
 *  - manpower_count DITURUNKAN dari rincian per jabatan begitu rincian ada.
 *    Angka manual yang berbeda ditolak 422 yang menyebut KEDUA angka dan
 *    selisihnya — menimpa diam-diam ke salah satu arah berarti salah satu
 *    dari dua angka yang pernah diketik seseorang lenyap tanpa jejak.
 *    Tanpa rincian, angka manual tetap berlaku: laporan pra-P0-A tidak
 *    pernah dipaksa mundur (forward-only).
 *  - work_end > work_start, dan pembanding update parsial adalah nilai
 *    TERSIMPAN, bukan hanya payload.
 *  - qty_rejected ≤ qty_received per baris material masuk.
 *  - Laporan terkunci (locked_at) menolak update/delete dengan pesan yang
 *    menyebut SIAPA yang menguncinya: BAST I beserta kode dan tanggal serah
 *    terimanya. Kunci diperiksa SEBELUM assertOperational — proyek pasca-BAST
 *    berstatus Masa Pemeliharaan juga, dan pesan status itu tidak menyebut
 *    dokumen yang membekukan laporannya.
 */
class DailyReportService
{
    /** Kunci payload keempat tabel baris (+ materials warisan). */
    private const LINE_KEYS = ['materials', 'manpower', 'equipment', 'receipts', 'activity_lines'];

    public function create(array $data): DailyReport
    {
        // Laporan harian is site data: a report dated after the project closed
        // is exactly the row that made closed-period progress and cost reports
        // untrustworthy on the audit's finding. The refusal names the status.
        Project::query()->findOrFail((int) $data['project_id'])
            ->assertOperational('laporan harian');

        $lines = $this->pullLines($data);

        $this->assertWorkHours($data['work_start'] ?? null, $data['work_end'] ?? null);
        $this->assertReceiptQuantities($lines['receipts'] ?? []);
        $data['manpower_count'] = $this->resolveManpowerCount(
            $lines['manpower'] ?? [],
            array_key_exists('manpower_count', $data) ? $data['manpower_count'] : null,
        ) ?? 0;

        return DB::transaction(function () use ($data, $lines): DailyReport {
            $report = DailyReport::query()->create(Arr::except($data, ['code']));

            foreach ($lines as $key => $rows) {
                $this->replaceLines($report, $key, $rows);
            }

            return $report->load(['materials', 'manpower', 'equipment', 'receipts', 'activityLines']);
        });
    }

    public function update(DailyReport $report, array $data): DailyReport
    {
        // Kunci dulu, status kemudian: laporan yang dibekukan BAST I hidup di
        // proyek Masa Pemeliharaan juga, dan penolakan yang jujur menyebut
        // dokumen pengunci, bukan sekadar status proyeknya.
        $this->assertUnlocked($report, 'diubah');

        // Same door as create: once the project stops being operational its
        // record of what happened on site is history, not a draft. The update
        // request cannot move a report between projects, so the report's own
        // project is the only one to ask.
        $report->project()->firstOrFail()->assertOperational('laporan harian');

        $lines = $this->pullLines($data);

        // Pembanding jam kerja adalah nilai EFEKTIF: payload bila kuncinya
        // dikirim, nilai tersimpan bila tidak — update parsial yang hanya
        // menggeser work_end tetap diadu dengan work_start lama.
        $this->assertWorkHours(
            array_key_exists('work_start', $data) ? $data['work_start'] : $report->work_start,
            array_key_exists('work_end', $data) ? $data['work_end'] : $report->work_end,
        );

        if (isset($lines['receipts'])) {
            $this->assertReceiptQuantities($lines['receipts']);
        }

        // Rincian efektif: baris payload bila dikirim (termasuk [] = hapus),
        // baris tersimpan bila tidak — angka manual yang menyimpang dari
        // rincian TERSIMPAN pun ditolak, bukan hanya dari rincian baru.
        $effectiveRows = $lines['manpower']
            ?? $report->manpower()->get()->map(fn ($row): array => ['headcount' => $row->headcount])->all();

        $resolved = $this->resolveManpowerCount(
            $effectiveRows,
            array_key_exists('manpower_count', $data) ? $data['manpower_count'] : null,
        );

        if ($resolved === null) {
            unset($data['manpower_count']); // tak ada klaim baru: angka lama berdiri
        } else {
            $data['manpower_count'] = $resolved;
        }

        return DB::transaction(function () use ($report, $data, $lines): DailyReport {
            $report->fill(Arr::except($data, ['code', 'created_by']))->save();

            // Lines are replaced wholesale when the key is present.
            foreach ($lines as $key => $rows) {
                $this->replaceLines($report, $key, $rows);
            }

            return $report->load(['materials', 'manpower', 'equipment', 'receipts', 'activityLines']);
        });
    }

    public function delete(DailyReport $report): void
    {
        $this->assertUnlocked($report, 'dihapus');

        // Rasional update() berlaku di sini juga: laporan harian pada proyek
        // tutup adalah riwayat, bukan draf — dan riwayat tidak dihapus.
        $report->project()->firstOrFail()->assertOperational('laporan harian');

        $report->delete();
    }

    /**
     * BAST I yang DISETUJUI membekukan laporan bertanggal ≤ tanggal serah
     * terima. Keputusan (spec diam soal cakupan): BAST I adalah tanda tangan
     * tiga pihak atas pekerjaan SAMPAI serah terima — laporan pada rentang
     * itulah yang diserahterimakan dan berhenti menjadi draf. Laporan
     * bertanggal SESUDAHNYA bukan bagian dari yang diserahkan dan tidak
     * dikunci oleh dokumen ini. BAST tanpa tanggal serah terima tetap
     * menyerahkan seluruh pekerjaan, jadi tanpa batas tanggal seluruh laporan
     * proyek terkunci.
     *
     * Dipanggil dari ProjectService::approveBast, di dalam transaksinya.
     * Idempoten: baris yang sudah terkunci tidak dicap ulang, sehingga kunci
     * yang lebih dulu (kelak: keputusan eksternal) tidak bergeser waktunya.
     *
     * Seam: bila patch spike ExternalApprovalService hadir, keputusan
     * eksternal PERTAMA atas satu laporan mengunci laporan itu di sini juga —
     * satu kolom locked_at, dua pintu masuk.
     */
    public function lockForApprovedBastOne(Bast $bast): int
    {
        // handover_date NOT NULL di skema prj_bast — BAST tanpa tanggal serah
        // terima tidak bisa ada, jadi tidak ada cabang untuknya di sini.
        return DailyReport::query()
            ->where('project_id', $bast->project_id)
            ->whereNull('locked_at')
            ->whereDate('report_date', '<=', $bast->handover_date->toDateString())
            ->update(['locked_at' => now()]);
    }

    // ---------------------------------------------------------------- rules

    private function assertUnlocked(DailyReport $report, string $verb): void
    {
        if (! $report->isLocked()) {
            return;
        }

        // Cari dokumen penguncinya untuk disebut dalam pesan. Satu-satunya
        // pintu yang mengisi locked_at hari ini adalah BAST I disetujui;
        // fallback tanggal kunci menjaga pesan tetap jujur bila pintu kedua
        // (keputusan eksternal, patch spike) hadir lebih dulu daripada
        // pembaruan pesan ini.
        $bast = Bast::query()
            ->where('project_id', $report->project_id)
            ->where('bast_type', BastType::Bast1->value)
            ->where('status', DocumentStatus::Approved->value)
            ->orderByDesc('id')
            ->first();

        throw new LogicException($bast !== null
            ? sprintf(
                'Laporan %s terkunci oleh BAST I %s (serah terima %s) dan tidak dapat %s: '
                    .'pekerjaan sebelum serah terima sudah ditandatangani tiga pihak.',
                $report->code, $bast->code, $bast->handover_date?->toDateString() ?? '—', $verb,
            )
            : sprintf('Laporan %s terkunci sejak %s dan tidak dapat %s.',
                $report->code, $report->locked_at?->toDateTimeString(), $verb));
    }

    /**
     * Turunan manpower_count. Null berarti "tidak ada klaim": tanpa rincian
     * dan tanpa angka manual, tidak ada yang perlu ditulis.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function resolveManpowerCount(array $rows, mixed $manual): ?int
    {
        if ($rows === []) {
            return $manual === null ? null : (int) $manual;
        }

        $derived = 0;

        foreach ($rows as $row) {
            $derived += (int) $row['headcount'];
        }

        if ($manual !== null && (int) $manual !== $derived) {
            throw ValidationException::withMessages(['manpower_count' => sprintf(
                'Jumlah tenaga kerja manual (%d) berbeda dengan total rincian per jabatan (%d); selisih %d. '
                    .'Kosongkan angka manual atau samakan dengan rinciannya — rincian per jabatan adalah sumbernya.',
                (int) $manual, $derived, abs((int) $manual - $derived),
            )]);
        }

        return $derived;
    }

    private function assertWorkHours(?string $start, ?string $end): void
    {
        if ($start === null || $end === null) {
            return; // salah satu kosong: belum ada pasangan untuk diadu
        }

        // 'HH:MM' membandingkan benar secara leksikografis; substr menormalkan
        // 'HH:MM:SS' yang mungkin dikembalikan driver untuk kolom TIME.
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);

        if ($end <= $start) {
            throw ValidationException::withMessages(['work_end' => sprintf(
                'Jam selesai (%s) harus setelah jam mulai (%s).', $end, $start,
            )]);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertReceiptQuantities(array $rows): void
    {
        foreach ($rows as $i => $row) {
            $received = (float) $row['qty_received'];
            $rejected = (float) ($row['qty_rejected'] ?? 0);

            if ($rejected > $received) {
                throw ValidationException::withMessages(["receipts.{$i}.qty_rejected" => sprintf(
                    'Jumlah ditolak (%s) melebihi jumlah diterima (%s) pada baris "%s" — '
                        .'yang ditolak adalah bagian dari yang datang.',
                    rtrim(rtrim(number_format($rejected, 3, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($received, 3, '.', ''), '0'), '.'),
                    (string) ($row['description'] ?? '—'),
                )]);
            }
        }
    }

    // ---------------------------------------------------------------- lines

    /**
     * Cabut kunci tabel baris yang HADIR di payload; kunci yang tidak dikirim
     * tidak menyentuh barisnya (pola materials[] lama, kini untuk kelimanya).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function pullLines(array &$data): array
    {
        $lines = [];

        foreach (self::LINE_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $value = Arr::pull($data, $key);

                if (is_array($value)) {
                    $lines[$key] = array_values($value);
                }
            }
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceLines(DailyReport $report, string $key, array $rows): void
    {
        match ($key) {
            'materials' => $this->replaceMaterials($report, $rows),
            'manpower' => $this->replaceChildren($report->manpower(), $rows, fn (array $line): array => [
                'role_key' => $line['role_key'],
                'headcount' => (int) $line['headcount'],
                'notes' => $line['notes'] ?? null,
            ]),
            'equipment' => $this->replaceChildren($report->equipment(), $rows, fn (array $line): array => [
                'asset_id' => $line['asset_id'] ?? null,
                'description' => $line['description'],
                'qty' => (int) $line['qty'],
                'hours' => $line['hours'] ?? null,
            ]),
            'receipts' => $this->replaceChildren($report->receipts(), $rows, fn (array $line): array => [
                'goods_receipt_id' => $line['goods_receipt_id'] ?? null,
                'item_id' => $line['item_id'] ?? null,
                'description' => $line['description'],
                'qty_received' => round((float) $line['qty_received'], 3),
                'qty_rejected' => round((float) ($line['qty_rejected'] ?? 0), 3),
                'unit' => $line['unit'],
                'rejection_reason' => $line['rejection_reason'] ?? null,
            ]),
            'activity_lines' => $this->replaceChildren($report->activityLines(), $rows, fn (array $line, int $i): array => [
                'wbs_task_id' => $line['wbs_task_id'] ?? null,
                'description' => $line['description'],
                'progress_note' => $line['progress_note'] ?? null,
                'target_note' => $line['target_note'] ?? null,
                'obstacle' => $line['obstacle'] ?? null,
                'sort_order' => (int) ($line['sort_order'] ?? $i + 1),
            ]),
        };
    }

    /**
     * @param  HasMany<covariant \Modules\Core\Models\BaseModel, DailyReport>  $relation
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceChildren($relation, array $rows, callable $shape): void
    {
        $relation->delete();

        foreach ($rows as $i => $line) {
            $relation->create($shape($line, $i));
        }
    }

    private function replaceMaterials(DailyReport $report, array $materials): void
    {
        $report->materials()->delete();

        foreach ($materials as $line) {
            $report->materials()->create([
                'item_id' => (int) $line['item_id'],
                'qty_used' => round((float) $line['qty_used'], 3),
                'unit' => $line['unit'],
            ]);
        }
    }
}
