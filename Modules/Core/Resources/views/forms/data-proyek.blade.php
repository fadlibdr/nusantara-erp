{{--
    Lembar Data Proyek — the cover sheet of the site file.

    Every row on it comes out of the database, which is the point: it proves the
    whole house-format assembly (band, identity block, body grid, notes,
    signatures, form code) without one invented cell in the body. Where a fact
    genuinely is not recorded — a job with no konsultan MK, a project manager
    not yet assigned — the cell gets the ruled blank the pad has always had.
--}}
@extends('coredoc::forms.layout')

@section('content')
    <table class="grid">
        <thead>
            <tr>
                <th class="ctr" style="width: 9mm">NO</th>
                <th style="width: 52mm">URAIAN</th>
                <th>KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $index => $row)
                <tr>
                    <td class="ctr">{{ $index + 1 }}</td>
                    <td>{{ $row['label'] }}</td>
                    <td>
                        @if (filled($row['value']))
                            {{ $row['value'] }}
                        @else
                            <div class="fill"></div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
