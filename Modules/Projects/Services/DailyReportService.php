<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;

class DailyReportService
{
    public function create(array $data): DailyReport
    {
        // Laporan harian is site data: a report dated after the project closed
        // is exactly the row that made closed-period progress and cost reports
        // untrustworthy on the audit's finding. The refusal names the status.
        Project::query()->findOrFail((int) $data['project_id'])
            ->assertOperational('laporan harian');

        $materials = Arr::pull($data, 'materials', []);

        return DB::transaction(function () use ($data, $materials): DailyReport {
            $report = DailyReport::query()->create(Arr::except($data, ['code']));

            $this->replaceMaterials($report, $materials);

            return $report->load('materials');
        });
    }

    public function update(DailyReport $report, array $data): DailyReport
    {
        // Same door as create: once the project stops being operational its
        // record of what happened on site is history, not a draft. The update
        // request cannot move a report between projects, so the report's own
        // project is the only one to ask.
        $report->project()->firstOrFail()->assertOperational('laporan harian');

        return DB::transaction(function () use ($report, $data): DailyReport {
            $materials = Arr::pull($data, 'materials');

            $report->fill(Arr::except($data, ['code', 'created_by']))->save();

            // Lines are replaced wholesale when the key is present.
            if (is_array($materials)) {
                $this->replaceMaterials($report, $materials);
            }

            return $report->load('materials');
        });
    }

    public function delete(DailyReport $report): void
    {
        // Rasional update() berlaku di sini juga: laporan harian pada proyek
        // tutup adalah riwayat, bukan draf — dan riwayat tidak dihapus.
        $report->project()->firstOrFail()->assertOperational('laporan harian');

        $report->delete();
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
