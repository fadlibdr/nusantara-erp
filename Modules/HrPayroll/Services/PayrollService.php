<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\HrPayroll\Enums\OvertimeBasis;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Models\Payslip;

class PayrollService
{
    public function __construct(private readonly Pph21TerService $pph21) {}

    public function create(array $data): PayrollRun
    {
        return DB::transaction(function () use ($data): PayrollRun {
            $run = new PayrollRun(Arr::except($data, ['code', 'status']));
            $run->status = DocumentStatus::Draft;
            $run->save(); // HasDocumentNumber fills the PYR code

            return $run;
        });
    }

    public function update(PayrollRun $run, array $data): PayrollRun
    {
        $this->assertEditable($run);

        return DB::transaction(function () use ($run, $data): PayrollRun {
            $run->fill(Arr::except($data, ['code', 'status', 'total_gross', 'total_deductions', 'total_net']));

            $periodChanged = $run->isDirty(['period_year', 'period_month', 'run_type']);
            $run->save();

            if ($periodChanged) {
                // Payslips computed for the old period/type are stale — drop them.
                $run->payslips()->delete();
                $run->forceFill(['total_gross' => 0, 'total_deductions' => 0, 'total_net' => 0])->save();
            }

            return $run;
        });
    }

    public function delete(PayrollRun $run): void
    {
        $this->assertEditable($run);

        DB::transaction(function () use ($run): void {
            $run->payslips()->delete();
            $run->delete();
        });
    }

    /**
     * Generate payslips for all active employees of the run's period.
     * Recalculating replaces any previously generated payslips wholesale.
     */
    public function calculate(PayrollRun $run): PayrollRun
    {
        $this->assertEditable($run);

        return DB::transaction(function () use ($run): PayrollRun {
            $run->payslips()->delete();

            $periodEnd = Carbon::create($run->period_year, $run->period_month, 1)->endOfMonth();

            $employees = Employee::query()
                ->where('status', 'active')
                ->whereDate('join_date', '<=', $periodEnd->toDateString())
                ->orderBy('code')
                ->get();

            $projectByEmployee = $this->projectAssignments($run, $periodEnd);

            foreach ($employees as $employee) {
                $payslip = $run->run_type === PayrollRunType::Thr
                    ? $this->buildThrPayslip($run, $employee, $periodEnd)
                    : $this->buildRegularPayslip($run, $employee);

                if ($payslip === null) {
                    continue;
                }

                // Frozen here, not resolved at posting time: assignments change,
                // and a payslip that re-allocated itself afterwards would make
                // the same approved run post to different projects on different
                // days.
                $payslip['project_id'] = $projectByEmployee[$employee->id] ?? null;

                $run->payslips()->create($payslip);
            }

            $this->refreshTotals($run);

            return $run->load('payslips.employee');
        });
    }

    /**
     * Which project each employee was working on during the payroll period.
     *
     * An assignment counts when its date range OVERLAPS the period at all, so
     * somebody who joined a site mid-month is still that project's cost — the
     * alternative, requiring the assignment to span the whole month, would send
     * every mid-month mobilisation to office overhead.
     *
     * Where an employee has more than one overlapping assignment the most
     * recently started one wins, and it is a single choice rather than a split:
     * splitting a wage across sites needs a basis this system does not capture
     * (days on each), and inventing one would put a made-up number in the
     * project ledger.
     *
     * @return array<int, int> employee_id => project_id
     */
    private function projectAssignments(PayrollRun $run, Carbon $periodEnd): array
    {
        $periodStart = $periodEnd->copy()->startOfMonth()->toDateString();
        $periodEndDate = $periodEnd->toDateString();

        $rows = DB::table('prj_manpower_assignments')
            ->where('is_active', true)
            ->whereDate('assigned_from', '<=', $periodEndDate)
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('assigned_until')
                    ->orWhereDate('assigned_until', '>=', $periodStart);
            })
            ->orderBy('assigned_from')
            ->get(['employee_id', 'project_id']);

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->employee_id] = (int) $row->project_id;
        }

        return $map;
    }

    /**
     * Regular monthly payslip:
     * gross = basic + fixed allowances + overtime; deductions = employee BPJS + PPh 21.
     */
    private function buildRegularPayslip(PayrollRun $run, Employee $employee): array
    {
        $recap = AttendanceRecap::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', $run->period_year)
            ->where('period_month', $run->period_month)
            ->first();

        $basic = round((float) $employee->base_salary, 2);
        $allowances = $employee->fixed_allowances ?? [];
        $allowancesTotal = $employee->fixedAllowancesTotal();

        /*
         * Kepmenaker 102/2004: upah sejam = 1/173 x upah sebulan, dan upah
         * sebulan memuat tunjangan tetap (basis yang sama dengan BPJS di
         * bawah). Lembur dibayar 1,5x untuk jam PERTAMA setiap hari lembur dan
         * 2x untuk jam berikutnya.
         *
         * SAMPAI F-5 baris ini menerapkan 1,5x RATA atas total bulanan, dan
         * komentar di tempat ini mengakui sendiri itu kurang: rekap bulanan
         * hanya menyimpan TOTAL jam, jadi tidak ada yang tahu jam mana yang
         * jam pertama. F-5 memberi rincian hariannya — lihat
         * overtimeComputation() untuk kapan ia dipakai, kapan jalur lama tetap
         * dipakai, dan kenapa slipnya harus menyebutkan yang mana.
         *
         * TOTAL JAMNYA TETAP DARI REKAP BULANAN. Rincian harian menentukan
         * TARIF-nya, tidak pernah jumlahnya: ILB tetap otoritatif atas berapa
         * jam lembur yang berhak dibayar, dan rekap tetap tempat payroll
         * membacanya.
         */
        $overtimeHours = round((float) ($recap?->overtime_hours ?? 0), 2);
        $overtime = $this->overtimeComputation($run, $employee, $overtimeHours, $basic + $allowancesTotal);
        $overtimePay = $overtime['pay'];

        $gross = round($basic + $allowancesTotal + $overtimePay, 2);

        $bpjs = $this->computeBpjs($basic, $allowancesTotal);

        // PPh 21 base simplification: the fiscal monthly gross should also include the
        // employer-paid BPJS premiums treated as income (JKK + JKM + Kesehatan company
        // share); this implementation uses the cash gross only.
        $hasTaxId = $employee->hasTaxId();

        if ((int) $run->period_month === 12) {
            // December: Pasal 17 annual true-up instead of the TER rate.
            $tax = $this->decemberTax($run, $employee, $gross, $bpjs['breakdown'], $hasTaxId);
        } else {
            $tax = $this->pph21->monthlyTax($employee->ptkp_status, $gross, $hasTaxId);
        }

        $totalDeductions = round($bpjs['employee_total'] + $tax['amount'], 2);

        return [
            'employee_id' => $employee->id,
            'basic_salary' => $basic,
            'allowances' => $allowances,
            'allowances_total' => $allowancesTotal,
            'overtime_hours' => $overtimeHours,
            'overtime_pay' => $overtimePay,
            // Dibekukan, seperti project_id dan has_tax_id di bawah: pertanyaan
            // "dengan dasar apa slip ini dibayar" adalah pertanyaan tentang uang
            // yang sudah keluar, dan jawabannya tidak boleh berubah karena
            // absensi bulan itu dikoreksi sesudahnya.
            'overtime_basis' => $overtime['basis'],
            'overtime_rate_detail' => $overtime['detail'],
            'thr_amount' => 0,
            'gross_income' => $gross,
            'bpjs' => $bpjs['breakdown'],
            'bpjs_employee_total' => $bpjs['employee_total'],
            'bpjs_company_total' => $bpjs['company_total'],
            'ter_category' => $tax['category'],
            'ter_rate' => $tax['rate'],
            'pph21_amount' => $tax['amount'],
            // Frozen: the flag this very computation used (P-3b R2-rekap-1).
            'has_tax_id' => $hasTaxId,
            'total_deductions' => $totalDeductions,
            'net_pay' => round($gross - $totalDeductions, 2),
        ];
    }

    /**
     * UPAH LEMBUR, DAN DASAR YANG DIPAKAI MENGHITUNGNYA (F-5, T5.3).
     *
     * INI MENYENTUH UANG, jadi aturannya ditulis di sini lengkap.
     *
     * 1. TOTAL JAM SELALU DARI REKAP BULANAN. Rincian harian tidak pernah
     *    menambah atau mengurangi satu jam pun — ILB tetap otoritatif atas
     *    berapa jam yang berhak dibayar. Yang diputuskan di sini hanya TARIF.
     *
     * 2. RINCIAN HARIAN DIPAKAI HANYA BILA TOTALNYA SAMA PERSIS dengan total
     *    rekap. Kalau keduanya berbeda — ILB menulis 10 jam sementara absensi
     *    mengukur 7 — maka bentuk harian yang diketahui BUKAN bentuk dari jam
     *    yang dibayar, dan membelah 10 jam menurut bentuk 7 jam adalah
     *    mengarang hari lembur yang tidak ada catatannya. Dalam keadaan itu
     *    jalur lama tetap dipakai apa adanya.
     *
     * 3. JALUR LAMA TETAP HIDUP, dan slipnya MENGATAKAN ia yang dipakai
     *    beserta sebabnya. Dua periode yang dibayar dengan tarif berbeda tanpa
     *    ada yang bisa melihat sebabnya adalah persis cacat yang kolom
     *    `overtime_basis` dibuat untuk mencegahnya.
     *
     * MAJU-SAJA ikut dari pemanggilnya: calculate() menolak run yang statusnya
     * tidak editable (assertEditable), jadi periode yang payroll-nya sudah
     * approved atau closed tidak pernah sampai ke fungsi ini sama sekali. Tidak
     * ada perhitungan ulang surut, dan tidak ada backfill kolom pada slip lama.
     *
     * @param  float  $monthlyWage  gaji pokok + tunjangan tetap
     * @return array{pay: float, basis: string, detail: array<string, mixed>|null}
     */
    private function overtimeComputation(PayrollRun $run, Employee $employee, float $overtimeHours, float $monthlyWage): array
    {
        $divisor = Erp::int('payroll.overtime.divisor', 173);
        $hourlyWage = $monthlyWage / $divisor;
        $firstRate = Erp::float('hr.timesheet.overtime_first_hour_pct', 150) / 100;
        $nextRate = Erp::float('hr.timesheet.overtime_next_hours_pct', 200) / 100;

        if ($overtimeHours <= 0) {
            return ['pay' => 0.0, 'basis' => OvertimeBasis::TanpaLembur->value, 'detail' => null];
        }

        $shape = app(TimesheetService::class)->measuredOvertimeShape(
            (int) $employee->id,
            (int) $run->period_year,
            (int) $run->period_month,
        );

        $flat = fn (string $reason): array => [
            'pay' => round($overtimeHours * $hourlyWage * $firstRate, 2),
            'basis' => OvertimeBasis::RataJamPertama->value,
            'detail' => [
                'divisor' => $divisor,
                'first_hour_pct' => round($firstRate * 100, 2),
                'next_hours_pct' => round($nextRate * 100, 2),
                // Jalur lama membayar dari JAM rekap, bukan dari menit absensi:
                // rekap bulanan memang menyimpan jam desimal, dan tidak ada
                // bentuk harian yang boleh dipakai membelahnya.
                'minutes_at_first_rate' => (int) round($overtimeHours * 60),
                'minutes_at_next_rate' => 0,
                'hours_at_first_rate' => $overtimeHours,
                'hours_at_next_rate' => 0.0,
                'days' => [],
                'reason' => $reason,
            ],
        ];

        if ($shape === null) {
            return $flat(
                'Tidak ada satu hari pun dengan cap jam masuk DAN pulang pada periode ini, jadi tidak '
                .'ada rincian harian yang bisa membelah jam pertama dari jam berikutnya. Seluruh jam '
                .'dibayar dengan tarif jam pertama — jalur yang berlaku sebelum 14 September 2026.'
            );
        }

        if (round($shape['total_hours'], 2) !== $overtimeHours) {
            return $flat(sprintf(
                'Rincian harian dari absensi berjumlah %s jam, sementara rekap bulanan yang dibayar '
                .'berjumlah %s jam. Karena keduanya tidak sama, bentuk harian yang diketahui bukan '
                .'bentuk dari jam yang dibayar, dan membelahnya akan mengarang hari lembur. Seluruh '
                .'jam dibayar dengan tarif jam pertama.',
                rtrim(rtrim(number_format($shape['total_hours'], 2, ',', '.'), '0'), ','),
                rtrim(rtrim(number_format($overtimeHours, 2, ',', '.'), '0'), ','),
            ));
        }

        /*
         * Jam PERTAMA tiap hari lembur pada tarif pertama, sisanya pada tarif
         * berikutnya. Sebuah hari berlembur 0,5 jam menyumbang 0,5 jam ke tarif
         * pertama dan tidak satu pun ke tarif berikutnya.
         *
         * DIJUMLAHKAN DALAM MENIT, DIBAGI 60 SEKALI DI AKHIR. Menjumlahkan
         * `hours` yang sudah dibulatkan per hari adalah pembulatan KEDUA, dan
         * gerbang di atas membandingkan rekap terhadap total yang dihitung dari
         * MENIT — jadi slip akan membayar jumlah jam yang berbeda dari jumlah
         * jam yang tertulis pada slip itu sendiri. Pada pembulatan bawaan 15
         * menit selisihnya nol dan tidak ada uji yang bisa melihatnya; pada
         * pembulatan 10 menit, tiga hari x 10 menit membayar Rp 953,76 LEBIH
         * dari 0,5 jam yang tertulis di kolom `overtime_hours`, dan pada 20
         * menit ia membayar Rp 953,75 KURANG. Arahnya bolak-balik, jadi ia juga
         * tidak bisa dibaca sebagai kebijakan.
         */
        $firstMinutes = 0;
        $nextMinutes = 0;

        foreach ($shape['days'] as $day) {
            $firstMinutes += min(60, (int) $day['minutes']);
            $nextMinutes += max(0, (int) $day['minutes'] - 60);
        }

        $firstHours = $firstMinutes / 60;
        $nextHours = $nextMinutes / 60;

        /*
         * SETIAP ANGKA JAM DI SINI DITURUNKAN DARI MENITNYA SENDIRI (putaran
         * penutup, V-3).
         *
         * Versi sebelumnya menghitung `hours_at_next_rate` sebagai SISA dari
         * `overtime_hours` REKAP, demi invarian "kedua angka harus berjumlah
         * persis jam yang dibayar". Invarian itu SALAH sesudah A-2/B-1
         * memindahkan sumber uang ke MENIT: kolom rekap adalah jam bulanan yang
         * disetujui HR, sementara yang dibayar adalah menit absensi. Ketika
         * keduanya tidak habis dibagi — 110 + 50 menit dengan rekap 2,67 jam,
         * yang bisa dicapai operator lewat `rounding_minutes` 10 atau 20 —
         * catatannya membantah dirinya sendiri: `minutes_at_next_rate` 50
         * (= 0,83 jam) berdampingan dengan `hours_at_next_rate` 0,84, dan
         * menghitung ulang upah dari kedua angka jam itu tidak memulangkan
         * `overtime_pay`.
         *
         * Sekarang keduanya dibulatkan dari menitnya masing-masing, dan
         * `minutes_paid` dibawa supaya catatan ini bisa menghasilkan kembali
         * upahnya SENDIRIAN — tanpa meminjam angka dari kolom rekap yang bukan
         * sumber uangnya.
         */
        return [
            'pay' => round(($firstHours * $firstRate + $nextHours * $nextRate) * $hourlyWage, 2),
            'basis' => OvertimeBasis::RincianHarian->value,
            'detail' => [
                'divisor' => $divisor,
                'first_hour_pct' => round($firstRate * 100, 2),
                'next_hours_pct' => round($nextRate * 100, 2),
                'minutes_at_first_rate' => $firstMinutes,
                'minutes_at_next_rate' => $nextMinutes,
                'minutes_paid' => $firstMinutes + $nextMinutes,
                'hours_at_first_rate' => round($firstHours, 2),
                'hours_at_next_rate' => round($nextHours, 2),
                'days' => $shape['days'],
                'reason' => null,
            ],
        ];
    }

    /**
     * THR payslip (Permenaker 6/2016): tenure >= 12 months gets 1x base salary,
     * tenure 1-11 months gets base * months / 12, tenure < 1 month gets nothing.
     * The statute allows gaji pokok + tunjangan tetap as the THR base; company
     * policy here uses the base salary only. No BPJS is levied on THR.
     */
    private function buildThrPayslip(PayrollRun $run, Employee $employee, Carbon $periodEnd): ?array
    {
        $tenureMonths = (int) floor($employee->join_date->diffInMonths($periodEnd));

        if ($tenureMonths < 1) {
            return null; // masa kerja below 1 month: not yet entitled to THR
        }

        $base = round((float) $employee->base_salary, 2);
        $thr = $tenureMonths >= 12 ? $base : round($base * $tenureMonths / 12, 2);

        // PPh 21 on THR: TER applies to the month's combined gross (salary + THR), so
        // this run withholds TER(salary + THR) - TER(salary). Simplification: the
        // month's salary gross is estimated as base + fixed allowances (overtime for
        // the month is not yet known when THR is paid).
        $monthlySalaryGross = round($base + $employee->fixedAllowancesTotal(), 2);
        $hasTaxId = $employee->hasTaxId();

        $combined = $this->pph21->monthlyTax($employee->ptkp_status, round($monthlySalaryGross + $thr, 2), $hasTaxId);
        $salaryOnly = $this->pph21->monthlyTax($employee->ptkp_status, $monthlySalaryGross, $hasTaxId);

        $pph21 = max(0.0, round($combined['amount'] - $salaryOnly['amount'], 2));

        return [
            'employee_id' => $employee->id,
            'basic_salary' => 0,
            'allowances' => null,
            'allowances_total' => 0,
            'overtime_hours' => 0,
            'overtime_pay' => 0,
            // Run THR tidak pernah membayar lembur — dan itu dikatakan, bukan
            // dibiarkan kosong: kolom kosong berarti "slip dihitung sebelum
            // F-5", keadaan yang sama sekali berbeda.
            'overtime_basis' => OvertimeBasis::TanpaLembur->value,
            'overtime_rate_detail' => null,
            'thr_amount' => $thr,
            'gross_income' => $thr,
            'bpjs' => $this->zeroBpjsBreakdown(),
            'bpjs_employee_total' => 0,
            'bpjs_company_total' => 0,
            // Stored rate is the TER rate of the combined (salary + THR) income.
            'ter_category' => $combined['category'],
            'ter_rate' => $combined['rate'],
            'pph21_amount' => $pph21,
            'has_tax_id' => $hasTaxId,
            'total_deductions' => $pph21,
            'net_pay' => round($thr - $pph21, 2),
        ];
    }

    /**
     * BPJS contributions. Wage base = basic salary + fixed allowances (upah pokok +
     * tunjangan tetap, the standard reported wage). Rates and caps come from the
     * settings store (config/erp.php defaults, overridable per installation).
     * Employer portions are NOT deducted from the employee.
     *
     * @return array{breakdown: array<string, float>, employee_total: float, company_total: float}
     */
    private function computeBpjs(float $basic, float $fixedAllowances): array
    {
        $wageBase = round($basic + $fixedAllowances, 2);

        $kesBase = min($wageBase, Erp::float('payroll.bpjs.kesehatan.salary_cap', 12000000));
        $jpBase = min($wageBase, Erp::float('payroll.bpjs.jp.salary_cap', 10547400));

        $riskClass = Erp::int('payroll.bpjs.jkk.default_risk_class', 3);
        $jkkRate = Erp::float("payroll.bpjs.jkk.rates.{$riskClass}", 0.89);

        $breakdown = [
            'kes_company' => round($kesBase * Erp::float('payroll.bpjs.kesehatan.company', 4.0) / 100, 2),
            'kes_employee' => round($kesBase * Erp::float('payroll.bpjs.kesehatan.employee', 1.0) / 100, 2),
            // JHT has no wage cap.
            'jht_company' => round($wageBase * Erp::float('payroll.bpjs.jht.company', 3.7) / 100, 2),
            'jht_employee' => round($wageBase * Erp::float('payroll.bpjs.jht.employee', 2.0) / 100, 2),
            'jp_company' => round($jpBase * Erp::float('payroll.bpjs.jp.company', 2.0) / 100, 2),
            'jp_employee' => round($jpBase * Erp::float('payroll.bpjs.jp.employee', 1.0) / 100, 2),
            'jkk_company' => round($wageBase * $jkkRate / 100, 2),
            'jkm_company' => round($wageBase * Erp::float('payroll.bpjs.jkm.company', 0.30) / 100, 2),
        ];

        return [
            'breakdown' => $breakdown,
            'employee_total' => round(
                $breakdown['kes_employee'] + $breakdown['jht_employee'] + $breakdown['jp_employee'],
                2,
            ),
            'company_total' => round(
                $breakdown['kes_company'] + $breakdown['jht_company'] + $breakdown['jp_company']
                + $breakdown['jkk_company'] + $breakdown['jkm_company'],
                2,
            ),
        ];
    }

    /**
     * December withholding = Pasal 17 annual tax minus TER already withheld Jan-Nov
     * (PMK 168/2023 Pasal 15). Prior months are read from this year's approved/closed
     * runs (THR runs included — THR is part of the annual gross). A negative amount
     * is a refund of over-withheld TER, paid back through the December payroll.
     *
     * @param  array<string, float>  $bpjsBreakdown
     * @return array{category: null, rate: null, amount: float}
     */
    private function decemberTax(
        PayrollRun $run,
        Employee $employee,
        float $decemberGross,
        array $bpjsBreakdown,
        bool $hasTaxId,
    ): array {
        $prior = Payslip::query()
            ->where('employee_id', $employee->id)
            ->whereHas('payrollRun', function ($query) use ($run): void {
                $query->where('period_year', $run->period_year)
                    ->where('id', '!=', $run->id)
                    ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value]);
            })
            ->get();

        $annualGross = round((float) $prior->sum('gross_income') + $decemberGross, 2);
        $withheld = round((float) $prior->sum('pph21_amount'), 2);

        // Deductible pension contributions: employee JHT + JP shares (BPJS Kesehatan
        // employee share is not deductible for PPh 21).
        $annualJhtJp = round(
            (float) $prior->sum(
                static fn (Payslip $slip): float => (float) (($slip->bpjs['jht_employee'] ?? 0))
                    + (float) (($slip->bpjs['jp_employee'] ?? 0)),
            )
            + $bpjsBreakdown['jht_employee'] + $bpjsBreakdown['jp_employee'],
            2,
        );

        $trueUp = $this->pph21->annualTrueUp(
            $employee->ptkp_status,
            $annualGross,
            $annualJhtJp,
            $withheld,
            $hasTaxId,
        );

        return ['category' => null, 'rate' => null, 'amount' => $trueUp['december_tax']];
    }

    /**
     * @return array<string, float>
     */
    private function zeroBpjsBreakdown(): array
    {
        return [
            'kes_company' => 0, 'kes_employee' => 0,
            'jht_company' => 0, 'jht_employee' => 0,
            'jp_company' => 0, 'jp_employee' => 0,
            'jkk_company' => 0, 'jkm_company' => 0,
        ];
    }

    private function refreshTotals(PayrollRun $run): void
    {
        $payslips = $run->payslips()->get();

        $run->forceFill([
            'total_gross' => round((float) $payslips->sum('gross_income'), 2),
            'total_deductions' => round((float) $payslips->sum('total_deductions'), 2),
            'total_net' => round((float) $payslips->sum('net_pay'), 2),
        ])->save();
    }

    private function assertEditable(PayrollRun $run): void
    {
        if (! $run->status->isEditable()) {
            throw new LogicException(
                "Payroll run {$run->code} cannot be modified while status is {$run->status->value}."
            );
        }
    }
}
