# Nusantara ERP → "seperti HashMicro" — rencana bertahap

> **Status: DISETUJUI pemilik 5 Sep 2026 — eksekusi menunggu perintah per fase.** Tidak ada
> kode yang disentuh oleh rencana ini. Dokumen ini adalah otoritas paket seperti
> `ROADMAP-DEVIASI.md` sebelumnya: setiap sesi mengerjakan SATU paket, laporan
> `docs/LAPORAN-PAKET-<id>.md`, verifikasi adversarial ganda sebelum merge. Dasar fakta:
> dua eksplorasi baca-saja (SPA & backend) dan tiga desain, 5 Sep 2026.

## Context

Pemilik meminta: "create this app like hashmicro, make a plan first". Jawaban klarifikasi:

- Cakupan: **tampilan & navigasi modern** + **kelengkapan fitur modul** + **integrasi &
  kepatuhan**. BUKAN produk SaaS multi-perusahaan (tidak ada multi-company/tenant/langganan).
- Platform: pemilik minta **pro-kontra antara** (1) tetap SPA vanilla + MySQL + antrean +
  pustaka UI ringan yang di-vendor, dan (3) tulis ulang front-end dengan framework + build.
- Eksekusi: **rencana saja dulu**; kode tidak disentuh sampai ada perintah per fase.

Keadaan hari ini (diukur 5 Sep 2026): 14 modul, 776 rute API, 92 layar deklaratif +
layar khusus, 121 tautan sidebar, ~35.000 baris JS vanilla (tanpa build), 1.503 baris CSS,
62 formulir cetak, 3.768 uji, SQLite di produksi (docker-compose prod sudah memuat MySQL),
QUEUE_CONNECTION=database tanpa worker, MAIL_MAILER=log, tanpa PWA, tanpa kanban/gantt,
grafik SVG tangan (EVM, proyek, harga satuan), 1 tabel perusahaan (bukan multi-company),
audit log ada + layar, lampiran 39 jenis dokumen, e-Faktur/e-Bupot/BPJS/GPS sudah ada
sebagian (dipetakan di bawah).

## 1. Pro-kontra platform: (1) SPA vanilla + MySQL + antrean + pustaka ringan  vs  (3) tulis ulang front-end

Yang SAMA di kedua pilihan — dan harus dikerjakan LEBIH DULU apa pun pilihan front-end-nya:

- **MySQL.** `lockForUpdate()` dipanggil di **141 titik / 53 berkas** dan SQLite mengompilasinya
  menjadi string kosong — 20 docblock di kode mengakuinya (DocumentNumberService, JournalService,
  StockService, FieldReportService, BuktiPotongNumber…). Penomoran dokumen, jurnal, stok dan
  laporan lapangan hari ini "aman" hanya karena satu kunci tulis global SQLite; kunci itu pula yang
  memberi **503 pada ~40 permintaan bersamaan** (DEPLOYMENT.md:494, HASIL-UJI §6.4). Yang harus
  diport cuma 2 indeks unik parsial (`WHERE deleted_at IS NULL`) + audit desimal/JSON; tidak ada
  cabang `driver === 'sqlite'` di kode; CI belum punya job MySQL.
- **Antrean & scheduler sebagai layanan.** 7 perintah terjadwal jalan lewat satu baris cron tanpa
  pengawas dan log ke `/dev/null`; 0 Job, 0 ShouldQueue; pengiriman notifikasi sinkron di dalam
  listener. Tanpa ini tidak ada WhatsApp/e-mail/push yang jujur (gagal kirim harus terlihat, bukan
  ditelan).
- **E-mail sudah ada di kode** (NotificationService, setting `notifications.email_enabled`) —
  yang kurang hanya SMTP. **WhatsApp belum ada sama sekali** dan tercatat DITOLAK tertulis di
  ROADMAP-DEVIASI §1 (butuh akun penyedia + template yang disetujui Meta) — rencana ini mencabut
  penolakan itu dengan syarat yang dinyatakan.

Perbedaannya hanya di sisi front-end.

| | (1) Tetap vanilla ES modules + vendor pustaka kecil | (3) Tulis ulang dengan framework + build |
|---|---|---|
| **Aset yang terbawa** | 92 layar deklaratif (`schema.js`), ~35 rb baris JS teruji di lapangan, 1.503 baris CSS dengan token, 19 skenario harness Playwright, ±10 uji PHP yang memaku registri ↔ SPA — **semua tetap hidup** | `schema.js` (katalog RESOURCES/NAV/enum) bisa dibaca ulang oleh framework mana pun, tapi setiap renderer (list/detail/form/actions/cells/print) dan 15+ layar khusus (proyek, RFQ, tender, EVM, lapangan, kas kecil, onboarding…) **ditulis ulang** |
| **Waktu ke hasil pertama** | Minggu: app launcher, dashboard widget, kanban, tema — bertahap, layar demi layar, produksi tetap jalan | Bulan: tidak ada yang tayang sampai paritas 92 layar tercapai, atau dua front-end hidup berdampingan |
| **Risiko regresi** | Rendah per langkah; harness + uji registri menangkap pergeseran | Tinggi: 3.768 uji mengamankan API, bukan UI; paritas perilaku (draf sesi, 401, confirm-resubmit, maker-checker di layar, cetak) harus dibuktikan ulang satu per satu |
| **Grafik/gantt/kanban** | Pustaka kecil di-vendor sebagai berkas statis (mis. chart ringan, gantt ringan; tanpa npm/build) — cukup untuk dashboard & jadwal; grafik SVG tangan yang ada dipertahankan | Ekosistem komponen matang (grid, gantt, chart, date-picker) — tampilan paling "HashMicro" |
| **Mobile** | PWA di atas SPA yang ada (manifest + service worker + antrean offline yang sudah dipakai Lapangan) | Sama-sama PWA; komponen mobile siap pakai lebih banyak |
| **Perawatan jangka panjang** | Kode rumah yang sudah dikenal; tanpa rantai toolchain yang menua; setiap pengembang bisa `php -S` dan bekerja | Tooling standar industri, mudah merekrut; tetapi versi framework/build harus dirawat, `CONVENTIONS.md` + aturan "tanpa framework" ditulis ulang |
| **Biaya (perkiraan kasar)** | Fase UI 4–6 minggu kerja agen bertahap, tanpa henti produksi | 10–16 minggu untuk paritas + risiko jadwal; fitur baru tertunda selama itu |
| **Cocok bila** | Tujuannya "terasa seperti HashMicro" dan menutup fitur secepatnya | Tujuannya membangun tim front-end sendiri jangka panjang dengan stack standar |

**Rekomendasi: (1).** Katalog deklaratif adalah kekuatan sistem ini — HashMicro sendiri lahir dari
Odoo yang juga deklaratif. Yang membuat sebuah ERP "terasa modern" adalah dashboard, navigasi,
kanban/gantt, mobile, dan notifikasi — semuanya bisa dibangun di atas SPA yang ada dengan dua–tiga
pustaka kecil yang di-vendor. Tulis ulang baru masuk akal bila ada tim front-end tetap; hari ini
tidak ada. Bila pemilik tetap memilih (3), fase 0–2 di bawah tetap sama (MySQL, antrean,
integrasi); hanya fase UI yang berubah menjadi "bangun paritas dulu".

## 2. Peta fitur HashMicro (Construction ERP) ↔ Nusantara ERP hari ini

Legenda: ✅ ada · 🟡 ada sebagian · ⬜ belum. Sumber angka: inventaris 5 Sep 2026 (rincian
per berkas menyusul dari eksplorasi).

| Area HashMicro | Nusantara ERP | Kesenjangan yang direncanakan |
|---|---|---|
| App launcher / navigasi modul | 🟡 sidebar 14 grup, favorit, Ctrl+K (rilis UX Sep) | Grid modul + beranda per modul dengan ubin KPI |
| Dashboard widget yang bisa diatur | 🟡 dasbor tunggal, kartu tetap; beranda per peran masih P2 | Widget per peran + susunan tersimpan per pengguna |
| Laporan & BI | 🟡 laporan tetap, ekspor XLSX 10 formulir, EVM/kurva S SVG | Report builder ringan (saring–kelompok–pivot tersimpan), grafik |
| Kanban (CRM, tiket, tugas) | ⬜ | Tampilan kanban generik atas enum status |
| Gantt / jadwal proyek | 🟡 WBS + baseline + kurva S; tanpa gantt | Gantt dari `prj_wbs_tasks` (impor MPP-XML sudah ada) |
| Matriks persetujuan dari layar | 🟡 tangga n-level (P2) di config; OQ-4/OQ-5 menunggu direksi | Layar Pengaturan: ambang per jenis dokumen & delegasi |
| Budgeting vs realisasi | 🟡 RAP, gate anggaran, EVM, arus kas | Layar anggaran vs realisasi per proyek/periode + peringatan |
| CRM pipeline | 🟡 prospek → penawaran → kontrak, paket tender/TKDN | Pipeline kanban + aktivitas/tindak lanjut |
| HR: absensi GPS/selfie | 🟡 absensi + geo di lampiran Lapangan | Absensi ponsel dengan lokasi & foto, rekap ke payroll |
| Helpdesk / layanan | ✅ tiket, SLA, PM terjadwal | Portal pelanggan sederhana (bila diminta) |
| Notifikasi WhatsApp / e-mail | ⬜ (log saja) | Kanal e-mail + WhatsApp via penyedia API, antrean |
| e-Faktur / e-Bupot | 🟡 ekspor pajak, bukti potong | Format impor/ekspor DJP terkini, validasi NPWP/NIK |
| BPJS | 🟡 di payroll | Verifikasi tarif & pelaporan |
| Integrasi bank | 🟡 impor rekening koran (BCA CSV, Mandiri .sta) + rekonsiliasi | Format bank lain, jadwal impor |
| OCR nota / tagihan | ⬜ | Butuh layanan eksternal — opsional, terakhir |
| API / webhook | 🟡 API 776 rute, token Sanctum | Token API per integrasi + webhook keluar |
| Mobile app | 🟡 layar Lapangan responsif, onboarding bottom sheet | PWA terpasang, offline, push |
| Multi-company / multi-currency | ⬜ | **Di luar cakupan** (keputusan pemilik) |

## 3. Roadmap bertahap

Empat fase, dikerjakan sebagai paket ala ROADMAP-DEVIASI (satu paket per sesi, satu tugas = satu
commit, Feature test per perubahan server, suite penuh hijau sebelum rilis, Core tidak mengimpor
modul fitur, perubahan data maju-saja). Urutan tayang mengikuti jawaban pemilik: **tampilan &
BI dulu** — tetapi Fase 0 (MySQL) adalah prasyarat teknis yang harus mendahului apa pun yang
menambah beban baca/tulis (dashboard widget = lebih banyak permintaan paralel).

| Fase | Isi | Hari-orang | Prasyarat |
|---|---|---|---|
| **0 — Platform** | MySQL & cadangan; antrean & penjadwal sebagai layanan | 14 + 6 | cadangan MySQL teruji restore |
| **1 — Tampilan & BI** | app launcher, dashboard widget, grafik, report builder, kanban, gantt, penyegaran visual, PWA | (diisi dari desain UI) | Fase 0 |
| **2 — Fitur modul** | matriks persetujuan dari layar, anggaran vs realisasi, CRM aktivitas/pipeline, absensi GPS/selfie, reorder/barcode, pengingat perawatan, kedaluwarsa dokumen | (diisi dari desain fitur) | Fase 0; kanban dari Fase 1 |
| **3 — Integrasi & kepatuhan** | SMTP + WhatsApp + outbox, Coretax e-Faktur/e-Bupot, preset bank, token API + webhook, web push | 10 + 8 + 4 + 8 + 5 | Fase 0b; DJP boleh paralel |

### Fase 0 — Platform (P-0 14 h-o, P-0b 6 h-o)

**P-0 MySQL & cadangan dulu.** Jalur: erp1 tetap bare-metal, MySQL 8 sebagai layanan systemd
(bukan pindah ke docker — terlalu banyak variabel berubah sekaligus; dari jalur docker hanya
`mysqldump --single-transaction` yang diambil ke `deploy/backup-erp1.sh`). Tanpa Redis (antrean
driver `database`). Tugas: T0.1 `erp:mysql-preflight` (audit desimal-float, JSON rusak, SQL mentah
khusus SQLite; angka tak diketahui dicetak `?`) · T0.2 dua indeks unik parsial → kolom generated
`live_key` + UNIQUE di migrasi BARU berpenjaga driver (migrasi lama hanya diberi cabang
`sqlite`, tidak ditulis ulang) + uji dua driver · T0.3 job CI MySQL nightly (`phpunit.mysql.xml`,
`SqlitePragmaTest` di-skip di MySQL, `MysqlModeTest` sql_mode ketat) · T0.4 harness burst 20/40/80
paralel pada lima layanan berisiko (DocumentNumberService, JournalService, StockService,
FieldReportService, BuktiPotongNumber): 0 deadlock, nomor kontigu — deadlock diperbaiki lewat
URUTAN kunci, bukan retry buta · T0.5 `erp:sqlite-to-mysql` + `erp:migration-verify` (hitungan
baris, SUM desimal, hash kolom kunci; selisih → exit 1) · T0.6 cadangan MySQL + drill restore
WAJIB hijau sebelum cut-over; runbook `down` → cron mati → snapshot → pindah → verify → `.env` →
smoke → `up`; rollback = `.env` kembali ke SQLite (berkas tak disentuh), jendela 24 jam ·
T0.7 metrik: 40 paralel → **0×503, p95 ≤ 1,5 s**; 80 paralel → 0×503, p95 ≤ 3 s (diukur).
Keputusan pemilik: blok migrasi Core lanjutan (rek. 001400–001499), jendela cut-over (rek. Sabtu
pagi), retensi arsip SQLite (rek. 30 hari). Risiko: `->change()` menghapus atribut kolom di
Laravel 12; deadlock baru di posting jurnal; RAM erp1 (+≥1 GB).

**P-0b Antrean & penjadwal sebagai layanan.** Unit systemd `erp1-queue` (`queue:work database
--tries=5`) + `erp1-scheduler` (`schedule:work`), cron lama dihapus, log ke `/var/log/erp1/`;
`erp:heartbeat` 5 menit + watchdog cron terpisah (restart + notifikasi bila > 20 menit);
`GET core/health` melaporkan umur heartbeat, umur antrean tertua, jumlah gagal (null → `?`);
`core_notification_deliveries` (kanal, status queued|sent|failed|skipped, attempts, provider_id,
error) + Job `DeliverNotification` (5 percobaan, backoff) — pengiriman yang gagal TERLIHAT di layar
Pengaturan › Pengiriman notifikasi dengan `Kirim ulang`; layar `failed_jobs`.

### Fase 3 — Integrasi & kepatuhan (35 h-o; DJP boleh paralel)

**P-3a Kanal notifikasi (10).** T3a.0 mencabut penolakan WhatsApp tertulis (ROADMAP-DEVIASI §0.5,
ASSESSMENT) di `docs/KEPUTUSAN-INTEGRASI.md` dengan TIGA prasyarat milik pemilik: akun WABA
terverifikasi Meta, template disetujui Meta (1–7 hari, bisa ditolak), anggaran per percakapan;
tanpa itu kanal ada tapi `skipped` jujur. Penyedia: **Meta Cloud API langsung** bila bisnis sudah
terverifikasi, else **Qontak** (faktur IDR, dukungan lokal); **Fonnte & sejenis tidak** (nomor
bisa diblokir Meta). Klien HTTP Laravel, tanpa SDK. T3a.1 SMTP + template per peristiwa
(deadline.due, approval.escalated, ar.dunning, backup.stale, scheduler.down). T3a.2 preferensi
kanal per pengguna + jam tenang (tunda, bukan buang). T3a.3 kanal WhatsApp (5 template, webhook
status bertanda tangan, `users.phone_e164` + opt-in). T3a.4 ulang-kirim 1/5/15/60 menit;
in-app tetap kanal kebenaran.

**P-3b Kepatuhan DJP terkini (8 + konsultan).** Prinsip: **tidak mengarang tata letak berkas DJP**
— template resmi diunduh pemilik/konsultan ke `docs/samples/pajak/` bertanggal, writer mengikuti
berkas itu, diuji di sandbox Coretax satu periode nyata. Isi: e-Faktur `format=coretax_xml` di
samping CSV legacy (verifikasi format); e-Bupot unifikasi cocokkan kolom (verifikasi); e-Bupot
PPh 21/26 bulanan dari snapshot payslip (fitur baru kecil); aturan `Npwp` 16 digit / NIK / NITKU
(fitur baru, tanpa checksum karangan); ekspor SIPP BPJS (bila wajib XLSX → dependensi
`phpoffice/phpspreadsheet`, keputusan sadar); TER sudah benar; NTPN tetap manual (tidak ada API).

**P-3c Bank (4).** Preset `parse_options` tersimpan per bank (dipilih eksplisit, header tak cocok →
422 menyebut kolom; preset bawaan BCA/Mandiri/BNI/BRI HANYA dari berkas ekspor nyata pemilik);
impor dari folder terpantau per jam (P-0b), gagal → notifikasi; SFTP tidak; host-to-host tetap
ditolak.

**P-3d API & webhook (8).** Token akses pribadi (abilities = subset izin, kedaluwarsa ≤ 1 tahun,
tampil sekali) + layar Profil › Token API; webhook keluar pada `DocumentTransitioned`
(langganan + log pengiriman, HMAC `X-Nusantara-Signature`, 5 percobaan via antrean, https wajib);
OpenAPI **dipelihara tangan** untuk 20 endpoint terpakai + uji anti-drift terhadap `Route::getRoutes()`
(pembangkit otomatis ditolak: dependensi + 793 rute tak terkurasi).

**P-3e PWA push (5).** `sw.js` + manifest + tombol aktifkan di Profil (iOS ≥ 16.4 lewat "Tambahkan
ke Layar Utama"), VAPID dengan dependensi `minishlink/web-push` (kripto aes128gcm tidak ditulis
sendiri), `core_push_subscriptions`, 404/410 → langganan dihapus & tercatat. Tidak ada aplikasi
native, tidak ada FCM.

### Fase 1 — Tampilan & BI (≈33 h-o, 9 paket: A→B→C→D→E→F→G→H→I)

Prinsip: SPA tetap vanilla ES modules tanpa build; satu-satunya pustaka yang di-vendor adalah
**SortableJS 1.15** (44 KB, drag widget & kanban incl. sentuh) + sprite ikon Lucide subset; grafik
ditulis sendiri sebagai **SVG `charts.js`** (0 KB, mewarisi token tema & blok print, warnanya bisa
diukur harness S8 — canvas tidak). Pemilik memutuskan: landing = dasbor di ≥760 px, app launcher
`#/home` di ponsel; 8 resource untuk report builder v1; berbagi laporan per peran; drop kanban yang
menyetujui HARUS lewat `runAction()` (jalur tombol yang sama — catatan inline, maker-checker,
confirm-resubmit); aksi `post` (jurnal/stok) tidak pernah di papan; ketergantungan gantt ditunda
ke Fase 2 (kolomnya tidak ada — impor MPP-XML mengabaikan PredecessorLink, legenda mengatakannya).

| Paket | Isi | h-o |
|---|---|---|
| **P1-A** Fondasi vendor + `charts.js` | `public/app/vendor/<lib>@<ver>/` + `VENDOR.md` (sha256, lisensi); `lineChart/barChart/donutChart/sparkline/ganttChart`; uji `VendorManifestTest` (aturan tanpa-CDN jadi uji) | 1,5 |
| **P1-B** Penyegaran visual dalam sistem token | `--accent-<modul>` (8 slot warna departemen yang sudah tervalidasi ΔE), `--row-h` + kepadatan per pengguna, breadcrumb → beranda modul, empty state berilustrasi; S8 dijalankan di dua tema | 3 |
| **P1-C** Preferensi per pengguna + App launcher + Beranda modul | `core_user_preferences` (kunci whitelist, ≤16 KB) + `core/me/preferences`; blok `modules` di `core/dashboard/summary` (registri `ModuleCounts`: hitungan tanpa skema → `null`, bukan 0); `#/home` grid modul, `#/m/<prefix>` KPI + kartu layar + "Terakhir dibuka"; favorit/recent pindah ke server | 4 |
| **P1-D** Dasbor yang bisa diatur | 18 widget atas endpoint YANG SUDAH ADA (inbox, tenggat, AR/AP aging, proyeksi kas, EVM, RAP vs realisasi, stok minimum, SLA tiket, PO terlambat, NCR/defect, payroll, pajak, …) + 12 set bawaan per peran (T4.1); laci "Atur dasbor" (tambah/hapus/ukuran/urut); muat per batch 4; `DashboardTileFailureTest` ditulis ulang memindai `views/widgets/*` — setiap widget WAJIB bercabang `failure(` | 6 |
| **P1-E** Migrasi 3 grafik tangan ke `charts.js` | S-curve proyek, kurva EVM (aturan sumbu >100 % dipertahankan), tren harga satuan; diff piksel ≤ 2 % | 2,5 |
| **P1-F** Report builder "Laporan Bebas" v1 | registri `ReportableResources` (8 resource, kolom = kolom layar daftar — dipaku uji), `POST core/reports/run` = SATU kueri `DB::table` ber-whitelist, group-by + pivot 1×1×1, batas 5.000 baris/200 kelompok yang DIUMUMKAN; izin = `{prefix}.view` layar daftar; `core_saved_reports` + bagi per peran (pemilik saja yang mengubah); CSV/XLSX (sel kosong, bukan 0) | 7 |
| **P1-G** Kanban atas resource ber-enum status | blok `board:` di schema.js (kolom + `moves` → kunci aksi yang SUDAH ADA); `views/board.js` memakai `runAction()`; drop terlarang = kartu kembali + kalimat "PO/… tidak bisa dipindah ke Disetujui: aksi Setujui tidak tersedia untuk Anda."; 0 endpoint baru | 4 |
| **P1-H** Gantt baca-saja v1 | `prj_wbs_tasks` + baseline per `wbs_code` (bukan id — kolomnya nullable) + progres; garis hari ini, bayangan akhir pekan, zoom minggu/bulan, cetak lanskap; tab "Jadwal" di proyek | 3,5 |
| **P1-I** PWA | `manifest.webmanifest` + `sw.js` (network-first untuk shell `/app/*`; `/api/*` & lampiran TIDAK PERNAH di-cache — dipaku uji), "Pasang aplikasi", toast "Versi baru siap — Muat ulang", pita luring di atas antrean Lapangan; push ditunda ke Fase 3 | 2,5 |

Target metrik Fase 1: dasbor direktur ≤10 permintaan (11), warehouse ≤5 (6); 0 peran tanpa ubin
(2); ketuk ke Lapangan ≤2 (3); widget gagal tidak pernah "Rp 0"; report run = 1 kueri SQL;
drop kanban tertolak selalu bersebab; vendor ≤60 KB gzip; 0 respons API dari cache SW.
### Fase 2 — Fitur modul (≈42 h-o, urutan F-1 → F-2 → F-3 → F-4 → F-6 → F-7 → F-8 → F-9; F-5 ditunda)

Fakta yang mengubah desain: kunci `approvals.purchase_order/subcontract.threshold_two_level` SUDAH
ada di SettingService (layar matriks memperluas mekanisme yang ada, bukan mengarang); `crm_leads
.next_follow_up_at` dan `inv_items.barcode` sudah ada; blok migrasi Projects (000799) & Finance
(001199) HABIS → F-2 menambah blok lanjutan di CONVENTIONS §2 (Finance 001500–, Projects 001600–).

| Paket | Isi | h-o |
|---|---|---|
| **F-1** Matriks persetujuan dari layar + delegasi "a.n." + setujui massal | `ApprovalPolicy` per 28 jenis dokumen (ambang, mode `single_director`/`extra_level`, tingkat-3) di Pengaturan — dikirim dengan NILAI EFEKTIF HARI INI sebagai bawaan, jadi tidak ada perilaku berubah sampai pemilik mengedit (OQ-4/OQ-5 tetap milik pemilik); kebijakan distempel di baris `submitted` (tidak retroaktif); mengubah `approvals.*` butuh `core.update` + pemegang `*.approve-director` + audit; gerbang lama PO/SPK TIDAK dimigrasikan, kesetaraannya DIBUKTIKAN uji 6 nilai di sekitar ambang; izin `*.approve-director` untuk 9 prefix lain; `core_approval_delegations` + `Gate::before` HANYA untuk `*.approve*` + patch `NotificationService::approvers`; delegat tidak boleh menyetujui yang diajukan dirinya MAUPUN pemberi delegasi; trail & cetakan "Budi a.n. Sari"; setujui massal dengan `approvals.batch_cap` (bawaan kosong = mati), loop di klien memanggil endpoint modul masing-masing | 9–10 |
| **F-2** Anggaran vs realisasi + overhead + revisi RAP + registri ambang | `WatchedThresholds` (Core, saudara `WatchedDeadlines` untuk "aktual vs batas"; limit null = digaris); layar per proyek × bulan (anggaran bulanan = RAP × fase baseline, BERLABEL; tanpa baseline → digaris; bulan kosong → null) & portofolio (angka SAMA dengan `BudgetGateService`, uji kesetaraan); `fin_overhead_budgets` (OVB, Approvable, satu approved/tahun) + realisasi dari jurnal; revisi RAP (`revision`, `superseded_by_id`, riwayat selisih); peringatan `project_budget_pct` ≥ 90 % | 8 |
| **F-3** CRM aktivitas + pemilik prospek + transisi pipeline | `crm_activities` (call/meeting/email/visit/note, due/done, owner) + kartu di prospek/penawaran/pelanggan; `next_follow_up_at` DITURUNKAN dari aktivitas; `owner_user_id` tanpa backfill ("Belum ditugaskan"); `LeadStatus::canMoveTo` (maju bebas, mundur wajib alasan, won/lost HANYA lewat penawaran); `GET crm/pipeline/board` untuk kanban Fase 1; watcher aktivitas jatuh tempo | 5 |
| **F-4** Absensi masuk/pulang GPS + selfie | kolom `check_in/out_*` (lat/lng/akurasi/jarak/foto/waktu perangkat) forward-only; `clock-in/out` memakai `Geotag::distanceMetres` ke koordinat proyek — di luar geofence (setting, bawaan 500 m) DICATAT & ditandai, tidak ditolak; proyek tanpa koordinat → digaris; layar ponsel "Absensi Saya" dengan antrean offline (diekstrak dari lapangan.js ke `uploadqueue.js`); koreksi pengawas beralasan; **usulan rekap** dari register — HR yang menerapkan (register tetap bukan input payroll kecuali pemilik memutuskan) | 6 |
| **F-6** Reorder → usulan PR, label & pindai barcode | `inv_reorder_rules` per item×gudang (prioritas atas `min_stock`, ditulis); usulan PR draft lewat `PurchaseRequisitionService`; label Code 128 sebagai SVG tanpa dependensi (F/LBL); pindai `BarcodeDetector` dengan jalur manual (iOS Safari tidak mendukung) | 5 |
| **F-7** Servis alat per hour-meter (+ log perjalanan bila ada kendaraan) | `next_due_hour_meter`; ambang dari log hour-meter terakhir; aset tanpa pembacaan → digaris | 2–3 |
| **F-8** Kedaluwarsa lampiran umum; sikap e-sign | `core_attachments.valid_until` + watcher Core-only; e-sign: PERTAHANKAN tautan persetujuan eksternal + tanda tangan basah (PSrE = integrasi berbayar, ditolak sekarang) | 2 |
| **F-9** CSAT tiket via tautan sekali pakai; basis pengetahuan DITUNDA | pola token `ExternalApprovalService`, bukan portal; rata-rata hanya dari yang dinilai | 2 |
| **F-5** Timesheet & lembur dari absensi | DITUNDA sampai ≥ 1 bulan data F-4 — tanpa itu aturan pembulatan lembur dikarang; ILB tetap otoritatif | (3) |

## 4. Verifikasi

- **Per paket** (aturan ROADMAP-DEVIASI §4/§6 tidak berubah): uji gagal dulu, ≥N uji Feature,
  per-direktori saat kerja, suite penuh sebelum merge, `LAPORAN-PAKET-<id>.md`, verifikasi
  adversarial ganda (dua verifier baca-saja) sebelum merge — pola yang menemukan ~40 cacat di
  P2–P8 termasuk tiga bug uang.
- **Harness Playwright** adalah bukti UI: skenario baru per paket (S20 token grafik, S21 breadcrumb,
  S22 kebenaran hitungan launcher, S23 dasbor diatur & gagal-jujur, S24 laporan bebas, S25 papan PR,
  S26 gantt, S27 PWA luring; absensi ponsel, pindai, CSAT di Fase 2) dijalankan di desktop 1440×900
  DAN ponsel 390×844, hasil `results-<fase>.json` di `docs/bukti-uji/`.
- **Fase 0**: job CI MySQL nightly hijau; `erp:migration-verify` 0 selisih; drill restore MySQL
  hijau sebelum cut-over; burst 40/80 paralel 0×503 (diukur); `core/health` melaporkan tiga angka.
- **Fase 3**: pemilik menerima satu e-mail, satu WhatsApp (`provider_id` terlihat), satu push nyata
  dari erp1; dua ekspor Coretax diimpor sukses di sandbox oleh konsultan (bukti disimpan).
- **Tabel metrik RECAP § Verification** ditambah baris Fase 1 (di atas) dan diisi kolom "After
  fase N" setiap akhir fase — angka diukur, tidak diklaim.

## 5. Keputusan pemilik (ledger; angka bertanda ⏳ memakai rekomendasi sampai dijawab)

| # | Keputusan | Rekomendasi |
|---|---|---|
| 1 | Platform front-end | **(1) tetap vanilla + vendor kecil** (§1) |
| 2 | Mesin grafik / pustaka vendor | SVG tangan; SortableJS + sprite Lucide saja |
| 3 | Landing setelah masuk | dasbor di desktop, launcher di ponsel |
| 4 | Resource report builder v1 / berbagi | 8 resource; per peran |
| 5 | Blok migrasi lanjutan | Core 001400–?, Finance 001500–, Projects 001600– (CONVENTIONS §2) |
| 6 | Jendela cut-over MySQL / retensi arsip SQLite | Sabtu pagi / 30 hari |
| 7 | Penyedia WhatsApp & anggaran | Meta Cloud API langsung bila bisnis terverifikasi, else Qontak; bukan Fonnte |
| 8 | SMTP | kotak surat domain perusahaan, 587 STARTTLS |
| 9 | Konsultan pajak untuk sandbox Coretax; `phpoffice/phpspreadsheet` bila SIPP wajib XLSX | ya; keputusan sadar |
| 10 | CORS / laju token integrasi | kosong / 300 per menit |
| 11 | `minishlink/web-push` (VAPID) | ya — kripto tidak ditulis sendiri |
| 12 | OQ-4 ambang per jenis, OQ-5 delegasi (F-1) | layar dikirim dengan nilai hari ini; pemilik mengisi |
| 13 | Fase bulanan anggaran = baseline; ambang peringatan | ya; 90 % |
| 14 | Geofence absensi & di-luar-geofence; usulan rekap ke payroll | 500 m, dicatat bukan ditolak; usulan yang HR terapkan |
| 15 | Code 128 vs QR ter-vendor | Code 128 tanpa dependensi |
| 16 | `queue:retry` shell untuk job pengiriman (`DeliverNotification`): no-op jujur vs terima baris `failed` | ⏳ **no-op jujur = perilaku hari ini** (verifikasi P-0b, 5 Sep 2026): kebenaran pengiriman adalah baris `core_notification_deliveries`; job yang kembali lewat `queue:retry` melewati baris `failed` tanpa mengirim dan menulis satu peringatan di log pekerja yang menunjuk ke Sistem › Pengiriman Notifikasi; layar Antrean Gagal menolak (422) dengan penunjuk yang sama. Alternatif (menerima baris `failed` saat job datang lewat retry) = dua jalur kirim ulang yang bisa saling menimpa |

## 6. Urutan pengerjaan yang diusulkan & total

Fase 0 (20 h-o) → Fase 1 (33) → Fase 2 (42) → Fase 3 (35) ≈ **130 hari-orang** agen, satu paket
per sesi seperti kampanye ROADMAP-DEVIASI (16 paket ≈ 2 minggu kalender). Fase 3b (DJP) dan
persiapan akun WhatsApp/SMTP boleh berjalan paralel sejak awal karena bergantung pada pihak luar.
**Yang dikerjakan lebih dulu bila disetujui: P-0 T0.1–T0.3** (pra-penerbangan, indeks parsial,
CI MySQL) — semuanya tanpa menyentuh produksi, dan menjawab apakah cut-over MySQL aman sebelum
satu widget pun dibangun. Multi-perusahaan, portal pelanggan, host-to-host bank, aplikasi native,
multi-tenant: tetap di luar cakupan (penolakan tertulis dipertahankan; hanya WhatsApp yang dicabut).
