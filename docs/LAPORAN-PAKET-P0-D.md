# Laporan Paket P0-D — Kebijakan lampiran gambar teknik & jadwal

Branch: main (langsung; disiplin feat/<paket> mulai P1) · Commit: 92cf307 ·
28 Agustus 2026

> Laporan ini disusun-ulang 28 Agustus dari pesan commit, pohon kode, dan keluaran
> verifikasi adversarial paketnya — laporan §6 tidak sempat ditulis pada sesinya.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| D8 — Lampiran dwg/mpp/vsd/pptx; "5 MB; `.dwg` ditolak" | ⬜ 🔬 | ✅ untuk daftar asumsi #4: `dwg dxf mpp xml pptx ppt` diterima dengan MIME pin dari `finfo` atas biner asli; batas per ekstensi (dwg/dxf/mpp 25 MB); `vsd` tetap di luar (bukan daftar asumsi #4) | `Modules/Core/Services/AttachmentService.php:60` (`SIZE_LIMITS`), `:97` (`ALLOWED`), `:160` (`storeBinary`); `AttachmentPolicyTest` |
| 3.3 lampiran file gambar (prasyarat P1-ENG: `eng_drawing_submittals` "lampiran file gambar (P0-D)") | terblokir | prasyarat terbuka — dwg/dxf bisa dilampirkan ke dokumen mana pun di `AttachableDocuments` | `tests/fixtures/attachments/` (6 sampel biner asli + `generate.php`) |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

Asumsi #4 dipakai: izinkan `dwg dxf mpp xml pptx ppt`; batas bawaan 5 MB tetap; 25 MB
untuk gambar teknik & jadwal — diimplementasikan sebagai `dwg/dxf/mpp` 25 MB lewat
multipart (25 MB mentah ≈ 33,4 MB base64 melampaui `post_max_size` 26M yang
dideploy). Perlu konfirmasi pemilik: daftar ekstensi & batas final, dan dua penyempitan
sadar —

- DXF BINER ditolak (hanya DXF ASCII): biner sniff `application/octet-stream`, dan
  melebarkan ALLOWED ke octet-stream membuat byte apa pun bisa bersembunyi di balik
  `.dxf`.
- `application/zip` tidak ditambahkan untuk pptx (berbeda preseden docx/xlsx): tidak
  ada pptx asli yang bisa dibuat yang membuat `finfo` build ini menjawab zip.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

Tidak ada migrasi — kebijakan hidup di `AttachmentService` (konstanta `ALLOWED` +
`SIZE_LIMITS`), satu rute multipart baru, dan `api.js`. Tidak ada pertanyaan MySQL.

## Uji

- baru: 22 — `AttachmentPolicyTest` (19 run; satu metode ber-data-provider atas enam
  fixture biner asli di `tests/fixtures/attachments/` — OLE CFB v3 ber-mini-FAT untuk
  ppt/mpp, paket zip OOXML untuk pptx, kerangka DXF ASCII, penanda AC1027 untuk dwg;
  `generate.php` keluar non-nol bila jawaban `finfo` bergeser pada build PHP mendatang)
  dan `AttachmentSpaPolicyTest` (3; uji drift PHP yang membaca sumber JS — pola repo
  untuk SPA tanpa build). Termasuk: PNG bernama .dwg ditolak, HTML bernama .xml ditolak
  menyebut penandanya, HTML di balik prolog XML tetap ditolak, SVG bernama .xml
  ditolak, XHTML berprefiks namespace ditolak (uji regresi temuan), batas per tipe di
  kedua rute, multipart butuh izin `{prefix}.update` modul pemilik, jawaban multipart
  se-bentuk JSON.
- lama yang diubah: tidak ada — pesan batas ukuran mempertahankan frasa "melebihi
  batas" supaya regex `AttachmentTest` lama tetap lolos.
- suite penuh: OK (3.088 uji, 14.069 asersi; run verifikasi terekam 406,1 dtk pada
  3.087/14.068, sebelum satu uji regresi temuan ditambahkan).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Endpoint baru: `POST api/core/attachments/upload` (multipart, field `file`) untuk
berkas > 5 MB; JSON base64 tetap untuk yang kecil; kedua pintu menyatu di
`storeBinary()` sehingga kebijakan tidak bisa berbeda antar pintu; `api.js
uploadFile()` memilih jalur otomatis pada ambang 5 MB.

Ekstensi di luar daftar → 422:

> "Jenis berkas \".{ekstensi}\" tidak diizinkan. Yang diterima: {daftar}."

Melebihi batas per ekstensi → 422:

> "Berkas berukuran {ukuran}, melebihi batas 25 MB untuk berkas .dwg."

HTML menyamar sebagai .xml → 422 menyebut penandanya:

> "Berkas .xml ini terlihat seperti dokumen HTML (diawali {penanda}), bukan data XML.
> Berkas HTML tidak diizinkan."

Isi tidak cocok nama → 422:

> "Isi berkas terbaca sebagai {mime}, tidak cocok dengan ekstensi \".{ekstensi}\".
> Berkas yang isinya berbeda dari namanya ditolak."

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

PANDUAN-PENGGUNA §2.7 (dua plafon dan pesan-pesannya; panduan DXF ASCII untuk pengguna
yang berkas binernya ditolak); PANDUAN-ADMINISTRATOR §11.1 (baris jenis berkas);
README (kebijakan lampiran; "22 jenis dokumen" basi dikoreksi menjadi 31 sesuai
`AttachableDocuments::MAP`); DEPLOYMENT.md (angka yang benar-benar dideploy
25M/26M/26m menggantikan cerita bawaan 8M yang basi; catatan rsync tetap berlaku).
CONVENTIONS, ARCHITECTURE: tidak ada.

## Yang sengaja tidak dikerjakan, dan mengapa

- `vsd` (disebut prompt asal di D8): tidak ada di daftar asumsi #4; menunggu keputusan
  pemilik.
- DXF biner dan pelebaran octet-stream: ditolak sadar (lihat Asumsi).
- `lapangan.js` tetap di jalur foto 5 MB miliknya — foto adalah kelas 5 MB, dan
  fallback nama `foto-<ts>.jpg` untuk jepretan kamera sengaja tidak direplikasi
  `uploadFile()`.
- Impor MPP-XML → WBS: itu P8 (impor ≠ lampiran); paket ini hanya membuat berkasnya
  bisa dititipkan.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

Verifikasi adversarial: 8 konfirmasi, satu temuan — ditutup:

1. XHTML BERPREFIKS NAMESPACE (`<x:html xmlns:x="…xhtml">`) lolos penjaga masquerade
   `.xml` (sniff `text/xml`, regex hanya mengenal tag tanpa prefiks). Bukan XSS
   tersimpan — unduhan attachment + `nosniff` + CSP sandbox menetralkan — tetapi
   penjaga itu ada untuk menolak pengakuan diri seperti itu. Ditutup: regex mengenal
   prefiks namespace; uji regresi
   `test_a_namespace_prefixed_xhtml_masquerading_as_xml_is_refused`.

Ditemukan basi dan diperbaiki dalam paket: DEPLOYMENT.md masih menceritakan
`post_max_size` bawaan 8M padahal yang dideploy 26M; README masih menyebut "22 jenis
dokumen" padahal registri berisi 31.
