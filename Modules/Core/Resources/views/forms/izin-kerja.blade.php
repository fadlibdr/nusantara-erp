{{--
    IZIN KERJA LAPANGAN — printed blank, on purpose.

    Nothing in this ERP records a work permit: no table, no column, not a
    partial one. So every cell below the letterhead is a rule for the hand that
    fills it, and the sheet says so at the foot rather than leaving the reader
    to work it out. What the computer contributes — and the only reason this
    form is worth printing at all — is the four-party band and the contract
    identity block that the site office currently copies out by hand onto a
    photocopied blank: no. SPK, tanggal SPK, waktu pelaksanaan, nama pemilik,
    nama konsultan MK.

    The sheet is undated unless ?tanggal= says otherwise (see
    FormPrintService::izinDocument): a pad printed on Monday is worked through
    all month, and a permit filled in on day 71 must not carry "HARI KE 52".

    No name is printed in any signature column, including the one the ERP could
    supply. A pemohon, a pengawas and a petugas K3 are whoever is on that shift;
    prj_projects.site_manager_id is none of the three.
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
        .blanko { margin-top: 2.5mm; font-size: 6.8pt; line-height: 1.35; font-style: italic; }
    </style>

    <table class="isian">
        <tr>
            <td class="k">PEKERJAAN YANG DIMOHONKAN</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k">LOKASI / AREA KERJA</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:110mm"></span></td>
        </tr>
        <tr>
            <td class="k">TANGGAL PELAKSANAAN</td><td class="s">:</td>
            <td>
                <span class="fill-line" style="min-width:40mm"></span>
                &nbsp;&nbsp;JAM <span class="fill-line" style="min-width:16mm"></span>
                s/d <span class="fill-line" style="min-width:16mm"></span>
            </td>
        </tr>
        <tr>
            <td class="k">JUMLAH PEKERJA</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:22mm"></span> orang</td>
        </tr>
        <tr>
            <td class="k">PELAKSANA / MANDOR</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:80mm"></span></td>
        </tr>
        <tr>
            <td class="k">SUBKONTRAKTOR (bila ada)</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:80mm"></span></td>
        </tr>
    </table>

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
            @for ($i = 0; $i < $blankRows['alat']; $i++)
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
            @for ($i = 0; $i < $blankRows['bahaya']; $i++)
                <tr class="kosong">
                    <td class="ctr">{{ $i + 1 }}</td>
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
