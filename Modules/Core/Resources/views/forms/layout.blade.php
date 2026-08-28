{{--
    Formulir rumah — the shared sheet for every printed house form.

    This is the browser's page, not dompdf's. The four documents under
    documents/ go through dompdf at A4 portrait; these forms go to the print
    dialog, because the weekly schedule is a landscape grid with two-row-deep
    grouped headers that dompdf cannot lay out at all.

    Everything here is written for PAPER first and screen second:

      - Inline CSS, no external stylesheet, no web font. The sheet is opened
        from a blob: URL, which has no base — a relative href resolves to
        nothing, and a font fetched over the network would make the same form
        print differently on a laptop with no signal.
      - Both orientations. @page landscape + `body.landscape { page: landscape }`
        (CSS Paged Media named pages, Chrome 85+). A form asks for it by
        passing orientation => 'landscape'; everything else stays portrait.
      - thead repeats. The dompdf letterhead prints once, so page 2 of a long
        table arrives with no column headings — unreadable when the header is
        grouped two rows deep. `display: table-header-group` is the fix, and
        `break-inside: avoid` on rows stops a row being sliced by a page break.
      - print-color-adjust: exact. Chrome drops backgrounds when printing, and
        a grouped header that loses its shading loses the grouping with it.

    THE HAND-FILL CONVENTION. Half the cells on the owner's paper forms have no
    counterpart in this ERP (manpower by role, material ditolak, alat, jam
    kerja, perpanjangan waktu). The paper leaves them as dotted lines for the
    site to fill in, and so does this sheet: `.fill` is a blank grid cell with a
    writing rule, `.fill-line` is an inline rule inside a sentence ("dimulai
    jam …… s/d jam ……"). A cell is filled from the database or it is one of
    these two. It is never filled with a plausible-looking guess.

    What a form's composer supplies (defaults from FormPrintService::document):
      $header      — the assembled band, identity block and signatures
      $formTitle   — LAPORAN HARIAN / DATA PROYEK / …
      $formCode    — the code printed at the foot, e.g. "Form F/DS"
      $orientation — 'portrait' | 'landscape'
      $notes       — null to omit, or:
                     text    ?string  what the ERP recorded, if anything
                     lines   int      ruled lines when it recorded nothing
                     weather ?array   ['options' => ['Cerah',…],
                                       'pagi' => ?string, 'sore' => ?string]
                     hours   bool|array  the two working-day sentences: true
                                      prints them hand-filled; an array
                                      ['start','end','reason'] prints each
                                      recorded value, rules for the rest
      $docControl  — null, or ['judul','no_dok','no_rev','tanggal'] (WIKA IK)
      $money,$date — formatters, so no form formats a number its own way
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $formTitle }}@if ($project?->code) — {{ $project->code }}@endif</title>
    <style>
        @page { size: A4 portrait; margin: 10mm 8mm 11mm 8mm; }
        /* Named page, switched on by the body class. The weekly grid is the
           reason this exists; dompdf's @page could not have done it. */
        @page landscape { size: A4 landscape; margin: 8mm 8mm 10mm 8mm; }
        body.landscape { page: landscape; }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 8.5pt; line-height: 1.25; color: #000; background: #fff;
            /* Without this Chrome prints every shaded header white and the
               grouping the header exists to show disappears. */
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        b, strong { font-weight: bold; }

        /* On screen the sheet looks like the paper it becomes; in print it IS
           the paper and the chrome around it goes away. */
        @media screen {
            body { background: #eceff1; padding: 6mm; }
            .lembar { background: #fff; max-width: 210mm; margin: 0 auto; padding: 10mm 8mm; box-shadow: 0 1px 6px rgba(0,0,0,.25); }
            body.landscape .lembar { max-width: 297mm; }
        }
        .hint { max-width: 210mm; margin: 0 auto 5mm; padding: 2.5mm 3mm; border: 1px solid #b0bec5; background: #fff8e1; font-size: 9pt; }
        body.landscape .hint { max-width: 297mm; }
        @media print { .hint { display: none; } .lembar { padding: 0; box-shadow: none; max-width: none; } }

        table { width: 100%; border-collapse: collapse; }
        /* Grouped headers repeat on every page; rows are never sliced. */
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }

        /* --- the four-party band ------------------------------------------- */
        .band { border: 1pt solid #000; margin-bottom: 2.5mm; table-layout: fixed; }
        .band td { width: 25%; border-right: .7pt solid #000; padding: 1.4mm 1.6mm; text-align: center; vertical-align: top; }
        .band td:last-child { border-right: 0; }
        .band .cap { font-size: 6.5pt; letter-spacing: .06em; text-transform: uppercase; border-bottom: .5pt solid #999; padding-bottom: .6mm; margin-bottom: 1.2mm; }
        .band .party { min-height: 11mm; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 8.5pt; word-break: break-word; }
        .band .party img { max-height: 10mm; max-width: 100%; }
        .band .meta { font-size: 7pt; }

        /* --- document control (WIKA IK convention) ------------------------- */
        .dokkontrol { border: .7pt solid #000; margin-bottom: 2.5mm; font-size: 7.5pt; }
        .dokkontrol td { border-right: .5pt solid #000; padding: 1mm 1.4mm; }
        .dokkontrol td:last-child { border-right: 0; }
        .dokkontrol .k { font-weight: bold; }

        /* --- titles --------------------------------------------------------- */
        .judul { text-align: center; font-weight: bold; font-size: 11pt; letter-spacing: .01em; margin: 1mm 0 .8mm; }
        .pekerjaan { text-align: center; font-size: 8.5pt; margin-bottom: 2mm; }
        .form-title { text-align: center; font-weight: bold; font-size: 10pt; letter-spacing: .08em; text-decoration: underline; margin: 1.5mm 0 2.5mm; }

        /* --- identity block ------------------------------------------------- */
        .identitas { margin-bottom: 2.5mm; font-size: 8pt; }
        .identitas td { padding: .35mm 0; vertical-align: top; }
        .identitas td.k { width: 33mm; white-space: nowrap; }
        .identitas td.s { width: 3mm; }
        .identitas td.v { padding-right: 6mm; }

        /* --- body grid ------------------------------------------------------ */
        .grid { margin-bottom: 2mm; }
        .grid th, .grid td { border: .7pt solid #000; padding: 1.3mm 1.4mm; vertical-align: top; }
        .grid thead th { background: #e6e6e6; text-align: center; font-weight: bold; font-size: 7.5pt; line-height: 1.15; }
        .grid td.num, .grid th.num { text-align: right; }
        .grid td.ctr, .grid th.ctr { text-align: center; }
        .grid tbody tr.total td { background: #f2f2f2; font-weight: bold; }

        /* --- the hand-fill convention --------------------------------------- */
        /* A cell the ERP cannot answer. Not empty — ruled, so the site knows it
           is theirs to write on, exactly as the printed pad has always been. */
        .fill { min-height: 7mm; }
        .fill::after { content: ""; display: block; border-bottom: .4pt dotted #666; margin-top: 3.6mm; }
        .fill-line { display: inline-block; min-width: 22mm; border-bottom: .4pt dotted #444; height: 1.05em; vertical-align: bottom; }

        /* --- notes ----------------------------------------------------------- */
        .catatan { border: .7pt solid #000; padding: 1.6mm; margin-top: 2.5mm; break-inside: avoid; page-break-inside: avoid; }
        .catatan .h { font-weight: bold; margin-bottom: 1mm; }
        .catatan .rule { border-bottom: .4pt dotted #666; height: 4.6mm; }
        .catatan .baris { margin-top: 1.4mm; }
        .kotak { display: inline-block; width: 3.2mm; height: 3.2mm; border: .7pt solid #000; margin: 0 1.2mm 0 2.5mm; text-align: center; line-height: 2.9mm; font-size: 7pt; font-weight: bold; vertical-align: -.4mm; }
        .kotak:first-of-type { margin-left: 0; }

        /* --- signatures ------------------------------------------------------ */
        .ttd { margin-top: 5mm; break-inside: avoid; page-break-inside: avoid; }
        .ttd td { width: 33.33%; padding: 0 3mm; text-align: center; vertical-align: top; font-size: 8pt; }
        .ttd .party { font-weight: bold; min-height: 4.6mm; }
        .sig-space { height: 19mm; }
        .sig-name { border-top: .6pt solid #000; padding-top: 1mm; display: inline-block; min-width: 46mm; font-weight: bold; }
        .sig-role { font-size: 7.5pt; }

        /* --- foot ------------------------------------------------------------ */
        .kaki { margin-top: 3mm; font-size: 7pt; overflow: hidden; }
        .kaki .kode { float: left; font-weight: bold; }
        .kaki .cetak { float: right; }
    </style>
</head>
<body class="{{ $orientation === 'landscape' ? 'landscape' : 'portrait' }}">

{{-- Screen only. The tab is opened and print() is called for the user, but the
     two settings that decide whether the sheet matches the pad — orientation
     and background graphics — live in the browser's dialog and nowhere else. --}}
<div class="hint">
    Tekan <b>Ctrl+P</b> lalu pilih <b>Simpan sebagai PDF</b>. Kertas <b>A4</b>,
    orientasi <b>{{ $orientation === 'landscape' ? 'Lanskap' : 'Potret' }}</b>, skala 100%,
    dan aktifkan <b>Grafik latar belakang</b> agar arsiran kepala tabel ikut tercetak.
</div>

<div class="lembar">

    <table class="band">
        <tr>
            @foreach ($header['parties'] as $party)
                <td>
                    <div class="cap">{{ $party['caption'] }}</div>
                    <div class="party">
                        @if (! empty($party['logo']))
                            <img src="{{ $party['logo'] }}" alt="">
                        @else
                            {{-- Blank, never "null": most projects in this database
                                 have no konsultan MK, and the paper's answer to that
                                 is an empty box. --}}
                            <span>{{ $party['name'] }}</span>
                        @endif
                    </div>
                    @if (! empty($party['meta']))
                        <div class="meta">{{ $party['meta'] }}</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    @if ($docControl)
        <table class="dokkontrol">
            <tr>
                <td class="k">Judul</td><td>{{ $docControl['judul'] ?? '' }}</td>
                <td class="k">No. Dok.</td><td>{{ $docControl['no_dok'] ?? '' }}</td>
                <td class="k">No. Rev.</td><td>{{ $docControl['no_rev'] ?? '' }}</td>
                <td class="k">Tanggal</td><td>{{ $docControl['tanggal'] ?? '' }}</td>
            </tr>
        </table>
    @endif

    {{-- Omitted, not printed empty. A document with genuinely nothing to name
         above the PEKERJAAN line used to leave a blank bold line and its
         margins there, which reads as a value that failed to load rather than
         as a sheet that has no subject line. --}}
    @if (filled($header['projectTitle']))
        <div class="judul">{{ $header['projectTitle'] }}</div>
    @endif
    <div class="pekerjaan">
        PEKERJAAN :
        @if (filled($header['pekerjaan']))
            {{ $header['pekerjaan'] }}
        @else
            <span class="fill-line" style="min-width:70mm"></span>
        @endif
    </div>

    <div class="form-title">{{ $formTitle }}</div>

    {{-- Two columns of label : value, five pairs each, the way the pad has it. --}}
    @php $identityRows = array_chunk($header['identity'], (int) ceil(count($header['identity']) / 2)); @endphp
    <table class="identitas">
        @for ($i = 0; $i < count($identityRows[0] ?? []); $i++)
            <tr>
                @foreach ([0, 1] as $column)
                    @php $cell = $identityRows[$column][$i] ?? null; @endphp
                    <td class="k">{{ $cell['label'] ?? '' }}</td>
                    <td class="s">{{ $cell ? ':' : '' }}</td>
                    <td class="v">
                        @if ($cell === null)
                        @elseif (filled($cell['value']))
                            {{ $cell['value'] }}
                        @else
                            <span class="fill-line"></span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endfor
    </table>

    @yield('content')

    @if ($notes)
        <div class="catatan">
            <div class="h">Catatan :</div>
            @if (filled($notes['text'] ?? null))
                <div>{!! nl2br(e($notes['text'])) !!}</div>
            @else
                @for ($i = 0; $i < (int) ($notes['lines'] ?? 3); $i++)
                    <div class="rule"></div>
                @endfor
            @endif

            @if (! empty($notes['weather']))
                @foreach (['pagi' => 'PAGI', 'sore' => 'SORE'] as $slot => $label)
                    <div class="baris">
                        <b>Cuaca {{ $label }} :</b>
                        @foreach ($notes['weather']['options'] ?? [] as $option)
                            {{-- Ticked from prj_daily_reports.weather_am/pm when the
                                 report recorded it; all three boxes empty when it did
                                 not, which is what the pad looks like unfilled. --}}
                            <span class="kotak">{{ $option === ($notes['weather'][$slot] ?? null) ? 'X' : '' }}</span>{{ $option }}
                        @endforeach
                    </div>
                @endforeach
            @endif

            @if (! empty($notes['hours']))
                {{-- The laporan harian passes hours as an array since P0-A —
                     start/end 'HH:MM' and the lost-hours reason, read off the
                     report itself; a bool (the registry forms) still means the
                     hand-filled rules. A slot the report did not record keeps
                     its rule: a printed value never invents its neighbour.
                     The flush-left directives are byte-preserving on purpose —
                     see the note in laporan-harian.blade.php. --}}
@php($jam = is_array($notes['hours']) ? $notes['hours'] : [])
                <div class="baris">
@if (filled($jam['start'] ?? null))
                    Pekerjaan dimulai jam {{ $jam['start'] }}
@else
                    Pekerjaan dimulai jam <span class="fill-line" style="min-width:16mm"></span>
@endif
@if (filled($jam['end'] ?? null))
                    s/d jam {{ $jam['end'] }}
@else
                    s/d jam <span class="fill-line" style="min-width:16mm"></span>
@endif
                </div>
                <div class="baris">
@if (filled($jam['reason'] ?? null))
                    Jam kerja (sepenuhnya dapat / sebagian tidak dapat digunakan untuk bekerja) karena
                    {{ $jam['reason'] }}
@else
                    Jam kerja (sepenuhnya dapat / sebagian tidak dapat digunakan untuk bekerja) karena
                    <span class="fill-line" style="min-width:70mm"></span>
@endif
                </div>
            @endif
        </div>
    @endif

    <table class="ttd">
        <tr>
            @foreach ($header['signatures'] as $signature)
                <td>
                    <div>{{ $signature['heading'] }}</div>
                    {{-- The nbsp is load-bearing: an empty div is zero pixels
                         tall, and the "Mengetahui," column has no subheading, so
                         without it that column's rule sits a line higher than the
                         other two and the block reads as a mistake. --}}
                    <div>{{ $signature['subheading'] }}&nbsp;</div>
                    <div class="party">{{ $signature['party'] }}</div>
                    <div class="sig-space"></div>
                    {{-- The rule is printed whether or not a name goes above it.
                         Nothing here knows who signs for the owner or the MK, and
                         a name invented for those two columns would be a forged
                         signature line on a document three parties file. --}}
                    <div class="sig-name">{{ $signature['name'] ?: '' }}&nbsp;</div>
                    <div class="sig-role">{{ $signature['role'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="kaki">
        @if ($formCode)<span class="kode">{{ $formCode }}</span>@endif
        <span class="cetak">Dicetak {{ $printedAt }} — Nusantara ERP</span>
    </div>

</div>
</body>
</html>
