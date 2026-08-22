<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HrPayroll\Models\Attendance;

/**
 * Absensi harian — deliberately a register, not a pay input (finding #22,
 * half 2). Nothing here recalculates a payslip or a recap; the monthly
 * hr_attendance_recaps that payroll reads stays a separate, human-owned
 * document. The linkage (deriving the recap, prorating daily-rate pay) is
 * left unbuilt on purpose until the recap's role as payroll input of record
 * is redesigned around it.
 */
class AttendanceService
{
    /**
     * The site sheet: one date, one project, many employees, one transaction.
     *
     * Upsert against the (employee, date) unique key, so posting the corrected
     * sheet a second time fixes rows instead of doubling them — the clerk's
     * retry after a dropped connection must be idempotent, not additive.
     *
     * @param  array{date: string, project_id?: int|null, entries: list<array{employee_id: int, status: string, note?: string|null}>}  $data
     * @return array{created: int, updated: int}
     */
    public function bulkUpsert(array $data, ?int $recordedBy): array
    {
        return DB::transaction(function () use ($data, $recordedBy): array {
            $date = Carbon::parse($data['date'])->toDateString();
            $projectId = $data['project_id'] ?? null;

            $created = 0;
            $updated = 0;

            foreach ($data['entries'] as $entry) {
                // whereDate, not updateOrCreate(['date' => $date]): the date
                // cast STORES midnight timestamps, so a plain equality against
                // 'Y-m-d' finds nothing, re-inserts, and the clerk's retry dies
                // on the unique key instead of correcting the sheet.
                $attendance = Attendance::query()
                    ->where('employee_id', (int) $entry['employee_id'])
                    ->whereDate('date', $date)
                    ->first();

                $values = [
                    'status' => $entry['status'],
                    'project_id' => $projectId,
                    'note' => $entry['note'] ?? null,
                    'recorded_by' => $recordedBy,
                ];

                if ($attendance === null) {
                    Attendance::query()->create($values + [
                        'employee_id' => (int) $entry['employee_id'],
                        'date' => $date,
                    ]);
                    $created++;
                } else {
                    $attendance->fill($values)->save();
                    $updated++;
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }
}
