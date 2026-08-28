{{--
    Halaman keputusan MK/Owner — satu-satunya layar tanpa login (P0-F).

    Ditulis untuk LAYAR PONSEL dulu: MK membukanya dari WhatsApp/e-mail di
    lapangan. CSS inline seluruhnya, tanpa font web, tanpa aset eksternal —
    halaman ini harus utuh di ponsel tanpa sinyal bagus, dan tidak boleh
    menyeret aset dari balik gerbang nginx yang mungkin masih berdiri.

    Empat keadaan, satu berkas supaya gayanya tidak berserak:
      state = form      formulir keputusan: ringkasan dokumen + 3 tombol + catatan
      state = receipt   struk keputusan yang sudah tercatat (milik pemutusnya)
      state = terminal  dicabut / kedaluwarsa / ditolak diterapkan — jujur, tanpa formulir
      state = unknown   token tak dikenal — tidak membocorkan apa pun

    Kejujuran: halaman sesudah pakai MENAMPILKAN yang diputuskan (struk),
    tidak pernah menampilkan ulang formulir, dan tidak pernah membawa lebih
    dari kode dokumen pada halaman gagal.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Persetujuan Eksternal — {{ $company }}</title>
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
        .kop {
            background: #263238; color: #fff; padding: 14px 18px;
        }
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
        .tombol { display: block; width: 100%; border: 0; border-radius: 6px; cursor: pointer;
            font: inherit; font-size: 16px; font-weight: bold; color: #fff;
            padding: 14px; margin-bottom: 10px; }
        .setuju { background: #2e7d32; }
        .catatan { background: #ef6c00; }
        .tolak { background: #c62828; }
        .galat { background: #ffebee; border: 1px solid #ef9a9a; color: #b71c1c;
            border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 14px; }
        .stempel {
            display: inline-block; border: 2px solid; border-radius: 6px;
            padding: 8px 14px; font-weight: bold; font-size: 16px; margin: 6px 0 12px;
        }
        .stempel.approved { color: #2e7d32; border-color: #2e7d32; }
        .stempel.approved_with_notes { color: #ef6c00; border-color: #ef6c00; }
        .stempel.rejected { color: #c62828; border-color: #c62828; }
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
        <div class="judul">Persetujuan Eksternal</div>
    </div>

    <div class="isi">
    @if ($state === 'unknown')
        <h1>Tautan tidak dikenal</h1>
        <p class="pesan">{{ $message }}</p>
        <p class="rinci">Periksa kembali tautan yang Anda terima, atau hubungi kontraktor untuk diterbitkan tautan baru.</p>

    @elseif ($state === 'terminal')
        <h1>{{ $label }} <span class="kode">{{ $code }}</span></h1>
        {{-- TANPA baris "Untuk: nama (pihak)": halaman terminal bisa dibuka
             siapa pun yang memegang tautan mati, dan janji desainnya adalah
             tidak membawa lebih dari label + kode dokumen dan alasannya. --}}
        <p class="pesan">{{ $message }}</p>
        <p class="rinci">Tidak ada keputusan yang tercatat lewat halaman ini.</p>

    @elseif ($state === 'receipt')
        <h1>{{ $label }} <span class="kode">{{ $code }}</span></h1>
        <p class="pihak">Diputuskan oleh: {{ $row->name }}@if ($row->organization) — {{ $row->organization }}@endif ({{ $row->partyLabel() }})</p>

        @if (! $fresh)
            <p class="pesan">Tautan ini sudah digunakan. Keputusan yang tercatat:</p>
        @else
            <p class="pesan">Terima kasih — keputusan Anda tercatat.</p>
        @endif

        <div class="stempel {{ $row->decision?->value }}">{{ $row->decision?->label() }}</div>

        <p class="rinci">
            Dicatat: <b>{{ $row->decided_at?->format('d-m-Y H:i') }}</b>
            (via {{ $row->decided_via === 'physical' ? 'lembar fisik' : 'tautan' }})<br>
            @if ($row->decision_notes)
                Catatan: <b>{{ $row->decision_notes }}</b><br>
            @endif
        </p>
        <p class="rinci">Simpan tangkapan layar halaman ini sebagai arsip Anda. Keputusan tidak dapat diubah dari tautan ini.</p>

    @else {{-- form --}}
        <h1>{{ $label }} <span class="kode">{{ $code }}</span></h1>
        <p class="pihak">Dimintakan kepada: {{ $row->name }}@if ($row->organization) — {{ $row->organization }}@endif ({{ $row->partyLabel() }})</p>

        @if ($error)
            <div class="galat">{{ $error }}</div>
        @endif

        <table class="ringkas">
            @foreach ($summary as $baris)
                <tr>
                    <td class="k">{{ $baris['label'] }}</td>
                    <td>{{ $baris['value'] }}</td>
                </tr>
            @endforeach
            @if ($row->expires_at)
                <tr>
                    <td class="k">Tautan berlaku s/d</td>
                    <td>{{ $row->expires_at->format('d-m-Y H:i') }}</td>
                </tr>
            @endif
        </table>

        <form method="post" action="{{ url('persetujuan/'.$token) }}">
            <label for="notes" class="rinci" style="display:block; margin-bottom:6px;">Catatan (wajib diisi bila menyertai keputusan Anda):</label>
            <textarea id="notes" name="notes" maxlength="1000" placeholder="Catatan keputusan…"></textarea>

            <button class="tombol setuju" type="submit" name="decision" value="approved">Setuju</button>
            <button class="tombol catatan" type="submit" name="decision" value="approved_with_notes">Setuju dengan catatan</button>
            <button class="tombol tolak" type="submit" name="decision" value="rejected">Tolak</button>
        </form>

        <p class="rinci">Tautan ini SEKALI PAKAI: keputusan pertama yang tercatat berlaku dan tidak dapat diubah dari sini.</p>
    @endif
    </div>

    <div class="kaki">
        Halaman ini diterbitkan {{ $company }} untuk satu keputusan atas satu dokumen.
        Jangan meneruskan tautan kepada pihak lain.
    </div>
</div>
</body>
</html>
