{{--
    LAPORAN HARIAN — the sheet the site files every working day.

    Read this next to Modules\Projects\Services\LaporanFormService, which is
    where every value below comes from and, more to the point, where the nulls
    come from. Since P0-A each pad table has its own line table, and each fills
    ONLY from it:

      TENAGA KERJA        prj_daily_report_manpower, one row per jabatan. A
                          role without a row keeps its ruled blank; TOTAL is
                          manpower_count, as ever.
      MATERIAL MASUK      prj_daily_report_receipts — diterima AND ditolak are
                          arrival facts recorded on the report itself now.
      MATERIAL DIPAKAI    prj_daily_report_materials, printed under its own
                          heading — it is consumption, and putting it under the
                          receipt table's heading would be the same lie as
                          inventing the figure.
      ALAT-ALAT           prj_daily_report_equipment.
      URAIAN dst.         prj_daily_report_activities per line (sort_order);
                          a report without lines prints its summary activities
                          and obstacles text exactly as before.

    Ruled blanks pad every table to its pre-P0-A geometry, and a report with
    no line rows at all — every report from before the tables existed —
    renders BYTE-identically to the old sheet. The footnote at the bottom
    names whichever tables are still manual for this report.

    NOTE ON WHITESPACE: the flush-left Blade directives below are deliberate.
    A directive's leading indentation is emitted as literal output, and the
    compat contract above is byte-level — LaporanHarianPenuhTest holds the
    render against a golden fixture captured before P0-A. Indenting these
    directives prettily would shift those bytes.
--}}
@extends('coredoc::forms.layout')

@section('content')
    <style>
        /* Two body columns side by side. A plain layout table, so the .grid
           borders belong to the real tables inside it and not to the scaffold. */
        .kolom { table-layout: fixed; margin-bottom: 1.5mm; }
        .kolom > tbody > tr > td { vertical-align: top; padding: 0; border: 0; }
        .kolom > tbody > tr > td.kiri { width: 40%; padding-right: 2.5mm; }
        /* A hand-filled cell narrow enough that its own grid border is already
           the writing rule: a headcount, a quantity, a percentage. The layout's
           .fill is used instead wherever the site writes WORDS — there a dotted
           guide earns its 7mm, and here thirteen of them would make the manpower
           table taller than the whole rest of the sheet. */
        .grid td.kosong { height: 6.2mm; }
        .catatan-kaki { margin-top: 2mm; font-size: 6.8pt; line-height: 1.3; }
        .catatan-kaki ol { margin: .6mm 0 0; padding-left: 4.5mm; }
    </style>

    <table class="kolom">
        <tr>
            <td class="kiri">
                <table class="grid" id="tenaga-kerja">
                    <thead>
                        <tr><th colspan="2">TENAGA KERJA</th></tr>
                        <tr>
                            <th>JABATAN</th>
                            <th class="ctr" style="width: 20mm">JUMLAH ORANG</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($manpower as $baris)
                            <tr @class(['total' => $baris['total']])>
                                <td>{{ $baris['label'] }}</td>
                                {{-- Empty, never "0". The cell's own border is the
                                     rule the site writes on, exactly as the pad has
                                     it; a nought here would be a statement that
                                     nobody in that trade came to work. --}}
                                <td class="ctr">@if ($baris['count'] !== null){{ $baris['count'] }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
            <td>
                <table class="grid" id="material-masuk">
                    <thead>
                        <tr><th colspan="3">MATERIAL YANG MASUK HARI INI</th></tr>
                        <tr>
                            <th>JENIS BAHAN</th>
                            <th class="ctr" style="width: 22mm">JUMLAH YANG DITERIMA</th>
                            <th class="ctr" style="width: 22mm">JUMLAH YANG DITOLAK</th>
                        </tr>
                    </thead>
                    <tbody>
@foreach ($materialMasuk as $baris)
                        <tr>
                            <td>{{ $baris['description'] }}@if ($baris['reason'] !== null) <span style="font-size:6.5pt">(ditolak: {{ $baris['reason'] }})</span>@endif</td>
                            {{-- DITOLAK prints even at 0: this row exists because the
                                 delivery was recorded, so "nothing rejected" is a
                                 statement, not an untouched default. --}}
                            <td class="num">{{ rtrim(rtrim(number_format($baris['received'], 3, ',', '.'), '0'), ',') }}@if ($baris['unit'] !== null) {{ $baris['unit'] }}@endif</td>
                            <td class="num">{{ rtrim(rtrim(number_format($baris['rejected'], 3, ',', '.'), '0'), ',') }}</td>
                        </tr>
@endforeach
                        @for ($i = 0; $i < $blankRows['materialMasuk']; $i++)
                            <tr>
                                <td><div class="fill"></div></td>
                                <td class="kosong"></td>
                                <td class="kosong"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>

                <table class="grid" id="material-dipakai">
                    <thead>
                        <tr><th colspan="3">MATERIAL YANG DIPAKAI HARI INI</th></tr>
                        <tr>
                            <th>JENIS BAHAN</th>
                            <th class="ctr" style="width: 22mm">JUMLAH</th>
                            <th class="ctr" style="width: 22mm">SATUAN</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($materialsUsed as $bahan)
                            <tr>
                                <td>{{ $bahan['name'] }}@if ($bahan['code']) <span style="font-size:6.5pt">({{ $bahan['code'] }})</span>@endif</td>
                                <td class="num">{{ rtrim(rtrim(number_format($bahan['qty'], 3, ',', '.'), '0'), ',') }}</td>
                                <td class="ctr">{{ $bahan['unit'] }}</td>
                            </tr>
                        @empty
                            {{-- Said in words. An empty table looks like a table
                                 somebody forgot to fill in. --}}
                            <tr><td colspan="3" class="ctr">Tidak ada pemakaian material dicatat pada laporan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <table class="grid" id="alat-alat">
                    <thead>
                        <tr><th colspan="2">ALAT-ALAT</th></tr>
                        <tr>
                            <th>JENIS ALAT</th>
                            <th class="ctr" style="width: 44mm">JUMLAH YANG DIGUNAKAN</th>
                        </tr>
                    </thead>
                    <tbody>
@foreach ($alat as $baris)
                        <tr>
                            {{-- No hours recorded prints no hours — not "0 jam". --}}
                            <td>{{ $baris['description'] }}@if ($baris['hours'] !== null) <span style="font-size:6.5pt">({{ rtrim(rtrim(number_format($baris['hours'], 2, ',', '.'), '0'), ',') }} jam)</span>@endif</td>
                            <td class="ctr">{{ $baris['qty'] }}</td>
                        </tr>
@endforeach
                        @for ($i = 0; $i < $blankRows['alat']; $i++)
                            <tr>
                                <td><div class="fill"></div></td>
                                <td class="kosong"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <table class="grid" id="uraian">
        <thead>
            <tr>
                <th class="ctr" style="width: 8mm">NO</th>
                <th>URAIAN PEKERJAAN</th>
                <th class="ctr" style="width: 22mm">PROGRESS</th>
                <th class="ctr" style="width: 22mm">TARGET</th>
                <th style="width: 58mm">HAMBATAN</th>
            </tr>
        </thead>
        <tbody>
@if ($uraianRows === [])
            <tr>
                <td class="ctr">1</td>
                <td>
                    @if (filled($activities))
                        {!! nl2br(e($activities)) !!}
                    @else
                        <div class="fill"></div>
                    @endif
                </td>
                {{-- No activity lines on this report — pre-P0-A shape — so the
                     summary text prints as it always did, and PROGRESS and
                     TARGET have nothing to answer them: prj_wbs_tasks'
                     progress_pct is a package's cumulative percentage, not a
                     day's, and there is no daily target outside the lines. --}}
                <td class="kosong"></td>
                <td class="kosong"></td>
                <td>
                    @if (filled($obstacles))
                        {!! nl2br(e($obstacles)) !!}
                    @else
                        <div class="fill"></div>
                    @endif
                </td>
            </tr>
            @for ($i = 0; $i < $blankRows['uraian']; $i++)
                <tr>
                    <td class="ctr">{{ $i + 2 }}</td>
                    <td><div class="fill"></div></td>
                    <td class="kosong"></td>
                    <td class="kosong"></td>
                    <td><div class="fill"></div></td>
                </tr>
            @endfor
@else
            @foreach ($uraianRows as $baris)
                <tr>
                    <td class="ctr">{{ $loop->iteration }}</td>
                    <td>@if ($baris['description'] !== null){!! nl2br(e($baris['description'])) !!}@else<div class="fill"></div>@endif</td>
                    {{-- A note the site did not record keeps its ruled blank
                         inside an otherwise sourced row. --}}
                    <td class="{{ $baris['progress'] === null ? 'kosong' : 'ctr' }}">{{ $baris['progress'] }}</td>
                    <td class="{{ $baris['target'] === null ? 'kosong' : 'ctr' }}">{{ $baris['target'] }}</td>
                    <td>@if ($baris['obstacle'] !== null){!! nl2br(e($baris['obstacle'])) !!}@else<div class="fill"></div>@endif</td>
                </tr>
            @endforeach
            @for ($i = 0; $i < $blankRows['uraian']; $i++)
                <tr>
                    <td class="ctr">{{ count($uraianRows) + $i + 1 }}</td>
                    <td><div class="fill"></div></td>
                    <td class="kosong"></td>
                    <td class="kosong"></td>
                    <td><div class="fill"></div></td>
                </tr>
            @endfor
@endif
        </tbody>
    </table>

@if ($handFilled !== [])
    <div class="catatan-kaki">
        <b>Diisi manual di lapangan :</b>
        <ol>
            @foreach ($handFilled as $catatan)
                <li>{{ $catatan }}</li>
            @endforeach
        </ol>
    </div>
@endif
@endsection
