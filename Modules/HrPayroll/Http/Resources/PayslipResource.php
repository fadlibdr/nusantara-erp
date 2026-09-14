<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayslipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'payroll_run' => PayrollRunResource::make($this->whenLoaded('payrollRun')),
            'employee_id' => $this->employee_id,
            'employee' => EmployeeResource::make($this->whenLoaded('employee')),
            'basic_salary' => $this->basic_salary,
            'allowances' => $this->allowances,
            'allowances_total' => $this->allowances_total,
            'overtime_hours' => $this->overtime_hours,
            'overtime_pay' => $this->overtime_pay,
            /*
             * F-5 — dengan dasar apa lembur slip ini dibayar. Dikirim bersama
             * LABEL-nya, seperti setiap enum lain di rumah ini: 'rata_jam_pertama'
             * di layar orang yang sedang memeriksa gaji seseorang adalah kebocoran
             * istilah basis data. null = slip dihitung sebelum 14 Sep 2026 dan
             * dasarnya memang tidak pernah dicatat — keadaan yang berbeda dari
             * 'tanpa_lembur', dan layar mengatakannya berbeda.
             */
            'overtime_basis' => $this->overtime_basis?->value,
            'overtime_basis_label' => $this->overtime_basis?->label(),
            'overtime_rate_detail' => $this->rateDetailFor($request),
            'thr_amount' => $this->thr_amount,
            'gross_income' => $this->gross_income,
            'bpjs' => $this->bpjs,
            'bpjs_employee_total' => $this->bpjs_employee_total,
            'bpjs_company_total' => $this->bpjs_company_total,
            'ter_category' => $this->ter_category,
            'ter_rate' => $this->ter_rate,
            'pph21_amount' => $this->pph21_amount,
            'total_deductions' => $this->total_deductions,
            'net_pay' => $this->net_pay,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * RINCIAN TARIF, TANPA KALENDER LEMBUR HARIAN ORANG LAIN.
     *
     * `overtime_rate_detail.days` adalah daftar TANGGAL dan JAM LEMBUR harian
     * seseorang — data turunan absensi yang persis sama dengan yang
     * `TimesheetController::show()` jaga dengan 404 yang sengaja tidak bisa
     * dibedakan dari "id tidak ada", dengan alasan yang ditulis panjang di
     * kepala kelasnya. Sumber daya ini dilayani antara lain oleh
     * `GET hr/employees/{employee}/payslips`, sebuah rute yang TIDAK bergerbang
     * izin (keadaan pra-F-5; LAPORAN-DEVIASI menghitung 218 dari 862 rute
     * seperti itu) — jadi tanpa penyaringan di sini, F-5 menaruh muatan baru
     * ke dalam pintu yang terbuka, dan data yang satu pintu tolak keluar bebas
     * lewat pintu di sebelahnya.
     *
     * Yang disaring hanya `days` — bagian yang paket ini TAMBAHKAN. Sisa
     * rinciannya (dasar, tarif, jumlah jam per tarif, kalimat sebabnya) adalah
     * keterangan tentang angka yang sudah ada di baris yang sama sejak P0.
     * Menutup rute itu sendiri adalah pekerjaan gerbang izin, bukan pekerjaan
     * paket ini.
     *
     * Pola bersyaratnya sama dengan yang sudah dipakai rumah ini untuk NPWP di
     * `EmployeeResource`.
     *
     * @return array<string, mixed>|null
     */
    private function rateDetailFor(Request $request): ?array
    {
        $detail = $this->overtime_rate_detail;

        if (! is_array($detail) || ! array_key_exists('days', $detail)) {
            return $detail;
        }

        $user = $request->user();
        $isMine = $user?->employee_id !== null && (int) $this->employee_id === (int) $user->employee_id;

        if ($isMine || (bool) $user?->can('hr.view')) {
            return $detail;
        }

        // KUNCINYA DIBUANG, bukan dikosongkan: sebuah `days: []` akan terbaca
        // sebagai "orang ini tidak berlembur satu hari pun", yang tidak benar
        // dan tidak bisa dibedakan dari yang benar.
        unset($detail['days']);

        return $detail;
    }
}
