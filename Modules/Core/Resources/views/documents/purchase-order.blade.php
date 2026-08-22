@extends('coredoc::documents.layout')

@section('content')
    <table class="kv">
        <tr><td class="k">Kepada</td><td><strong>{{ $order->vendor?->name }}</strong></td></tr>
        @if ($order->vendor?->address)
            <tr><td class="k"></td><td>{{ $order->vendor->address }}</td></tr>
        @endif
        @if ($order->vendor?->pic_name)
            <tr><td class="k">u.p.</td><td>{{ $order->vendor->pic_name }}</td></tr>
        @endif
        <tr><td class="k">Tanggal pesanan</td><td>{{ $date($order->order_date) }}</td></tr>
        @if ($order->expected_date)
            <tr><td class="k">Diharapkan tiba</td><td>{{ $date($order->expected_date) }}</td></tr>
        @endif
        @if ($order->project)
            <tr><td class="k">Proyek</td><td>{{ $order->project->code }} — {{ $order->project->name }}</td></tr>
        @endif
        @if ($order->delivery_address)
            <tr><td class="k">Kirim ke</td><td>{{ $order->delivery_address }}</td></tr>
        @endif
        @if ($order->payment_term_days)
            <tr><td class="k">Termin bayar</td><td>{{ $order->payment_term_days }} hari</td></tr>
        @endif
    </table>

    <table class="data" style="margin-top:14px">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th style="width:44%">Uraian</th>
                <th class="right" style="width:12%">Qty</th>
                <th style="width:10%">Satuan</th>
                <th class="right" style="width:15%">Harga</th>
                <th class="right" style="width:15%">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 3, ',', '.'), '0'), ',') }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->amount) }}</td>
                </tr>
            @endforeach
            <tr><td colspan="5" class="right">Subtotal</td><td class="num">{{ $money($order->subtotal) }}</td></tr>
            @if ((float) $order->discount_amount > 0)
                <tr><td colspan="5" class="right">Diskon</td><td class="num">({{ $money($order->discount_amount) }})</td></tr>
            @endif
            @if ((float) $order->ppn_amount > 0)
                <tr><td colspan="5" class="right">PPN {{ rtrim(rtrim(number_format((float) $order->ppn_rate, 2, ',', '.'), '0'), ',') }}%</td><td class="num">{{ $money($order->ppn_amount) }}</td></tr>
            @endif
            <tr class="total-row"><td colspan="5" class="right">Total</td><td class="num">{{ $money($order->total) }}</td></tr>
        </tbody>
    </table>

    @if ($terbilang)
        <div class="terbilang">Terbilang: {{ $terbilang }}</div>
    @endif

    @if ($order->notes)
        <div class="note">{{ $order->notes }}</div>
    @endif

    <div class="place-date">{{ $company?->city ? $company->city.', ' : '' }}{{ $date($order->order_date) }}</div>

    <table class="signatures">
        <tr>
            <td>
                Menyetujui,
                <div class="sig-space"></div>
                <span class="sig-name">Direktur</span>
            </td>
            <td>
                Dibuat oleh,
                <div class="sig-space"></div>
                <span class="sig-name">Pengadaan</span>
            </td>
            <td>
                Diterima vendor,
                <div class="sig-space"></div>
                <span class="sig-name">{{ $order->vendor?->name }}</span>
            </td>
        </tr>
    </table>
@endsection
