<?php

namespace Modules\HrPayroll\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\NumberSequence;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Services\PayrollService;

class HrPayrollDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEmployees();
        $this->seedAttendanceRecaps();
        $this->seedThrRun();
        $this->seedRegularRun();
        $this->seedLeaveRequests();
        $this->seedAttendances();
        $this->syncNumberSequences();

        // Iam's UserSeeder runs before HrPayroll, so its hr_employees lookups
        // stored null; repair the links now that the canon employees exist.
        $this->backfillUserEmployeeLinks();
    }

    /**
     * Back-fill users.employee_id from the canon EMP codes (single-pass
     * db:seed correctness). Role emails per Modules\Iam UserSeeder; only
     * null links are touched.
     */
    private function backfillUserEmployeeLinks(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $links = [
            'direktur@nusantara.test' => 'EMP-0001',
            'project-manager@nusantara.test' => 'EMP-0002',
            'site-manager@nusantara.test' => 'EMP-0003',
            'estimator@nusantara.test' => 'EMP-0008',
            'procurement@nusantara.test' => 'EMP-0005',
            'finance@nusantara.test' => 'EMP-0004',
            'hr@nusantara.test' => 'EMP-0006',
            'teknisi@nusantara.test' => 'EMP-0007',
        ];

        foreach ($links as $email => $employeeCode) {
            $employeeId = Employee::query()->where('code', $employeeCode)->value('id');

            if ($employeeId === null) {
                continue;
            }

            DB::table('users')
                ->where('email', $email)
                ->whereNull('employee_id')
                ->update(['employee_id' => $employeeId]);
        }
    }

    private function seedEmployees(): void
    {
        $employees = [
            [
                'code' => 'EMP-0001',
                'name' => 'Budi Santoso',
                'nik_ktp' => '3174051506710001',
                'npwp' => '07.123.456.7-013.000',
                'gender' => 'male',
                'birth_date' => '1971-06-15',
                'ptkp_status' => 'K/3', // TER category C
                'join_date' => '2015-03-02',
                'employment_type' => 'tetap',
                'position' => 'Direktur',
                'department' => 'hrga', // direksi administered under head office / HRGA
                'base_salary' => 45000000,
                'fixed_allowances' => ['jabatan' => 12500000, 'transport' => 3000000],
                'bpjs_kesehatan_no' => '0001234567890',
                'bpjs_tk_no' => '15037654321',
                'bank_name' => 'BCA',
                'bank_account_no' => '5410078812',
                'bank_account_name' => 'Budi Santoso',
            ],
            [
                'code' => 'EMP-0002',
                'name' => 'Rina Wijaya',
                'nik_ktp' => '3173042708860002',
                'npwp' => '08.234.567.8-014.000',
                'gender' => 'female',
                'birth_date' => '1986-08-27',
                'ptkp_status' => 'TK/0', // TER category A
                'join_date' => '2019-01-14',
                'employment_type' => 'tetap',
                'position' => 'Project Manager',
                'department' => 'proyek',
                'base_salary' => 28000000,
                'fixed_allowances' => ['jabatan' => 4000000, 'transport' => 1000000, 'makan' => 660000],
                'bpjs_kesehatan_no' => '0001234567891',
                'bpjs_tk_no' => '19017654322',
                'bank_name' => 'BCA',
                'bank_account_no' => '5410078933',
                'bank_account_name' => 'Rina Wijaya',
            ],
            [
                'code' => 'EMP-0003',
                'name' => 'Agus Prasetyo',
                'nik_ktp' => '3275031103830003',
                'npwp' => '09.345.678.9-015.000',
                'gender' => 'male',
                'birth_date' => '1983-03-11',
                'ptkp_status' => 'K/2', // TER category B
                'join_date' => '2021-06-01',
                'employment_type' => 'tetap',
                'position' => 'Site Manager',
                'department' => 'proyek',
                'base_salary' => 18000000,
                'fixed_allowances' => ['jabatan' => 2500000, 'transport' => 750000, 'makan' => 660000],
                'bpjs_kesehatan_no' => '0001234567892',
                'bpjs_tk_no' => '21067654323',
                'bank_name' => 'Mandiri',
                'bank_account_no' => '1230009845671',
                'bank_account_name' => 'Agus Prasetyo',
            ],
            [
                'code' => 'EMP-0004',
                'name' => 'Dewi Lestari',
                'nik_ktp' => '3171065509880004',
                'npwp' => '10.456.789.0-016.000',
                'gender' => 'female',
                'birth_date' => '1988-09-15',
                'ptkp_status' => 'TK/1', // TER category A
                'join_date' => '2018-04-02',
                'employment_type' => 'tetap',
                'position' => 'Finance Manager',
                'department' => 'keuangan',
                'base_salary' => 22000000,
                'fixed_allowances' => ['jabatan' => 3000000, 'transport' => 750000, 'makan' => 660000],
                'bpjs_kesehatan_no' => '0001234567893',
                'bpjs_tk_no' => '18047654324',
                'bank_name' => 'BCA',
                'bank_account_no' => '5410079021',
                'bank_account_name' => 'Dewi Lestari',
            ],
            [
                'code' => 'EMP-0005',
                'name' => 'Andi Kurniawan',
                'nik_ktp' => '3671021812900005',
                'npwp' => '11.567.890.1-017.000',
                'gender' => 'male',
                'birth_date' => '1990-12-18',
                'ptkp_status' => 'K/1', // TER category B
                'join_date' => '2022-02-07',
                'employment_type' => 'tetap',
                'position' => 'Procurement Officer',
                'department' => 'procurement',
                'base_salary' => 12000000,
                'fixed_allowances' => ['transport' => 600000, 'makan' => 660000],
                'bpjs_kesehatan_no' => '0001234567894',
                'bpjs_tk_no' => '22027654325',
                'bank_name' => 'BNI',
                'bank_account_no' => '0335567812',
                'bank_account_name' => 'Andi Kurniawan',
            ],
            [
                'code' => 'EMP-0006',
                'name' => 'Siti Rahayu',
                'nik_ktp' => '3172054307920006',
                'npwp' => '12.678.901.2-018.000',
                'gender' => 'female',
                'birth_date' => '1992-07-03',
                'ptkp_status' => 'TK/0', // TER category A
                'join_date' => '2020-09-01',
                'employment_type' => 'tetap',
                'position' => 'HR & GA Officer',
                'department' => 'hrga',
                'base_salary' => 11000000,
                'fixed_allowances' => ['transport' => 500000, 'makan' => 660000],
                'bpjs_kesehatan_no' => '0001234567895',
                'bpjs_tk_no' => '20097654326',
                'bank_name' => 'BCA',
                'bank_account_no' => '5410079144',
                'bank_account_name' => 'Siti Rahayu',
            ],
            [
                'code' => 'EMP-0007',
                'name' => 'Joko Susilo',
                'nik_ktp' => '3603120507950007',
                // No NPWP: NIK functions as NPWP (PMK 112/2022), so no 120% surcharge.
                'npwp' => null,
                'gender' => 'male',
                'birth_date' => '1995-07-05',
                'ptkp_status' => 'K/0', // TER category A
                'join_date' => '2024-03-04',
                'employment_type' => 'kontrak',
                'position' => 'Teknisi ELV',
                'department' => 'servis',
                'base_salary' => 7500000,
                'fixed_allowances' => ['transport' => 500000, 'makan' => 660000],
                'bpjs_kesehatan_no' => '0001234567896',
                'bpjs_tk_no' => '24037654327',
                'bank_name' => 'BRI',
                'bank_account_no' => '057601002233504',
                'bank_account_name' => 'Joko Susilo',
            ],
            [
                'code' => 'EMP-0008',
                'name' => 'Made Wirawan',
                'nik_ktp' => '5171012409970008',
                'npwp' => '13.789.012.3-019.000',
                'gender' => 'male',
                'birth_date' => '1997-09-24',
                'ptkp_status' => 'TK/2', // TER category B
                'join_date' => '2025-09-15', // tenure < 1 year at THR 2026 => prorated THR
                'employment_type' => 'kontrak',
                'position' => 'Drafter / Estimator',
                'department' => 'engineering',
                'base_salary' => 10000000,
                'fixed_allowances' => ['transport' => 500000, 'makan' => 660000],
                'bpjs_kesehatan_no' => '0001234567897',
                'bpjs_tk_no' => '25097654328',
                'bank_name' => 'BCA',
                'bank_account_no' => '5410079267',
                'bank_account_name' => 'Made Wirawan',
            ],
        ];

        foreach ($employees as $data) {
            Employee::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                $data + ['status' => 'active', 'resign_date' => null],
            );
        }
    }

    /**
     * June 2026 attendance recaps (22 working days). Overtime concentrated on
     * site/field staff, as is typical.
     */
    private function seedAttendanceRecaps(): void
    {
        $recaps = [
            'EMP-0001' => ['present' => 22, 'sick' => 0, 'leave' => 0, 'alpha' => 0, 'overtime' => 0],
            'EMP-0002' => ['present' => 21, 'sick' => 0, 'leave' => 1, 'alpha' => 0, 'overtime' => 6],
            'EMP-0003' => ['present' => 22, 'sick' => 0, 'leave' => 0, 'alpha' => 0, 'overtime' => 18],
            'EMP-0004' => ['present' => 20, 'sick' => 2, 'leave' => 0, 'alpha' => 0, 'overtime' => 0],
            'EMP-0005' => ['present' => 21, 'sick' => 0, 'leave' => 1, 'alpha' => 0, 'overtime' => 4],
            'EMP-0006' => ['present' => 22, 'sick' => 0, 'leave' => 0, 'alpha' => 0, 'overtime' => 0],
            'EMP-0007' => ['present' => 22, 'sick' => 0, 'leave' => 0, 'alpha' => 0, 'overtime' => 26],
            'EMP-0008' => ['present' => 21, 'sick' => 0, 'leave' => 0, 'alpha' => 1, 'overtime' => 10],
        ];

        foreach ($recaps as $employeeCode => $data) {
            $employee = Employee::query()->where('code', $employeeCode)->first();

            if (! $employee) {
                continue;
            }

            AttendanceRecap::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'period_year' => 2026, 'period_month' => 6],
                [
                    'work_days' => 22,
                    'present_days' => $data['present'],
                    'sick_days' => $data['sick'],
                    'leave_days' => $data['leave'],
                    'alpha_days' => $data['alpha'],
                    'overtime_hours' => $data['overtime'],
                ],
            );
        }
    }

    /**
     * THR Idul Fitri 1447 H, paid March 2026. Calculated through PayrollService
     * so the proration and PPh 21 (TER on combined income) are the real math.
     */
    private function seedThrRun(): void
    {
        $run = PayrollRun::withTrashed()->updateOrCreate(
            ['code' => 'PYR/2026/03/001'],
            [
                'period_year' => 2026,
                'period_month' => 3,
                'run_type' => 'thr',
                'payment_date' => '2026-03-16',
                'status' => 'draft', // reset to draft so re-seeding can recalculate
                'notes' => 'THR Idul Fitri 1447 H — dibayarkan paling lambat H-7 sebelum hari raya.',
            ],
        );

        app(PayrollService::class)->calculate($run->refresh());

        $run->forceFill(['status' => 'approved'])->save();
    }

    /**
     * Regular June 2026 payroll, calculated from the seeded attendance recaps
     * (overtime pay, BPJS and PPh 21 TER computed by PayrollService) and approved.
     */
    private function seedRegularRun(): void
    {
        $run = PayrollRun::withTrashed()->updateOrCreate(
            ['code' => 'PYR/2026/06/002'],
            [
                'period_year' => 2026,
                'period_month' => 6,
                'run_type' => 'regular',
                'payment_date' => '2026-06-25',
                'status' => 'draft', // reset to draft so re-seeding can recalculate
                'notes' => 'Gaji bulan Juni 2026.',
            ],
        );

        app(PayrollService::class)->calculate($run->refresh());

        $run->forceFill(['status' => 'approved'])->save();
    }

    /**
     * A register in three statuses. Planted directly (no LeaveService), so the
     * approved row does NOT feed an August recap — none is seeded for August,
     * and running the maker-checker workflow inside a seeder would need users
     * this module does not own. The June recap's leave/sick columns above stay
     * the hand-typed record the posted June payroll was computed from.
     */
    private function seedLeaveRequests(): void
    {
        $rows = [
            [
                'code' => 'CTI/2026/VIII/0001',
                'employee' => 'EMP-0007', // joined 2024-03-04: > 12 bulan, eligible
                'leave_type' => 'tahunan',
                'start_date' => '2026-08-10', 'end_date' => '2026-08-12', 'day_count' => 3,
                'reason' => 'Pulang kampung — acara keluarga di Yogyakarta.',
                'status' => 'approved',
            ],
            [
                'code' => 'CTI/2026/VIII/0002',
                'employee' => 'EMP-0005',
                'leave_type' => 'sakit',
                'start_date' => '2026-08-04', 'end_date' => '2026-08-05', 'day_count' => 2,
                'reason' => 'Demam — surat dokter menyusul di lampiran.',
                'status' => 'submitted',
            ],
            [
                'code' => 'CTI/2026/VIII/0003',
                'employee' => 'EMP-0008',
                'leave_type' => 'izin',
                'start_date' => '2026-08-21', 'end_date' => '2026-08-21', 'day_count' => 1,
                'reason' => 'Mengurus dokumen kependudukan.',
                'status' => 'draft',
            ],
        ];

        foreach ($rows as $row) {
            $employee = Employee::query()->where('code', $row['employee'])->first();

            if (! $employee) {
                continue;
            }

            LeaveRequest::withTrashed()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'employee_id' => $employee->id,
                    'leave_type' => $row['leave_type'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'day_count' => $row['day_count'],
                    'reason' => $row['reason'],
                    'status' => $row['status'],
                    'deleted_at' => null,
                ],
            );
        }
    }

    /**
     * Two site days on PRJ-2026-001 — enough for the absensi screen to open on
     * data. Keyed on employee+date, the same identity the bulk upsert uses.
     */
    private function seedAttendances(): void
    {
        $projectId = Schema::hasTable('prj_projects')
            ? DB::table('prj_projects')->where('code', 'PRJ-2026-001')->value('id')
            : null;

        $sheet = [
            '2026-08-06' => ['EMP-0003' => 'hadir', 'EMP-0007' => 'hadir'],
            '2026-08-07' => ['EMP-0003' => 'hadir', 'EMP-0007' => 'setengah_hari'],
        ];

        foreach ($sheet as $date => $entries) {
            foreach ($entries as $employeeCode => $status) {
                $employee = Employee::query()->where('code', $employeeCode)->first();

                if (! $employee) {
                    continue;
                }

                $existing = Attendance::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('date', $date)
                    ->first();

                $values = ['status' => $status, 'project_id' => $projectId];

                $existing === null
                    ? Attendance::query()->create($values + ['employee_id' => $employee->id, 'date' => $date])
                    : $existing->fill($values)->save();
            }
        }
    }

    /**
     * Seeded PYR codes use explicit sequence numbers 1-2; move the 2026 counter
     * past them so runtime-generated numbers never collide with the canon.
     * Same treatment for the three canon CTI codes.
     */
    private function syncNumberSequences(): void
    {
        foreach (['PYR' => 2, 'CTI' => 3] as $type => $minimum) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => 2026],
                ['last_number' => 0],
            );

            if ((int) $sequence->last_number < $minimum) {
                $sequence->update(['last_number' => $minimum]);
            }
        }
    }
}
