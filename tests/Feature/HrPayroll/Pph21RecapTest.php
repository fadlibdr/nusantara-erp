<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Models\Payslip;
use Modules\HrPayroll\Services\Pph21RecapService;
use Modules\HrPayroll\Services\Pph21TerService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P-3b T3b.2 — rekap PPh 21/26 bulanan dari SNAPSHOT slip gaji.
 *
 * Tiga hal yang dijaga: (1) angkanya SAMA dengan jumlah slip run yang
 * disetujui/diposting — bukan dihitung ulang dari gaji hari ini; (2) run
 * draf/diajukan/ditolak TIDAK masuk, dan disebut sebagai tidak masuk; (3)
 * pegawai tanpa identitas pajak yang dikenali mendapat SEL KOSONG — bukan
 * 0, bukan garis — dan dihitung di ikhtisar. Berkas CSV-nya diberi label
 * rekap internal, BUKAN berkas impor DJP; format impor e-Bupot 21/26 hidup
 * di registri DjpFormats sebagai "menunggu template".
 */
class Pph21RecapTest extends ErpTestCase
{
    use PayrollFixtures;

    private Pph21RecapService $recap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recap = app(Pph21RecapService::class);
    }

    private function approved(array $runAttributes = []): PayrollRun
    {
        $run = $this->makeRun($runAttributes);
        $this->payrollService()->calculate($run);
        $run->forceFill(['status' => DocumentStatus::Approved])->save();

        return $run->refresh();
    }

    // -------------------------------------------------------------- angka

    /** Jumlah rekap = jumlah slip, sen demi sen — dan per baris pun sama. */
    public function test_the_recap_equals_the_sum_of_the_slips_of_the_approved_run(): void
    {
        // 9.000.000 → TER A 1,75 % = 157.500; 12.000.000 → TER A 4 % = 480.000
        $a = $this->makeEmployee(['code' => 'EMP-0002', 'name' => 'Rina', 'base_salary' => 9_000_000, 'npwp' => '08.234.567.8-014.000']);
        $b = $this->makeEmployee(['code' => 'EMP-0001', 'name' => 'Budi', 'base_salary' => 12_000_000, 'npwp' => '07.123.456.7-013.000']);
        $run = $this->approved();

        $recap = $this->recap->monthly(2026, 6);

        $this->assertMoney(157_500.0 + 480_000.0, $recap['summary']['pph21']);
        $this->assertMoney(21_000_000.0, $recap['summary']['gross']);
        $this->assertMoney((float) Payslip::query()->where('payroll_run_id', $run->id)->sum('pph21_amount'), $recap['summary']['pph21']);
        $this->assertSame(2, $recap['summary']['employees']);
        $this->assertSame(2, $recap['summary']['slips']);
        $this->assertSame(0, $recap['summary']['without_tax_id']);

        // Urut kode pegawai, bukan urut id.
        $this->assertSame(['EMP-0001', 'EMP-0002'], array_column($recap['rows'], 'employee_code'));
        $this->assertMoney(480_000.0, $recap['rows'][0]['pph21']);
        $this->assertSame('A', $recap['rows'][0]['ter_category']);
        $this->assertSame(4.0, $recap['rows'][0]['ter_rate']);
        $this->assertMoney(157_500.0, $recap['rows'][1]['pph21']);
        $this->assertSame(1.75, $recap['rows'][1]['ter_rate']);

        $this->assertSame([$run->code], array_column($recap['runs']['included'], 'code'));
        $this->assertSame([], $recap['runs']['excluded']);
    }

    /** Snapshot, bukan hitung ulang: gaji yang berubah SESUDAH run disetujui tidak menggeser rekap. */
    public function test_the_recap_reads_the_snapshot_not_todays_salary(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9_000_000]);
        $this->approved();

        $employee->forceFill(['base_salary' => 30_000_000])->save();

        $this->assertMoney(157_500.0, $this->recap->monthly(2026, 6)['summary']['pph21']);
    }

    public function test_the_real_approval_path_is_what_the_recap_reads(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);

        $this->assertSame(0, $this->recap->monthly(2026, 6)['summary']['slips'], 'draf tidak masuk');

        $submitter = User::query()->create(['name' => 'HR', 'email' => 'hr@test.local', 'password' => 'password', 'is_active' => true]);
        $approver = User::query()->create(['name' => 'Direktur', 'email' => 'dir@test.local', 'password' => 'password', 'is_active' => true]);
        $run->submit($submitter);

        $this->assertSame(0, $this->recap->monthly(2026, 6)['summary']['slips'], 'diajukan tidak masuk');

        $run->approve($approver);

        $this->assertSame(1, $this->recap->monthly(2026, 6)['summary']['slips']);
    }

    // ------------------------------------------------------- run yang masuk

    public function test_draft_and_rejected_runs_are_excluded_and_named_as_such(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000, 'join_date' => '2020-01-01']);
        $regular = $this->approved();

        // THR bulan yang sama, masih draf: slipnya ada di hr_payslips tetapi TIDAK boleh dihitung.
        $thrDraft = $this->makeRun(['run_type' => PayrollRunType::Thr]);
        $this->payrollService()->calculate($thrDraft);
        $this->assertGreaterThan(0, $thrDraft->payslips()->count());

        $recap = $this->recap->monthly(2026, 6);

        $this->assertMoney(157_500.0, $recap['summary']['pph21']);
        $this->assertSame(1, $recap['summary']['slips']);
        $this->assertSame([$regular->code], array_column($recap['runs']['included'], 'code'));
        $this->assertSame([$thrDraft->code], array_column($recap['runs']['excluded'], 'code'));
        $this->assertSame('draft', $recap['runs']['excluded'][0]['status']);
        $this->assertStringContainsString('tidak masuk rekap', $recap['runs']['excluded'][0]['reason']);

        // Ditolak: sama-sama di luar.
        $thrDraft->forceFill(['status' => DocumentStatus::Rejected])->save();
        $recap = $this->recap->monthly(2026, 6);
        $this->assertSame(1, $recap['summary']['slips']);
        $this->assertSame('rejected', $recap['runs']['excluded'][0]['status']);
    }

    public function test_a_closed_run_counts_like_an_approved_one(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $run = $this->approved();
        $run->forceFill(['status' => DocumentStatus::Closed])->save();

        $this->assertSame(1, $this->recap->monthly(2026, 6)['summary']['slips']);
    }

    public function test_a_soft_deleted_run_is_neither_included_nor_listed(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $run = $this->approved();
        $run->delete();

        $recap = $this->recap->monthly(2026, 6);

        $this->assertSame(0, $recap['summary']['slips']);
        $this->assertSame([], $recap['runs']['included']);
        $this->assertSame([], $recap['runs']['excluded']);
    }

    public function test_other_months_do_not_leak_in(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $this->approved(['period_month' => 5]);

        $this->assertSame(0, $this->recap->monthly(2026, 6)['summary']['slips']);
        $this->assertSame(1, $this->recap->monthly(2026, 5)['summary']['slips']);
    }

    /**
     * THR dan gaji di bulan yang sama: SATU baris per pegawai (itulah yang
     * diisi ke e-Bupot 21/26 per masa), bruto dan PPh dijumlahkan, kedua
     * slipnya disebut — tidak ada slip yang dihitung dua kali dan tidak ada
     * pegawai yang muncul dua kali.
     */
    public function test_thr_and_regular_runs_of_one_month_aggregate_per_employee_without_double_counting(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000, 'join_date' => '2020-01-01']);
        $regular = $this->approved();
        $thr = $this->approved(['run_type' => PayrollRunType::Thr]);

        $regularSlip = Payslip::query()->where('payroll_run_id', $regular->id)->firstOrFail();
        $thrSlip = Payslip::query()->where('payroll_run_id', $thr->id)->firstOrFail();

        $recap = $this->recap->monthly(2026, 6);

        $this->assertSame(1, $recap['summary']['employees']);
        $this->assertSame(2, $recap['summary']['slips']);
        $this->assertCount(1, $recap['rows']);

        $row = $recap['rows'][0];
        $this->assertMoney((float) $regularSlip->gross_income + (float) $thrSlip->gross_income, $row['gross']);
        $this->assertMoney((float) $regularSlip->pph21_amount + (float) $thrSlip->pph21_amount, $row['pph21']);
        $this->assertMoney($row['pph21'], $recap['summary']['pph21']);
        $this->assertSame([$regular->code, $thr->code], array_column($row['slips'], 'run_code'));
        // Kategori/tarif baris = slip gaji reguler; slip THR menyimpan tarif gabungan dan disebut di rinciannya.
        $this->assertSame((float) $regularSlip->ter_rate, $row['ter_rate']);
        $this->assertSame((float) $thrSlip->ter_rate, $row['slips'][1]['ter_rate']);
    }

    // -------------------------------------------------- identitas pajak

    public function test_tax_identity_comes_from_npwp_then_nik_and_an_unrecognised_one_is_an_empty_cell_that_is_counted(): void
    {
        $this->makeEmployee(['code' => 'EMP-0001', 'name' => 'Ber-NPWP', 'base_salary' => 9_000_000, 'npwp' => '07.123.456.7-013.000', 'nik_ktp' => '3174051506710001']);
        $this->makeEmployee(['code' => 'EMP-0002', 'name' => 'Ber-NIK', 'base_salary' => 9_000_000, 'npwp' => null, 'nik_ktp' => '3173042708860002']);
        $this->makeEmployee(['code' => 'EMP-0003', 'name' => 'NPWP-16', 'base_salary' => 9_000_000, 'npwp' => '0071234567013000', 'nik_ktp' => '3275031103830003']);
        // Baris warisan: NPWP "N/A" dan NIK yang bukan 16 digit — tidak lewat pintu tulis, tetapi ada di basis data lama.
        $this->makeEmployee(['code' => 'EMP-0004', 'name' => 'Tanpa Identitas', 'base_salary' => 9_000_000, 'npwp' => 'N/A', 'nik_ktp' => 'BELUM-ADA']);
        $this->approved();

        $recap = $this->recap->monthly(2026, 6);
        $rows = collect($recap['rows'])->keyBy('employee_code');

        $this->assertSame('07.123.456.7-013.000', $rows['EMP-0001']['tax_id']);
        $this->assertSame('npwp15', $rows['EMP-0001']['tax_id_kind']);
        $this->assertSame('NPWP 15 digit (format lama)', $rows['EMP-0001']['tax_id_kind_label']);
        $this->assertSame('npwp', $rows['EMP-0001']['tax_id_source']);

        $this->assertSame('3173042708860002', $rows['EMP-0002']['tax_id']);
        $this->assertSame('nik', $rows['EMP-0002']['tax_id_kind']);
        $this->assertSame('NIK (16 digit)', $rows['EMP-0002']['tax_id_kind_label']);
        $this->assertSame('nik_ktp', $rows['EMP-0002']['tax_id_source']);

        $this->assertSame('npwp16', $rows['EMP-0003']['tax_id_kind']);
        $this->assertSame('NPWP 16 digit / NIK', $rows['EMP-0003']['tax_id_kind_label']);

        $this->assertNull($rows['EMP-0004']['tax_id']);
        $this->assertNull($rows['EMP-0004']['tax_id_kind']);
        $this->assertNull($rows['EMP-0004']['tax_id_kind_label']);
        $this->assertNull($rows['EMP-0004']['tax_id_source']);
        $this->assertStringContainsString('N/A', (string) $rows['EMP-0004']['tax_id_issue']);
        // Barisnya TETAP ADA dengan angkanya — hanya identitasnya yang kosong.
        $this->assertMoney(157_500.0, $rows['EMP-0004']['pph21']);

        $this->assertSame(1, $recap['summary']['without_tax_id']);
        $this->assertSame(4, $recap['summary']['employees']);
    }

    // ---------------------------------------------------------------- CSV

    public function test_the_csv_is_labelled_internal_not_a_djp_import_file_and_its_totals_equal_the_slips(): void
    {
        $this->makeEmployee(['code' => 'EMP-0001', 'name' => 'Budi; Santoso', 'base_salary' => 9_000_000, 'npwp' => '07.123.456.7-013.000']);
        $this->makeEmployee(['code' => 'EMP-0002', 'name' => 'Tanpa Identitas', 'base_salary' => 12_000_000, 'npwp' => 'N/A', 'nik_ktp' => 'BELUM-ADA']);
        $run = $this->approved();

        $recap = $this->recap->monthly(2026, 6);

        $this->assertSame('rekap-internal-pph21-2026-06.csv', $recap['filename']);
        $this->assertSame(Pph21RecapService::LABEL, $recap['label']);
        $this->assertStringContainsString('BUKAN berkas impor DJP', Pph21RecapService::LABEL);

        $lines = explode("\r\n", rtrim($recap['csv'], "\r\n"));

        $this->assertStringStartsWith('# Rekap internal PPh 21/26', $lines[0]);
        $this->assertStringContainsString('BUKAN berkas impor DJP', $lines[0]);
        $this->assertStringContainsString($run->code, $lines[0]);
        $this->assertSame(
            'kode_pegawai;nama;identitas_pajak;jenis_identitas;bruto;kategori_ter;tarif_ter_persen;pph21;jumlah_slip;run',
            $lines[1],
        );
        $this->assertCount(4, $lines, 'komentar + header + dua baris pegawai');

        // Nama ber-titik-koma dikutip; pemisah ';' dan desimal koma (Excel-ID, konvensi csv.js).
        $this->assertSame('EMP-0001;"Budi; Santoso";07.123.456.7-013.000;NPWP 15 digit (format lama);9000000,00;A;1,75;157500,00;1;'.$run->code, $lines[2]);
        // Sel identitas KOSONG — bukan 0, bukan "—".
        $this->assertSame('EMP-0002;Tanpa Identitas;;;12000000,00;A;4,00;480000,00;1;'.$run->code, $lines[3]);

        $sum = 0.0;
        foreach (array_slice($lines, 2) as $line) {
            $sum += (float) str_replace(',', '.', str_getcsv($line, ';')[7]);
        }
        $this->assertMoney((float) Payslip::query()->where('payroll_run_id', $run->id)->sum('pph21_amount'), $sum);
    }

    /** Desember: true-up Pasal 17, kategori dan tarif TER null — sel kosong, bukan 0. */
    public function test_december_rows_carry_no_ter_category_and_the_csv_cells_are_empty(): void
    {
        $this->makeEmployee(['code' => 'EMP-0001', 'base_salary' => 9_000_000, 'npwp' => '07.123.456.7-013.000']);
        $this->approved(['period_month' => 12, 'payment_date' => '2026-12-25']);

        $recap = $this->recap->monthly(2026, 12);

        $this->assertNull($recap['rows'][0]['ter_category']);
        $this->assertNull($recap['rows'][0]['ter_rate']);

        $line = explode("\r\n", $recap['csv'])[2];
        $cells = str_getcsv($line, ';');
        $this->assertSame('', $cells[5], 'kategori TER Desember kosong');
        $this->assertSame('', $cells[6], 'tarif TER Desember kosong');
    }

    // ------------------------------------------------------- registri & TER

    public function test_the_payload_carries_the_registry_entry_and_the_ter_verification_note(): void
    {
        $recap = $this->recap->monthly(2026, 6);

        $this->assertSame('ebupot_2126_bulanan', $recap['format']['key']);
        $this->assertSame('menunggu template', $recap['format']['status']);
        $this->assertFalse($recap['format']['downloadable']);
        $this->assertStringStartsWith('BELUM DIVERIFIKASI terhadap template DJP', $recap['format']['verification']);

        $this->assertSame(Pph21TerService::VERIFICATION_NOTE, $recap['ter_note']);
        $this->assertStringContainsString('PMK 168/2023', Pph21TerService::VERIFICATION_NOTE);
        $this->assertStringContainsString('perlu dicek terhadap peraturan yang berlaku', Pph21TerService::VERIFICATION_NOTE);
    }

    // ------------------------------------------------------------- endpoint

    public function test_the_endpoint_is_read_only_and_gated_on_hr_view(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $this->approved();

        Sanctum::actingAs($this->userWith(['fin.view']));
        $this->getJson('/api/hr/pph21-recap?year=2026&month=6')->assertForbidden();

        Sanctum::actingAs($this->userWith(['hr.view']));
        $this->getJson('/api/hr/pph21-recap?year=2026&month=6')
            ->assertOk()
            ->assertJsonPath('data.summary.slips', 1)
            ->assertJsonPath('data.period.year', 2026)
            ->assertJsonPath('data.period.month', 6)
            ->assertJsonPath('data.label', Pph21RecapService::LABEL);

        $this->getJson('/api/hr/pph21-recap?year=2026&month=13')->assertStatus(422);
    }

    // -------------------------------------------------------------- layar

    public function test_the_screen_is_wired_and_reads_its_sentences_from_the_api(): void
    {
        $app = (string) file_get_contents(public_path('app/js/app.js'));
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $worker = (string) file_get_contents(public_path('app/sw.js'));
        $viewPath = public_path('app/js/views/rekappph21.js');

        $this->assertFileExists($viewPath);
        $view = (string) file_get_contents($viewPath);

        $this->assertStringContainsString('export async function renderRekapPph21(', $view);
        $this->assertMatchesRegularExpression("/import \{[^}]*\brenderRekapPph21\b[^}]*\} from '\.\/views\/rekappph21\.js'/", $app);
        $this->assertStringContainsString("route('rekap-pph21'", $app);
        $this->assertStringContainsString("{ label: 'Rekap PPh 21 Bulanan', route: 'rekap-pph21' }", $schema);
        $this->assertStringContainsString("'js/views/rekappph21.js'", $worker, 'berkas cangkang baru tidak terdaftar di SHELL sw.js');

        // Kalimat kejujuran datang dari API, bukan diketik di layar.
        $this->assertStringContainsString('payload.label', $view);
        $this->assertStringContainsString('payload.format.verification', $view);
        $this->assertStringContainsString('payload.ter_note', $view);
        $this->assertStringContainsString('tanpa identitas pajak', $view);
        // Sel identitas kosong: sel .tax-id dengan data-empty, bukan '—'.
        $this->assertStringContainsString('.tax-id', $view);
        $this->assertStringContainsString("'data-empty'", $view);
        $this->assertStringContainsString("text: row.tax_id ?? ''", $view);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
