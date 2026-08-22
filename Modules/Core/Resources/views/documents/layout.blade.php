{{--
    Shared letterhead for every printed document.

    Deliberately plain: inline CSS, no external stylesheet, no web fonts. dompdf
    resolves neither, and a document that renders differently on the machine that
    prints it is worse than a plain one that always looks the same. Everything
    below is what a Berita Acara or an invoice needs to be accepted — who issued
    it, their NPWP, the document's own number, and room for a signature.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22mm 18mm 24mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; line-height: 1.45; }
        .letterhead { border-bottom: 1.5px solid #111; padding-bottom: 8px; margin-bottom: 16px; }
        .letterhead .company { font-size: 14px; font-weight: bold; }
        .letterhead img.logo { max-height: 42px; margin-bottom: 5px; }
        .letterhead .meta { font-size: 9px; color: #444; margin-top: 2px; }
        h1 { font-size: 13px; margin: 0 0 2px; text-transform: uppercase; letter-spacing: .04em; }
        .docno { font-size: 10px; color: #444; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        table.data th { background: #f0f0f0; border: .5px solid #999; padding: 5px 6px; text-align: left; font-size: 9px; }
        table.data td { border: .5px solid #bbb; padding: 5px 6px; vertical-align: top; }
        table.kv td { padding: 2px 0; vertical-align: top; }
        table.kv td.k { width: 130px; color: #444; }
        .right { text-align: right; }
        .num { text-align: right; }
        .total-row td { font-weight: bold; background: #f6f6f6; }
        .terbilang { margin-top: 10px; font-style: italic; font-size: 9.5px; }
        .note { margin-top: 12px; font-size: 9px; color: #444; }
        /* Place and date sit above the signature row so all three blocks rest
           on the same baseline however many lines each carries. */
        .place-date { margin-top: 24px; text-align: right; font-size: 9.5px; }
        /* Signature blocks are why this exists: a web page cannot be signed. */
        .signatures { width: 100%; margin-top: 28px; }
        .signatures td { width: 33%; text-align: center; font-size: 9.5px; vertical-align: top; padding-top: 4px; }
        .sig-space { height: 52px; }
        .sig-name { border-top: .5px solid #111; padding-top: 3px; display: inline-block; min-width: 130px; }
        .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8px; color: #777; }
        .voided { border: 1.5px solid #b00020; color: #b00020; font-weight: bold; text-align: center;
                  padding: 5px 6px; margin: 0 0 10px; font-size: 11px; letter-spacing: .3px; }
    </style>
</head>
<body>
    <div class="letterhead">
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="">
        @endif
        <div class="company">{{ $company?->legal_name ?: $company?->name ?: config('erp.company.name') }}</div>
        <div class="meta">
            {{ collect([$company?->address, $company?->city, $company?->province, $company?->postal_code])->filter()->implode(', ') }}
            @if ($company?->phone) · Telp {{ $company->phone }} @endif
            @if ($company?->npwp) · NPWP {{ $company->npwp }} @endif
        </div>
    </div>

    @if ($voided)
        <div class="voided">{{ $voided }}</div>
    @endif

    <h1>{{ $title }}</h1>
    <div class="docno">{{ $subtitle }}</div>

    @yield('content')

    <div class="footer">
        Dicetak {{ $printedAt }} dari Nusantara ERP · {{ $subtitle }}
    </div>
</body>
</html>
