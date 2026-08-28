# Persetujuan Eksternal (MK / Owner)

> Status: **DIBANGUN LANGSUNG DI REPO pada paket P0-F** — "patch spike" yang
> disebut ROADMAP-DEVIASI (dokumen ini + `ExternalApprovalService` +
> `ExternalApprovableDocuments`) **tidak pernah diterapkan sebelumnya**; berkas
> ini ditulis bersama implementasinya, bukan sebagai patch terpisah.
> Keputusan pemilik #1 (✅ 22 Agu): MK/Owner memutuskan lewat **tautan
> sekali-pakai** (setuju / setuju dengan catatan / tolak) **atau lembar
> fisik** bertanda tangan.

## Dua pintu, satu tabel bukti

Satu baris `core_external_approvals` adalah **satu mandat untuk satu pihak
atas satu dokumen** — diterbitkan dengan nama orangnya, dan setelah terpakai
baris yang sama membawa keputusannya (`decision`, `decided_at`,
`decided_via`: `link`/`physical`). Bukti penerbitan dan bukti keputusan tidak
bisa saling lepas.

1. **Tautan sekali-pakai** — `POST api/core/external-approvals` menerbitkan
   URL `…/persetujuan/{token}`. Token polos tampil **tepat sekali** di respons
   penerbitan; server hanya menyimpan sha256-nya (`token_hash`, unik). Tautan
   punya masa berlaku (bawaan 7 hari; `expires_at` = sekarang **sudah**
   kedaluwarsa), bisa dicabut selama belum dipakai, dan menutup dirinya pada
   keputusan pertama — dua klik tidak pernah mencatat dua kali (baca ulang
   terkunci di dalam transaksi, idiom TOCTOU rumah).
2. **Lembar fisik** — `POST api/core/external-approvals/record-physical`
   mencatat keputusan dari kertas bertanda tangan: pihak, nama, keputusan,
   tanggal, **wajib** melampirkan scan lembarnya, dan scan itu harus terlampir
   pada **dokumen yang sama** (lampiran dokumen lain ditolak dengan menyebut
   namanya). Pencatatan fisik tidak dibatasi status dokumen — kertas boleh
   pulang terlambat; pada mode transisi aturan Approvable di adapter yang
   tetap memutuskan.

## Registri dan dua mode

`Modules/Core/Support/ExternalApprovableDocuments` — slug → kelas dokumen,
prefix izin, mode, hook. Pola `AttachableDocuments`: slug yang menyeberangi
kawat, kelas tidak pernah; satu-satunya tempat Core menyebut kelas modul.

| Slug | Mode | Efek keputusan |
|---|---|---|
| `projects/daily-reports` | **record** | Keputusan PERTAMA mengisi `locked_at` laporan (hook `DailyReportService::lockFromExternalDecision` — pintu kedua kolom yang sama dengan BAST I; keputusan berikutnya tidak menggeser cap). Status laporan tidak ada, tidak ada yang bertransisi. |
| `crm/contract-change-orders` | **record** | Bukti saja. CCO **tidak** bertransisi — proksi internal tetap penyetujunya (keputusan #6). Tautan hanya terbit saat CCO `submitted` (keputusan #7). |
| `projects/work-permits` | **transisi** | Keputusan eksternal **menggerakkan** izin lewat adapter `WorkPermitService::applyExternalDecision` (bukan trait) — item 🧪 P0-C ditunaikan. Tautan hanya terbit saat `submitted`. |

- **record** (bawaan): keputusan eksternal DICATAT sebagai bukti; transisi
  internal tetap milik proksi (keputusan #6 — 18 dokumen Approvable tidak
  disentuh).
- **transisi**: adapter service di modul pemilik menerapkan keputusan atas
  nama **penerbit tautan** (`issued_by`): setuju / setuju dengan catatan →
  `approve`, tolak → `reject`, dengan catatan yang menyebut keputusan
  eksternal dan pemutus aslinya. Karena identitas penerbit ikut,
  **maker-checker jatuh pada mekanisme rumah**: trait Approvable menolak
  approve oleh pengaju dokumen, maka tautan terbitan si pengaju sendiri tidak
  bisa menyetujui dokumennya — ditolak saat terbit
  (`ExternalApprovalService::issue`) dan sekali lagi saat diterapkan (pengaju
  bisa berganti lewat reject-resubmit di antaranya). Penolakan adapter
  menggulung pencatatan keputusannya ikut: tidak ada "tercatat tetapi tidak
  diterapkan".

## Izin

Menerbitkan, mencabut, dan mencatat lembar fisik = `{prefix}.approve` modul
pemilik dokumen (menerbitkan tautan keputusan adalah kuasa setingkat
menyetujui); membaca daftarnya = `{prefix}.view`. Diturunkan per permintaan
dari registri — pola `AttachmentController`, diperiksa sebelum id
diresolusi supaya tidak menjadi orakel enumerasi.

## Halaman publik

`GET|POST /persetujuan/{token}` — `Modules/Core/Routes/web.php`, dimuat
`CoreServiceProvider` (routes/* akar tidak disentuh), `throttle:10,1`
(preseden rute login), **tanpa** grup `web` (tanpa sesi/cookie/CSRF — token
sekali-pakai di URL adalah kapabilitasnya). Blade mandiri ramah ponsel, CSS
inline, tanpa aset eksternal: ringkasan dokumen (kode, angka kunci milik
modulnya — dari `summarize()` registri), tiga tombol keputusan, kolom catatan.

Kejujuran halaman terminal:

- token tak dikenal → 404 yang tidak menyebut apa pun;
- sudah dipakai → **struk** keputusan yang tercatat (pemutus berhak melihat
  keputusannya sendiri), tidak pernah formulir lagi;
- dicabut / kedaluwarsa → 410 dengan alasan dan kode dokumen, tidak lebih.

Sesudah setiap keputusan tercatat (pintu mana pun), pemegang
`{prefix}.approve` menerima lonceng **"Keputusan eksternal tercatat: …"**
dengan tautan ke dokumennya.

## Kenyataan operasional erp1 (dibaca sebelum mengirim tautan)

Gerbang **Basic Auth nginx di erp1 masih berdiri** dan memblokir akses
anonim: tautan `/persetujuan/{token}` baru benar-benar terbuka untuk MK/Owner
**setelah gerbang itu diturunkan** — dan penurunannya menunggu satu item
pemilik yang masih terbuka: rotasi sandi (lihat catatan "erp1 gate removal
pending"). Sampai hari itu, pintu yang berfungsi penuh di produksi adalah
**lembar fisik**. Jangan mengubah nginx dari aplikasi; itu keputusan dan
keystroke pemilik.

## Yang sengaja TIDAK dilakukan

- Tidak ada user proksi baru dan tidak satu pun dari 18 dokumen Approvable
  diubah trait-nya (keputusan #6 — proksi dipertahankan).
- Token tidak pernah ditulis ke log, audit, atau kolom mana pun selain
  hash-nya; tidak ada endpoint "lihat ulang tautan".
- E-mail ke MK/Owner tidak dikirim otomatis — kolom `email` disimpan sebagai
  arsip untuk siapa tautan diterbitkan; pengirimannya urusan penerbit
  (WhatsApp/e-mail pribadi), karena alamat eksternal bukan akun sistem ini.

## Kekurangan — yang akan dibangun paket berikutnya di atas ini

Berkas ini rujukan yang dikutip paket-paket lanjutan; kekurangan yang sudah
diketahui dicatat di sini supaya perluasannya tidak mengulang desain.

- **Keputusan empat nilai (stempel FM-10).** `ExternalDecision` hari ini tiga
  nilai: Setuju / Setuju dengan catatan / Tolak. P1-ENG membutuhkan stempel MK
  empat nilai untuk `eng_drawing_submittals` & `eng_material_submittals`
  (`approved | approved_as_noted | revise_resubmit | rejected`). Registri sudah
  slug-per-baris, jadi pilihannya — memperluas enum atau memetakan
  `approved_with_notes → approved_as_noted` — jatuh ke P1-ENG, dengan aturan
  roadmap-nya sendiri: *pilih yang lebih jujur dan tulis alasannya*. Tiga
  anggota yang ada tidak perlu berubah.
- **Anggota berikutnya.** Kandidat 🧪 yang roadmap sudah menyebut:
  `eng_drawing_submittals` dan `eng_material_submittals` (P1-ENG, transisi),
  `qc_inspections` (P1-QC, transisi), `prj_progress_measurements` (P3,
  transisi). Harga satu anggota baru: satu baris registri + adapter service di
  modul pemiliknya (mode transisi) atau hook (mode record) + cabang
  `summarize()`. Tabel, halaman publik, dan kartu SPA tidak berubah.
- **Ringkasan dokumen ditulis tangan, per slug.** `summarize()` tidak punya
  fallback yang mencetak kolom sembarang — dengan sengaja: halaman publik tidak
  boleh membocorkan lebih dari yang dipilihkan modulnya. Anggota baru wajib
  menulis ringkasannya sendiri, dan lupa menulisnya berarti halaman keputusan
  tanpa angka kunci, bukan kebocoran.
- **Tidak ada pengingat kedaluwarsa.** Tautan yang tidak dipakai kedaluwarsa
  diam-diam; tidak ada lonceng "tautan akan kedaluwarsa besok". Bila kelak
  dibutuhkan, tempatnya registri tenggat (`WatchedDeadlines`) — bukan cron
  baru.
