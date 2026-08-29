{{--
    LAPORAN MINGGUAN — the landscape DETAIL SCHEDULE / PROGRAM KERJA.

    The pad is a bar chart somebody rules by hand: one row per jenis pekerjaan,
    a bobot column, and week blocks of six day columns (Senin..Sabtu). What is
    printed and what is left to the pen is decided in
    Modules\Projects\Services\LaporanFormService; the rules it enforces and this
    sheet obeys are:

      BOBOT           prj_wbs_tasks.weight_pct. Leaf weights close on 100, which
                      is what makes the column add up at the foot.
      VOLUME          the linked BOQ line's quantity — the CONTRACT volume. The
                      pad's column says "bulan ini" and no monthly split exists
                      anywhere in this database, so the footnote says so rather
                      than the column implying otherwise. No link, no volume.
      the day grid    shaded from prj_baseline_tasks — the APPROVED, frozen
                      plan. prj_wbs_tasks carries planned dates too, but those
                      move whenever the plan slips, and a bar that redraws
                      itself is not a commitment. No approved baseline, no bar,
                      and the footnote says why.
      RENCANA/        prj_weekly_progress, cumulative, matched to each week by
      REALISASI       DATE RANGE. A week with no row prints EMPTY. "0%" there
                      would read as a week in which the site did nothing.
                      REALISASI is TWO different numbers since P3 — a typed
                      percentage or an approved opname's value-weighted
                      measurement (actual_pct_source) — so a measured week
                      prints its OPNAME NUMBER under the figure and the footnote
                      states the provenance of what was actually printed. An
                      estimate and a measurement may not print identically.

    The header repeats on page two (thead + display: table-header-group in the
    layout), which is the whole reason these forms are browser-printed rather
    than run through dompdf.
--}}
@extends('coredoc::forms.layout')

@section('content')
    <style>
        .jadwal { table-layout: fixed; }
        .jadwal th, .jadwal td { padding: .8mm 1mm; }
        .jadwal thead th.minggu { font-size: 7pt; }
        .jadwal thead th.minggu .rentang { display: block; font-weight: normal; font-size: 6pt; }
        /* Six day columns per week block. Narrow on purpose — nothing is
           written in them, they are the bar chart's canvas. */
        .jadwal th.hari, .jadwal td.hari { width: 4.4mm; padding: .8mm 0; text-align: center; font-size: 6pt; }
        .jadwal th.hari .tgl { display: block; font-weight: normal; font-size: 5.5pt; color: #333; }
        /* A day outside the printed month. 23-28 occurs twice in a month that
           opens on a Sunday, so the neighbour's days are greyed rather than
           silently identical. */
        .jadwal th.hari.luar { background: #f4f4f4; color: #777; }
        /* The bar: the frozen planned span for this package on this day. */
        .jadwal td.hari.bar { background: #555; }
        .jadwal tr.grup td { background: #f2f2f2; font-weight: bold; }
        .jadwal tfoot td { background: #f2f2f2; font-weight: bold; }
        .jadwal tfoot td.wk { text-align: center; }
        /* The opname a measured realisasi came from, under its own figure. The
           block is 26mm wide and an OPN code is 17 characters at 5.5pt. */
        .jadwal tfoot td.wk .sumber { display: block; font-weight: normal; font-size: 5.5pt; letter-spacing: -.1pt; }
        .catatan-kaki { margin-top: 2mm; font-size: 6.8pt; line-height: 1.3; }
        .catatan-kaki ol { margin: .6mm 0 0; padding-left: 4.5mm; }
    </style>

    <table class="grid jadwal">
        <thead>
            <tr>
                <th rowspan="2" class="ctr" style="width: 8mm">NO</th>
                <th rowspan="2" style="width: 56mm">JENIS PEKERJAAN</th>
                <th colspan="2" style="width: 24mm">VOLUME</th>
                <th rowspan="2" class="ctr" style="width: 15mm">BOBOT (%)</th>
                @foreach ($weeks as $minggu)
                    <th colspan="6" class="minggu">
                        MINGGU {{ $minggu['roman'] }}
                        <span class="rentang">{{ $minggu['label'] }}</span>
                    </th>
                @endforeach
                <th rowspan="2" style="width: 26mm">KETERANGAN</th>
            </tr>
            <tr>
                <th class="ctr" style="width: 15mm">JUMLAH</th>
                <th class="ctr" style="width: 9mm">SAT.</th>
                @foreach ($weeks as $minggu)
                    @foreach ($minggu['days'] as $hari)
                        <th @class(['hari', 'luar' => ! $hari['inMonth']])>
                            {{ $hari['letter'] }}<span class="tgl">{{ $hari['dom'] }}</span>
                        </th>
                    @endforeach
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $baris)
                <tr @class(['grup' => $baris['group']])>
                    <td class="ctr">{{ $baris['no'] }}</td>
                    <td>
                        {{ $baris['code'] }} — {{ $baris['name'] }}@if ($baris['offBaseline'])<sup>*</sup>@endif
                    </td>
                    {{-- Volume is the linked BOQ line or nothing at all. Seven of
                         the eight packages on the live project carry no
                         boq_item_id, and a dash there reads as a zero. --}}
                    <td class="num">@if ($baris['volume'] !== null){{ rtrim(rtrim(number_format($baris['volume'], 3, ',', '.'), '0'), ',') }}@endif</td>
                    <td class="ctr">{{ $baris['unit'] }}</td>
                    <td class="num">{{ number_format($baris['weight'], 4, ',', '.') }}</td>
                    @foreach ($baris['bars'] as $terisi)
                        <td class="{{ $terisi ? 'hari bar' : 'hari' }}"></td>
                    @endforeach
                    <td></td>
                </tr>
            @empty
                <tr><td colspan="{{ 6 + count($weeks) * 6 }}" class="ctr">Proyek ini belum memiliki paket pekerjaan (WBS).</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr id="bobot-rencana">
                <td colspan="4">JUMLAH BOBOT — RENCANA (kumulatif %)</td>
                <td class="num">@if ($weightTotal !== null){{ number_format($weightTotal, 4, ',', '.') }}@endif</td>
                @foreach ($weeks as $minggu)
                    {{-- Blank, not 0,0000: a week with no progress row is a week
                         nobody has reported, which is not the same statement as
                         a week in which nothing was planned. --}}
                    <td class="wk" colspan="6">@if ($minggu['planned'] !== null){{ number_format($minggu['planned'], 4, ',', '.') }}@endif</td>
                @endforeach
                <td></td>
            </tr>
            <tr id="bobot-realisasi">
                <td colspan="4">JUMLAH BOBOT — REALISASI (kumulatif %)</td>
                <td></td>
                @foreach ($weeks as $minggu)
                    {{-- The figure, and — when it came from an approved opname
                         rather than from a typed percentage — the number of the
                         opname that produced it. The footnote says what the
                         mark means; an unmarked figure is a supervisor's
                         estimate and must not read as a measurement. --}}
                    <td class="wk" colspan="6">@if ($minggu['actual'] !== null){{ number_format($minggu['actual'], 4, ',', '.') }}@if ($minggu['actualNote'] !== null)<span class="sumber">{{ $minggu['actualNote'] }}</span>@endif @endif</td>
                @endforeach
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="catatan-kaki">
        <b>Catatan pengisian :</b>
        <ol>
            @foreach ($handFilled as $catatan)
                <li>{{ $catatan }}</li>
            @endforeach
            @if ($rows && collect($rows)->contains(fn (array $baris): bool => $baris['offBaseline']))
                <li><sup>*</sup> Paket pekerjaan ini tidak ada di dalam baseline yang disetujui — ditambahkan setelah rencana dibekukan, sehingga batang rencananya tidak dapat dicetak.</li>
            @endif
        </ol>
    </div>
@endsection
