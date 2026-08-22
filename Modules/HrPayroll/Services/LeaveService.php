<?php

namespace Modules\HrPayroll\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\HrPayroll\Enums\LeaveType;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Models\PayrollRun;

/**
 * Cuti/izin: the register, the saldo arithmetic, and the recap feed.
 *
 * The saldo is never stored. 12 hari kerja per entitlement year (UU 13/2003
 * Pasal 79 jo. PP 35/2021: earned after 12 months continuous work, counted
 * from each join_date anniversary) is recomputed from join_date plus the
 * approved cuti tahunan rows every time it is asked for, so it cannot drift
 * from the documents that justify it. Carry-over defaults to OFF —
 * hr.leave.carry_over — because that is the statutory floor: the UU grants the
 * days per year and is silent on hoarding them, and a default that accumulates
 * would let five quiet years turn into a sixty-day absence nobody budgeted.
 */
class LeaveService
{
    /**
     * Working days between two dates, inclusive.
     *
     * hr.leave.workweek_days = 6 (default): only Sunday is a rest day — the
     * Kepmenaker 40-hour week over six days that construction sites actually
     * run. Set to 5 for an office regime and Saturdays stop burning saldo.
     * Public holidays are NOT excluded: the system has no holiday calendar,
     * and silently guessing one would debit or refund days no one can audit.
     */
    public static function workingDays(CarbonInterface $from, CarbonInterface $to): int
    {
        $restDays = Erp::int('hr.leave.workweek_days', 6) >= 6
            ? [CarbonInterface::SUNDAY]
            : [CarbonInterface::SATURDAY, CarbonInterface::SUNDAY];

        $days = 0;
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            if (! in_array($cursor->dayOfWeek, $restDays, true)) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    public function create(array $data): LeaveRequest
    {
        return DB::transaction(function () use ($data): LeaveRequest {
            $start = Carbon::parse($data['start_date']);
            $end = Carbon::parse($data['end_date']);

            $this->assertSaneRange($start, $end);
            $this->assertNoOverlap((int) $data['employee_id'], $start, $end);

            $request = new LeaveRequest(Arr::except($data, ['code', 'status', 'day_count']));
            $request->day_count = $this->countedDays($start, $end);
            $request->status = DocumentStatus::Draft;
            $request->save(); // HasDocumentNumber fills the CTI code

            return $request;
        });
    }

    public function update(LeaveRequest $request, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($request, $data): LeaveRequest {
            // Diputuskan pada baris yang dibaca ulang, bukan instance route
            // binding: approve yang commit di antara keduanya membuat edit ini
            // menimpa pengajuan yang sudah disetujui — dan rekapnya sudah
            // termakan saldo. Tujuh kali pola ini terbakar di repo ini.
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $this->assertEditable($request);

            // employee_id stays immovable, same rule as project_id on an issue
            // (temuan 11): re-pointing a document at somebody else would carry
            // its day_count into the other person's saldo window unreviewed.
            $request->fill(Arr::except($data, ['code', 'status', 'day_count', 'employee_id']));

            $this->assertSaneRange($request->start_date, $request->end_date);
            $this->assertNoOverlap($request->employee_id, $request->start_date, $request->end_date, $request->id);

            $request->day_count = $this->countedDays($request->start_date, $request->end_date);
            $request->save();

            return $request;
        });
    }

    public function delete(LeaveRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $this->assertEditable($request);

            $request->delete();
        });
    }

    /**
     * Submit with the saldo guard. The re-read under lock is not decoration:
     * two browser tabs submitting the last three days of saldo at once must
     * serialise here, or both pass the check and the second approval finds a
     * negative balance it can only honour or repudiate.
     */
    public function submit(LeaveRequest $request, ?User $by): LeaveRequest
    {
        return DB::transaction(function () use ($request, $by): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DocumentStatus::Draft || $locked->status === DocumentStatus::Rejected) {
                $this->assertBalanceCovers($locked);
            }

            return $locked->submit($by);
        });
    }

    /**
     * Approve = the decision that debits the saldo and feeds the recap, in one
     * transaction. The balance is re-checked on the locked row because it may
     * have shrunk since submit: another request of the same employee can be
     * approved in between, and "approved" printed on two overlapping claims to
     * the same three days is exactly the register this feature replaces.
     *
     * @return array{request: LeaveRequest, skipped_periods: list<string>}
     */
    public function approve(LeaveRequest $request, User $by, ?string $note = null): array
    {
        return DB::transaction(function () use ($request, $by, $note): array {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DocumentStatus::Submitted) {
                $this->assertBalanceCovers($locked);
            }

            $locked->approve($by, $note); // asserts submitted + maker-checker

            return [
                'request' => $locked,
                'skipped_periods' => $this->syncRecaps($locked),
            ];
        });
    }

    /**
     * Saldo cuti tahunan of one employee, computed, never stored.
     *
     * @return array{
     *     eligible: bool, eligible_from: string,
     *     window_start: string|null, window_end: string|null,
     *     entitled: int, carried_over: int, used: int, pending: int, remaining: int,
     * }
     */
    public function balance(Employee $employee, ?CarbonInterface $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $join = $employee->join_date->copy()->startOfDay();

        // UU 13/2003 Pasal 79: the right EXISTS only after 12 months masa
        // kerja. 11 months = 0 hari, not 11/12 of the year — annual leave does
        // not accrue monthly under the statute.
        $eligibleFrom = $join->copy()->addMonthsNoOverflow(12);

        if ($asOf->lt($eligibleFrom)) {
            return [
                'eligible' => false, 'eligible_from' => $eligibleFrom->toDateString(),
                'window_start' => null, 'window_end' => null,
                'entitled' => 0, 'carried_over' => 0, 'used' => 0, 'pending' => 0, 'remaining' => 0,
            ];
        }

        // The entitlement year runs anniversary to anniversary. max(1, …)
        // covers the Feb-29 joiner whose diffInYears reads 0 on Feb 28.
        $fullYears = max(1, (int) $join->diffInYears($asOf));
        $windowStart = $join->copy()->addYearsNoOverflow($fullYears);

        if ($windowStart->gt($asOf)) {
            $windowStart = $join->copy()->addYearsNoOverflow($fullYears - 1);
        }

        $windowEnd = $windowStart->copy()->addYearsNoOverflow(1);

        $entitled = Erp::int('hr.leave.annual_days', 12);

        // Carry-over policy, default NO: the remainder dies on the
        // anniversary. When switched on, only the immediately previous year's
        // remainder rides along — an unbounded accumulator would quietly grow
        // a liability no one approved.
        $carried = 0;

        if (Erp::bool('hr.leave.carry_over', false) && $fullYears >= 2) {
            $usedPrevious = $this->takenDays(
                $employee->id,
                $windowStart->copy()->subYearsNoOverflow(1),
                $windowStart,
                [DocumentStatus::Approved],
            );
            $carried = max(0, $entitled - $usedPrevious);
        }

        $used = $this->takenDays($employee->id, $windowStart, $windowEnd, [DocumentStatus::Approved]);
        $pending = $this->takenDays($employee->id, $windowStart, $windowEnd, [DocumentStatus::Submitted]);

        return [
            'eligible' => true, 'eligible_from' => $eligibleFrom->toDateString(),
            'window_start' => $windowStart->toDateString(), 'window_end' => $windowEnd->toDateString(),
            'entitled' => $entitled, 'carried_over' => $carried,
            'used' => $used, 'pending' => $pending,
            'remaining' => $entitled + $carried - $used,
        ];
    }

    /**
     * Push the approved absences of the months this request touches into the
     * monthly recap — the numbers HR used to type by hand (finding #22).
     *
     * FORWARD-ONLY. A month whose REGULAR payroll run is already approved or
     * closed is skipped and reported back, never rewritten: the recap is the
     * record of what that posted run was computed from, and editing it
     * afterwards would leave a payroll in the ledger that its own recap
     * contradicts. The leave lands in the register either way; only the recap
     * of a posted period stays frozen.
     *
     * Recomputed wholesale per month from every approved request overlapping
     * it (the overlap guard keeps requests disjoint, so the sum cannot double
     * count) — an increment would go wrong the first time anything is approved
     * twice or synced out of order.
     *
     * @return list<string> periods skipped as 'YYYY-MM'
     */
    private function syncRecaps(LeaveRequest $request): array
    {
        $skipped = [];
        $cursor = $request->start_date->copy()->startOfMonth();
        $lastMonth = $request->end_date->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            if ($this->periodPayrollPosted($cursor->year, $cursor->month)) {
                $skipped[] = $cursor->format('Y-m');
                $cursor->addMonth();

                continue;
            }

            $monthStart = $cursor->copy();
            $monthEnd = $cursor->copy()->endOfMonth();

            $sick = 0;
            $leave = 0;

            $approved = LeaveRequest::query()
                ->where('employee_id', $request->employee_id)
                ->where('status', DocumentStatus::Approved->value)
                ->whereDate('start_date', '<=', $monthEnd->toDateString())
                ->whereDate('end_date', '>=', $monthStart->toDateString())
                ->get();

            foreach ($approved as $row) {
                $days = $this->countedDays($row->start_date->max($monthStart), $row->end_date->min($monthEnd));

                if ($row->leave_type->recapColumn() === 'sick_days') {
                    $sick += $days;
                } else {
                    $leave += $days;
                }
            }

            $recap = AttendanceRecap::query()->firstOrNew([
                'employee_id' => $request->employee_id,
                'period_year' => $cursor->year,
                'period_month' => $cursor->month,
            ]);

            $recap->sick_days = $sick;
            $recap->leave_days = $leave;
            $recap->save();

            $cursor->addMonth();
        }

        return $skipped;
    }

    private function periodPayrollPosted(int $year, int $month): bool
    {
        return PayrollRun::query()
            ->where('run_type', PayrollRunType::Regular->value)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->exists();
    }

    private function assertBalanceCovers(LeaveRequest $request): void
    {
        if (! $request->leave_type->countsAgainstBalance()) {
            return; // sakit/izin/khusus are recorded, not debited (Pasal 93)
        }

        // The window of the START date, not today's: a December request filed
        // in November must be judged against the year it will consume.
        $balance = $this->balance($request->employee()->firstOrFail(), $request->start_date);

        if (! $balance['eligible']) {
            throw new LogicException(
                'Cuti tahunan belum tersedia: masa kerja belum genap 12 bulan (UU 13/2003 Pasal 79). '
                ."Hak cuti terbit {$balance['eligible_from']}."
            );
        }

        if ($balance['remaining'] < $request->day_count) {
            throw new LogicException(
                "Saldo cuti tahunan tidak cukup: sisa {$balance['remaining']} hari, "
                ."diminta {$request->day_count} hari ({$request->code})."
            );
        }
    }

    /**
     * Approved-or-submitted cuti tahunan days whose start_date falls in
     * [$from, $until). A request is attributed whole to the window its start
     * date opens in — splitting one document across two entitlement years
     * would make its day_count disagree with every screen that shows it.
     */
    private function takenDays(int $employeeId, CarbonInterface $from, CarbonInterface $until, array $statuses): int
    {
        return (int) LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type', LeaveType::Tahunan->value)
            ->whereIn('status', array_map(static fn (DocumentStatus $status): string => $status->value, $statuses))
            ->whereDate('start_date', '>=', $from->toDateString())
            ->whereDate('start_date', '<', $until->toDateString())
            ->sum('day_count');
    }

    private function countedDays(CarbonInterface $start, CarbonInterface $end): int
    {
        $days = self::workingDays($start, $end);

        if ($days < 1) {
            // A Sunday-only range on the six-day week: zero saldo movement and
            // an absence covering no working day is not a document.
            throw new LogicException('Rentang tanggal tidak memuat satu pun hari kerja.');
        }

        return $days;
    }

    private function assertSaneRange(CarbonInterface $start, CarbonInterface $end): void
    {
        if ($end->lt($start)) {
            throw new LogicException('Tanggal selesai mendahului tanggal mulai.');
        }

        // 90 days is far beyond any statutory leave; past it the plausible
        // explanation is a mistyped year, which would otherwise loop the day
        // counter through decades and file a request debiting hundreds of days.
        if ($start->diffInDays($end) > 90) {
            throw new LogicException('Rentang cuti melebihi 90 hari — periksa tahunnya.');
        }
    }

    private function assertNoOverlap(int $employeeId, CarbonInterface $start, CarbonInterface $end, ?int $exceptId = null): void
    {
        $colliding = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', [DocumentStatus::Rejected->value, DocumentStatus::Cancelled->value])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->first();

        if ($colliding !== null) {
            // Overlap with a DRAFT blocks too: two documents claiming the same
            // Tuesday would both sync the recap and the later approval would
            // double-debit the saldo.
            throw new LogicException(
                "Rentang tanggal bertabrakan dengan {$colliding->code} "
                ."({$colliding->start_date->toDateString()} s.d. {$colliding->end_date->toDateString()})."
            );
        }
    }

    private function assertEditable(LeaveRequest $request): void
    {
        if (! $request->status->isEditable()) {
            throw new LogicException(
                "Leave request {$request->code} cannot be modified while status is {$request->status->value}."
            );
        }
    }
}
