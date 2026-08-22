{{--
    IZIN KERJA LEMBUR — printed blank, on purpose.

    Same story as izin-kerja: no table in this ERP records an overtime permit,
    so the whole body is ruled and the foot of the sheet says why. The twelve
    worker rows are not a guess at a crew size — the owner's pad has a block of
    them and a lembur sheet is signed PER PERSON, because the hours written on
    it are what payroll is later asked to pay against.

    Nothing is pre-filled in the NAMA column even though hr_employees exists.
    Who worked late on a given night is a fact about that night, and a printed
    list of the payroll would be a claim that those twelve people were there.
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
        .blanko { margin-top: 2.5mm; font-size: 6.8pt; line-height: 1.35; font-style: italic; }
    </style>

    <table class="isian">
        <tr>
            <td class="k">TANGGAL LEMBUR</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:44mm"></span></td>
        </tr>
        <tr>
            <td class="k">JAM LEMBUR</td><td class="s">:</td>
            <td>
                dari <span class="fill-line" style="min-width:20mm"></span>
                sampai <span class="fill-line" style="min-width:20mm"></span>
            </td>
        </tr>
        <tr>
            <td class="k">LOKASI / AREA KERJA</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k">PEKERJAAN YANG DILEMBURKAN</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k">ALASAN LEMBUR</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k"></td><td class="s"></td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
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
            @for ($i = 0; $i < $blankRows['pekerja']; $i++)
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

    <div class="blanko">{{ $blankNotice }}</div>

    <div class="catatan">
        <div class="h">Catatan :</div>
        <div class="rule"></div>
        <div class="rule"></div>
    </div>

    <div class="tempat">
        @if (filled($header['place'])){{ $header['place'] }}, @endif
        @if (filled($header['dateLabel']))
            {{ $header['dateLabel'] }}
        @else
            <span class="fill-line" style="min-width:34mm"></span>
        @endif
    </div>
@endsection
