{{--
    DAFTAR TEMUAN / DEFECT LIST — the punch list as the QC folder keeps it.

    The one house form on which nothing is hand-filled. prj_defects carries
    every column the owner's own 'Defect List serius.xls' has, so each cell here
    is either a value off the row or an empty cell meaning "that step has not
    happened yet" — an open item has no DIPERBAIKI date because nobody has
    repaired it, not because the computer declined to look. The footnote says
    that in as many words, since an empty cell on a signed register is exactly
    the kind of thing a reader fills in with the wrong assumption.

    Twelve columns, so landscape. The recap band at the top is
    DefectService::summary() — the same counts the BAST II gate refuses on — and
    it deliberately does NOT narrow when ?status= narrows the rows: a page
    showing two open items must not read as a job with two findings.
--}}
@extends('coredoc::forms.layout')

@section('content')
    <style>
        .rekap { border: .7pt solid #000; padding: 1.6mm; margin-bottom: 2.5mm; font-size: 7.5pt; }
        .rekap .h { font-weight: bold; margin-bottom: 1mm; }
        .rekap .baris { margin-top: .9mm; }
        /* nowrap: a count that wraps away from its own label reads as the next
           label's count. */
        .rekap .stat { display: inline-block; margin-right: 6mm; white-space: nowrap; }
        .rekap .saring { margin-top: 1.4mm; font-style: italic; }

        /* 7pt and tight padding: twelve columns across 281mm of usable
           landscape width, with URAIAN TEMUAN and KETERANGAN taking whatever
           the fixed columns leave. */
        #temuan { font-size: 7pt; }
        #temuan th, #temuan td { padding: .9mm 1mm; }
        /* A stage that has not happened yet. Empty, with the cell's own grid
           border as the rule to pencil on during a punch walk — the same
           convention the laporan harian uses for its narrow columns, rather
           than a 7mm dotted box that would turn a 40-row register into three
           pages of mostly nothing. */
        #temuan td.kosong { height: 5.4mm; }
        #temuan td.lewat { font-weight: bold; }
        #temuan .sub { display: block; font-size: 6.4pt; }

        .catatan-kaki { margin-top: 2mm; font-size: 6.8pt; line-height: 1.3; }
        .catatan-kaki ol { margin: .6mm 0 0; padding-left: 4.5mm; }
    </style>

    <div class="rekap">
        <div class="h">REKAPITULASI — seluruh temuan proyek ini, keadaan per {{ $date($summary['asOf']) }}</div>
        <div class="baris">
            @foreach ($summary['stats'] as $stat)
                <span class="stat"><b>{{ $stat['label'] }}</b> : {{ $stat['value'] }}</span>
            @endforeach
        </div>
        <div class="baris">
            <b>Menurut keparahan</b> &mdash;
            @foreach ($summary['bySeverity'] as $level)
                <span class="stat"><b>{{ $level['label'] }}</b> : {{ $level['count'] }}</span>
            @endforeach
        </div>
        <div class="baris">
            <b>Menurut status</b> &mdash;
            @foreach ($summary['byStatus'] as $state)
                <span class="stat"><b>{{ $state['label'] }}</b> : {{ $state['count'] }}</span>
            @endforeach
        </div>
        @if ($filterNote)
            <div class="saring">{{ $filterNote }}</div>
        @endif
    </div>

    <table class="grid" id="temuan">
        <thead>
            <tr>
                <th class="ctr" style="width: 8mm">NO</th>
                <th style="width: 24mm">KODE</th>
                <th style="width: 26mm">LOKASI</th>
                <th>URAIAN TEMUAN</th>
                <th class="ctr" style="width: 16mm">KEPARAHAN</th>
                <th style="width: 22mm">SUMBER</th>
                <th class="ctr" style="width: 19mm">DILAPORKAN</th>
                <th class="ctr" style="width: 19mm">TENGGAT</th>
                <th style="width: 24mm">STATUS</th>
                <th class="ctr" style="width: 19mm">DIPERBAIKI</th>
                <th class="ctr" style="width: 19mm">DIVERIFIKASI</th>
                <th style="width: 32mm">KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="ctr">{{ $row['no'] }}</td>
                    <td>{{ $row['code'] }}</td>
                    <td @class(['kosong' => blank($row['location'])])>{{ $row['location'] }}</td>
                    <td>
                        {{ $row['title'] }}
                        @if (filled($row['description']))
                            <span class="sub">{!! nl2br(e($row['description'])) !!}</span>
                        @endif
                    </td>
                    <td class="ctr">{{ $row['severity'] }}</td>
                    <td>{{ $row['source'] }}</td>
                    <td class="ctr">{{ $date($row['reportedOn']) }}</td>
                    {{-- The asterisk is Defect::isOverdue(), not a comparison
                         written again here: the sheet and the list screen must
                         never disagree about which repairs are late. --}}
                    <td @class(['ctr', 'kosong' => blank($row['dueDate']), 'lewat' => $row['overdue']])>
                        {{ $date($row['dueDate']) }}@if ($row['overdue']) *@endif
                    </td>
                    <td>{{ $row['status'] }}</td>
                    <td @class(['ctr', 'kosong' => blank($row['fixedAt'])])>{{ $date($row['fixedAt']) }}</td>
                    <td @class(['ctr', 'kosong' => blank($row['verifiedAt'])])>{{ $date($row['verifiedAt']) }}</td>
                    <td @class(['kosong' => blank($row['note'])])>{!! nl2br(e((string) $row['note'])) !!}</td>
                </tr>
            @empty
                {{-- Said in words. An empty grid looks like a grid somebody
                     forgot to fill in, and on this particular form that reading
                     is expensive. --}}
                <tr>
                    <td colspan="12" class="ctr">Tidak ada temuan yang cocok dengan saringan lembar ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="catatan-kaki">
        <ol>
            @if (collect($rows)->contains(fn (array $row): bool => $row['overdue']))
                <li>Tanda <b>*</b> pada kolom TENGGAT berarti sudah <b>lewat tenggat</b> perbaikan dan temuan belum ditutup.</li>
            @endif
            <li>Sel kosong berarti tahapannya belum terjadi &mdash; kolom DIPERBAIKI pada temuan yang masih
                terbuka, misalnya &mdash; bukan data yang hilang.</li>
            <li>Rekapitulasi di kepala lembar dihitung dari <b>seluruh</b> temuan proyek ini, bukan dari baris
                yang tercetak di bawahnya.</li>
            <li>Penahan BAST II adalah temuan <b>Kritis</b> dan <b>Mayor</b> yang masih terbuka; temuan Minor
                tidak menahan serah terima.</li>
            <li>Daftar ini adalah keadaan pada saat dicetak; temuan yang dicatat setelahnya tidak ada di lembar ini.</li>
        </ol>
    </div>
@endsection
