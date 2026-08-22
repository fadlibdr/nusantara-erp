<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Support\Terbilang;
use Modules\HrPayroll\Enums\AttendanceStatus;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Models\Payslip;

/**
 * The body of the three SDM house forms, in the taste of
 * Modules\Procurement\Services\ProcurementFormService.
 *
 * ==========================================================================
 * THE PERSONAL-DATA POSTURE, stated once here because all three sheets
 * inherit it and because a printed sheet is the one artefact that leaves the
 * permission system entirely.
 *
 * hr_employees carries nik_ktp, npwp and a bank account number. The routes
 * already gate even the GETs of the cuti and sertifikat registers on hr.view
 * for exactly that reason ("personal data no procurement- or servis-only
 * token has any business reading"). A printout has no token: it is
 * photocopied, left on a desk, pinned to a site notice board and filed for
 * seven years.
 *
 * So these three print the least that still makes them useful — name,
 * employee code, position, and the money or the days the sheet is about. No
 * KTP number, no NPWP, no bank account, on any of them, and that is a
 * deliberate omission rather than an oversight. The document that legitimately
 * carries those is the per-employee slip gaji, which already exists as a
 * dompdf artefact, is printed one employee at a time, and is NOT duplicated
 * here.
 * ==========================================================================
 *
 * TWO ANSWERS THAT NEED PROSE, which is why they are methods rather than
 * closures in the registry:
 *
 *   THE CUTI SALDO EXISTS FOR CUTI TAHUNAN AND NOWHERE ELSE. LeaveService
 *   computes it from join_date and the approved rows (UU 13/2003 Pasal 79: the
 *   right exists only after 12 months, 12 hari per entitlement year). Sakit,
 *   izin and cuti khusus have no quota in the statute and none in this ERP, so
 *   a "sisa 7 hari" printed on a sick note would be a balance nobody keeps.
 *   The block says so in a sentence instead.
 *
 *   A DAFTAR HADIR IS ONE PROJECT ON ONE DAY. hr_attendances is the only place
 *   that pair exists — hr_attendance_recaps is (employee, month) and cannot
 *   answer "who was on the Cakung site on 6 August" at all. So the sheet
 *   printed from any attendance row is the register of THAT row's project on
 *   THAT row's date, the same shape the Crm guarantee register uses.
 */
class HrFormService
{
    /**
     * Bulan dalam bahasa Indonesia — the payroll period is (year, month) and
     * nothing else. Another copy for the reason FormPrintService gives for
     * its own: APP_LOCALE is 'en' with no lang/ directory, so translatedFormat()
     * would mean switching the whole application locale to 'id'.
     */
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    // ------------------------------------------------------------ rekap gaji

    /**
     * One printed line per payslip.
     *
     * The columns are chosen so the row ADDS UP on the paper: pokok +
     * tunjangan + lembur + THR = bruto, and bruto − potongan = netto. A rekap
     * whose columns do not reconcile is a rekap a director cannot check, and
     * dropping THR (zero on a regular run, everything on a THR run) is exactly
     * how that happens.
     *
     * @return list<array<string, mixed>>
     */
    public function payrollRows(PayrollRun $run): array
    {
        $rows = [];
        $no = 0;

        foreach ($run->payslips as $slip) {
            /** @var Payslip $slip */
            $rows[] = [
                'no' => ++$no,
                'nama' => $slip->employee?->name,
                'jabatan' => $slip->employee?->position,
                'pokok' => (float) $slip->basic_salary,
                'tunjangan' => (float) $slip->allowances_total,
                'lembur' => (float) $slip->overtime_pay,
                'thr' => (float) $slip->thr_amount,
                'bruto' => (float) $slip->gross_income,
                'potongan' => (float) $slip->total_deductions,
                'netto' => (float) $slip->net_pay,
            ];
        }

        return $rows;
    }

    /**
     * The recap under the table.
     *
     * The first three are the run's OWN stored totals, not a second sum taken
     * at print time: PayrollService wrote them and PayrollPostingService
     * journals against them, so the sheet and the ledger cannot disagree.
     *
     * The employer BPJS line is summed from the payslips because the run
     * carries no column for it — and it is printed because it is the number
     * that makes a rekap gaji a cost report rather than a transfer list: the
     * company pays it and it never appears in anyone's netto.
     *
     * The netto line states the identity rather than repeating the sheet's
     * headline, and that is not decoration: it is the third of three stored
     * totals whose siblings already say what they comprise, PayrollService
     * writes all three off the same payslips (net_pay is gross_income −
     * total_deductions on every slip, THR runs included), and a reader can
     * therefore check the block with a pen instead of trusting it. It also
     * stops this cell reading as a second, independent statement of the figure
     * the body table already foots — see the totals row on rekap-payroll.
     *
     * @return list<array<string, mixed>>
     */
    public function payrollRecapRows(PayrollRun $run): array
    {
        return [
            ['uraian' => 'Jumlah bruto (gaji, tunjangan, lembur, THR)', 'nilai' => (float) $run->total_gross],
            ['uraian' => 'Jumlah potongan (BPJS karyawan, PPh 21, lain-lain)', 'nilai' => (float) $run->total_deductions],
            ['uraian' => 'JUMLAH NETTO DIBAYARKAN (bruto − potongan)', 'nilai' => (float) $run->total_net],
            [
                'uraian' => 'Iuran BPJS ditanggung perusahaan (di luar netto)',
                'nilai' => round((float) $run->payslips->sum('bpjs_company_total'), 2),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function payrollTerbilangRow(PayrollRun $run): array
    {
        return [[
            'amount' => (float) $run->total_net,
            'terbilang' => Terbilang::rupiah((float) $run->total_net),
        ]];
    }

    public function periodLabel(?int $month, ?int $year): ?string
    {
        if ($month === null || $year === null) {
            return null;
        }

        return (self::MONTHS[$month] ?? (string) $month).' '.$year;
    }

    // -------------------------------------------------------- pengajuan cuti

    /**
     * The saldo block — or nothing at all, which is what the sheet's `empty`
     * sentence then prints. See the class docblock.
     *
     * Counted AS AT THE FIRST DAY OF THE REQUESTED LEAVE, not as at today: the
     * question this block answers is "does this request fit", and a form
     * reprinted in November must still show the balance the approver decided
     * on. Every figure comes from LeaveService — the same arithmetic
     * assertBalanceCovers() refuses a submit on — so the paper and the guard
     * can never disagree about how many days are left.
     *
     * @return list<array<string, mixed>>
     */
    public function leaveBalanceRows(LeaveRequest $request): array
    {
        // LeaveType::countsAgainstBalance() is the module's own answer to
        // "does this kind burn saldo" — asked rather than re-decided here, so
        // a fifth leave type added there cannot start printing a balance the
        // statute does not grant it.
        if ($request->leave_type?->countsAgainstBalance() !== true || $request->employee === null) {
            return [];
        }

        $balance = app(LeaveService::class)->balance($request->employee, $request->start_date);

        /*
         * The dates are handed over as CARBON INSTANCES, never as the ISO
         * strings LeaveService returns them in. The sheet's own text cast
         * renders a date object as "01 Maret 2026"; concatenating it into a
         * sentence here would print "2026-03-01" beside a TANGGAL MASUK KERJA
         * three lines above that reads "01 Maret 2021" — two date formats on
         * one signed form, which is the sort of thing that makes a reader stop
         * trusting the rest of it.
         */
        if ($balance['eligible'] === false) {
            return [
                [
                    'uraian' => 'Hak cuti tahunan',
                    'nilai' => 'Belum berhak — timbul setelah 12 bulan masa kerja',
                ],
                ['uraian' => 'Mulai berhak', 'nilai' => Carbon::parse($balance['eligible_from'])],
            ];
        }

        return [
            ['uraian' => 'Periode hak berjalan — mulai', 'nilai' => Carbon::parse($balance['window_start'])],
            ['uraian' => 'Periode hak berjalan — sampai', 'nilai' => Carbon::parse($balance['window_end'])],
            ['uraian' => 'Hak cuti tahunan periode berjalan', 'nilai' => $balance['entitled'].' hari'],
            ['uraian' => 'Sisa periode sebelumnya (carry over)', 'nilai' => $balance['carried_over'].' hari'],
            ['uraian' => 'Sudah diambil (disetujui)', 'nilai' => $balance['used'].' hari'],
            ['uraian' => 'Sedang diajukan (belum disetujui)', 'nilai' => $balance['pending'].' hari'],
            ['uraian' => 'Sisa saldo', 'nilai' => $balance['remaining'].' hari'],
        ];
    }

    // --------------------------------------------------------- daftar hadir

    /**
     * The register the anchor row belongs to: same DATE, same PROJECT.
     *
     * whereDate, not a plain equality: the date cast STORES midnight
     * timestamps, and AttendanceService::bulkUpsert learned the same lesson the
     * hard way. A null project is matched with whereNull rather than skipped —
     * an office sheet is a register too.
     *
     * Ordered by employee code so the printed sheet keeps the same row order
     * every time it is reprinted; a signature list that reshuffles between
     * prints cannot be compared with last week's.
     *
     * @return Collection<int, Attendance>
     */
    public function attendanceRegister(Attendance $anchor): Collection
    {
        $date = $anchor->date?->toDateString();

        return Attendance::query()
            // withTrashed: hr_employees soft-deletes, and a daftar hadir is a
            // record of who was on site that day. An employee who has since
            // left would otherwise leave a signed row with a real status and
            // a ruled name.
            ->with(['employee' => fn ($query) => $query->withTrashed()])
            ->whereDate('date', $date)
            ->when(
                $anchor->project_id === null,
                fn ($query) => $query->whereNull('project_id'),
                fn ($query) => $query->where('project_id', $anchor->project_id),
            )
            ->get()
            ->sortBy(fn (Attendance $row): string => (string) ($row->employee?->code ?? '~'))
            ->values();
    }

    /**
     * One printed line per worker.
     *
     * jam_masuk, jam_keluar and tanda_tangan are absent from every row on
     * purpose: nothing in hr_attendances records a clock time, and the
     * signature is the wet ink the sheet is printed to collect. The generic
     * Blade rules any cell it is not given, which is exactly the behaviour
     * wanted here — see the columns declared with no value at all.
     *
     * @return list<array<string, mixed>>
     */
    public function attendanceRows(Attendance $anchor): array
    {
        $rows = [];
        $no = 0;

        foreach ($this->attendanceRegister($anchor) as $row) {
            $rows[] = [
                'no' => ++$no,
                'kode' => $row->employee?->code,
                'nama' => $row->employee?->name,
                'jabatan' => $row->employee?->position,
                'status' => $row->status?->label(),
                'keterangan' => $row->note,
            ];
        }

        return $rows;
    }

    /** How many rows the register holds — a count, and counts start at 0. */
    public function attendanceCount(Attendance $anchor): int
    {
        return $this->attendanceRegister($anchor)->count();
    }

    /**
     * "Hadir 12, Sakit 1, Izin 0, Alpa 2" — every status listed whether or not
     * anybody has it.
     *
     * A band that changes shape between prints cannot be read at a glance, and
     * "Alpa 0" is a fact this register asserts rather than a gap. Same reason
     * the defect register lists all three severities.
     */
    public function attendanceRecap(Attendance $anchor): string
    {
        $counts = $this->attendanceRegister($anchor)
            ->countBy(fn (Attendance $row): string => (string) $row->status?->value);

        $parts = [];

        foreach (AttendanceStatus::cases() as $status) {
            $parts[] = $status->label().' '.(int) ($counts[$status->value] ?? 0);
        }

        return implode(', ', $parts);
    }
}
