# Laporan Paket P0-F — Tombol "Terbitkan tautan" dan kartu Persetujuan Eksternal di SPA

Branch: feat/p0-f · Commit: belum ada — worktree **sengaja** belum di-commit: pohon
kerja ini dipakai beberapa lane sekaligus dan `git add -A` pernah menyapu pohon sesi
tetangga (preseden a20dbf0); penyatuan commit paket milik orkestrator ·
28 Agustus 2026

> **Dinyatakan gamblang, karena roadmap berasumsi sebaliknya:** "patch spike"
> persetujuan eksternal yang disebut ROADMAP-DEVIASI (§1 butir 8, catatan 🧪 P0-C
> dan judul P0-F) **tidak pernah diterapkan** — tidak ada berkas patch yang bisa
> dipulihkan. `ExternalApprovalService`, `ExternalApprovableDocuments`, tabel
> `core_external_approvals`, halaman publik `/persetujuan/{token}`, dan
> `docs/PERSETUJUAN-EKSTERNAL.md` **diautori di paket ini** dari spesifikasi
> roadmap yang tersebar, lalu kartu SPA P0-F dibangun di atasnya dalam paket yang
> sama.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| Keputusan pemilik #1 (✅ 22 Agu): MK/Owner memutuskan via tautan sekali-pakai atau lembar fisik — prasyarat "patch spike" yang diasumsikan roadmap | tidak pernah diterapkan | ✅ **diautori** di paket ini: registri dua mode, service empat aturan, tabel bukti, halaman publik | `Modules/Core/Support/ExternalApprovableDocuments.php`; `Modules/Core/Services/ExternalApprovalService.php`; `Modules/Core/Database/Migrations/2026_08_28_000180_…`; `tests/Feature/Core/ExternalApprovalTest.php` (9 uji), `ExternalApprovalPublicTest.php` (14 uji) |
| P0-F: kartu `externalApprovalsCard` + tombol Terbitkan tautan / Cabut / Catat tanda tangan fisik di Laporan Harian, CCO, Izin Kerja | ⬜ | ✅ pola `attachmentsCard`; URL tampil sekali dengan tombol salin | `public/app/js/views/external.js` (baru); `public/app/js/views/detail.js`; `tests/Feature/Core/ExternalApprovalRegistryTest.php` (4 uji) |
| Item 🧪 P0-C yang ditunda: `prj_work_permits` mode **transisi** — MK menyetujui izin kerja lewat adapter service, bukan trait | ⬜ | ✅ | `Modules/Projects/Services/WorkPermitService.php:99` (`applyExternalDecision`); `test_an_external_approval_transitions_the_work_permit_through_the_adapter`, `test_an_external_rejection_rejects_the_work_permit` |
| Seam `locked_at` P0-A: keputusan eksternal PERTAMA mengunci laporan harian (pintu kedua kolom yang sama dengan BAST I) | komentar seam | ✅ | `Modules/Projects/Services/DailyReportService.php:176` (`lockFromExternalDecision`); `test_the_first_decision_locks_the_daily_report_and_the_second_does_not_move_the_clock` |
| P0-F: lonceng "keputusan eksternal tercatat" menaut ke dokumennya | ⬜ | ✅ `#/d/{slug}/{id}`, kosakata `ApprovableDocuments` | `ExternalApprovalService::afterDecision`; `test_approve_holders_are_notified_with_a_link_to_the_document` |
| P0-F: bab baru PANDUAN-PENGGUNA "Persetujuan oleh Pemilik/MK" | ⬜ | ✅ bab 15 — dua pintu, pesan penolakan kata demi kata, peringatan URL-tampil-sekali | `docs/PANDUAN-PENGGUNA.md` §15, daftar isi, §0 |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

Dua asumsi Bagian 2 dipakai sebagaimana bunyinya: **#6** proksi internal
dipertahankan — mode `record` mencatat bukti tanpa menyentuh satu pun dari 18
dokumen Approvable, dan **#7** tautan CCO hanya terbit saat `submitted`.

Celah spesifikasi yang **diputuskan tertulis** di paket ini (komentar registri dan
service memuat alasannya; perlu konfirmasi pemilik):

- **IKL juga hanya `submitted`** untuk penerbitan tautan — mode transisi atas draf
  menghasilkan keputusan yang pasti gagal diterapkan Approvable, dan tautan yang
  pasti gagal bukan tautan yang jujur.
- **Maker-checker mode transisi:** keputusan dari tautan diterapkan **atas nama
  penerbitnya** (`issued_by` menempel di baris), maka pengaju dokumen tidak boleh
  menerbitkan tautan untuk dokumennya sendiri — ditolak saat **terbit** dan
  diperiksa **ulang saat diterapkan** (pengaju bisa berganti lewat reject-resubmit
  di antaranya; penolakan adapter menggulung pencatatan keputusannya ikut).
  Pencatat **lembar fisik** pada mode transisi terkena aturan yang sama. Tunduk
  saklar `approvals.segregation_of_duties` yang sama dengan trait-nya.
- **Lembar fisik tidak dibatasi status dokumen** — kertas boleh pulang terlambat,
  buktinya tetap dicatat; pada mode transisi aturan Approvable di adapter yang
  tetap memutuskan.
- **Masa berlaku bawaan tautan 7 hari**; `expires_at` kiriman penerbit divalidasi
  `after:now`.
- **E-mail hanya arsip.** Sistem tidak mengirim apa pun; pengiriman tautan urusan
  penerbit (bab 15 mengatakannya kepada pengguna).

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

Satu tabel **baru**: `core_external_approvals`
(`2026_08_28_000180`, blok Core 000100–000199 per CONVENTIONS §2). Aman di MySQL
dengan data lama karena hanya `CREATE TABLE`: tidak ada `ALTER`, tidak ada
backfill. `token_hash` `char(64) NULL UNIQUE` — baris lembar fisik tidak punya
token, dan NULL ganda sah di MySQL maupun SQLite. FK `constrained` hanya ke
`users` dan `core_attachments` (preseden `core_attachments`/`core_notifications`);
dokumen pemilik dirujuk `document_slug`+`document_id` **tanpa FK** dengan index
gabungan (aturan lintas-modul CONVENTIONS §3). `core_approvals`,
`core_number_sequences`, `core_attachments` **tidak disentuh**; tidak ada kolom
baru pada tabel modul (`locked_at` sudah ada sejak P0-A). Enum baru
`Modules/Core/Enums/ExternalDecision` (Setuju / Setuju dengan catatan / Tolak).

## Uji

- baru: **27**.
  - `ExternalApprovalTest` (9): izin `{prefix}.approve` saat menerbitkan; token
    polos tampil sekali & hanya hash tersimpan; CCO hanya `submitted`; transisi
    hanya `submitted`; pengaju tidak bisa menerbitkan tautan dokumennya sendiri;
    slug tak dikenal ditolak; cabut & tautan berkeputusan tidak bisa dicabut;
    lembar fisik menuntut lampiran dokumen yang sama; daftar butuh
    `{prefix}.view`.
  - `ExternalApprovalPublicTest` (14): formulir + ringkasan dokumen; token tak
    dikenal tidak membocorkan apa pun; ketiga nilai keputusan tercatat dan
    berstruk; sekali-pakai (klik kedua = struk, bukan formulir); balapan kalah
    pada baca-ulang terkunci; batas kedaluwarsa tepat di detiknya; token dicabut
    ditolak; keputusan pertama mengunci laporan harian & yang kedua tidak
    menggeser cap; keputusan CCO tidak mentransisikan CCO; adapter menyetujui dan
    menolak izin kerja; adapter menolak tautan yang menyetujui-diri; lonceng ke
    pemegang approve; kedua rute publik ter-throttle seperti login.
  - `ExternalApprovalRegistryTest` (4): registri PHP ↔ JS memuat dokumen yang
    sama; setiap slug dirender sebuah layar; layar detail generik memasang
    kartunya; kosakata pihak & keputusan JS = PHP.
- lama yang diubah: **0** — tidak ada.
- suite penuh: **OK (3.125 uji, 14.210 asersi, 06:24)** — run verifikasi lane
  dokumentasi 28 Agu (run lane backend pada pohon yang sama: 06:13). Basis
  pra-paket 3.098 / 14.096 (P0-E); selisih persis 27 uji / 114 asersi paket ini.

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

DoD P0-F meminta screenshot/GIF alur; mesin ini tanpa browser (dan tanpa node),
maka bagian ini memuat **transkrip curl** terhadap `migrate:fresh --seed` pada
sqlite scratch (basis data hidup tidak ditulis), `php artisan serve` lokal.

**Terbit → putuskan → struk (laporan harian, mode record).** `POST
api/core/external-approvals` sebagai admin → 201; token polos hanya di `data.url`,
`token_hash` tidak pernah ikut di respons mana pun:

```
URL: http://127.0.0.1:8099/persetujuan/ldo66riePKcF2LTCsAdOwoKMhkvj7rXh7WPyRw2j
GET  /persetujuan/{token}  → 200: tiga tombol — Setuju · Setuju dengan catatan · Tolak
POST /persetujuan/{token} decision=approved_with_notes
     → 200: "Terima kasih — keputusan Anda tercatat." / "Setuju dengan catatan"
       "Simpan tangkapan layar halaman ini sebagai arsip Anda. Keputusan tidak
        dapat diubah dari tautan ini."
GET  ulang → 200: "Keputusan yang tercatat:" (struk, bukan formulir)
token salah → 404: "Tautan tidak dikenal atau sudah tidak berlaku."
```

Laporan yang terkunci keputusan itu menolak ubah, kata demi kata seperti janji
bab 15:

> "Laporan DRP/2026/03/0001 terkunci oleh keputusan eksternal Setuju dengan
> catatan — Ir. Bambang Priyono (MK) pada 28-08-2026 23:14 — dan tidak dapat
> diubah: yang sudah diputuskan pihak luar bukan draf lagi."

**CCO hanya `submitted` (keputusan #7), dan record berarti tidak bertransisi:**

> "Tautan persetujuan pekerjaan tambah-kurang hanya dapat diterbitkan saat dokumen
> berstatus submitted — saat ini draft." — HTTP 422

Setelah CCO diajukan, tautan Owner terbit; **Tolak** via tautan tercatat — dan
status CCO **tetap `submitted`** (proksi internal tetap penyetujunya, keputusan
pemilik #6).

**Maker-checker mode transisi (IKL).** Project manager membuat + mengajukan
IKL/2026/VIII/0001 lalu mencoba menerbitkan tautannya sendiri:

> "Maker-checker: pengaju izin kerja lapangan ini tidak boleh menerbitkan tautan
> persetujuan eksternal untuk dokumennya sendiri — keputusan dari tautan
> diterapkan atas nama penerbitnya. Minta pemegang izin approve yang lain
> menerbitkannya." — HTTP 422

Admin menerbitkan; **Setuju** via tautan → `GET api/projects/work-permits/1` →
`status=approved` — keputusan eksternal menggerakkan izin lewat adapter.

**Lembar fisik menuntut bukti pada dokumen yang sama.** Scan terlampir pada
DRP/…/0002, dicatatkan ke DRP/…/0003:

> "Lampiran \"scan-lembar-persetujuan-drp2.png\" terpasang pada dokumen lain —
> scan lembar fisik harus dilampirkan pada laporan harian DRP/2026/03/0003 itu
> sendiri sebelum dicatat." — HTTP 422

Pada dokumen yang benar → 201 "Keputusan Setuju dari Hendra Gunawan tercatat dari
lembar fisik."

**Izin.** `sales` (tanpa `prj.approve`) menerbitkan tautan laporan harian →
403 "Anda tidak memiliki izin prj.approve."

**Lonceng.** `GET api/core/notifications` admin memuat empat baris "Keputusan
eksternal tercatat: …" — masing-masing menaut `#/d/{slug}/{id}` dokumennya,
menyebut pihak, nama, organisasi, keputusan, catatan, dan pintunya (via tautan /
via lembar fisik).

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- `docs/PERSETUJUAN-EKSTERNAL.md` — **baru**, ditulis bersama implementasinya
  (bukan dari patch): dua pintu, registri dua mode, siklus token, apa yang tidak
  bisa dilakukan pemegang tautan, catatan gerbang erp1, "Yang sengaja TIDAK
  dilakukan", dan bab "Kekurangan" untuk paket lanjutan (keputusan empat nilai
  P1-ENG, anggota 🧪 berikutnya).
- `PANDUAN-PENGGUNA.md` — bab **15** baru "Persetujuan oleh Pemilik/MK" (dua
  pintu; pesan penolakan kata demi kata; peringatan URL-tampil-sekali; catatan
  jujur produksi), daftar isi, dan dua baris §0 (project-manager,
  finance-manager/direktur → bab 15).
- `PANDUAN-ADMINISTRATOR.md` — §3.5: blok "satu halaman tanpa login"
  (`/persetujuan/{token}`, `throttle:10,1`, sha256-saja, jawaban 404/struk/410);
  §12(a): gerbang nginx kini juga menahan tautan persetujuan — satu biaya lagi
  dari rotasi yang menunggu.
- README, CONVENTIONS, ARCHITECTURE: **tidak ada** — tidak ada modul baru; Core
  menyebut kelas modul hanya di berkas registri, preseden `ApprovableDocuments`
  yang sudah tertulis.

## Yang sengaja tidak dikerjakan, dan mengapa

- **Menerapkan patch spike: tidak pernah terjadi, dan tidak bisa** — patch-nya
  tidak ada di repo maupun di `docs/`. Ketiga artefaknya **diautori** di paket
  ini; roadmap yang menganggapnya sudah terpasang ("bila patch spike ada") kini
  terbaca sebagai terpenuhi, tetapi lewat penulisan, bukan penerapan.
- **Uji jsdom kartu** — DoD menyebutnya opsional; node tidak terpasang di mesin
  ini. Kompensasi: `ExternalApprovalRegistryTest` menguji sumber JS dengan regex
  (registri, kosakata, pemasangan kartu) + transkrip curl di atas.
- **Screenshot/GIF alur** — tidak ada browser; diganti transkrip curl (bagian
  Smoke), dengan sepengetahuan pembaca laporan.
- **Keputusan empat nilai (`approved_as_noted`)** — milik P1-ENG; jalannya
  tertulis di PERSETUJUAN-EKSTERNAL.md bab Kekurangan.
- **E-mail otomatis ke MK/Owner; endpoint "lihat ulang tautan"; user proksi
  baru; menyentuh 18 dokumen Approvable** — masing-masing ditolak desain:
  alamat eksternal bukan akun sistem; token polos tidak tersimpan maka tidak
  bisa ditampilkan ulang; keputusan pemilik #6 mempertahankan proksi.
- **Menyentuh nginx erp1** — gerbang Basic Auth masih berdiri dan **memblokir
  halaman publik ini di produksi**; sampai rotasi sandi (item pemilik yang masih
  terbuka) dijalankan dan gerbang diturunkan, pintu yang berfungsi penuh untuk
  MK/Owner adalah lembar fisik. Dinyatakan di ketiga dokumen; aplikasi tidak
  mengubah nginx.
- **Commit** — worktree dibiarkan tidak ter-commit dengan sengaja (multi-lane;
  preseden a20dbf0). Seluruh berkas paket terdaftar bersih di `git status`.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **Badge lonceng "—" untuk semua notifikasi sistem** —
   `public/app/js/notifications.js:21–25` (`EVENT_LABEL`) hanya memetakan tiga
   event dokumen; event `system.alert` (alarm cadangan, tenggat, tutup buku, dan
   kini keputusan eksternal) dirender badge "—" di `:65`. Kosmetik, sudah berlaku
   untuk semua notifikasi sistem lama; satu entri label memperbaikinya.
2. **README masih memuat angka suite era P0-G** (2.995 uji / 13.610 asersi) —
   sudah dicatat laporan P0-E, masih terbuka; jaraknya kini 3.125 / 14.210.
3. **API menjawab 302, bukan 422, bila klien lupa `Accept: application/json`** —
   perilaku bawaan Laravel pada galat validasi, teramati saat smoke; SPA tidak
   terdampak (`api.js:80` selalu mengirim header), tetapi pemakai curl akan
   melihat redirect bisu. Bukan buatan P0-F; berlaku seluruh API.
