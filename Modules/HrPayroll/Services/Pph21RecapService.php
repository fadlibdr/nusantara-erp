<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Npwp;
use Modules\Finance\Support\DjpFormats;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Models\Payslip;
use Modules\HrPayroll\Services\Pph21TerService as Ter;

/**
 * Rekap PPh 21/26 bulanan dari SNAPSHOT slip gaji (P-3b, T3b.2).
 *
 * APA INI DAN BUKAN APA. Satu baris per pegawai per masa — bruto, kategori
 * TER, tarif, PPh 21 — persis yang petugas pajak salin ke e-Bupot 21/26.
 * Ini REKAP INTERNAL: berkas CSV-nya diberi label demikian di baris
 * pertamanya, dan format impor e-Bupot 21/26 yang sebenarnya hidup di
 * registri DjpFormats sebagai "menunggu template" — tata letaknya tidak
 * dikarang di sini.
 *
 * ANGKANYA DARI SNAPSHOT, BUKAN HITUNG ULANG. hr_payslips menyimpan
 * ter_category, ter_rate dan pph21_amount saat run dihitung; rekap membaca
 * kolom itu apa adanya, sehingga gaji yang berubah sesudah run disetujui
 * tidak menggeser rekap masa yang sudah dibayar dan dibukukan. Ujinya
 * memaku kesetaraan dengan SUM(hr_payslips.pph21_amount).
 *
 * RUN MANA YANG MASUK. Hanya run berstatus approved atau closed — status
 * yang sama yang dipakai PayrollService::decemberTax dan
 * TaxEqualizationService untuk "sudah menjadi fakta". Draf, diajukan, dan
 * ditolak TIDAK masuk, tetapi DISEBUT di `runs.excluded` beserta statusnya,
 * supaya rekap yang kosong terbaca "run Juli masih draf", bukan "tidak ada
 * gaji Juli". Run yang dihapus lunak tidak ada di kedua daftar. Tidak ada
 * "run koreksi" di modul ini: indeks unik (tahun, bulan, jenis) menjamin
 * paling banyak satu run gaji dan satu run THR per masa, dan run yang
 * disetujui tidak bisa dihitung ulang — jadi satu slip tidak pernah
 * terjumlah dua kali.
 *
 * THR DAN GAJI DI BULAN YANG SAMA dijumlahkan ke SATU baris pegawai (itu
 * yang diisi ke e-Bupot per masa); kategori/tarif baris diambil dari slip
 * gaji reguler, dan setiap slip disebut di `slips` beserta tarifnya sendiri
 * — slip THR menyimpan tarif TER penghasilan GABUNGAN (PayrollService::
 * buildThrPayslip), jadi tarif baris × bruto baris memang tidak selalu sama
 * dengan PPh baris, dan rinciannya yang menjelaskan mengapa.
 *
 * IDENTITAS PAJAK dibaca dari master pegawai HARI INI (slip tidak
 * menyimpan NPWP): npwp bila dikenali (15/16/22 digit), bila tidak NIK bila
 * 16 digit, bila tidak pun — SEL KOSONG, bukan 0, bukan garis (pelajaran
 * Fase 1), dan pegawainya dihitung di `summary.without_tax_id`. Nilai lama
 * yang tidak dikenali tidak ditolak: ia disebut di `tax_id_issue`.
 */
class Pph21RecapService
{
    public const LABEL = 'Rekap internal PPh 21/26 bulanan — untuk diisi ke e-Bupot 21/26 oleh petugas pajak. '
        .'BUKAN berkas impor DJP.';

    public const KIND_NIK = 'nik';

    /** Kolom CSV — pemisah ';' dan desimal koma (Excel-ID, konvensi csv.js), CRLF. */
    private const CSV_COLUMNS = [
        'kode_pegawai', 'nama', 'identitas_pajak', 'jenis_identitas', 'bruto',
        'kategori_ter', 'tarif_ter_persen', 'pph21', 'jumlah_slip', 'run',
    ];

    /**
     * @return array<string, mixed>
     */
    public function monthly(int $year, int $month): array
    {
        $this->assertPeriod($year, $month);

        $period = Carbon::create($year, $month, 1);

        $runs = PayrollRun::query()
            ->withCount('payslips')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->orderBy('id')
            ->get();

        [$included, $excluded] = $runs->partition(
            fn (PayrollRun $run): bool => in_array($run->status, [DocumentStatus::Approved, DocumentStatus::Closed], true),
        );

        $slips = Payslip::query()
            ->with(['employee' => fn ($query) => $query->withTrashed(), 'payrollRun'])
            ->whereIn('payroll_run_id', $included->modelKeys())
            ->orderBy('id')
            ->get();

        $rows = $slips
            ->groupBy('employee_id')
            ->map(fn (Collection $group): array => $this->row($group))
            ->sortBy('employee_code', SORT_NATURAL)
            ->values()
            ->all();

        $summary = [
            'employees' => count($rows),
            'slips' => $slips->count(),
            'gross' => round((float) array_sum(array_column($rows, 'gross')), 2),
            'pph21' => round((float) array_sum(array_column($rows, 'pph21')), 2),
            'without_tax_id' => count(array_filter($rows, fn (array $row): bool => $row['tax_id'] === null)),
            // V2-7/V3b-3: dari baris tanpa identitas yang dikenali, berapa yang slipnya
            // dihitung payroll dengan tarif NORMAL (kolom NPWP/NIK terisi sesuatu yang
            // tidak dikenali — Employee::hasTaxId menganggapnya ber-identitas).
            'without_tax_id_normal_rate' => count(array_filter(
                $rows,
                fn (array $row): bool => $row['tax_id'] === null && ($row['tax_id_treated_as_identified'] ?? false),
            )),
            'runs_included' => $included->count(),
            'runs_excluded' => $excluded->count(),
        ];

        $runsMeta = [
            'included' => $included->map(fn (PayrollRun $run): array => $this->runMeta($run, null))->values()->all(),
            'excluded' => $excluded->map(fn (PayrollRun $run): array => $this->runMeta(
                $run,
                sprintf('Status %s — tidak masuk rekap; hanya run yang disetujui/diposting yang dihitung.', $run->status->label()),
            ))->values()->all(),
        ];

        return [
            'kind' => 'rekap-pph21-internal',
            'label' => self::LABEL,
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $period->translatedFormat('F Y'),
            ],
            'columns' => ['employee', 'tax_id', 'tax_id_kind_label', 'gross', 'ter_category', 'ter_rate', 'pph21'],
            'rows' => $rows,
            'runs' => $runsMeta,
            'summary' => $summary,
            // Registri T3b.0: format impor e-Bupot 21/26 menunggu template resmi.
            'format' => DjpFormats::get(DjpFormats::EBUPOT_2126_BULANAN),
            // T3b.3: tabel TER ditandai perlu dicek — kalimatnya dari tempat tabel itu hidup.
            'ter_note' => Ter::VERIFICATION_NOTE,
            'filename' => sprintf('rekap-internal-pph21-%04d-%02d.csv', $year, $month),
            'csv' => $this->csv($period, $rows, $runsMeta['included']),
        ];
    }

    /**
     * @param  Collection<int, Payslip>  $group  semua slip satu pegawai di masa ini
     * @return array<string, mixed>
     */
    private function row(Collection $group): array
    {
        /** @var Payslip $first */
        $first = $group->first();
        $employee = $first->employee;

        // Kategori/tarif baris dari slip gaji reguler; THR-saja memakai slip THR.
        $lead = $group->first(fn (Payslip $slip): bool => $slip->payrollRun?->run_type === PayrollRunType::Regular) ?? $first;

        $identity = $employee !== null
            ? $this->identity($employee)
            : ['tax_id' => null, 'tax_id_kind' => null, 'tax_id_kind_label' => null, 'tax_id_source' => null, 'tax_id_issue' => 'Data pegawai tidak ditemukan.', 'tax_id_treated_as_identified' => null, 'tax_id_treatment' => null];

        return array_merge([
            'employee_id' => (int) $first->employee_id,
            'employee_code' => $employee?->code,
            'employee_name' => $employee?->name,
        ], $identity, [
            'gross' => round((float) $group->sum(fn (Payslip $slip): float => (float) $slip->gross_income), 2),
            'ter_category' => $lead->ter_category,
            'ter_rate' => $lead->ter_rate === null ? null : (float) $lead->ter_rate,
            'pph21' => round((float) $group->sum(fn (Payslip $slip): float => (float) $slip->pph21_amount), 2),
            'slips' => $group->map(fn (Payslip $slip): array => [
                'payslip_id' => (int) $slip->id,
                'run_code' => $slip->payrollRun?->code,
                'run_type' => $slip->payrollRun?->run_type?->value,
                'run_type_label' => $slip->payrollRun?->run_type?->label(),
                'gross' => round((float) $slip->gross_income, 2),
                'ter_category' => $slip->ter_category,
                'ter_rate' => $slip->ter_rate === null ? null : (float) $slip->ter_rate,
                'pph21' => round((float) $slip->pph21_amount, 2),
            ])->values()->all(),
        ]);
    }

    /**
     * NPWP bila dikenali, lalu NIK bila 16 digit, lalu kosong — dengan
     * kalimat yang menyebut apa yang tersimpan dan tidak dikenali.
     *
     * Dua permukaan satu fakta (V2-7/V3b-3): sel identitas rekap KOSONG tidak
     * berarti payroll memotong dengan tambahan 20 %. Payroll memakai
     * Employee::hasTaxId() — kolom NPWP ATAU NIK terisi APA PUN — jadi baris
     * warisan ber-NIK "BELUM-ADA" dihitung dengan tarif normal. Rekap tidak
     * menghitung ulang; ia MENYEBUT perlakuan itu (`tax_id_treatment`) di
     * samping sel kosongnya, menurut data pegawai saat ini. Mengganti definisi
     * identitas payroll mengubah pemotongan = keputusan pemilik (laporan §9-I).
     *
     * @return array{tax_id: ?string, tax_id_kind: ?string, tax_id_kind_label: ?string, tax_id_source: ?string, tax_id_issue: ?string, tax_id_treated_as_identified: ?bool, tax_id_treatment: ?string}
     */
    private function identity(Employee $employee): array
    {
        $npwp = Npwp::describe($employee->npwp);

        if ($npwp['valid']) {
            return [
                'tax_id' => $npwp['display'],
                'tax_id_kind' => $npwp['kind'],
                'tax_id_kind_label' => $npwp['kind_label'],
                'tax_id_source' => 'npwp',
                'tax_id_issue' => null,
                'tax_id_treated_as_identified' => true,
                'tax_id_treatment' => null,
            ];
        }

        $npwpIssue = $npwp['raw'] === null
            ? null
            : sprintf('NPWP tersimpan "%s" tidak dikenali (bukan 15/16/22 digit)', $npwp['raw']);

        $nik = Npwp::normalize($employee->nik_ktp);

        if (preg_match('/^\d{16}$/', $nik) === 1) {
            return [
                'tax_id' => $nik,
                'tax_id_kind' => self::KIND_NIK,
                'tax_id_kind_label' => 'NIK (16 digit)',
                'tax_id_source' => 'nik_ktp',
                'tax_id_issue' => $npwpIssue === null ? null : $npwpIssue.'; dipakai NIK.',
                'tax_id_treated_as_identified' => true,
                'tax_id_treatment' => null,
            ];
        }

        $nikIssue = trim((string) $employee->nik_ktp) === ''
            ? 'NIK kosong'
            : sprintf('NIK tersimpan "%s" bukan 16 digit', $employee->nik_ktp);

        $treatedAsIdentified = $employee->hasTaxId();
        $surcharge = (int) round((Ter::NON_TAX_ID_SURCHARGE - 1) * 100);
        $treatment = $treatedAsIdentified
            ? sprintf(
                'PPh 21 slip dihitung dengan tarif NORMAL, tanpa tambahan %d %% — payroll menganggap identitas terisi karena kolom NPWP/NIK tidak kosong (menurut data pegawai saat ini).',
                $surcharge,
            )
            : sprintf(
                'PPh 21 slip dihitung DENGAN tambahan %d %% (tanpa NPWP/NIK — menurut data pegawai saat ini).',
                $surcharge,
            );

        return [
            'tax_id' => null,
            'tax_id_kind' => null,
            'tax_id_kind_label' => null,
            'tax_id_source' => null,
            'tax_id_issue' => implode('; ', array_filter([$npwpIssue ?? 'NPWP kosong', $nikIssue]))
                .' — lengkapi di data pegawai; baris ini tetap dihitung. '.$treatment,
            'tax_id_treated_as_identified' => $treatedAsIdentified,
            'tax_id_treatment' => $treatment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runMeta(PayrollRun $run, ?string $reason): array
    {
        return [
            'id' => (int) $run->id,
            'code' => $run->code,
            'run_type' => $run->run_type?->value,
            'run_type_label' => $run->run_type?->label(),
            'status' => $run->status?->value,
            'status_label' => $run->status?->label(),
            'payslips' => (int) ($run->payslips_count ?? 0),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $included
     */
    private function csv(Carbon $period, array $rows, array $included): string
    {
        $runCodes = array_column($included, 'code');

        $comment = sprintf(
            '# %s Masa %s. Dibentuk dari slip gaji run yang disetujui/diposting: %s. Format impor e-Bupot 21/26 menunggu template resmi (%s).',
            self::LABEL,
            $period->translatedFormat('F Y'),
            $runCodes === [] ? '(tidak ada run yang disetujui pada masa ini)' : implode(', ', $runCodes),
            DjpFormats::README,
        );

        $lines = [
            $this->csvLine([$comment]),
            $this->csvLine(self::CSV_COLUMNS),
        ];

        foreach ($rows as $row) {
            $lines[] = $this->csvLine([
                $row['employee_code'],
                $row['employee_name'],
                $row['tax_id'],                 // kosong bila tidak dikenali — bukan 0, bukan garis
                $row['tax_id_kind_label'],
                $this->csvNumber($row['gross']),
                $row['ter_category'],           // kosong pada Desember (Pasal 17)
                $row['ter_rate'] === null ? null : $this->csvNumber($row['ter_rate']),
                $this->csvNumber($row['pph21']),
                (string) count($row['slips']),
                implode(' + ', array_column($row['slips'], 'run_code')),
            ]);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function csvLine(array $cells): string
    {
        return implode(';', array_map(function (mixed $cell): string {
            $text = $cell === null ? '' : (string) $cell;

            return preg_match('/[";\r\n]/', $text) === 1 ? '"'.str_replace('"', '""', $text).'"' : $text;
        }, $cells));
    }

    /** Desimal koma, tanpa pemisah ribuan — Excel-ID membaca ',' sebagai desimal (csv.js). */
    private function csvNumber(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    private function assertPeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new LogicException('Masa pajak harus 1-12.');
        }

        if ($year < 2000 || $year > 2100) {
            throw new LogicException('Tahun pajak di luar rentang yang wajar.');
        }
    }
}
