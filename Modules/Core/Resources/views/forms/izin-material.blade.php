{{--
    IZIN MASUK / KELUAR MATERIAL & PERALATAN — P0-C: printed FROM
    prj_gate_passes + its item lines.

    ONE direction box is ticked — the recorded direction of the pass. Before
    P0-C both boxes printed empty because the computer never saw the load;
    now the direction is a fact of the document management approved, and the
    guard's periksa stamp attests the load matched it.

    Cells with no backing column keep their rules: JAM, JENIS KENDARAAN (only
    the plate is recorded), PEMOHON, and the SPESIFIKASI column of the table.
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
    </style>

    <div class="arah">
        ARAH BARANG :
        <span class="kotak">@if ($pass->direction?->value === 'in')&#10005;@endif</span>MASUK
        <span class="kotak">@if ($pass->direction?->value === 'out')&#10005;@endif</span>KELUAR
    </div>

    <table class="isian">
        <tr>
            <td class="k">NO. IZIN</td><td class="s">:</td>
            <td>{{ $pass->code }} &nbsp;&nbsp;&mdash;&nbsp; STATUS: {{ $pass->status?->label() }}</td>
        </tr>
        <tr>
            <td class="k">TANGGAL</td><td class="s">:</td>
            <td>
                {{ $date($pass->pass_date) }}
                &nbsp;&nbsp;JAM
                @if ($pass->checked_at)
                    {{ $pass->checked_at->format('H:i') }} (diperiksa)
                @else
                    <span class="fill-line" style="min-width:18mm"></span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="k">JENIS KENDARAAN</td><td class="s">:</td>
            <td>
                <span class="fill-line" style="min-width:46mm"></span>
                &nbsp;&nbsp;NO. POLISI
                @if (filled($pass->vehicle_no))
                    {{ $pass->vehicle_no }}
                @else
                    <span class="fill-line" style="min-width:34mm"></span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="k">NAMA PENGEMUDI</td><td class="s">:</td>
            <td>
                @if (filled($pass->driver_name))
                    {{ $pass->driver_name }}
                @else
                    <span class="fill-line" style="min-width:100mm"></span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="k">ASAL / TUJUAN</td><td class="s">:</td>
            <td>
                @if (filled($counterparty))
                    {{ $counterparty }}
                @else
                    <span class="fill-line" style="min-width:100mm"></span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="k">PEMOHON / PENANGGUNG JAWAB</td><td class="s">:</td>
            <td><span class="fill-line" style="min-width:100mm"></span></td>
        </tr>
        <tr>
            <td class="k">NO. SURAT JALAN / REFERENSI</td><td class="s">:</td>
            <td>
                @if (filled($reference))
                    {{ $reference }}
                @else
                    <span class="fill-line" style="min-width:100mm"></span>
                @endif
            </td>
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
            @foreach ($pass->items as $i => $item)
                <tr>
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    {{-- No spec field; the rule stays for the pen. --}}
                    <td></td>
                    <td class="ctr">{{ rtrim(rtrim(number_format((float) $item->qty, 3, '.', ''), '0'), '.') }}</td>
                    <td class="ctr">{{ $item->unit }}</td>
                    <td>{{ $item->notes }}</td>
                </tr>
            @endforeach
            @for ($i = $pass->items->count(); $i < $blankRows; $i++)
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
