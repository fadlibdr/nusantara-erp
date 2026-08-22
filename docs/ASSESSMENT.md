# Penilaian & Rekomendasi — Nusantara ERP

> **Lanjutan (1 Agustus 2026):** asesmen generasi kedua tentang proses bisnis,
> kelengkapan fitur, dan UI/UX — [ASSESSMENT-LANJUTAN.md](ASSESSMENT-LANJUTAN.md).

Tanggal: 27 Juli 2026. Disusun setelah audit menyeluruh atas seluruh modul, kode
front-end, dan host produksi `erp1.pi2.co.id`.

Dokumen ini menyebut apa yang **belum ada** dan apa yang **salah**, bukan apa
yang sudah baik. Aplikasi ini sudah jauh lebih lengkap daripada kebanyakan ERP
internal: 12 modul terhubung dari penawaran sampai jurnal, 1.081 tes hijau,
matematika statutori Indonesia yang tergarap serius. Yang di bawah ini adalah
sisa jalannya.

Urutannya adalah urutan **kerugian bila diabaikan**, bukan urutan kesulitan.

---

## Tingkat 0 — kerjakan minggu ini (total ± 1 jam)  ✅ SELESAI 27 Juli 2026

Tiga hal murah yang mencegah kerugian besar. Tidak satu pun butuh kode aplikasi.

| Yang dikerjakan | Di mana |
|---|---|
| Backup harian terverifikasi (DB + lampiran), rotasi 14 hari | `deploy/backup-erp1.sh`, `/etc/cron.d/erp1` 02:15 |
| Snapshot otomatis **sebelum** setiap `migrate` | `deploy/sync-erp1.sh` — deploy dibatalkan bila backup gagal |
| WAL + `busy_timeout` 5 detik + `synchronous NORMAL` | `config/database.php` |
| Penjadwal Laravel berjalan tiap menit | `/etc/cron.d/erp1` — `svc:generate-pm` kini aktif |

Restore sudah diuji: arsip dibuka kembali, 98 tabel, neraca saldo tetap nol.
**Yang masih kurang: salinan ke luar mesin.** Semua cadangan masih di disk yang
sama, sehingga melindungi dari migrasi buruk dan penghapusan keliru, bukan dari
disk mati. Lihat catatan di kaki `deploy/backup-erp1.sh`.

### 0.1 Tidak ada cadangan data sama sekali — **risiko kehilangan total**

Tidak ada backup `database/database.sqlite` maupun `storage/app/private`
(lampiran) di host produksi, dalam bentuk apa pun, seumur apa pun. Tidak ada
cron, tidak ada `/backups`, tidak ada berkas dump di disk.

`deploy/backup/backup.sh` ada, tetapi **tidak bisa jalan di host ini**: isinya
hanya jalur `mysqldump` (langsung dan via Docker), sedangkan produksi memakai
SQLite di bare-metal. Prosedur restore di `docs/DEPLOYMENT.md` §5.2 memulihkan
dari `/backups/erp-db-*.sql.gz` ke kontainer MySQL — di host ini itu fiksi.

`deploy/sync-erp1.sh` melindungi data hidup dengan `--exclude` (benar, dan
terdokumentasi), tetapi **tidak mengambil snapshot sebelum `migrate --force`**.
Migrasi yang merusak data tidak dapat dikembalikan.

> Satu-satunya salinan yang ada adalah `/tmp/backup-before-migrate.sqlite`,
> disalin manual oleh seseorang sebelum migrasi, di disk yang sama, tanpa rotasi.
> Orang itu tahu risikonya dan melakukan dengan tangan apa yang seharusnya
> otomatis.

**Yang perlu dibuat:** skrip harian yang memakai `sqlite3 .backup` (bukan `cp` —
menyalin berkas SQLite saat ada penulisan menghasilkan salinan rusak), mengarsip
`storage/app/private`, merotasi 14 hari, dan menyalin ke luar mesin. Ditambah
snapshot otomatis di `sync-erp1.sh` **sebelum** `migrate`.


**Salinan offsite dikerjakan 28 Juli 2026.** `deploy/backup-erp1.sh` kini
mengenkripsi setiap arsip (GPG AES-256, kunci di `/etc/erp1/backup.key`) dan
menyinkronkannya ke tujuan offsite — server lain lewat rsync/SSH **atau**
bucket cloud apa pun lewat rclone (sudah terpasang) — lalu membaca balik dan
mencocokkan checksum, memangkas salinan remote yang lebih tua dari 30 hari,
dan menulis statusnya ke `/var/lib/erp1/offsite-status.json`. Sinkronisasi
bersifat *push-by-sync*: malam yang gagal disembuhkan run berikutnya, dan cron
tengah hari (13:15) mencoba ulang. `--restore-drill` menarik salinan offsite
terbaru, mendekripsi, dan membuktikannya bisa dibuka — seluruh jalur pemulihan
bencana dilatih, bukan diandaikan. Seluruh mekanisme sudah diuji ujung-ke-ujung
(push 54 arsip, verifikasi checksum, prune, drill pulih 11 pengguna & 9 jurnal,
kunci salah ditolak, nama berkas hostil di remote diabaikan oleh whitelist).

Perintah `erp:backup-watch` (terjadwal 08:00 WIB) membaca status itu dan
membunyikan alarm dalam-aplikasi ke pemegang `core.approve` bila offsite belum
dikonfigurasi, macet, atau tidak pernah berjalan — kegagalan cadangan tidak
lagi hanya hidup di berkas log yang tidak dibaca siapa pun.

**Satu langkah tersisa, dan hanya pemilik yang bisa melakukannya:** menunjuk
tujuannya. Isi `/etc/erp1/backup.conf` (contoh di
`deploy/erp1-backup.conf.example`) dengan server lain yang Anda kuasai
(otorisasi `/root/.ssh/id_ed25519.pub` di sana; port 22 ke 194.163.42.54
tertutup saat ini) **atau** remote rclone ke bucket cloud. Lalu jalankan
`backup-erp1.sh --offsite-only` dan `--restore-drill`. **Simpan salinan
`/etc/erp1/backup.key` di luar server** — di password manager — karena tanpa
kunci itu salinan offsite hanyalah derau acak persis ketika dibutuhkan.
Sampai tujuan diisi, setiap run keluar non-zero dan ERP menampilkan alarmnya.

Mekanisme ini melewati tinjauan adversarial 35-agen sebelum dipakai; 29 temuan
terkonfirmasi diperbaiki, di antaranya: status yang bisa berkata "sehat" ketika
nol cadangan tersisa (kini "ok" mensyaratkan remote tidak kosong DAN merekam
umur arsip terbaru, yang diperiksa terpisah oleh pengawas — sinkronisasi tanpa
apa pun untuk dikirim tidak lagi bisa mencuci matinya cadangan lokal); tar/gzip
parsial yang bisa memakai nama arsip jadi (kini tulis-ke-.part, uji kontainer,
baru rename); conf yang salah ketik membunuh cadangan LOKAL lewat `set -e`
(kini divalidasi sebelum di-source); pemangkasan tanpa lantai yang bisa
mengosongkan remote bila jam server melompat (kini 3 arsip terbaru per jenis
kebal umur, lokal maupun remote); berkas cron tanpa bit eksekusi (kini
dipanggil lewat `bash`); jadwal cron UTC yang mengaku WIB (kini ditulis UTC
dengan makna WIB-nya); dan drill pemulihan kini berjalan otomatis tiap bulan,
hasilnya ikut diawasi.

### 0.2 SQLite tanpa WAL dan tanpa `busy_timeout`

`config/database.php` menyetel `'busy_timeout' => null, 'journal_mode' => null`.
Artinya: mode rollback journal (pembaca memblokir penulis dan sebaliknya), dan
koneksi yang terblokir **gagal seketika** dengan `database is locked` alih-alih
menunggu. Hari ini tertutupi oleh `pm.max_children = 5`.

Dua nilai konfigurasi, tanpa perubahan kode:
`'journal_mode' => 'WAL'`, `'busy_timeout' => 5000`.

### 0.3 Tidak ada penjadwal — pekerjaan terjadwal tidak pernah berjalan

Root tidak punya crontab dan tidak ada unit systemd untuk scheduler. Akibatnya
`svc:generate-pm` (`ServiceDeskServiceProvider`, dijadwalkan `dailyAt('06:00')`)
**belum pernah sekali pun dijalankan**. Kunjungan preventive maintenance di
`svc_preventive_schedules` lewat jatuh tempo diam-diam — tanpa tiket, tanpa
galat, tanpa pemberitahuan.

Satu baris cron: `* * * * * cd /var/www/erp1.pi2.co.id && php artisan schedule:run`.

> Terkait: `QUEUE_CONNECTION=database` tanpa worker. Hari ini tidak berbahaya —
> tidak ada satu pun kelas `ShouldQueue` di seluruh kode. Tetapi ini senapan
> terkokang: developer pertama yang menambahkan `implements ShouldQueue` akan
> melihat pekerjaannya hilang ke tabel yang tidak ada yang menguras, tanpa galat
> di mana pun.

---

## Tingkat 1 — buku besar tidak mencerminkan kenyataan

Empat cacat yang membuat laporan keuangan salah hari ini. Semuanya memakai mesin
yang sudah ada (`JournalService::autoPost`, `ProjectCostService::record`) dan
akun yang sudah ada di bagan akun.

### 1.1 Payroll tidak pernah masuk buku besar — **cacat terbesar** ✅ SELESAI

> Diperbaiki 27 Juli 2026. `Modules/HrPayroll/Services/PayrollPostingService.php`
> memposting saat run disetujui, dalam satu transaksi dengan perubahan
> statusnya. Akun `2-1110 Hutang Gaji & Upah` dan `2-1120 Hutang BPJS`
> ditambahkan ke bagan akun. Upah karyawan yang sedang ditugaskan ke proyek
> (`prj_manpower_assignments`) masuk `5-1200` **dan** `fin_project_costs`
> kategori `labor`; sisanya `6-1100`. Alokasi dibekukan pada payslip saat
> `calculate()`, bukan diresolusi saat posting.
>
> Terbukti di demo: run `PYR/2026/06/002` diposting sebagai `JV/2026/07/0009`,
> Rp 210.772.491 di kedua sisi, neraca saldo tetap seimbang.
>
> Posting kedua **ditolak**, tidak menimpa: `autoPost()` selalu membuat jurnal
> baru dan jurnal terposting tidak dapat dihapus, jadi "posting ulang" akan
> menggandakan seluruh beban gaji. Koreksi atas run terposting adalah jurnal
> balik — keputusan akuntan, bukan keputusan method ini.

`Modules/HrPayroll` menghitung PPh 21 TER, BPJS berplafon, lembur dan THR dengan
benar dan teruji. **Tidak satu rupiah pun sampai ke jurnal.** Tidak ada satu pun
referensi ke `JournalService` di seluruh modul; `PayrollRunController::approve`
hanya mengubah status.

Akibatnya, pada neraca saldo, laba-rugi dan neraca:

- `6-1100 Beban Gaji & Tunjangan` — nol
- `6-1200 Beban BPJS & Kesejahteraan` — nol
- `5-1200 Beban Upah Proyek` — nol
- `2-1210 Hutang PPh 21` — nol, sehingga **PPh 21 terpotong tidak pernah muncul
  di e-Bupot maupun di kewajiban pajak**

Ini bukan kelalaian yang tersembunyi: komentar di
`fin_project_costs` sendiri berbunyi *"Fed by AP bill approvals here; payroll &
material-issue postings from…"*. Pengeluaran material dibangun; payroll tidak.

Kategori biaya `labor` pada laporan profitabilitas proyek karena itu **selalu
nol** kecuali seseorang memasukkannya sebagai tagihan vendor.

### 1.2 Retensi subkon ditahan di satu tempat dan dibayarkan di tempat lain ✅ SELESAI

> Diperbaiki 27 Juli 2026. `fin_ap_bills.retention_amount` ditambahkan dan diisi
> dari opname; `total_payable` kini `dpp + PPN − PPh − retensi`, dan retensi
> dikreditkan ke `2-1500 Hutang Retensi Subkon` saat tagihan disetujui. DPP tetap
> bruto — PPN dan PPh final dikenakan atas pekerjaan yang dikerjakan, bukan atas
> yang dibayar bulan ini. Tes lama yang mengunci perilaku keliru diperbarui.

`ClaimService` menghitung `net_payable = gross − retensi + PPN − PPh`, dan
`RetentionService::balance()` melaporkan retensi itu sebagai tertahan. Tetapi
`ApBillService::createFromSubconClaim()` membuat tagihan dengan
`'dpp' => $claim->gross_amount` — **bruto, mengabaikan retensi**.

Subkontraktor dibayar penuh, sementara sistem melaporkan retensinya masih
dipegang. Pada SPK Rp 2,1 miliar dengan retensi 5%, itu Rp 105 juta keluar lebih
awal per SPK, dengan laporan yang menyatakan sebaliknya.

Akun `2-1500 Hutang Retensi Subkon` ada di bagan akun dan **tidak dirujuk di mana
pun selain seeder**.

### 1.3 Retensi pelanggan tidak pernah bisa dicairkan ✅ SELESAI

> Diperbaiki 27 Juli 2026. `ArRetentionService` + dua endpoint:
> `GET finance/ar-retentions` (yang masih ditahan, dengan tanggal jatuh tempo
> dari `prj_bast.retention_release_due` — kolom yang selama ini hanya
> ditampilkan) dan `POST finance/ar-retentions/{id}/release`, yang membukukan
> Dr Bank / Cr `1-1350` lalu menandai barisnya. `1-1350` kini bisa nol kembali.

`ArInvoiceService::approve()` mendebit `1-1350 Piutang Retensi` dan membuat baris
`fin_ar_retentions` dengan `released = false`. **Tidak ada satu pun kode yang
pernah menulis `released = true`.** Tidak ada rute, tidak ada layar, tidak ada
service. `1-1350` hanya pernah didebit, tidak pernah dikredit — saldonya tumbuh
selamanya.

`prj_bast.retention_release_due` dihitung dengan benar (`handover_date +
warranty_months`) lalu **hanya ditampilkan**; tidak ada query, laporan, atau
pemberitahuan yang membacanya. Tidak ada yang memberi tahu kapan retensi menjadi
tertagih.

### 1.4 Penyusutan tidak pernah masuk buku besar ✅ SELESAI

> Diperbaiki 27 Juli 2026. `DepreciationService::post()` kini membukukan
> Dr akun beban / Cr akun akumulasi per kategori aset, dikelompokkan per pasangan
> akun. Kolom `depreciation_account_hint` dan `accum_account_hint` akhirnya
> menjadi akun sungguhan, bukan "hint". Kategori tanpa akun **ditolak**, bukan
> diposting ke akun bawaan — menebak akun akumulasi salah menyatakan dua kelas
> aset sekaligus, dan keduanya duduk berdampingan di bawah 1-2000.

`DepreciationService` memperbarui `accumulated_depreciation` dan `book_value`
pada aset, lalu berhenti. Tidak ada jurnal. Kolom
`ast_categories.depreciation_account_hint` dan `accum_account_hint` divalidasi,
ditampilkan, dan **tidak pernah dipakai memposting apa pun**.

Digabung dengan §1.1 dan dengan biaya alat yang hanya "saran" (§3.4), artinya
**alat milik sendiri gratis di setiap P&L proyek** — yang secara struktural
memihak sewa dalam setiap perbandingan beli-atau-sewa.

---

## Tingkat 2 — kepatuhan statutori

### 2.1 Ekspor pajak dibangun untuk skema yang sudah digantikan

Layar *Ekspor Pajak* menghasilkan CSV skema **e-Faktur desktop lama** (rekaman
`FK` / `LT` / `OF` dengan `KD_JENIS_TRANSAKSI`, `FG_PENGGANTI`, dst.).

Sejak 2025 DJP memakai **Coretax**, yang menerima faktur pajak keluaran dan bukti
potong lewat **impor XML**, dengan konverter Excel→XML resmi (per Januari 2026:
faktur v1.5, BPMP 3.0, BP21 4.0). Impor data SPT PPN pun sudah beralih dari CSV
ke XML.

Peringatan yang sudah ada di README ("impor satu masa ke lingkungan uji dan
cocokkan totalnya dulu") adalah naluri yang benar — tetapi ini bukan lagi
*mungkin berubah*, melainkan **sudah berubah**. Berkas yang dihasilkan hari ini
kemungkinan besar tidak diterima.

Yang diperlukan: pengganti keluaran XML sesuai skema Coretax yang berlaku, dengan
nomor versi skema yang tercatat di berkas dan di layar. Struktur `TaxExportService`
(kumpulkan → validasi → laporkan yang tertahan → keluarkan) tetap benar; hanya
lapisan penulisannya yang berganti.

### 2.2 Yang perlu ditinjau ulang setiap tahun

Sudah tercatat di README dan tetap berlaku: tabel bracket PPh 21 TER wajib
diverifikasi terhadap lampiran PMK sebelum produksi; plafon BPJS ditinjau
pemerintah tiap tahun; kode objek pajak e-Bupot sengaja tidak di-seed dan harus
diisi dari daftar DJP yang berlaku.

---

## Tingkat 3 — struktural

### 3.1 Tidak ada jejak audit — **celah tata kelola paling serius** ✅ SELESAI

> Diperbaiki 27 Juli 2026. `core_audit_log` (append-only), `AuditService`, dan
> `GET core/audit-log` di belakang `core.view`.
>
> Dipasang sebagai **model observer**, bukan trait: trait harus ditambahkan ke
> tiap kelas dan akan terlewat pada salah satunya — dan jalur tulis yang terlewat
> justru yang dicari saat investigasi. Observer menangkap semuanya sekaligus:
> controller, service, perintah konsol, seeder, tinker.
>
> Yang dicatat sengaja **bukan segalanya** (lihat `AuditedModels`): data induk
> yang mengalihkan uang (vendor, rekening bank), yang menghitungnya (setting,
> pajak, akun COA), siapa yang boleh melakukannya (pengguna), nilai kontrak, dan
> data karyawan yang memberi makan payroll. Dokumen tidak — siklusnya sudah ada
> di `core_approvals`.
>
> Kata sandi, token, dan cap waktu **tidak pernah** masuk log; ada tes yang
> menegakkannya. Dan seperti notifikasi, kegagalan pencatatan tidak boleh
> membatalkan perubahan yang diamatinya — ada tes yang menjatuhkan tabelnya di
> tengah jalan dan menegaskan penyuntingannya tetap tersimpan.
>
> Terbukti di produksi: perubahan nomor rekening vendor lewat API tercatat
> lengkap dengan pelaku, nilai lama, dan nilai baru.

Di luar `core_approvals` (aksi persetujuan saja), **tidak ada catatan siapa
mengubah apa**. Tidak ada paket audit di `composer.json`, tidak ada tabel
audit/activity/revision, dan `SettingService::set()` tidak mencatat apa pun.

| Perubahan | Tercatat? |
|---|---|
| Nomor rekening bank vendor diubah | **Tidak.** Baris ditimpa. |
| Tarif PPN diubah di layar Pengaturan | **Tidak.** Tidak ada pelaku, tidak ada nilai lama. |
| Jurnal draf disunting sebelum diposting | **Tidak.** |
| Pengguna dinonaktifkan | **Tidak.** |

Perubahan nomor rekening vendor adalah vektor penipuan faktur yang klasik, dan
justru itulah perubahan yang di sini tidak meninggalkan bukti apa pun.

### 3.2 `lockForUpdate()` tidak berfungsi di SQLite

`SQLiteGrammar::compileLock()` mengembalikan string kosong — tanpa peringatan.
**Ke-15 pemanggilan `lockForUpdate()` di kode ini tidak memberi perlindungan apa
pun di produksi**, termasuk:

- `DocumentNumberService` — komentarnya berbunyi *"Re-fetch with a row lock so
  concurrent requests can't share a number."* Di SQLite tidak. Dua PO bersamaan
  bisa mendapat nomor yang sama, lalu satu ditolak kolom unik sebagai galat 500.
- `PaymentService` (posting & alokasi), `JournalService` (posting),
  `StockService` (saldo stok), `ClaimService`, `RetentionService`,
  `BankStatementMatchService`.

Hari ini tertutupi kunci berkas SQLite dan `pm.max_children = 5`. Perlindungan
sebenarnya baru ada setelah pindah ke MySQL 8 — **dan pindah itu tidak boleh
dilakukan sebelum §0.1 selesai.**

Catatan portabilitas saat pindah: SQLite tidak menegakkan `decimal(18,2)`
(disimpan sebagai float), MySQL menegakkannya dan membulatkan saat menulis. Tes
pun berjalan di SQLite, jadi selisih pembulatan tidak akan tertangkap. Tujuh
kolom JSON juga akan ditolak MySQL bila isinya bukan JSON valid.

### 3.3 Tidak ada pekerjaan tambah-kurang (CCO) ✅ SELESAI

> Diperbaiki 27 Juli 2026. `crm_contract_change_orders` — dokumen approvable
> tersendiri dengan siklus submit/approve/reject yang sama, sehingga ikut
> mendapat notifikasi persetujuan tanpa tambahan kode.
>
> Kontrak **tidak disentuh** sampai perubahannya disetujui, lalu nilainya
> ditambah, bukan diganti: `crm_contracts.original_value` menyimpan nilai yang
> ditandatangani, sehingga "apa yang disepakati" dan "berapa nilainya sekarang"
> tetap dua pertanyaan dengan dua jawaban.
>
> `value_change` **bertanda**: negatif adalah pekerjaan kurang, sama lazimnya
> dengan pekerjaan tambah. Penjaga yang penting adalah lantainya — pengurangan
> tidak boleh menurunkan nilai kontrak di bawah yang sudah ditagihkan; kontrak
> yang nilainya lebih kecil daripada jumlah invoice-nya sendiri tidak dapat
> disajikan laporan mana pun.
>
> Termin yang sudah ada **tidak pernah diubah**. Menyebar ulang persentase pada
> jadwal yang termin awalnya sudah ditagih berarti menulis ulang riwayat
> penagihan; pekerjaan tambah ditagih lewat termin baru.

Kontrak yang sudah `Approved` **permanen tidak dapat diubah**
(`DocumentStatus::isEditable()` hanya `Draft` dan `Rejected`). Tidak ada entitas
change order, tidak ada `original_value`, tidak ada revisi jadwal termin.

Pekerjaan tambah-kurang yang sah karena itu **tidak punya jalur sama sekali**.
Dua-duanya salah: membuat kontrak kedua (memutus relasi satu-proyek-satu-kontrak)
atau menyunting basis data langsung.

Untuk kontraktor, ini bukan fitur pinggiran — CCO adalah kejadian normal pada
hampir setiap proyek.

### 3.4 Kendali biaya berhenti di anggaran-vs-aktual ✅ SEBAGIAN

> Diperbaiki 27 Juli 2026 — dua dari tiga.
>
> **Biaya komitmen.** `CommitmentService` menjumlahkan PO disetujui/ditutup
> dikurangi yang sudah ditagih, dan SPK disetujui dikurangi opname bruto yang
> sudah disetujui. Muncul di laporan profitabilitas sebagai `committed`, dengan
> `budget_remaining = RAP − aktual − komitmen`. **Tidak dibukukan ke mana pun**:
> belum ada yang diterima, dan akrual atas pekerjaan yang belum dikerjakan itu
> keliru. PO dibandingkan atas DPP, bukan total ber-PPN — kalau tidak, setiap PO
> yang sudah lunas ditagih akan tampak masih 11% tersisa.
>
> **Biaya alat ke proyek.** `DeploymentService::returnDeployment()` kini mencatat
> `hari × daily_rate_internal` ke `fin_project_costs` kategori `equipment`.
> Sebelumnya angka itu dihitung sebagai "saran" yang tidak ada yang mengambil,
> sehingga **alat milik sendiri gratis di setiap P&L proyek**.
>
> Sengaja **tidak masuk buku besar**: pembebanan internal adalah alokasi, bukan
> transaksi dengan pihak mana pun — uangnya sudah keluar saat alat dibeli dan
> diakui sebagai penyusutan di 6-3100. Membukukannya lagi berarti menghitung aset
> yang sama dua kali di tingkat perusahaan. Konsekuensinya harus diketahui saat
> membaca laporan: biaya proyek memuat alokasi ini dan neraca saldo tidak,
> sehingga keduanya berbeda tepat sebesar pembebanan alat internal.
>
> **Belum:** earned value / biaya sampai selesai (EAC). Itu perlu menggabungkan
> progres terukur dengan biaya, dan basis pengukurannya adalah keputusan yang
> sama dengan §3.5.

`projectProfitability` membandingkan RAP dengan biaya aktual, dan itu berjalan
baik. Yang tidak ada:

- **Biaya komitmen.** PO dan SPK yang sudah disetujui tetapi belum ditagih tidak
  dijumlahkan di mana pun (`openPurchaseOrderCount()` hanya menghitung *jumlah*
  dokumen). PM yang sudah mengikat Rp 5 miliar tidak melihatnya.
- **Earned value / biaya sampai selesai (EAC).** Nol hasil untuk pencarian
  `earned value|cost to complete|estimate at completion` di seluruh kode. Data
  progres (`prj_weekly_progress`, roll-up WBS berbobot) dan data biaya
  (`fin_project_costs`) tidak pernah bertemu dalam satu perhitungan.
- **Biaya alat ke proyek.** `DeploymentService::utilization()` menghitung
  `hari × daily_rate_internal` dan menamainya `internal_charge_suggestion` —
  **tidak ada yang mengambil saran itu**. Tidak ada pemanggilan
  `ProjectCostService::record()` di seluruh `Modules/Assets`.

### 3.5 Pengakuan pendapatan berbasis penagihan, bukan progres ✅ SELESAI

Pendapatan diakui saat invoice **disetujui**, dengan `dpp` berasal dari
`ContractTermin::amount` — persentase termin kontraktual, bukan pekerjaan
terukur. Tidak ada WIP, tidak ada aset kontrak, tidak ada akrual.

Proyek yang 60% selesai dengan baru 20% ditagih melaporkan 20% pendapatan dan 60%
biaya. Laba-rugi salah secara sistematis di kedua arah, setiap periode.

> **Ini yang paling tidak saya sarankan dikerjakan lebih dulu.** Bukan karena
> kecil, tetapi karena PSAK 72 adalah **kebijakan akuntansi**, bukan pilihan
> teknis. Ini butuh akuntan perusahaan menetapkan metode ukur progres dan akun
> aset/liabilitas kontrak, lalu barulah kode. Membangunnya duluan berarti
> menebak kebijakan orang lain.


**Dikerjakan 28 Juli 2026, atas arahan pemilik untuk menelaah PSAK 115/PSAK 72.**
Hasil telaahnya menjadi kebijakan tertulis di **docs/KEBIJAKAN-PENDAPATAN.md**
(PSAK 115 = PSAK 72 yang dinomori ulang DSAK IAI, efektif 1 Jan 2024; substansi
IFRS 15), dan mesinnya hidup di **Keuangan › Pengakuan Pendapatan**:

- **Metode input biaya-ke-biaya**: % = biaya kumulatif ÷ EAC; EAC dari RAP
  (yang ditolak tidak pernah dipakai), dapat ditelaah manajemen per baris dan
  telaahnya bertahan saat hitung ulang. Tanpa estimasi andal → margin nol
  (para 45). Kontrak multi-proyek menjumlah kedua basis biayanya.
- **Run bulanan draf → posting** (dokumen POC/…): satu jurnal penyesuaian per
  periode ke 1-1360 Aset Kontrak / 2-1410 Liabilitas Kontrak, akun pendapatan
  diresolusi PERSIS seperti jurnal invoice (tipe proyek menang) sehingga
  disagregasi per akun tidak pernah bercabang. Kontrak merugi → provisi penuh
  seketika (PSAK 237: 2-1700 / 5-1600), dilepas seiring kemajuan.
- **Posting selalu menghitung ulang dari basis data** — draf hanyalah
  pratinjau; urutan periode dipaksa maju; periode masa depan ditolak; periode
  fiskal tertutup ditolak bahkan tanpa pergerakan. Semuanya lahir dari tinjauan
  adversarial 34-agen (21 temuan ditegakkan, semua diperbaiki — termasuk draf
  basi yang menggandakan catch-up dan salah ketik Desember yang mengunci mesin).
- Data live membuktikan urgensinya: DP 20% Graha Sentosa menaruh Rp 9,7 M di
  pendapatan untuk pekerjaan 0,54% — run perdana memindahkannya ke liabilitas
  kontrak (penyesuaian −Rp 9.437.524.000), teruji 23 tes.

EVM (earned value management) tetap belum dibangun — nilai hasil kini tersedia
per baris run (pendapatan kumulatif = EV dalam rupiah), tinggal disandingkan
dengan kurva-S bila diinginkan kemudian.

### 3.6 Tidak ada register K3 / SMK3 ✅ SELESAI

Seluruh permukaan keselamatan adalah satu kolom teks bebas
`prj_daily_reports.safety_notes`. Data seed-nya sendiri menunjukkan yang hilang:
*"Satu near-miss material jatuh dari lantai 5"* — kejadian yang wajib dilaporkan
menurut PP 50/2012 dan PUPR 10/2021, terkubur dalam prosa, tanpa tingkat
keparahan, kategori, tindakan korektif, penanggung jawab, atau tanggal penutupan.
Tidak ada cara menanyakan "semua insiden kuartal ini" atau membuat laporan K3
bulanan.

**Dikerjakan 28 Juli 2026.** Register kecelakaan kerja `prj_safety_incidents`,
dengan tindak lanjut dan laporan K3 bulanan.

- Setiap insiden punya waktu kejadian **berikut jamnya** (pola shift adalah
  separuh dari yang dicari sebuah investigasi K3), lokasi di site, keparahan
  (`near_miss` … `fatality`), jenis kejadian, jumlah orang terlibat, dan hari
  kerja hilang.
- Tindak lanjut: tindakan segera, penyebab dasar, tindakan korektif, penanggung
  jawab (`hr_employees`), target selesai, tanggal penutupan.
  **Insiden tidak dapat ditutup** tanpa penyebab dasar, tindakan korektif, dan
  penanggung jawab — register yang bisa dikosongkan dengan mencentang "selesai"
  melaporkan nol dan tidak mengajarkan apa pun. Insiden yang sudah ditutup dapat
  **dibuka kembali** bila tindakan korektifnya ternyata tidak efektif; membuat
  baris kedua untuk kejadian yang sama akan menghitungnya dua kali di setiap rate.
- Dua pertanyaan yang disebut asesmen kini bisa dijawab: `GET
  projects/safety-incidents?from=…&to=…` (semua insiden satu kuartal) dan
  `?overdue=1` (tindakan korektif yang lewat target — satu-satunya angka yang
  ditanyakan saat safety walk).
- **Laporan K3 bulanan** di layar "Laporan K3": frequency rate, severity rate,
  rincian per keparahan dan per jenis kejadian, hari sejak insiden tercatat
  terakhir. Jam kerja orang diturunkan dari `prj_daily_reports.manpower_count`
  dikali `projects.working_hours_per_day` (baru, dapat disetel; 7 jam untuk pola
  6 hari kerja). Dasarnya ikut ditampilkan, karena rate itu bergantung pada
  laporan harian yang benar-benar diisi.
- Near miss dan P3K **dicatat tetapi tidak masuk frequency rate**, mengikuti
  konvensi ILO/OSHA: menghitungnya akan membuat lokasi yang jujur melapor tampak
  lebih buruk daripada lokasi yang tidak melapor sama sekali.
- Periode tanpa jam kerja tercatat melaporkan rate **null, bukan 0,00** — lokasi
  tanpa laporan harian punya rate yang tidak diketahui, bukan yang sempurna.
- Near-miss yang selama ini hidup sebagai prosa di `safety_notes` (DRP/2026/03/0003)
  kini menjadi baris pertama register, lengkap dengan penyebab dan tindakan
  korektifnya. Field `safety_notes` diberi keterangan bahwa kejadian dicatat di
  register, bukan di sana.

> **Ditemukan saat verifikasi, di luar §3.6:** `modal()` selalu memanggil
> `onClose` — termasuk saat ditutup oleh handler submit-nya sendiri. Karena
> `confirmDialog()` dan `promptFields()` menutup dialog **sebelum** memanggil
> `resolve()`, promise-nya sudah terlanjur settle sebagai `false`/`null`.
> Akibatnya **setiap aksi berjendela di SPA diam-diam tidak melakukan apa-apa**:
> "Setujui" beserta catatannya, "Tolak" beserta alasannya, "Hitung Payroll", dan
> semua aksi ber-`confirm:` lain. Aksi hapus selamat karena memakai `onConfirm`.
> Diperbaiki dengan memanggil `resolve()` sebelum `close()`.

---

## Tingkat 4 — kegunaan sehari-hari

### 4.1 Tidak ada satu pun dokumen cetak ✅ SELESAI

**Tidak ada invoice, PO, BAST, atau slip gaji yang bisa dicetak** — bukan sebagai
dokumen. Yang ada hanya Ctrl-P pada halaman detail web: daftar key/value setiap
field yang dikembalikan API, dikurangi navigasi.

- `barryvdh/laravel-dompdf` **sudah terpasang di `composer.json` dan tidak dipakai
  sama sekali** (nol pemanggilan di seluruh kode).
- `resources/views/` kosong; tidak ada satu pun berkas Blade di repositori.
- Gaya cetak seluruhnya adalah tujuh baris CSS: sembunyikan navigasi, putihkan
  latar. Tidak ada kop surat, logo, blok tanda tangan, nomor halaman, atau
  pengulangan header tabel.
- `core_company.logo_path` ada di skema dan **tidak dirujuk di mana pun** —
  kolom paling mati di basis data ini.
- `fin_ar_invoices.terbilang` dihitung dan **disimpan** justru supaya bisa
  dicetak di dokumen. Dokumennya tidak ada; nilainya tampil sebagai baris
  key/value biasa.
- `svc_field_reports.customer_sign_name` merekam tanda tangan pelanggan atas
  berita acara yang tidak bisa dicetak untuk ditandatangani.

Layar payroll mencetak satu tabel lebar berisi semua karyawan — **tidak bisa
mencetak slip satu karyawan**, dan tidak ada layar per-slip sama sekali.

**Dikerjakan 28 Juli 2026.** Empat dokumen PDF melalui satu kop surat bersama:
invoice AR, purchase order, BAST, dan slip gaji per karyawan.

- `Modules/Core/Services/DocumentPdfService.php` + empat template Blade di
  `Modules/Core/Resources/views/documents/` (namespace `coredoc::`, satu-satunya
  Blade di aplikasi). `barryvdh/laravel-dompdf` akhirnya dipakai.
- Rute `GET api/core/print/{ar-invoices|purchase-orders|bast|payslips}/{id}`,
  masing-masing di balik izin `view` modul pemilik dokumennya — mencetak dokumen
  adalah membacanya, dalam bentuk lain.
- SPA mengunduhnya sebagai blob (`public/app/js/print.js`); `<a href>` biasa
  tidak membawa header `X-Api-Token` dan akan 401. Tombol PDF muncul di layar
  detail invoice, PO, dan BAST, serta per baris slip di layar payroll.
- `fin_ar_invoices.terbilang` akhirnya tercetak di tempat yang menjadi alasan
  nilai itu disimpan; PO dan slip gaji dieja saat cetak lewat `Terbilang`.
- `prj_bast.customer_representative` akhirnya punya halaman untuk ditandatangani,
  lengkap dengan tanggal jatuh tempo dan nilai retensi yang mulai berjalan.
- `core_company.logo_path` akhirnya dirujuk: logo disisipkan sebagai data URI
  (dompdf dijalankan tanpa akses jaringan), dibaca lewat disk `public` sehingga
  path yang menyimpang tidak keluar dari direktori storage. Logo yang hilang,
  kebesaran, atau bukan gambar tidak menggagalkan dokumen — hanya tidak muncul.
- Nama bulan Indonesia dieja di service, bukan lewat `translatedFormat()`:
  `APP_LOCALE` masih `en` dan tidak ada direktori `lang/`, jadi memindah locale
  aplikasi demi kata "Juli" akan menghapus terjemahan seluruh pesan validasi.
- `enable_font_subsetting` dihidupkan; tanpa itu dompdf menyertakan seluruh
  DejaVu Sans dan satu invoice satu halaman berbobot 1,2 MB (kini ± 31 kB).

Yang belum: berita acara dari `svc_field_reports` (laporan lapangan servis) dan
kwitansi pembayaran. Keduanya mengikuti pola yang sama begitu diperlukan.

### 4.2 Tidak ada impor massal data master ✅ SELESAI

`maatwebsite/excel` **terpasang dan tidak dipakai sama sekali**. Satu-satunya
parser CSV adalah importir rekening koran, yang khusus bank.

`ProductionSeeder` menghasilkan bagan akun dan dua pohon kategori. **Item: 0.
Karyawan: 0. Vendor: 0. Pelanggan: 0. AHSP: 0.**

Memuat 2.000 item berarti 2.000 formulir satu per satu. AHSP paling parah:
setiap analisa adalah header plus N komponen, dan satu-satunya method yang bisa
memuatnya massal (`BoqService::importItemsFromAhsp`) **tidak punya rute HTTP** —
pemanggilnya hanya sebuah tes.

Tidak ada ekspor data master juga.

**Dikerjakan 28 Juli 2026.** Impor **dan** ekspor massal untuk empat tabel master
yang wajib ada saat go-live: item, vendor, pelanggan, karyawan. `maatwebsite/excel`
akhirnya dipakai — `.xlsx` dan `.xls` lewat pustaka itu, `.csv` dibaca sendiri.

- **Pratinjau terpisah dari simpan.** Berkas diurai dan divalidasi dua kali: sekali
  untuk menunjukkan apa yang akan terjadi, sekali untuk melakukannya. Impor yang
  baru melaporkan kesalahannya setelah menulis separuh baris lebih buruk daripada
  tidak ada impor.
- **Satu baris buruk tidak membatalkan 1.999 baris baik**, dan juga tidak
  tersimpan setengah: validasi per baris, penyimpanan dalam satu transaksi yang
  hanya mencakup baris yang lolos. Baris yang ditolak dilaporkan lengkap dengan
  **nomor barisnya di berkas**.
- **Pencocokan pada kode**, bukan pada urutan: kode yang sudah ada **diperbarui**,
  bukan digandakan. Itu yang membuat mengulang berkas yang sudah diperbaiki aman
  — dan mengulang adalah yang sebenarnya orang lakukan.
- **Kolom dicocokkan lewat judulnya**, bukan posisinya, karena ekspor dari sistem
  lama tidak pernah dalam urutan kita. Kolom yang tidak ada di berkas **tidak
  diubah**, sehingga berkas parsial ("perbaiki nomor rekening semua orang") tidak
  mengosongkan kolom lain.
- **Ekspor mengembalikan bentuk yang sama persis** dengan yang diterima importir.
  Ekspor → ubah di Excel → impor kembali adalah jalur ubah-massal, dan itu pula
  alasan ekspor ini ada.
- Hal-hal yang membuat impor gagal di dunia nyata, ditangani dan diuji: angka
  `1.250.000,50` gaya Indonesia; tanggal `03/04/2026` yang berarti 3 April dan
  bukan 4 Maret; NIK 16 digit yang dibaca spreadsheet sebagai bilangan lalu
  menjadi `3.2010101019E+15`; BOM UTF-8 yang membuat kolom pertama tidak cocok;
  pemisah titik koma; dan kode yang muncul dua kali dalam satu berkas.
- **Sel ekspor yang diawali `=`, `+`, `-`, atau `@` dinetralkan** dengan apostrof.
  Nama vendor adalah teks yang diisi orang lain, dan berkas itu dibuka di Excel
  pada mesin seseorang.
- Layar "Impor Data Master" membawa izinnya sendiri di sidebar, sehingga petugas
  gudang (`inv.create`) sampai ke sana tanpa perlu hak atas sisa menu Sistem.
  Impor menuntut hak **tambah dan ubah**, karena ia memperbarui baris yang ada.

AHSP sengaja tidak masuk: satu analisa adalah header plus N komponen, dan
memaksakannya ke lembar datar akan diam-diam menghilangkan komponen.
`BoqService::importItemsFromAhsp` masih tanpa rute HTTP.

> **Diperbaiki sekalian:** `visibleNav()` di SPA memetakan `group.items.filter(() => true)`
> — penyaring izin per-item yang jelas dimaksudkan tetapi tidak pernah ditulis.
> Kini item boleh membawa `perm` sendiri (termasuk larik "salah satu dari"), dan
> grup ikut tampil bila ada satu item yang diizinkan.

### 4.3 Pemilih referensi diam-diam terpotong di 500 baris ✅ SELESAI

> Diperbaiki 27 Juli 2026. `lookup.js` kini menelusuri halaman sampai habis
> (batas 20 halaman / 10.000 baris) dan **memberi tahu** bila batas itu tercapai,
> alih-alih menyerahkan daftar yang pendek tanpa kabar. Perbaikan sesungguhnya
> tetap ketik-cari di sisi server; ini menghentikan kegagalan senyapnya.

Setiap field `type: 'lookup'` adalah `<select>` biasa yang diisi dari satu
pengambilan `per_page: 500`, tanpa ketik-cari.

Dengan 2.000 item, **item ke-501 dan seterusnya tidak dapat dipilih** pada baris
PR, PO, penerimaan barang, pengeluaran barang, komponen AHSP, atau laporan
harian. Opsinya tidak ada di DOM. Tidak ada peringatan; pengambilannya sukses.
Berlaku sama untuk karyawan, vendor, pelanggan, proyek, dan akun.

Ini kegagalan senyap, dan ia muncul persis ketika perusahaan tumbuh.

### 4.4 Tidak ada pencarian global ✅ SELESAI

Tidak ada kotak cari di shell, tidak ada command palette. Navigasi hanya sidebar.
Pencarian per-daftar adalah `LIKE '%…%'` atas kolom pilihan — tidak terindeks —
dan **13 endpoint indeks tidak mendukung `q` sama sekali**, termasuk biaya
proyek, lampiran, notifikasi, dan pengguna.

**Dikerjakan 28 Juli 2026.** Satu kotak cari di header, dan **Ctrl/Cmd+K** dari
mana saja. Enter membuka hasil teratas — alasan orang mengetik kode dokumen utuh.

- Meliputi 16 jenis catatan: proyek, pelanggan, kontrak, penawaran, vendor, PO,
  PR, item, karyawan, aset, invoice, tagihan, pembayaran, SPK, tiket, insiden K3.
- **Hanya menanyakan yang boleh dibaca pemanggil.** Kelompok yang izinnya tidak
  dipegang **tidak di-query sama sekali**, bukan disaring setelahnya: kotak cari
  adalah oracle enumerasi paling mudah di sebuah ERP, dan "tidak ada hasil" harus
  identik dengan "ada hasil yang tidak boleh Anda buka".
- **Mencari yang benar-benar diketik orang** — kode dokumen, nama perusahaan,
  nama orang — bukan uraian atau catatan. Mencocokkan teks bebas akan mengubur
  invoice yang dicari di bawah setiap laporan harian yang menyebutnya.
- `%` dan `_` dicocokkan **harfiah** (`LIKE … ESCAPE '\'`); tanpa itu mengetik
  "50%" mengembalikan seluruh tabel. Kurang dari dua huruf tidak dicari.
- Tiap kelompok dibatasi 5 baris supaya satu jenis tidak mengubur sisanya, dan
  respons yang datang terlambat tidak menimpa hasil yang lebih baru.

Yang belum: 13 endpoint indeks itu masih tidak menerima `q`. Pencarian global
tidak melewatinya — ia menanyakan tabelnya langsung — tetapi filter per-daftar di
layar-layar tersebut tetap belum ada.

### 4.5 Zona waktu default UTC ✅ SELESAI

> Diperbaiki 27 Juli 2026 — `config/app.php` kini `env('APP_TIMEZONE', 'Asia/Jakarta')`.

`config/app.php` memakai `env('APP_TIMEZONE', 'UTC')`. `.env` produksi menyetel
`Asia/Jakarta` dengan benar, tetapi jalur pemasangan mana pun yang melewatkan
variabel itu berjalan tujuh jam mundur **tanpa galat**. Berdampak langsung pada
jam kerja SLA, `svc:generate-pm`, dan setiap `whereDate()` di laporan keuangan —
transaksi sore bisa jatuh ke neraca saldo hari sebelumnya.

Perbaikan: jadikan `Asia/Jakarta` sebagai default di `config/app.php`.

### 4.6 Endpoint tanpa layar ✅ SELESAI

Sudah ada di backend, belum dipanggil dari SPA: `tickets-sla-breaches`,
`assets/{id}/history`, `boqs/{id}/items`, `bank-reconciliation-overview`. Murah
untuk dipasang, dan `tickets-sla-breaches` khususnya berguna.

**Dikerjakan 28 Juli 2026.** Keempatnya kini punya layar.

- `tickets-sla-breaches` → menu **Layanan › Tiket Lewat SLA**: satu pertanyaan
  yang ditanyakan manajer layanan tiap pagi, lengkap dengan berapa lama tiap
  tiket sudah terlambat.
- `assets/{id}/history` → layar detail aset diganti dengan tampilan riwayat:
  mobilisasi, perawatan, dan penyusutan. `show()` hanya memuat kategori dan
  mobilisasi aktif, jadi ke mana mesin pernah pergi dan bagaimana nilai bukunya
  sampai di angka sekarang tidak terjangkau dari layar — padahal itulah sebagian
  besar isi pertanyaan orang kepada sebuah register aset.
- `boqs/{id}/items` → tabel "Seluruh item (datar)" di detail BOQ: bentuk yang
  disalin orang ke spreadsheet, yang tidak bisa diberikan tampilan berkelompok.
- `bank-reconciliation-overview` → tab **Ringkasan Semua Rekening** di layar
  rekonsiliasi: seluruh rekening sekaligus, dengan rekening yang jembatannya
  tidak cocok atau yang sama sekali tidak dapat direkonsiliasi ditampilkan apa
  adanya, bukan disembunyikan di balik tanda hubung.

---

## Yang saya sarankan dikerjakan lebih dulu

**Jembatan payroll → buku besar (§1.1).**

Alasannya: ia celah kebenaran terbesar, batasannya jelas, dan seluruh mesinnya
sudah ada — `JournalService::autoPost()`, `ProjectCostService::record()`, dan
akun `6-1100 / 6-1200 / 5-1200 / 2-1210` sudah di bagan akun. Tidak ada kebijakan
baru yang perlu diputuskan orang lain, tidak seperti §3.5.

Bentuknya, mengikuti pola `ApBillService::approve()` yang sudah ada:

```
Saat payroll run disetujui —
  Dr 6-1100 Beban Gaji & Tunjangan      gaji pokok + tunjangan
  Dr 6-1200 Beban BPJS & Kesejahteraan  iuran perusahaan
      Cr 2-1210 Hutang PPh 21                PPh 21 terpotong
      Cr 2-12xx Hutang BPJS                  iuran karyawan + perusahaan
      Cr 2-11xx Hutang Gaji                  yang dibayarkan
```

Yang perlu diputuskan sebelum menulis kode, dan sebaiknya oleh akuntan
perusahaan, bukan oleh saya:

1. **Akun hutang gaji dan hutang BPJS** belum ada di bagan akun — perlu
   ditambahkan, dengan kode yang mereka pilih.
2. **Alokasi ke proyek.** `hr_payslips` tidak punya `project_id`. Upah tukang di
   proyek seharusnya masuk `5-1200 Beban Upah Proyek` dan `fin_project_costs`
   kategori `labor`; gaji staf kantor masuk `6-1100`. Perlu aturan pemisahnya —
   per karyawan, per penugasan (`prj_manpower_assignments` sudah ada), atau
   proporsi.
3. **Apakah pembayaran gaji lewat `PaymentService`** (sehingga masuk rekonsiliasi
   bank) atau jurnal terpisah.

Setelah itu disepakati, pekerjaannya lugas: satu method di `PayrollService`,
dipanggil dari `approve()`, plus tes yang menegaskan neraca saldo seimbang dan
biaya proyek bertambah. Perkiraan: satu hari, ditambah waktu keputusan di atas.

**Sekalian dengan itu**, dua perbaikan kecil di keluarga yang sama:
retensi subkon yang dibayarkan (§1.2, ± 10 baris di `ApBillService` plus jurnal
ke `2-1500`) dan pencairan retensi pelanggan (§1.3, satu service + satu rute +
satu layar).

---

## Yang sengaja tidak saya sarankan

- **Jangan pindah ke MySQL sebelum ada cadangan (§0.1).** Migrasi basis data
  tanpa jalan pulang adalah taruhan yang tidak perlu.
- **Jangan bangun pengakuan pendapatan PSAK 72 (§3.5) sebelum akuntan menetapkan
  kebijakannya.** Kode akan mengunci kebijakan yang belum diputuskan.
- **Jangan bangun aplikasi mobile native.** Layar *Lapangan* sudah bekerja di
  peramban ponsel dengan kamera dan GPS; aplikasi kedua berarti dua kode yang
  harus disamakan selamanya dan app store di antara pengawas lapangan dan
  perbaikan.
- **Jangan hapus kolom mati** (`core_company.logo_path`, `prj_daily_reports.photos`,
  kolom tulis-saja pada rekening koran). Menghapus kolom memusnahkan apa pun yang
  sudah ditaruh pemasangan lain di sana; menandainya di model sudah cukup.
</content>
