{{--
    IZIN MASUK / KELUAR MATERIAL & PERALATAN — the gate pass, printed blank.

    Nothing in this ERP records a gate movement. Inventory knows a goods receipt
    against a surat jalan and an issue against a project, but neither is this:
    the guard on the gate stops a truck, and what he writes on this sheet is the
    only record that a genset left the site at all. So the whole body is ruled,
    and the foot of the sheet says why in plain Indonesian.

    BOTH DIRECTION BOXES PRINT EMPTY. Ticking one would be the computer deciding
    which way a load it never saw was going, and this is exactly the form on
    which that question is the whole point.
--}}
@extends('coredoc::forms.layout')

@section('content')
    <style>
        .arah { border: .7pt solid #000; padding: 1.6mm; margin-bottom: 3mm; font-size: 9pt; font-weight: bold; text-align: center; letter-spacing: .04em; }
        .isian { margin-bottom: 3mm; font-size: 8pt; }
        .isian td { padding: 1.1mm 0; vertical-align: bottom; }
        .isian td.k { width: 46mm; white-space: nowrap; }
        .isian td.s { width: 3mm; }
        .grid tr.kosong td { height: 7mm; }
        .tempat { text-align: right; margin-top: 4mm; font-size: 8pt; }
        .blanko { margin-top: 2.5mm; font-size: 6.8pt; line-height: 1.35; font-style: italic; }
    </style>

    <div class="arah">
        ARAH BARANG :
        <span class="kotak"></span>MASUK
        <span class="kotak"></span>KELUAR
    </div>

    <table class="isian">
        <tr>
            <td class="k">TANGGAL</td><td class="s">:</td>
            <td>
                <span class="fill-line" style="min-width:40mm"></span>
                &nbsp;&nbsp;JAM <span class="fill-line" style="min-width:18mm"></span>
            </td>
        </tr>
        <tr>
            <td class="k">JENIS KENDARAAN</td><td class="s">:</td>
            <td>
                <span class="fill-line" style="min-width:46mm"></span>
                &nbsp;&nbsp;NO. POLISI <span class="fill-line" style="min-width:34mm"></span>
            </td>
        </tr>
        <tr>
            <td class="k">NAMA PENGEMUDI</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:100mm"></span></td>
        </tr>
        <tr>
            <td class="k">ASAL / TUJUAN</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:100mm"></span></td>
        </tr>
        <tr>
            <td class="k">PEMOHON / PENANGGUNG JAWAB</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:100mm"></span></td>
        </tr>
        <tr>
            <td class="k">NO. SURAT JALAN / REFERENSI</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:100mm"></span></td>
        </tr>
    </table>

    <table class="grid" id="barang">
        <thead>
            <tr><th colspan="6">RINCIAN MATERIAL / PERALATAN</th></tr>
            <tr>
                <th class="ctr" style="width: 9mm">NO</th>
                <th>JENIS BARANG</th>
                <th style="width: 50mm">SPESIFIKASI</th>
                <th class="ctr" style="width: 20mm">JUMLAH</th>
                <th class="ctr" style="width: 18mm">SATUAN</th>
                <th style="width: 44mm">KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < $blankRows['barang']; $i++)
                <tr class="kosong">
                    <td class="ctr">{{ $i + 1 }}</td>
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
