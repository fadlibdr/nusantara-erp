@extends('coredoc::documents.layout')

@section('content')
    <table class="kv">
        <tr><td class="k">Nama</td><td><strong>{{ $payslip->employee?->name }}</strong></td></tr>
        <tr><td class="k">Kode karyawan</td><td>{{ $payslip->employee?->code }}</td></tr>
        {{-- The department goes through Department::labelFor, never straight
             off the column. hr_employees.department is a plain string with no
             cast, so the raw slug printed: "Teknisi Senior — servis" on the one
             document the employee takes home and files, while every screen the
             row was created on says "Servis". An unknown slug still prints as
             itself — see the enum. Same decision the pengajuan cuti form makes,
             so the two sheets cannot spell one department two ways. --}}
        <tr><td class="k">Jabatan</td><td>{{ collect([$payslip->employee?->position, \Modules\HrPayroll\Enums\Department::labelFor($payslip->employee?->department)])->filter()->implode(' — ') }}</td></tr>
        <tr><td class="k">Status PTKP</td><td>{{ $payslip->employee?->ptkp_status }}</td></tr>
    </table>

    <table class="data" style="margin-top:14px">
        <thead><tr><th style="width:60%">Penghasilan</th><th class="right">Jumlah (Rp)</th></tr></thead>
        <tbody>
            <tr><td>Gaji pokok</td><td class="num">{{ $money($payslip->basic_salary) }}</td></tr>
            <tr><td>Tunjangan tetap</td><td class="num">{{ $money($payslip->allowances_total) }}</td></tr>
            @if ((float) $payslip->overtime_pay > 0)
                <tr><td>Lembur ({{ number_format((float) $payslip->overtime_hours, 1, ',', '.') }} jam)</td>
                    <td class="num">{{ $money($payslip->overtime_pay) }}</td></tr>
            @endif
            @if ((float) $payslip->thr_amount > 0)
                <tr><td>THR</td><td class="num">{{ $money($payslip->thr_amount) }}</td></tr>
            @endif
            <tr class="total-row"><td>Penghasilan bruto</td><td class="num">{{ $money($payslip->gross_income) }}</td></tr>
        </tbody>
    </table>

    <table class="data" style="margin-top:10px">
        <thead><tr><th style="width:60%">Potongan</th><th class="right">Jumlah (Rp)</th></tr></thead>
        <tbody>
            <tr><td>BPJS (bagian karyawan)</td><td class="num">{{ $money($payslip->bpjs_employee_total) }}</td></tr>
            <tr><td>PPh 21{{ $payslip->ter_category ? ' — TER '.$payslip->ter_category : '' }}</td>
                <td class="num">{{ $money($payslip->pph21_amount) }}</td></tr>
            <tr class="total-row"><td>Jumlah potongan</td><td class="num">{{ $money($payslip->total_deductions) }}</td></tr>
        </tbody>
    </table>

    <table class="data" style="margin-top:10px">
        <tbody>
            <tr class="total-row"><td style="width:60%">Diterima bersih</td>
                <td class="num">{{ $money($payslip->net_pay) }}</td></tr>
        </tbody>
    </table>

    @if ($terbilang)
        <div class="terbilang">Terbilang: {{ $terbilang }}</div>
    @endif

    <div class="note">
        Iuran BPJS yang ditanggung perusahaan sebesar {{ $money($payslip->bpjs_company_total) }}
        tidak mengurangi penghasilan bersih di atas.
    </div>

    <div class="place-date">{{ $company?->city ? $company->city.', ' : '' }}{{ $date(now()) }}</div>

    <table class="signatures">
        <tr>
            <td>
                Diterima oleh,
                <div class="sig-space"></div>
                <span class="sig-name">{{ $payslip->employee?->name }}</span>
            </td>
            <td></td>
            <td>
                {{ $company?->legal_name ?: $company?->name }}
                <div class="sig-space"></div>
                <span class="sig-name">HRD</span>
            </td>
        </tr>
    </table>
@endsection
