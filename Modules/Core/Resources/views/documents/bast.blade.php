@extends('coredoc::documents.layout')

@section('content')
    <p style="margin:0 0 12px">
        Pada hari ini, {{ $date($bast->handover_date) }}, yang bertanda tangan di bawah ini
        telah melaksanakan serah terima pekerjaan sebagai berikut:
    </p>

    <table class="kv">
        <tr><td class="k">Jenis serah terima</td><td><strong>{{ $bastTypeLabel }}</strong></td></tr>
        <tr><td class="k">Proyek</td><td>{{ $bast->project?->code }} — {{ $bast->project?->name }}</td></tr>
        @if ($bast->project?->location)
            <tr><td class="k">Lokasi</td><td>{{ collect([$bast->project->location, $bast->project->city])->filter()->implode(', ') }}</td></tr>
        @endif
        @if ($bast->project?->contract)
            <tr><td class="k">Kontrak</td><td>{{ $bast->project->contract->code }} — {{ $bast->project->contract->title }}</td></tr>
        @endif
        @if ($bast->project?->customer)
            <tr><td class="k">Pemberi kerja</td><td>{{ $bast->project->customer->name }}</td></tr>
        @endif
        <tr><td class="k">Tanggal serah terima</td><td>{{ $date($bast->handover_date) }}</td></tr>
        @if ($bast->retention_release_due)
            {{-- The date the customer's retention becomes claimable; it is the
                 whole financial consequence of signing this page. --}}
            <tr><td class="k">Retensi jatuh tempo</td><td>{{ $date($bast->retention_release_due) }}</td></tr>
        @endif
        @if ($bast->project && (float) $bast->project->retention_pct > 0)
            <tr><td class="k">Nilai retensi</td>
                <td>Rp {{ $money($bast->project->retentionAmount()) }} ({{ rtrim(rtrim(number_format((float) $bast->project->retention_pct, 2, ',', '.'), '0'), ',') }}%)</td></tr>
        @endif
        @if ($bast->project?->warranty_months)
            <tr><td class="k">Masa pemeliharaan</td><td>{{ $bast->project->warranty_months }} bulan</td></tr>
        @endif
    </table>

    @if ($bast->notes)
        <div class="note" style="margin-top:14px">{{ $bast->notes }}</div>
    @endif

    <p style="margin:16px 0 0">
        Demikian berita acara ini dibuat dalam rangkap dua bermeterai cukup, masing-masing
        mempunyai kekuatan hukum yang sama, untuk dipergunakan sebagaimana mestinya.
    </p>

    <div class="place-date">{{ $company?->city ? $company->city.', ' : '' }}{{ $date($bast->handover_date) }}</div>

    <table class="signatures">
        <tr>
            <td>
                Yang menyerahkan,<br>
                {{ $company?->legal_name ?: $company?->name }}
                <div class="sig-space"></div>
                <span class="sig-name">&nbsp;</span>
            </td>
            <td></td>
            <td>
                Yang menerima,<br>
                {{ $bast->project?->customer?->name }}
                <div class="sig-space"></div>
                {{-- The name the ERP already records against this handover. --}}
                <span class="sig-name">@if ($bast->customer_representative){{ $bast->customer_representative }}@else&nbsp;@endif</span>
            </td>
        </tr>
    </table>
@endsection
