{{--
    IZIN KERJA LEMBUR — P0-C: printed FROM prj_overtime_permits + its worker
    rows. One printed line per worker: employee lines carry the name and
    position payroll knows; worker_name lines print exactly as typed, because
    the mandor's non-employee crew is real on paper even though it has no
    hr_attendance_recaps row for the approval feed to land on. The per-row JAM
    MULAI/SELESAI columns stay ruled — the header's JAM LEMBUR window is the
    permit's; per-person clock times are written at the gate.
--}}
@extends('coredoc::forms.layout')

@section('content')
    <style>
        .isian { margin-bottom: 3mm; font-size: 8pt; }
        .isian td { padding: 1.1mm 0; vertical-align: bottom; }
        .isian td.k { width: 40mm; white-space: nowrap; }
        .isian td.s { width: 3mm; }
        .grid tr.kosong td { height: 7mm; }
        .tempat { text-align: right; margin-top: 4mm; font-size: 8pt; }
    </style>

    <table class="isian">
        <tr>
            <td class="k">NO. IZIN</td><td class="s">:</td>
            <td>{{ $permit->code }} &nbsp;&nbsp;&mdash;&nbsp; STATUS: {{ $permit->status?->label() }}</td>
        </tr>
        <tr>
            <td class="k">TANGGAL LEMBUR</td><td class="s">:</td>
            <td>{{ $date($permit->overtime_date) }}</td>
        </tr>
        <tr>
            <td class="k">JAM LEMBUR</td><td class="s">:</td>
            <td>{{ $jamLembur }}</td>
        </tr>
        <tr>
            {{-- No column holds either; the rules stay for the pen. --}}
            <td class="k">LOKASI / AREA KERJA</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k">PEKERJAAN YANG DILEMBURKAN</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k">ALASAN LEMBUR</td><td class="s">:</td>
            <td>{{ $permit->reason }}</td>
        </tr>
    </table>

    <table class="grid" id="pekerja">
        <thead>
            <tr><th colspan="7">DAFTAR PEKERJA LEMBUR</th></tr>
            <tr>
                <th class="ctr" style="width: 9mm">NO</th>
                <th>NAMA</th>
                <th style="width: 38mm">JABATAN</th>
                <th class="ctr" style="width: 19mm">JAM MULAI</th>
                <th class="ctr" style="width: 19mm">JAM SELESAI</th>
                <th class="ctr" style="width: 18mm">TOTAL JAM</th>
                <th class="ctr" style="width: 32mm">TANDA TANGAN</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($workers as $i => $worker)
                <tr>
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td>{{ $worker['name'] }}</td>
                    <td>{{ $worker['position'] }}</td>
                    <td></td>
                    <td></td>
                    <td class="ctr">{{ $worker['hours'] }}</td>
                    <td></td>
                </tr>
            @endforeach
            @for ($i = count($workers); $i < $blankRows; $i++)
                <tr class="kosong">
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <div class="catatan">
        <div class="h">Catatan :</div>
        <div class="rule"></div>
        <div class="rule"></div>
    </div>

    <div class="tempat">
        @if (filled($header['place'])){{ $header['place'] }}, @endif
        {{ $header['dateLabel'] }}
    </div>
@endsection
