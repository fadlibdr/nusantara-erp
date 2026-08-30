<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\HseDaily;
use Modules\Projects\Models\Project;

/**
 * P6: formulir K3 harian (FM-10-13) — dan TAUTAN ke laporan harian.
 *
 * KEPUTUSAN TAUTAN (dipaku HseDailyTest): resolusi EAGER dua arah, tidak
 * pernah diketik klien.
 *
 *  - Saat formulir K3 dibuat/diubah, service ini mencari laporan harian HIDUP
 *    dengan (project_id, report_date) yang sama dan menautkannya; tidak ada →
 *    NULL, dan formulirnya tetap tercatat — site tanpa laporan harian hari itu
 *    tetap ber-toolbox-meeting.
 *  - Laporan harian yang lahir BELAKANGAN menaut-balik lewat relink(),
 *    dipanggil DailyReportService pada create/update; laporan yang pindah
 *    tanggal melepaskan tautan lamanya di jalur yang sama, dan laporan yang
 *    dihapus melepasnya lewat unlink(). Kedua model satu modul — alasan HSE
 *    tinggal di Projects: Projects tidak boleh bergantung ke Quality.
 *  - daily_report_id dari payload DIBUANG: tautan adalah fakta turunan
 *    (proyek, tanggal), bukan klaim yang bisa diketik.
 *
 * APD per kategori dan temuan adalah BARIS DATA yang diganti seutuhnya saat
 * update (pola line-table repo). Kategori yang tidak dicatat tidak punya
 * baris — lembar cetak menggarisinya, bukan mencetak 0.
 */
class HseDailyService
{
    public function create(array $data, User $by): HseDaily
    {
        Project::query()->findOrFail((int) $data['project_id'])
            ->assertOperational('formulir K3 harian');

        $this->assertSingleSheetPerDay((int) $data['project_id'], (string) $data['report_date']);

        return DB::transaction(function () use ($data, $by): HseDaily {
            /** @var HseDaily $daily */
            $daily = HseDaily::query()->create(
                Arr::except($data, ['code', 'daily_report_id', 'apd', 'findings'])
                + ['created_by' => $by->id]
            );

            $daily->forceFill([
                'daily_report_id' => $this->resolveReportId($daily),
            ])->save();

            $this->replaceLines($daily, $data);

            return $daily->load(['apd', 'findings', 'dailyReport']);
        });
    }

    public function update(HseDaily $daily, array $data): HseDaily
    {
        // Formulirnya tidak bisa pindah proyek — seperti laporan harian.
        unset($data['project_id']);

        $daily->project()->firstOrFail()->assertOperational('formulir K3 harian');

        if (array_key_exists('report_date', $data)) {
            $this->assertSingleSheetPerDay((int) $daily->project_id, (string) $data['report_date'], $daily->id);
        }

        return DB::transaction(function () use ($daily, $data): HseDaily {
            $daily->fill(Arr::except($data, ['code', 'created_by', 'daily_report_id', 'apd', 'findings']))->save();

            // Tanggal (mungkin) bergeser: tautan diselesaikan ulang dari fakta.
            $daily->forceFill([
                'daily_report_id' => $this->resolveReportId($daily),
            ])->save();

            $this->replaceLines($daily, $data);

            return $daily->load(['apd', 'findings', 'dailyReport']);
        });
    }

    /**
     * Taut-balik dari laporan harian — dipanggil DailyReportService setiap
     * kali sebuah laporan dibuat atau diubah: lepaskan tautan yang tidak lagi
     * cocok (laporan pindah tanggal), lalu isi formulir K3 proyek+tanggal sama
     * yang tautannya masih kosong.
     */
    public function relink(DailyReport $report): void
    {
        HseDaily::query()
            ->where('daily_report_id', $report->id)
            ->where(function ($query) use ($report): void {
                $query->where('project_id', '!=', $report->project_id)
                    ->orWhereDate('report_date', '!=', $report->report_date->toDateString());
            })
            ->update(['daily_report_id' => null]);

        HseDaily::query()
            ->whereNull('daily_report_id')
            ->where('project_id', $report->project_id)
            ->whereDate('report_date', $report->report_date->toDateString())
            ->update(['daily_report_id' => $report->id]);
    }

    /** Laporan hariannya dihapus: formulir K3-nya berdiri sendiri lagi. */
    public function unlink(DailyReport $report): void
    {
        HseDaily::query()
            ->where('daily_report_id', $report->id)
            ->update(['daily_report_id' => null]);
    }

    // ------------------------------------------------------------------ helpers

    /** Laporan harian HIDUP dengan proyek+tanggal yang sama, bila ada. */
    private function resolveReportId(HseDaily $daily): ?int
    {
        $id = DailyReport::query()
            ->where('project_id', $daily->project_id)
            ->whereDate('report_date', $daily->report_date->toDateString())
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Satu FM-10-13 per proyek per hari — 422 berbahasa manusia; indeks unik
     * parsial di migrasi 000742 tinggal jaring terakhir.
     */
    private function assertSingleSheetPerDay(int $projectId, string $date, ?int $ignoreId = null): void
    {
        $exists = HseDaily::query()
            ->where('project_id', $projectId)
            ->whereDate('report_date', $date)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'report_date' => 'Formulir K3 harian untuk proyek dan tanggal ini sudah ada.',
            ]);
        }
    }

    /** Baris APD dan temuan diganti seutuhnya bila kuncinya dikirim. */
    private function replaceLines(HseDaily $daily, array $data): void
    {
        if (array_key_exists('apd', $data)) {
            $daily->apd()->delete();

            foreach (array_values($data['apd'] ?? []) as $line) {
                $daily->apd()->create(Arr::only($line, ['category', 'qty']));
            }
        }

        if (array_key_exists('findings', $data)) {
            $daily->findings()->delete();

            foreach (array_values($data['findings'] ?? []) as $index => $line) {
                $daily->findings()->create(
                    Arr::only($line, ['finding', 'follow_up'])
                    + ['sort_order' => $line['sort_order'] ?? $index + 1],
                );
            }
        }
    }
}
