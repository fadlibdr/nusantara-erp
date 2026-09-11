{{--
    Halaman penilaian pelanggan (CSAT, F-9) — layar kedua sistem ini yang
    dibuka tanpa login, sesudah /persetujuan/{token}.

    Ditulis untuk LAYAR PONSEL dulu, dengan alasan yang sama seperti halaman
    persetujuan: pelanggan membukanya dari WhatsApp sambil berdiri di lobi.
    CSS inline seluruhnya, tanpa font web, tanpa aset eksternal, tanpa satu
    baris JavaScript — lima tombol yang mengirim formulir adalah lima tombol
    submit, dan halaman yang tidak punya skrip tidak punya skrip yang gagal.

    Empat keadaan, satu berkas supaya gayanya tidak berserak:
      state = form      formulir: ringkasan tiket + 5 tombol skor + komentar
      state = receipt   struk penilaian YANG INI (milik yang menulisnya)
      state = terminal  dicabut / kedaluwarsa / sudah dinilai lewat tautan lain
                        / tiket dibuka kembali — hanya KODE tiket + sebabnya
      state = unknown   token tak dikenal — tidak membocorkan apa pun

    KEJUJURAN YANG DIJAGA DI SINI:
     - halaman terminal membawa KODE tiket dan sebabnya saja: tidak ada judul
       tiket, tidak ada nama penerima undangan, tidak ada skor milik tautan
       lain (pelajaran halaman persetujuan: tautan mati bisa dibuka siapa pun
       yang menerimanya diteruskan);
     - halaman unknown tidak membawa apa pun sama sekali — dua tebakan berbeda
       harus menghasilkan byte yang sama;
     - tidak ada satu kata pun tentang surel: sistem ini tidak mengirim surel
       (MAIL_MAILER=log), jadi halaman ini tidak menjanjikan "kami akan
       mengabari Anda".
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Penilaian Layanan — {{ $company }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            background: #eceff1; color: #263238; line-height: 1.5;
            padding: 16px; min-height: 100vh;
        }
        .kartu {
            background: #fff; max-width: 520px; margin: 0 auto;
            border: 1px solid #cfd8dc; border-radius: 8px;
            box-shadow: 0 1px 6px rgba(0,0,0,.12); overflow: hidden;
        }
        .kop { background: #263238; color: #fff; padding: 14px 18px; }
        .kop .perusahaan { font-weight: bold; font-size: 15px; }
        .kop .judul { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: #b0bec5; margin-top: 2px; }
        .isi { padding: 18px; }
        h1 { font-size: 17px; margin-bottom: 4px; }
        .kode { font-family: "Courier New", monospace; font-weight: bold; }
        .pihak { font-size: 13px; color: #546e7a; margin-bottom: 14px; }
        table.ringkas { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 14px; }
        table.ringkas td { padding: 6px 0; border-bottom: 1px solid #eceff1; vertical-align: top; }
        table.ringkas td.k { width: 42%; color: #546e7a; padding-right: 10px; }
        textarea {
            width: 100%; min-height: 84px; font: inherit; font-size: 14px;
            border: 1px solid #b0bec5; border-radius: 6px; padding: 10px; margin-bottom: 14px;
        }
        .skor {
            display: block; width: 100%; border: 1px solid #b0bec5; border-radius: 6px;
            background: #fff; cursor: pointer; font: inherit; font-size: 16px;
            padding: 13px 14px; margin-bottom: 8px; text-align: left; color: #263238;
        }
        .skor .angka {
            display: inline-block; min-width: 30px; font-weight: bold; font-size: 17px;
            color: #37474f;
        }
        .skor-5, .skor-4 { border-color: #2e7d32; }
        .skor-5 .angka, .skor-4 .angka { color: #2e7d32; }
        .skor-3 { border-color: #ef6c00; }
        .skor-3 .angka { color: #ef6c00; }
        .skor-2, .skor-1 { border-color: #c62828; }
        .skor-2 .angka, .skor-1 .angka { color: #c62828; }
        .galat { background: #ffebee; border: 1px solid #ef9a9a; color: #b71c1c;
            border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 14px; }
        .stempel {
            display: inline-block; border: 2px solid; border-radius: 6px;
            padding: 8px 14px; font-weight: bold; font-size: 16px; margin: 6px 0 12px;
        }
        .stempel.baik { color: #2e7d32; border-color: #2e7d32; }
        .stempel.cukup { color: #ef6c00; border-color: #ef6c00; }
        .stempel.buruk { color: #c62828; border-color: #c62828; }
        .rinci { font-size: 13px; color: #546e7a; }
        .rinci b { color: #263238; }
        .pesan { font-size: 15px; margin-bottom: 10px; }
        .kaki { padding: 12px 18px; background: #f5f7f8; border-top: 1px solid #eceff1;
            font-size: 12px; color: #78909c; }
    </style>
</head>
<body>
<div class="kartu">
    <div class="kop">
        <div class="perusahaan">{{ $company }}</div>
        <div class="judul">Penilaian Layanan</div>
    </div>

    <div class="isi">
    @if ($state === 'unknown')
        <h1>Tautan tidak dikenal</h1>
        <p class="pesan">{{ $message }}</p>
        <p class="rinci">Periksa kembali tautan yang Anda terima, atau hubungi kami untuk diterbitkan tautan baru.</p>

    @elseif ($state === 'terminal')
        {{-- KODE tiket saja. Tanpa judul, tanpa nama penerima, tanpa skor: halaman
             terminal bisa dibuka siapa pun yang memegang tautan mati. --}}
        <h1>Tiket <span class="kode">{{ $ticket['code'] ?? '—' }}</span></h1>
        <p class="pesan">{{ $message }}</p>
        <p class="rinci">Tidak ada penilaian yang tercatat lewat halaman ini.</p>

    @elseif ($state === 'receipt')
        <h1>Tiket <span class="kode">{{ $ticket['code'] ?? '—' }}</span></h1>

        @if ($fresh)
            <p class="pesan">Terima kasih — penilaian Anda tercatat.</p>
        @else
            <p class="pesan">Tautan ini sudah Anda gunakan. Penilaian yang tercatat:</p>
        @endif

        {{-- AMBANG "PUAS" DIBACA DARI ENUM, bukan ditulis ulang di sini: sebuah
             `>= 4` yang disalin ke halaman ini membuat stempel pelanggan dan
             ubin "Puas" di ringkasan bisa berselisih tentang satu penilaian
             yang sama pada hari ambangnya digeser. --}}
        @php($nilai = $row->score?->value)
        @php($puas = $row->score?->isSatisfied())
        <div class="stempel {{ $puas ? 'baik' : ($nilai == 3 ? 'cukup' : 'buruk') }}">
            {{ $nilai }} dari 5 — {{ $row->score?->label() }}
        </div>

        <p class="rinci">
            Dicatat: <b>{{ $row->rated_at?->format('d-m-Y H:i') }}</b><br>
            @if ($row->comment)
                Komentar Anda: <b>{{ $row->comment }}</b><br>
            @endif
        </p>
        <p class="rinci">
            Penilaian tidak dapat diubah dari tautan ini. Bila ada yang perlu diperbaiki,
            hubungi kami dan sebutkan nomor tiket di atas.
        </p>

    @else {{-- form --}}
        <h1>Bagaimana layanan kami?</h1>
        <p class="pihak">Untuk: {{ $row->recipient_name }}</p>

        @if ($error)
            <div class="galat">{{ $error }}</div>
        @endif

        <table class="ringkas">
            <tr><td class="k">Nomor tiket</td><td class="kode">{{ $ticket['code'] ?? '—' }}</td></tr>
            <tr><td class="k">Pekerjaan</td><td>{{ $ticket['title'] ?? '—' }}</td></tr>
            @if (($ticket['finished_at'] ?? null))
                <tr><td class="k">Selesai</td><td>{{ $ticket['finished_at'] }}</td></tr>
            @endif
            @if ($row->expires_at)
                <tr><td class="k">Tautan berlaku s/d</td><td>{{ $row->expires_at->format('d-m-Y H:i') }}</td></tr>
            @endif
        </table>

        <form method="post" action="{{ url('penilaian/'.$token) }}">
            <label for="comment" class="rinci" style="display:block; margin-bottom:6px;">
                Komentar (opsional) — tulis dulu di sini, lalu pilih penilaian Anda:
            </label>
            <textarea id="comment" name="comment" maxlength="1000" placeholder="Apa yang sudah baik, dan apa yang perlu kami perbaiki?"></textarea>

            @foreach ($scores as $score)
                <button class="skor skor-{{ $score->value }}" type="submit" name="score" value="{{ $score->value }}">
                    <span class="angka">{{ $score->value }}</span> {{ $score->label() }}
                </button>
            @endforeach
        </form>

        <p class="rinci">
            Tautan ini SEKALI PAKAI: penilaian pertama yang tercatat berlaku dan tidak dapat diubah dari sini.
        </p>
    @endif
    </div>

    <div class="kaki">
        Halaman ini diterbitkan {{ $company }} untuk satu penilaian atas satu tiket layanan.
        Jangan meneruskan tautan kepada pihak lain.
    </div>
</div>
</body>
</html>
