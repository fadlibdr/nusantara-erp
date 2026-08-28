<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Services\OvertimeRecapService;
use Modules\Projects\Models\OvertimePermit;
use Modules\Projects\Models\Project;

/**
 * P0-C: Izin Kerja Lembur — header + baris pekerja, dan umpan rekap payroll
 * pada approve. Satu-satunya tulisan lintas modul paket ini, dan ia lewat
 * pintu HrPayroll (OvertimeRecapService), bukan menulis hr_attendance_recaps
 * langsung.
 *
 * KEPUTUSAN JAM, ditulis di sini karena di sinilah ditegakkan: end_time <
 * start_time berarti lembur MELEWATI TENGAH MALAM dan selesai keesokan
 * harinya — 22:00 s/d 02:00 adalah empat jam pengecoran yang nyata, dan
 * menolaknya demi "end > start" berarti sistem tidak bisa mencatat lembur
 * malam yang paling lazim di proyek. Hanya end == start yang ditolak: lembur
 * berdurasi nol adalah klaim tentang ketiadaan. Jam lembur yang dibayar
 * adalah `hours` PER PEKERJA (yang dijaga > 0 dan ≤ 24 di FormRequest);
 * jendela jam header hanyalah keterangan lembarnya. Lembur yang melewati
 * tengah malam tetap dibukukan pada BULAN overtime_date — tanggal yang
 * tercetak di lembar yang ditandatangani itulah periode rekapnya.
 */
class OvertimePermitService
{
    public function __construct(private readonly OvertimeRecapService $recaps) {}

    public function create(array $data): OvertimePermit
    {
        Project::query()->findOrFail((int) $data['project_id'])
            ->assertOperational('izin kerja lembur');

        $workers = $this->pullWorkers($data) ?? [];
        $this->assertTimes((string) $data['start_time'], (string) $data['end_time']);
        $this->assertWorkerIdentities($workers);

        return DB::transaction(function () use ($data, $workers): OvertimePermit {
            // Explicit draft: the column default is not hydrated on create.
            $permit = OvertimePermit::query()->create(
                Arr::except($data, ['code', 'status']) + ['status' => DocumentStatus::Draft],
            );
            $this->replaceWorkers($permit, $workers);

            return $permit->load('workers');
        });
    }

    public function update(OvertimePermit $permit, array $data): OvertimePermit
    {
        $permit->project()->firstOrFail()->assertOperational('izin kerja lembur');

        $workers = $this->pullWorkers($data);

        $this->assertTimes(
            (string) (array_key_exists('start_time', $data) ? $data['start_time'] : $permit->start_time),
            (string) (array_key_exists('end_time', $data) ? $data['end_time'] : $permit->end_time),
        );

        if ($workers !== null) {
            $this->assertWorkerIdentities($workers);
        }

        return DB::transaction(function () use ($permit, $data, $workers): OvertimePermit {
            $permit->fill(Arr::except($data, ['code', 'project_id', 'status']))->save();

            if ($workers !== null) {
                $this->replaceWorkers($permit, $workers);
            }

            return $permit->load('workers');
        });
    }

    /**
     * Approve = the decision that feeds the recap, in one transaction — the
     * LeaveService::approve shape, skipped periods reported the same way.
     * Un-approve does not exist and reject feeds nothing: only an APPROVED
     * sheet is hours payroll may pay against.
     *
     * @return array{permit: OvertimePermit, skipped_periods: list<string>}
     */
    public function approve(OvertimePermit $permit, User $by, ?string $note = null): array
    {
        return DB::transaction(function () use ($permit, $by, $note): array {
            /** @var OvertimePermit $locked */
            $locked = OvertimePermit::query()->whereKey($permit->getKey())->lockForUpdate()->firstOrFail();

            $locked->approve($by, $note); // asserts submitted + maker-checker

            return [
                'permit' => $locked->load('workers'),
                'skipped_periods' => $this->recaps->applyMonthlyOvertime(
                    $locked->overtime_date->year,
                    $locked->overtime_date->month,
                    $this->approvedHoursForPeriod($locked),
                ),
            ];
        });
    }

    // ----------------------------------------------------------------- feed

    /**
     * TOTAL approved hours per employee for the permit's period — recomputed
     * wholesale from EVERY approved permit of that month (syncRecaps' rule:
     * an increment goes wrong the first time anything syncs twice), but only
     * for the employees on THIS permit, so an approval never touches a
     * colleague's recap it has no news about.
     *
     * worker_name rows contribute nothing here BY DESIGN: a non-employee crew
     * member has no hr_attendance_recaps row to feed. The sheet still prints
     * them — the paper is signed per person whether payroll knows the person
     * or not.
     *
     * @return array<int, float> employee_id => hours
     */
    private function approvedHoursForPeriod(OvertimePermit $permit): array
    {
        $employeeIds = $permit->workers->pluck('employee_id')->filter()->unique()->values();

        if ($employeeIds->isEmpty()) {
            return [];
        }

        $monthStart = $permit->overtime_date->copy()->startOfMonth()->toDateString();
        $monthEnd = $permit->overtime_date->copy()->endOfMonth()->toDateString();

        $totals = DB::table('prj_overtime_permit_workers as w')
            ->join('prj_overtime_permits as p', 'p.id', '=', 'w.overtime_permit_id')
            ->where('p.status', DocumentStatus::Approved->value)
            ->whereNull('p.deleted_at')
            ->whereDate('p.overtime_date', '>=', $monthStart)
            ->whereDate('p.overtime_date', '<=', $monthEnd)
            ->whereIn('w.employee_id', $employeeIds)
            ->groupBy('w.employee_id')
            ->selectRaw('w.employee_id, sum(w.hours) as total_hours')
            ->pluck('total_hours', 'employee_id');

        $hours = [];

        foreach ($employeeIds as $employeeId) {
            $hours[(int) $employeeId] = (float) ($totals[$employeeId] ?? 0);
        }

        return $hours;
    }

    // ---------------------------------------------------------------- rules

    private function assertTimes(string $start, string $end): void
    {
        // 'HH:MM' compares correctly lexicographically; substr normalises the
        // 'HH:MM:SS' a TIME column may hand back (DailyReportService's rule).
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);

        if ($end === $start) {
            throw ValidationException::withMessages(['end_time' => sprintf(
                'Jam selesai (%s) sama dengan jam mulai (%s) — lembur berdurasi nol. '
                    .'Lembur yang melewati tengah malam ditulis dengan jam selesai lebih kecil dari jam mulai (mis. 22:00 s/d 02:00).',
                $end,
                $start,
            )]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertWorkerIdentities(array $rows): void
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['workers' => 'Izin lembur tanpa satu pun baris pekerja bukan izin — lembar ini ditandatangani per orang.']);
        }

        foreach ($rows as $i => $row) {
            $hasEmployee = ! blank($row['employee_id'] ?? null);
            $hasName = ! blank($row['worker_name'] ?? null);

            if ($hasEmployee === $hasName) {
                throw ValidationException::withMessages(["workers.{$i}" => sprintf(
                    'Baris pekerja #%d: isi employee_id ATAU worker_name, tepat satu — '
                        .'karyawan dirujuk ke daftar karyawan, kru mandor non-karyawan ditulis namanya.',
                    $i + 1,
                )]);
            }
        }
    }

    // ---------------------------------------------------------------- lines

    /** @return list<array<string, mixed>>|null null = key absent, keep stored rows */
    private function pullWorkers(array &$data): ?array
    {
        if (! array_key_exists('workers', $data)) {
            return null;
        }

        $value = Arr::pull($data, 'workers');

        return is_array($value) ? array_values($value) : [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceWorkers(OvertimePermit $permit, array $rows): void
    {
        $permit->workers()->delete();

        foreach ($rows as $row) {
            $permit->workers()->create([
                'employee_id' => $row['employee_id'] ?? null,
                'worker_name' => $row['worker_name'] ?? null,
                'hours' => round((float) $row['hours'], 2),
            ]);
        }
    }
}
