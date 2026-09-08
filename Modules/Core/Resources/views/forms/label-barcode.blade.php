{{--
    LEMBAR LABEL BARCODE — F/LBL (F-6).

    TIDAK MEWARISI forms.layout, DAN ITU KEPUTUSAN. Layout itu adalah kertas
    yang DITANDATANGANI: pita empat pihak (PEMILIK / KONSULTAN MK / PROYEK /
    KONTRAKTOR), blok identitas sepuluh baris, tiga kolom tanda tangan. Lembar
    ini bukan salah satunya — ia kisi stiker yang digunting dan ditempel di rak,
    dan mencetaknya di bawah kop proyek akan menghasilkan kertas yang tidak bisa
    dipakai siapa pun: setiap stiker akan membawa kop, atau kopnya hanya di
    halaman pertama dan stikernya tidak sejajar.

    ZONA TENANG IKUT KE DALAM SVG, bukan diserahkan ke tata letak halaman ini.
    Sebuah `overflow: hidden` atau lebar kotak yang lebih sempit daripada
    gambarnya akan memotong batang tepi dan pemindai gagal DIAM-DIAM — jadi
    kotak stiker di bawah tidak pernah memotong: gambarnya diberi lebar penuh
    dan kotaknya yang menyesuaikan.

    print-color-adjust: exact — Chrome membuang latar saat mencetak, dan batang
    hitam di atas latar yang hilang tetap hitam, tetapi stiker yang kehilangan
    garis potongnya tidak bisa digunting lurus.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $formTitle }} — {{ $item->code }}</title>
    <style>
        @page { size: A4 portrait; margin: 10mm 8mm 11mm 8mm; }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt; color: #000; background: #fff;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        .kepala { margin-bottom: 4mm; }
        .kepala h1 { font-size: 11pt; margin: 0 0 1mm; letter-spacing: .06em; text-decoration: underline; }
        .kepala .sub { font-size: 8pt; }
        .kepala .sub b { font-weight: bold; }

        .catatan {
            border: .7pt solid #000; padding: 2mm; margin-bottom: 4mm;
            font-size: 8pt; line-height: 1.35;
        }

        .kisi { display: flex; flex-wrap: wrap; gap: 3mm; }

        .stiker {
            border: .5pt dashed #666;
            padding: 2.5mm;
            width: 62mm;
            text-align: center;
            break-inside: avoid; page-break-inside: avoid;
        }
        .stiker .nama { font-size: 8pt; font-weight: bold; line-height: 1.2; margin-bottom: 1mm; }
        .stiker .satuan { font-size: 7pt; margin-bottom: 1.5mm; }
        .stiker svg { display: block; margin: 0 auto; max-width: 100%; height: auto; }

        /* Stiker tanpa batang: garis untuk ditulis tangan, bukan kotak kosong. */
        .stiker .tanpa-barcode {
            border-bottom: .7pt solid #000; height: 11mm; margin: 1mm 2mm 1.5mm;
        }
        .stiker .kode-tangan { font-family: monospace; font-size: 9pt; }

        .kaki { margin-top: 5mm; font-size: 7.5pt; display: flex; justify-content: space-between; }

        @media print { .kisi { gap: 2.5mm; } }
    </style>
</head>
<body>
    <div class="kepala">
        <h1>{{ $formTitle }}</h1>
        <div class="sub">
            <b>{{ $item->code }}</b> — {{ $item->name }}@if ($item->category?->name) · {{ $item->category->name }}@endif
        </div>
    </div>

    @if ($supported)
        {{--
            SATU DIREKTIF PER BARIS, dan itu bukan selera tata letak.

            Blade mencocokkan direktif dengan `\B@`, jadi sebuah `@else` yang
            didahului huruf ("…berbeda@else") BUKAN direktif: ia lolos sebagai
            teks biasa, cabang `@if` di atasnya menelan sisa berkas, dan
            seluruh lembar gagal dengan "unexpected end of file, expecting
            elseif". Kegagalan itu terjadi pada versi pertama berkas ini.
        --}}
        <div class="catatan">
            Yang dikodekan pada batang di bawah adalah <b>{{ $encoded }}</b>,
            @if ($encodedFromSupplierBarcode)
                yaitu <b>barcode pemasok</b> yang tercatat pada kartu item ini — bukan kode item
                {{ $item->code }}. Keduanya dicetak sebagai teks di bawah batang, karena yang dipindai
                mesin dan yang dicari orang di layar adalah dua hal yang berbeda.
            @else
                yaitu <b>kode item</b>-nya sendiri; kartu item ini belum mencatat barcode pemasok.
            @endif
            Simbologi Code 128. Jangan memperkecil, memotong, atau menempelkan apa pun pada ruang kosong
            di kiri dan kanan batang — ruang itu yang dipakai pemindai untuk menemukan tepi kode.
        </div>
    @else
        {{--
            ATURAN KEJUJURAN. Barcode yang dicetak dari teks yang tidak bisa
            dikodekan bukan sel kosong melainkan gambar yang SALAH, dan gambar
            yang salah terbaca sebagai kode LAIN. Jadi stikernya tetap dicetak
            — orang gudang tetap butuh label di raknya — tetapi tanpa batang,
            dengan garis untuk menuliskan kodenya sendiri.
        --}}
        <div class="catatan">
            <b>Barcode tidak dicetak.</b> Kode <b>{{ $encoded }}</b> memuat karakter yang tidak bisa
            dijadikan Code 128 (hanya huruf, angka, dan tanda baca ASCII biasa yang bisa). Stiker di bawah
            tetap dicetak dengan garis untuk ditulis tangan; perbaiki kode atau barcode item ini di layar
            Item lebih dulu, lalu cetak ulang lembar ini.
        </div>
    @endif

    <div class="kisi">
        @for ($i = 0; $i < $count; $i++)
            <div class="stiker">
                <div class="nama">{{ $item->name }}</div>
                <div class="satuan">{{ $item->code }}@if ($item->unit) · satuan {{ $item->unit }}@endif</div>
                @if ($supported)
                    {!! $svg !!}
                @else
                    <div class="tanpa-barcode"></div>
                    <div class="kode-tangan">{{ $encoded }}</div>
                @endif
            </div>
        @endfor
    </div>

    <div class="kaki">
        <span>{{ $formCode }}@if ($company?->name) · {{ $company->name }}@endif</span>
        <span>{{ $count }} label · dicetak {{ $printedAt }}</span>
    </div>
</body>
</html>
