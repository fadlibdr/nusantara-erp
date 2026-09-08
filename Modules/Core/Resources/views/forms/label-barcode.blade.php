{{--
    LEMBAR LABEL BARCODE — F/LBL (F-6).

    TIDAK MEWARISI forms.layout, DAN ITU KEPUTUSAN. Layout itu adalah kertas
    yang DITANDATANGANI: pita empat pihak (PEMILIK / KONSULTAN MK / PROYEK /
    KONTRAKTOR), blok identitas sepuluh baris, tiga kolom tanda tangan. Lembar
    ini bukan salah satunya — ia kisi stiker yang digunting dan ditempel di rak,
    dan mencetaknya di bawah kop proyek akan menghasilkan kertas yang tidak bisa
    dipakai siapa pun: setiap stiker akan membawa kop, atau kopnya hanya di
    halaman pertama dan stikernya tidak sejajar.

    TETAPI `.lembar` TETAP DIPAKAI, DAN ITU KONTRAK — bukan gaya. print.js
    menunggu `tab.document.querySelector('.lembar')` sebelum memanggil
    tab.print(): readyState saja tidak cukup, karena about:blank sudah
    'complete' dan yang tercetak akan menjadi halaman penampung "Menyiapkan
    formulir…". Tanpa pembungkus ini, lembar 12 stiker benar-benar tergambar di
    tab barunya dan dialog cetak TIDAK PERNAH muncul — satu-satunya formulir
    rumah yang begitu, tanpa satu pun pesan, yang di gudang terbaca sebagai
    "tombol cetaknya rusak".

    LEBAR MODUL CETAK DIHITUNG DI PHP, BUKAN DISERAHKAN KE CSS. `.stiker`
    selebar yang dipilih FormPrintService::labelGeometry(), dan SVG-nya membawa
    lebar dalam MILIMETER yang persis sama dengan lebar isi kotak itu. Versi
    pertama lembar ini memakai `max-width: 100%` dan menyerahkan ukurannya
    kepada tata letak: stikernya tetap 62 mm dan GAMBARNYA yang dikecilkan —
    2,8% untuk barcode 100 karakter, modul 0,055 mm, tidak terbaca satu pun
    garis pindai pada raster 600 dpi. Sekarang kisinya yang jatuh ke dua atau
    satu kolom, dan kode yang tetap tidak muat DITOLAK dengan kalimatnya.

    ZONA TENANG IKUT KE DALAM SVG, bukan diserahkan ke tata letak halaman ini:
    sebuah `overflow: hidden` atau kotak yang lebih sempit daripada gambarnya
    akan memotong batang tepi dan pemindai gagal DIAM-DIAM.

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

        .lembar { max-width: 194mm; margin: 0 auto; }

        .kepala { margin-bottom: 4mm; }
        .kepala h1 { font-size: 11pt; margin: 0 0 1mm; letter-spacing: .06em; text-decoration: underline; }
        .kepala .sub { font-size: 8pt; }
        .kepala .sub b { font-weight: bold; }

        .catatan {
            border: .7pt solid #000; padding: 2mm; margin-bottom: 4mm;
            font-size: 8pt; line-height: 1.35;
        }
        .catatan .ganda { display: block; margin-top: 1.5mm; }

        .kisi { display: flex; flex-wrap: wrap; gap: 3mm; }

        .stiker {
            border: .5pt dashed #666;
            /* 2,5 mm kiri-kanan, dan angka ini IKUT dihitung labelGeometry():
               lebar isi kotak = lebar stiker − 2 × padding, dan itulah lebar
               milimeter yang dibawa SVG-nya. Mengubah salah satunya tanpa yang
               lain membuat modul cetak berbeda dari yang tertulis di catatan. */
            padding: 2.5mm;
            width: {{ $geometry['sticker_mm'] ?? 62.0 }}mm;
            text-align: center;
            break-inside: avoid; page-break-inside: avoid;
        }
        .stiker .nama { font-size: 8pt; font-weight: bold; line-height: 1.2; margin-bottom: 1mm; }
        .stiker .satuan { font-size: 7pt; margin-bottom: 1.5mm; }
        /*
            TANPA `max-width: 100%` DAN TANPA `height: auto`, dan itu justru
            penjaganya: SVG-nya sudah membawa lebar milimeter yang sama dengan
            lebar isi kotak ini, jadi keduanya tidak akan pernah menggigit —
            dan seandainya suatu hari menggigit, angka lebar modul yang
            dicetak di kotak catatan menjadi bohong tanpa satu pun tanda.
            Gambar yang melebihi kotaknya terlihat; gambar yang dikecilkan
            diam-diam tidak.
        */
        .stiker svg { display: block; margin: 0 auto; }

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
<div class="lembar">
    <div class="kepala">
        <h1>{{ $formTitle }}</h1>
        <div class="sub">
            <b>{{ $item->code }}</b> — {{ $item->name }}@if ($item->category?->name) · {{ $item->category->name }}@endif
        </div>
    </div>

    @if ($svg)
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
            {{--
                ANGKA YANG BISA DIPERIKSA OPERATORNYA, bukan larangan yang
                lembarnya sendiri langgar. Versi pertama mencetak "jangan
                memperkecil" di atas barcode yang sudah ia perkecil sendiri
                sampai 2,8%; sekarang lebar modul yang BENAR-BENAR tercetak
                disebut, dan lembarnya tidak pernah lagi mengecilkannya.
            --}}
            Simbologi Code 128, lebar modul <b>{{ number_format($geometry['module_mm'], 3, ',', '.') }} mm</b>
            (minimum terpindai {{ number_format(\Modules\Core\Support\Code128::MIN_MODULE_MM, 2, ',', '.') }} mm),
            tinggi batang {{ number_format($geometry['bar_height_mm'], 1, ',', '.') }} mm,
            {{ $geometry['columns'] }} stiker per baris.
            Jangan memperkecil, memfotokopi mengecil, memotong, atau menempelkan apa pun pada ruang kosong
            di kiri dan kanan batang — ruang itu yang dipakai pemindai untuk menemukan tepi kode.
            @if ($sharedWith)
                <span class="ganda"><b>Kode ini tidak unik.</b> {{ $encoded }} juga dipakai
                    {{ implode(', ', $sharedWith) }}. Memindai stiker ini akan memulangkan lebih dari satu item,
                    dan layar Pindai Barcode akan meminta orangnya memilih sendiri. Perbaiki kolom Barcode di layar
                    Item lebih dulu bila kedua kartu itu memang barang yang berbeda.</span>
            @endif
        </div>
    @elseif ($supported)
        {{--
            ATURAN KEJUJURAN, SEBAB KEDUA: kodenya bisa dikodekan, tetapi tidak
            pada lebar yang masih terpindai. Menyusutkan gambarnya sampai muat
            adalah persis kegagalan yang lembar ini dulu punya, dan ia gagal
            DIAM-DIAM.
        --}}
        <div class="catatan">
            <b>Barcode tidak dicetak.</b> Kode <b>{{ $encoded }}</b> ({{ mb_strlen($encoded) }} karakter)
            membutuhkan {{ \Modules\Core\Support\Code128::moduleCount($encoded) }} modul, dan bahkan pada satu
            stiker selebar halaman lebar modulnya jatuh di bawah
            {{ number_format(\Modules\Core\Support\Code128::MIN_MODULE_MM, 2, ',', '.') }} mm — batangnya akan
            menyatu saat dicetak dan tidak ada pemindai yang bisa membacanya. Stiker di bawah tetap dicetak
            dengan garis untuk ditulis tangan. Pakai barcode pemasok yang lebih pendek, atau kode item ini
            sendiri, lalu cetak ulang lembar ini.
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
                @if ($svg)
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
</div>
</body>
</html>
