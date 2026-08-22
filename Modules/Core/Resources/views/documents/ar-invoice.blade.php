@extends('coredoc::documents.layout')

@section('content')
    <table class="kv">
        <tr><td class="k">Kepada</td><td><strong>{{ $invoice->customer?->name }}</strong></td></tr>
        @if ($invoice->customer?->billing_address)
            <tr><td class="k"></td><td>{{ $invoice->customer->billing_address }}</td></tr>
        @endif
        @if ($invoice->customer?->npwp)
            <tr><td class="k">NPWP</td><td>{{ $invoice->customer->npwp }}</td></tr>
        @endif
        <tr><td class="k">Tanggal</td><td>{{ $date($invoice->invoice_date) }}</td></tr>
        @if ($invoice->due_date)
            <tr><td class="k">Jatuh tempo</td><td>{{ $date($invoice->due_date) }}</td></tr>
        @endif
        @if ($invoice->contract)
            <tr><td class="k">Kontrak</td><td>{{ $invoice->contract->code }} — {{ $invoice->contract->title }}</td></tr>
        @endif
        @if ($invoice->faktur_pajak_no)
            <tr><td class="k">Faktur pajak</td><td>{{ $invoice->faktur_pajak_no }}</td></tr>
        @endif
    </table>

    <table class="data" style="margin-top:14px">
        <thead>
            <tr><th style="width:60%">Uraian</th><th class="right">Jumlah (Rp)</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->description }}</td>
                <td class="num">{{ $money($invoice->dpp) }}</td>
            </tr>
            @if ((float) $invoice->ppn_amount > 0)
                <tr><td>PPN {{ rtrim(rtrim(number_format((float) $invoice->ppn_rate, 2, ',', '.'), '0'), ',') }}%</td>
                    <td class="num">{{ $money($invoice->ppn_amount) }}</td></tr>
            @endif
            @if ((float) $invoice->retention_withheld > 0)
                <tr><td>Retensi ditahan</td><td class="num">({{ $money($invoice->retention_withheld) }})</td></tr>
            @endif
            <tr class="total-row"><td>Jumlah yang harus dibayar</td><td class="num">{{ $money($invoice->total) }}</td></tr>
        </tbody>
    </table>

    {{-- The reason terbilang was computed and stored in the first place. --}}
    @if ($invoice->terbilang)
        <div class="terbilang">Terbilang: {{ $invoice->terbilang }}</div>
    @endif

    <div class="note">
        Pembayaran ditransfer ke rekening perusahaan. Mohon cantumkan nomor invoice {{ $invoice->code }} pada berita transfer.
    </div>

    <div class="place-date">{{ $company?->city ? $company->city.', ' : '' }}{{ $date($invoice->invoice_date) }}</div>

    <table class="signatures">
        <tr>
            <td></td>
            <td></td>
            <td>
                {{ $company?->legal_name ?: $company?->name }}
                <div class="sig-space"></div>
                <span class="sig-name">&nbsp;</span>
            </td>
        </tr>
    </table>
@endsection
