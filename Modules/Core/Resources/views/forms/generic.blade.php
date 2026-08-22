{{--
    The body of ANY registry-defined house form.

    Everything above this Blade (band, doc-control strip, project title,
    PEKERJAAN line, form title, identity block) and everything below it (notes,
    signatures, form code) is layout.blade.php, unchanged and shared with the
    seven bespoke forms — this file extends that sheet, it does not fork it. So
    a registry document and a laporan harian print the same paper, and a change
    to the band changes both.

    What arrives here, already resolved by FormPrintService::registryDocument():

      $tables — a list of body tables. Two declared tables are two entries and
                render as two bordered tables, never one table with a second
                heading row: the borders are what a signed form is read by, and
                merging them silently re-parents every row.

        id      optional html id
        title   optional grouped header row spanning the table
        columns [label, align, width]
        rows    list of lists of CELLS, already cast to strings by the composer
        blanks  how many RULED rows to add under the real ones (a pad the site
                fills in by hand)
        totals  [label, value] printed in the last column
        empty   the sentence for a table with nothing in it

    THE ONE RULE THIS TEMPLATE ENFORCES: a cell is a string the composer proved,
    or it is null and gets the ruled blank the owner's paper has always had.
    There is no third branch below — no "—", no 0, no "belum diisi". A dotted
    line is an instruction to the person holding the pen; a zero is a claim.
--}}
@extends('coredoc::forms.layout')

@section('content')
    @foreach ($tables as $table)
        <table class="grid" @if (! empty($table['id'])) id="{{ $table['id'] }}" @endif>
            <thead>
                @if (filled($table['title'] ?? null))
                    <tr><th colspan="{{ count($table['columns']) }}">{{ $table['title'] }}</th></tr>
                @endif
                <tr>
                    @foreach ($table['columns'] as $column)
                        <th
                            @class(['ctr' => ($column['align'] ?? null) === 'center', 'num' => ($column['align'] ?? null) === 'right'])
                            @if (! empty($column['width'])) style="width: {{ $column['width'] }}" @endif
                        >{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    <tr>
                        @foreach ($table['columns'] as $index => $column)
                            <td @class(['ctr' => ($column['align'] ?? null) === 'center', 'num' => ($column['align'] ?? null) === 'right'])>
                                @if (filled($row[$index] ?? null))
                                    {{ $row[$index] }}
                                @else
                                    {{-- The ERP had nothing for this cell. Ruled,
                                         not empty, so the site knows it is theirs. --}}
                                    <div class="fill"></div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    @if (filled($table['empty'] ?? null) && (int) ($table['blanks'] ?? 0) === 0)
                        <tr>
                            <td colspan="{{ count($table['columns']) }}">{{ $table['empty'] }}</td>
                        </tr>
                    @endif
                @endforelse

                {{-- A pad the site fills in by hand: ruled rows, never rows of
                     zeros. Declared per table as minRows. --}}
                @for ($blank = 0; $blank < (int) ($table['blanks'] ?? 0); $blank++)
                    <tr>
                        @foreach ($table['columns'] as $column)
                            <td><div class="fill"></div></td>
                        @endforeach
                    </tr>
                @endfor

                @foreach ($table['totals'] ?? [] as $total)
                    {{-- The label spans everything but the last column, and the
                         figure sits in it — under the column it totals, which is
                         where the eye already is. --}}
                    <tr class="total">
                        <td colspan="{{ max(1, count($table['columns']) - 1) }}" class="num">{{ $total['label'] }}</td>
                        <td class="num">
                            @if (filled($total['value']))
                                {{ $total['value'] }}
                            @else
                                <div class="fill"></div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
@endsection
