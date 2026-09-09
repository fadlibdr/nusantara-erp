# Sikap terhadap tanda tangan elektronik (e-sign)

> **Status: DIPUTUSKAN, dan yang diputuskan adalah TIDAK MEMBANGUN.**
> Paket: HM **F-8** (ROADMAP-HASHMICRO Fase 2, baris 215), 9 September 2026.
> Berkas ini adalah keluaran paket itu. Nol kolom basis data, nol endpoint,
> nol pustaka, nol dependensi Composer/npm ditambahkan untuk e-sign.

## 1. Keputusan, satu kalimat

Nusantara ERP **mempertahankan dua mekanisme persetujuan yang sudah ada** —
**tautan persetujuan eksternal sekali-pakai** dan **tanda tangan basah di atas
formulir cetak** — dan **MENOLAK, untuk sekarang, integrasi PSrE**
(Penyelenggara Sertifikasi Elektronik) beserta seluruh turunannya: sertifikat
digital per penandatangan, e-Materai, dan tanda tangan elektronik
tersertifikasi.

Alasan penolakannya bukan teknis. Ia komersial dan operasional, dan tertulis
di §4.

## 2. Apa yang SUDAH ada — dan karena itu tidak perlu diganti

Pertanyaan yang biasanya dijawab dengan "beli e-sign" — *bagaimana pihak di
luar perusahaan menyetujui sebuah dokumen, dan apa buktinya?* — sudah punya
jawaban yang dibangun, diuji dan dipakai di repo ini.

### 2.1 Tautan persetujuan eksternal sekali-pakai (P0-F)

`Modules/Core/Services/ExternalApprovalService.php` +
`Modules/Core/Support/ExternalApprovableDocuments.php` +
tabel `core_external_approvals`. Dokumen lengkapnya:
[`PERSETUJUAN-EKSTERNAL.md`](PERSETUJUAN-EKSTERNAL.md).

Yang sudah dimilikinya, dan yang biasanya dijual sebagai fitur e-sign:

| Kemampuan | Di repo ini |
|---|---|
| Undangan per pihak, atas nama orang tertentu | satu baris `core_external_approvals` = satu mandat untuk satu pihak atas satu dokumen, diterbitkan dengan nama orangnya |
| Tautan yang tidak bisa ditebak dan tidak bisa dipakai ulang | token polos tampil **tepat sekali** di respons penerbitan; server hanya menyimpan `sha256` (`token_hash`, unik); baris menutup dirinya pada keputusan pertama, dengan baca-ulang terkunci di dalam transaksi |
| Masa berlaku undangan | `expires_at`, bawaan 7 hari |
| Pencabutan | selama belum dipakai |
| Keputusan bernuansa | Setuju / Setuju dengan catatan / Tolak, plus catatan bebas |
| Jejak siapa-kapan-lewat-apa | `decision`, `decided_at`, `decided_via` (`link`/`physical`), `issued_by` |
| Pemisahan tugas | penerbit tautan tidak bisa menyetujui dokumen yang ia ajukan sendiri — ditolak saat terbit DAN sekali lagi saat diterapkan |

### 2.2 Tanda tangan basah, dan scan-nya sebagai bukti

`POST api/core/external-approvals/record-physical` mencatat keputusan dari
**kertas bertanda tangan**: pihak, nama, keputusan, tanggal, dan **wajib**
melampirkan pindaian lembarnya — pindaian yang harus terlampir pada **dokumen
yang sama** (lampiran dokumen lain ditolak dengan menyebut namanya).

Sisi cetaknya ada di registri formulir rumah
(`Modules/Core/Support/PrintableDocuments`, aturan di
[`CONVENTIONS.md`](CONVENTIONS.md) dan
[`PANDUAN-PENGGUNA.md`](PANDUAN-PENGGUNA.md) §13.5): formulir dicetak dengan
kolom tanda tangan, dan **sel tanpa sumber data tetap bergaris kosong** — tidak
pernah diisi tebakan. Itulah yang membuat lembar fisik tetap menjadi dokumen
yang layak ditandatangani.

### 2.3 Yang mengelilingi keduanya

- **Matriks persetujuan** (F-1) — ambang nilai, mode `single_director` /
  `extra_level`, tingkat-3, distempel di baris `submitted` sehingga tidak
  retroaktif.
- **Delegasi "a.n."** (F-1) — `core_approval_delegations`, dengan cetakan
  "Budi a.n. Sari" dan larangan menyetujui yang diajukan diri sendiri maupun
  pemberi delegasi.
- **Jejak audit** — `core_audit_logs` atas perubahan yang menyentuh
  persetujuan.

Gabungan ini sudah menjawab pertanyaan hukum praktis yang paling sering
diajukan pemilik proyek: *siapa menyetujui, kapan, atas dokumen versi mana,
dan mana buktinya.*

## 3. Apa yang DITOLAK sekarang — daftar yang eksplisit

Supaya penolakan ini tidak bisa disalahartikan sebagai "belum sempat":

1. **Integrasi PSrE** mana pun (penyelenggara sertifikasi elektronik terdaftar
   di Indonesia) — penerbitan sertifikat digital per penandatangan, alur
   verifikasi identitas (KYC/liveness), dan API tanda tangannya.
2. **Tanda tangan elektronik tersertifikasi** pada dokumen keluaran ERP ini
   (PDF bertanda tangan digital, LTV, timestamping).
3. **e-Materai** (bea meterai elektronik) dan pembeliannya lewat distributor.
4. **Kolom basis data untuk tanda tangan** — tidak ada `signature_image`,
   `signature_hash`, `certificate_serial` atau sejenisnya yang ditambahkan
   pada tabel mana pun.
5. **Tanda tangan gambar tangan di layar** ("draw your signature") yang
   disimpan sebagai gambar dan ditempel ke PDF. Ini yang paling menggoda karena
   paling murah dibangun, dan justru ia yang **paling berbahaya**: ia
   *terlihat* seperti tanda tangan yang mengikat tanpa membawa satu pun sifat
   yang membuat tanda tangan mengikat — tidak ada verifikasi identitas
   penandatangan, tidak ada pengikatan ke isi dokumen, tidak ada cara
   membuktikan gambar itu tidak disalin dari dokumen lain. Sebuah fitur yang
   membuat orang merasa aman tanpa membuat mereka aman lebih buruk daripada
   tidak ada fitur.
6. **Pustaka kripto/PDF-signing apa pun** — tidak ada dependensi Composer atau
   npm baru; SPA tetap ES modules vanilla tanpa build step, dan aturan
   tanpa-CDN tetap dipaku `tests/Feature/Core/VendorManifestTest`.

## 4. Mengapa ditolak sekarang

**Biayanya berulang dan per-transaksi, bukan sekali bayar.** PSrE dijual dengan
kontrak korporat plus tarif per tanda tangan atau per sertifikat, dan
e-Materai dibeli per keping. Sebuah ERP kontraktor menandatangani ratusan
dokumen sebulan (PO, SPK, opname, BAST, berita acara); menyalakan e-sign
berarti menambahkan biaya variabel ke setiap dokumen itu — keputusan anggaran
pemilik, bukan keputusan paket pengembangan.

**Setiap penandatangan harus di-onboard lebih dulu.** Tanda tangan
tersertifikasi menuntut sertifikat atas nama orang, yang menuntut verifikasi
identitas orang itu. Pihak yang paling sering harus menandatangani dokumen di
sini adalah **pihak luar** — MK, wakil pemberi tugas, subkontraktor, mandor —
dan tidak satu pun dari mereka bisa dipaksa perusahaan ini untuk menempuh KYC
sebuah penyedia. Tanpa mereka, e-sign hanya berlaku di dalam perusahaan, yaitu
persis tempat matriks persetujuan internal sudah bekerja.

**Nilai hukum yang ditambahkannya adalah gradasi, bukan sakelar.** Regulasi
Indonesia (UU 11/2008 sebagaimana diubah UU 19/2016, dan PP 71/2019) mengenal
tanda tangan elektronik **tersertifikasi** dan **tidak tersertifikasi**;
keduanya diakui, dan yang tersertifikasi diberi kekuatan pembuktian yang lebih
kuat. Artinya bukti yang ada hari ini — tautan sekali-pakai beridentitas
penerima + jejak waktu + pindaian lembar bertanda tangan — bukan nol, dan
lompatan ke tersertifikasi adalah peningkatan derajat pembuktian. Apakah
derajat itu perlu dibeli **adalah pertanyaan untuk penasihat hukum pemilik**,
bukan untuk dokumen ini; berkas ini hanya mencatat bahwa pertanyaannya belum
pernah diajukan kepada mereka.

**Tidak ada satu pun permintaan nyata yang tercatat.** Belum ada pemberi tugas
yang menolak lembar basah, belum ada dokumen yang macet karena tanda tangannya
tidak elektronik, dan tidak ada baris di
[`LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md`](LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md)
maupun [`ANALISIS-PROSES-BISNIS-2026-09.md`](ANALISIS-PROSES-BISNIS-2026-09.md)
yang mengukur kerugiannya. Membangun integrasi berbayar untuk masalah yang
belum terukur adalah cara termahal untuk salah.

## 5. Syarat peninjauan ulang — apa yang harus dipenuhi PEMILIK

Penolakan ini **bukan permanen**. Ia dicabut ketika pemilik dapat menjawab
keempat hal berikut; sebelum itu, paket mana pun yang menyentuh e-sign berjalan
di luar mandat.

1. **Penyedia dan anggaran yang disebut namanya.** Satu PSrE terdaftar, satu
   angka tarif (per tanda tangan atau langganan), dan satu plafon biaya per
   bulan — bentuk jawaban yang sama dengan keputusan pemilik #7 untuk penyedia
   WhatsApp (ROADMAP-HASHMICRO §7).
2. **Pemicu bisnis yang nyata.** Sedikitnya satu dokumen yang tertahan karena
   tanda tangannya harus elektronik — pemberi tugas yang memintanya secara
   tertulis, atau lelang yang mensyaratkannya — dengan nomor dokumen dan
   tanggalnya, bukan kekhawatiran umum.
3. **Daftar dokumen yang ikut, dan yang tidak.** e-sign untuk SELURUH 28 jenis
   dokumen adalah biaya yang tidak akan disetujui siapa pun. Yang dibutuhkan
   daftar pendek (kandidat wajar: kontrak, addendum/CCO, BAST, SPK) plus
   pernyataan eksplisit bahwa sisanya tetap basah.
4. **Kesiapan penandatangan luar.** Konfirmasi bahwa pihak luar yang harus
   menandatangani bersedia menempuh verifikasi identitas penyedia itu — karena
   tanpa mereka, yang dibeli hanyalah tanda tangan internal.

Ditambah satu syarat teknis rumah: **e-sign tidak boleh menjadi mekanisme
persetujuan kedua.** Ia harus menumpang mandat `core_external_approvals` yang
sudah ada (satu baris = satu mandat = satu keputusan), bukan melahirkan tabel
keputusan kedua yang harus disinkronkan dengan yang pertama. Salinan kedua
sebuah aturan adalah penyimpangan yang paling mahal di repo ini — kalimat itu
sudah ditulis untuk `ModuleCounts` dan berlaku sama di sini.

## 6. Di mana penolakan tertulis lain berada — polanya sama

Repo ini sudah punya kebiasaan menolak secara tertulis alih-alih diam, dan
sudah pernah mencabut satu penolakan lewat jalur resmi. F-8 mengikuti pola itu.

| Yang ditolak | Tertulis di | Nasibnya |
|---|---|---|
| WhatsApp, portal pelanggan, multi-valuta, peminjaman alat kecil, bank host-to-host, aplikasi native, multi-tenant | [`ROADMAP-DEVIASI.md`](ROADMAP-DEVIASI.md) §0 batas 5 | **WhatsApp dicabut** oleh keputusan pemilik lewat paket P-3a T3a.0 (ROADMAP-HASHMICRO baris 139); sisanya tetap ditolak (baris 275) |
| Tanda tangan elektronik tersertifikasi / e-Materai, pengiriman tautan otomatis | [`LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md`](LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md) baris 331, "Sengaja tidak dibangun" | dipertegas dan dirinci di sini |
| Kalender fiskal auto-create | [`LAPORAN-DEVIASI.md`](LAPORAN-DEVIASI.md) baris 97 | ditolak tertulis, diganti cron + tombol + notifikasi |
| Biaya bank di luar AP | [`LAPORAN-DEVIASI.md`](LAPORAN-DEVIASI.md) baris 119 | ditolak langsung |

Jalur pencabutannya juga sama dengan WhatsApp: **satu paket khusus yang
tugas pertamanya adalah mencabut penolakan ini secara tertulis**, sesudah
keempat syarat §5 terjawab. Bukan satu commit yang diam-diam menambahkan
kolom.

## 7. Yang TIDAK dikerjakan di F-8, dan cara memeriksanya

| Klaim | Cara memeriksa |
|---|---|
| Tidak ada kolom tanda tangan yang ditambahkan | `git diff main...feat/phase2-f8 -- '*/Database/Migrations/*'` — satu berkas, satu kolom, `core_attachments.valid_until` |
| Tidak ada dependensi baru | `git diff main...feat/phase2-f8 -- composer.json composer.lock package.json` — kosong |
| Tidak ada endpoint e-sign | `git diff main...feat/phase2-f8 -- 'Modules/*/Routes/api.php'` — satu rute, `PATCH core/attachments/{id}` |
| Tidak ada pustaka vendor SPA baru | `tests/Feature/Core/VendorManifestTest` tetap hijau; isi `public/app/vendor/` tidak berubah |
