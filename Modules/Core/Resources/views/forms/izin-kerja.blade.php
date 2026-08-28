{{--
    IZIN KERJA LAPANGAN — P0-C: printed FROM the prj_work_permits row.

    One sheet is one permit for one shift. What the database knows is printed:
    the permit's own number and date, the shift and hours, the work asked for,
    the WBS package, the hazard/APD table, the pemohon and (when named) the K3
    officer. What it does not know keeps the pad's ruled blanks — lokasi/area,
    jumlah pekerja, the ALAT table, the two supervisory signatures — because a
    cell is printed from the database or printed as a rule, never guessed.
--}}
@extends('coredoc::forms.layout')

@section('content')
    <style>
        .isian { margin-bottom: 3mm; font-size: 8pt; }
        .isian td { padding: 1.1mm 0; vertical-align: bottom; }
        .isian td.k { width: 46mm; white-space: nowrap; }
        .isian td.s { width: 3mm; }
        /* Ruled rows tall enough to write a line of text into by hand. The
           cell's own grid border is the rule, exactly as on the pad. */
        .grid tr.kosong td { height: 6.6mm; }
        .grid + .grid { margin-top: 2.5mm; }
        .tempat { text-align: right; margin-top: 4mm; font-size: 8pt; }
    </style>

    <table class="isian">
        <tr>
            <td class="k">NO. IZIN</td><td class="s">:</td>
            <td>{{ $permit->code }} &nbsp;&nbsp;&mdash;&nbsp; STATUS: {{ $permit->status?->label() }}</td>
        </tr>
        <tr>
            <td class="k">PEKERJAAN YANG DIMOHONKAN</td><td class="s">:</td>
            <td>{{ $permit->work_description }}</td>
        </tr>
        <tr>
            <td class="k">PAKET PEKERJAAN (WBS)</td><td class="s">:</td>
            <td>
                @if ($permit->wbsTask)
                    {{ trim(($permit->wbsTask->wbs_code ?? '').' '.$permit->wbsTask->name) }}
                @else
                    <span class="fill-line" style="min-width:80mm"></span>
                @endif
            </td>
        </tr>
        <tr>
            {{-- No column holds the location; the rule stays for the pen. --}}
            <td class="k">LOKASI / AREA KERJA</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k">TANGGAL PELAKSANAAN</td><td class="s">:</td>
            <td>{{ $date($permit->permit_date) }} &nbsp;&nbsp;JAM {{ $jam }} &nbsp;&nbsp;SHIFT {{ $permit->shift?->label() }}</td>
        </tr>
        <tr>
            <td class="k">JUMLAH PEKERJA</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:22mm"></span> orang</td>
        </tr>
        <tr>
            <td class="k">PELAKSANA / MANDOR</td><td class="s">:</td>
            <td>
                @if ($permit->requestedBy)
                    {{ $permit->requestedBy->name }}
                @else
                    <span class="fill-line" style="min-width:80mm"></span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="k">SUBKONTRAKTOR (bila ada)</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:80mm"></span></td>
        </tr>
    </table>

    {{-- No equipment lines exist on an IKL (the spec's schema carries none),
         so the pad's five ruled rows stay exactly what they always were. --}}
    <table class="grid" id="alat">
        <thead>
            <tr><th colspan="4">ALAT YANG DIPAKAI</th></tr>
            <tr>
                <th class="ctr" style="width: 9mm">NO</th>
                <th>JENIS ALAT</th>
                <th class="ctr" style="width: 24mm">JUMLAH</th>
                <th style="width: 68mm">KONDISI / KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < 5; $i++)
                <tr class="kosong">
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <table class="grid" id="bahaya">
        <thead>
            <tr><th colspan="4">POTENSI BAHAYA DAN ALAT PELINDUNG DIRI (APD)</th></tr>
            <tr>
                <th class="ctr" style="width: 9mm">NO</th>
                <th>POTENSI BAHAYA</th>
                <th style="width: 58mm">PENGENDALIAN</th>
                <th style="width: 50mm">APD WAJIB DIPAKAI</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($hazards as $i => $row)
                <tr>
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td>{{ $row['bahaya'] }}</td>
                    {{-- PENGENDALIAN has no backing field: rule on every row. --}}
                    <td></td>
                    <td>{{ $row['apd'] }}</td>
                </tr>
            @endforeach
            @for ($i = count($hazards); $i < 6; $i++)
                <tr class="kosong">
                    <td class="ctr">{{ $i + 1 }}</td>
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
