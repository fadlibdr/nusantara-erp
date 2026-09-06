# Laporan Paket P1-D (ROADMAP-HASHMICRO Fase 1) — Dasbor yang bisa diatur

Branch: `feat/phase1-d` (dari `feat/phase1-c` — paket ini berdiri di atas preferensi
`dashboard.layout` dan registri P1-C, yang belum di-merge ke main) · 6 September 2026

> Status jujur: **dibangun, belum diverifikasi adversarial.** Yang ada di bawah adalah
> hasil putaran bangun plus uji yang ditulisnya sendiri. Dua putaran verifikasi ganda
> (aturan ROADMAP-DEVIASI §4/§6, pola yang menemukan ~40 cacat di P2–P8 dan 16 di P1-C)
> **belum dijalankan**, dan harness Playwright **tidak dapat dijalankan di lingkungan ini**
> — lihat § Yang belum diverifikasi. Tidak ada migrasi baru.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-D → status)

| Tugas | Status | Bukti |
|---|---|---|
| Katalog widget atas endpoint YANG SUDAH ADA | ✅ **19** (roadmap menulis 18 — lihat § Deviasi) | `views/widgets/registry.js` + 19 berkas `views/widgets/<id>.js`; 0 endpoint baru, 0 migrasi |
| 12 set bawaan per peran (T4.1) | ✅ | `DEFAULTS` di registry.js, satu baris per peran RoleSeeder; kesetaraan daftar peran dipaku `DashboardDefaultsTest` |
| Laci "Atur dasbor" (tambah/hapus/ukuran/urut) | ✅ | `views/dashsetup.js`; ukuran kecil/sedang/lebar, Naik/Turun tanpa vendor, seret-lepas SortableJS dimuat malas |
| Muat per batch 4 | ✅ | `const BATCH = 4` di dashboard.js, dipaku `DashboardWidgetRegistryTest::test_widgets_load_four_at_a_time` |
| `DashboardTileFailureTest` ditulis ulang memindai `views/widgets/*` | ✅ | berkas ber-`build(` **adalah** widget: wajib bercabang `failure(` di kode, wajib berpasangan satu-satu dengan katalog |
| Susunan tersimpan per pengguna | ✅ | preferensi `dashboard.layout` (P1-C), validator diperketat ke ISI lewat `Support\SpaWidgets` |

## Yang benar-benar berubah

**Dasbor menjadi penyusun.** `views/dashboard.js` tidak lagi menggambar satu angka pun (733
baris → 246, dan yang tersisa adalah kepala halaman, spanduk penjadwal, dan perulangan
pemuatan). Ia membaca susunan orangnya, menggambar **kerangka semua kartu lebih dulu** —
sehingga tinggi halaman tidak melompat saat jawaban berdatangan — lalu memuat **empat
sekaligus**, berurutan dari atas.

**Kenapa batch 4.** Sampai P1-C dasbor menembakkan seluruh sumbernya dalam satu
`Promise.all` (11 permintaan untuk direktur). Dengan susunan yang dipilih sendiri, "semua
sekaligus" berarti seseorang yang memasang 12 widget membuka 12 koneksi dari ponsel
lapangan dan tidak melihat apa pun sampai yang paling lambat selesai. Empat sekaligus:
jumlah permintaan serentak **tidak lagi bergantung** pada berapa banyak widget yang
dipasang orangnya.

**Satu widget yang gagal memuat ulang dirinya sendiri.** Sampai P1-C tombol "Coba lagi"
pada kartu yang gagal berarti menggambar ulang seluruh dasbor — 11 permintaan untuk
memperbaiki satu.

**Aturan Temuan 79 hidup satu kali.** `views/widgets/kit.js` memiliki `safe()`/`safeList()`
(nilai bertanda `loadFailure`), `failure()`, `failedStat()` (menulis `—`, tidak pernah
`Rp 0`) dan `failedBody()`. Menyalinnya ke 19 berkas adalah cara paling pasti membuat 18 di
antaranya menyimpang.

**Gerbang izin kotak masuk menjadi SATU.** Sampai P1-C `dashboard.js` menyebut
`session.can(ANY_APPROVE)` dua kali — sekali untuk permintaan `core/inbox`, sekali untuk
kartunya — dan `ApprovalInboxGateTest` menghitung keduanya, karena satu tanpa yang lain
berarti kartu kosong atau permintaan sia-sia. Sejak P1-D berkas `widgets/inbox.js` tidak
diimpor sama sekali kecuali izinnya lolos; keduanya tidak bisa lagi berselisih.

**Layar Laporan menerima `?tab=<kunci>`.** Tiga widget berjanji membuka laporannya (umur
piutang, umur hutang, proyeksi kas) dan sampai paket ini ketiganya mendarat di Neraca
Saldo — tab pertama, karena `state` modul `reports.js` satu-satunya yang memilih. Itu persis
temuan verifikasi P1-C tentang ubin yang menaut ke halaman yang mengulang angkanya lalu
berhenti (keputusan pemilik #2, § di bawah).

## Katalog (19 widget, endpoint yang sudah ada)

| id | judul | endpoint | izin |
|---|---|---|---|
| `ringkasan-uang` | Proyek, piutang & hutang | `core/dashboard/summary` | `prj.view` \| `fin.view` |
| `inbox` | Menunggu persetujuan Anda | `core/inbox` | `*.approve` |
| `tenggat` | Tenggat menipis & lewat | `core/deadlines` | — |
| `kalender` | Kalender acara | `core/calendar` | — |
| `ar-aging` | Umur piutang | `finance/reports/ar-aging` | `fin.view` |
| `ap-aging` | Umur hutang | `finance/reports/ap-aging` | `fin.view` |
| `proyeksi-kas` | Proyeksi kas | `finance/reports/cash-projection` | `fin.view` |
| `saldo-bank` | Saldo bank | `finance/reports/bank-balances` | `fin.view` |
| `siap-tagih` | Termin siap ditagih | `crm/contract-termins/billing-ready` | `fin.view` |
| `pajak` | Kewajiban pajak | `finance/tax-obligations` | `fin.view` |
| `evm` | Kinerja EVM portofolio | `projects/evm` | `prj.view` |
| `proyek-progres` | Progres proyek | `projects` | `prj.view` |
| `defect` | Temuan lapangan (defect) | `projects/defects/summary` | `prj.view` |
| `ncr` | NCR terbuka | `quality/ncr` (2×) | `qc.view` |
| `stok-minimum` | Stok di bawah minimum | `inventory/stock/low-stock` | `inv.view` |
| `po-outstanding` | PO belum diterima penuh | `procurement/reports/outstanding` | `prc.view` |
| `sla-tiket` | Tiket lewat SLA | `servicedesk/tickets-sla-breaches` | `svc.view` |
| `pipeline` | Pipeline penjualan | `crm/reports/pipeline` | `crm.view` |
| `payroll` | Payroll bulan berjalan | `hr/payroll-runs` | `hr.view` |

Tiga keputusan isi yang perlu dibaca:

- **`ncr` sengaja DUA permintaan.** "NCR terbuka" berarti `open` ATAU `under_correction` —
  cermin `NcrStatus::isOpen()`, angka yang sama yang dipimpin ubin launcher modul Mutu.
  Endpoint daftar hanya menerima satu status per permintaan, jadi pilihannya: menanyakan
  satu status dan menyebut angka yang **berbeda dari launcher**, atau menyaring sisi klien
  atas halaman pertama (persis Temuan 79). Keduanya berbohong; dua permintaan `per_page: 3`
  yang menjumlah `meta.total` tidak. Bila **salah satu** gagal, widget menulis `—` — bukan
  jumlah separuh yang percaya diri.
- **`proyek-progres` sengaja TIDAK menyebut jumlah proyek.** Daftarnya berhalaman, dan
  hitungan sisi klien atas halaman pertama adalah Temuan 79 itu sendiri. Angka "Proyek
  berjalan" yang benar ada di `ringkasan-uang` (dijumlah di SQL) dan di ubin launcher.
- **`pajak` memakai tanggal peramban** untuk "lewat tenggat setor", disalin persis dari
  layar Kalender Pajak karena endpoint-nya tidak mengirim `as_of`. Dua layar yang berdebat
  tentang berapa masa yang sudah lewat lebih buruk daripada satu perbandingan yang sedikit
  longgar; bila kelak server mengirim `as_of`, keduanya berpindah bersama.

## Susunan bawaan per peran (T4.1) — dan metrik "0 peran tanpa ubin"

Dua belas peran `RoleSeeder::intended()` punya satu baris masing-masing di `DEFAULTS`.
Metrik Fase 1 menuntut tidak ada peran yang mendarat di dasbor kosong. P1-C mencapainya di
launcher dengan **mengukur**: masuk sebagai 12 akun demo dan menghitung ubinnya. Ukuran
seperti itu benar pada hari ia diambil dan basi pada sunting berikutnya.

Di P1-D pertanyaan itu **menjadi uji**, dijawab dari dua sumber yang tidak saling menyalin:
peran dan izinnya dari `RoleSeeder::intended()` (yang benar-benar diseed), gerbang izin
widget dan susunan bawaan dari `registry.js` (yang benar-benar digambar SPA). Empat sifat
dipaku:

1. setiap peran seed punya baris `DEFAULTS`;
2. setiap entri menyebut widget dan ukuran yang ada;
3. **setiap peran melihat ≥ 1 widget** dengan izinnya sendiri (metriknya);
4. tidak ada entri bawaan yang **mati** — widget yang perannya tidak boleh lihat akan
   disaring `resolveLayout` tanpa suara, sehingga susunan yang tertulis delapan kartu
   tergambar lima dan tidak ada yang tahu kenapa.

Ditambah dua bagian yang MENOLAK (`sees()` bisa berkata tidak; setiap widget katalog dipakai
sedikitnya satu susunan bawaan), supaya tidak satu pun dari keempatnya lolos untuk daftar
apa pun.

## Uji

Baru:

- `DashboardWidgetRegistryTest` (7 uji / 304 asersi) — izin yang disebut katalog benar-benar
  dicetak `PermissionSeeder` (salah ketik `fin.veiw` menyembunyikan widget dari **semua**
  orang tanpa satu galat pun), rute kaki kartu benar-benar terdaftar, prefix modul benar-benar
  grup NAV, ukuran bawaan ada di antara ukuran yang ditawarkan, keranjang umur cermin
  `reports.js`, batch = 4.
- `DashboardDefaultsTest` (6 / 320) — § di atas.

Ditulis ulang:

- `DashboardTileFailureTest` (6 / 58) — dari satu berkas + daftar nama sumber yang
  dipelihara tangan, menjadi pemindaian folder yang tumbuh sendiri.

Diperbarui:

- `UserPreferencesTest` (18 / 112) — `dashboard.layout` sekarang punya ujinya sendiri
  (widget karangan ditolak dengan menyebut namanya, ukuran asing, duplikat, field titipan,
  bentuk lama P1-C), plus uji katalog-tak-terbaca yang membuktikan degradasi ke pemeriksaan
  bentuk. Uji plafon 16 KB berubah bentuk dan **menjadi lebih kuat**: karena tidak ada lagi
  kunci yang menerima teks bebas, yang dibuktikan sekarang adalah bahwa pemeriksaan byte
  berjalan **sebelum** pemeriksaan bentuk dan berhenti tepat di 16384 (16385 ditolak karena
  ukurannya; 16384 lolos ukuran dan jatuh pada bentuknya).
- `ApprovalInboxGateTest` (3 / 15) — mengikuti pindahnya gerbang ANY_APPROVE ke katalog.

`tests/Feature/Core` + `tests/Feature/Iam` sebelum perbaikan gerbang inbox: 803 hijau /
1 merah (6.068 asersi, 11 dilewati) — yang merah adalah `ApprovalInboxGateTest`, yang memang
menghitung dua pemanggilan `session.can(ANY_APPROVE)` di dashboard.js dan karena itu HARUS
merah pada paket ini; ia lalu ditulis ulang ke rantai gerbang yang baru. Angka setelah
perbaikan, dan suite penuh: (diisi).

## Deviasi

1. **19 widget, bukan 18.** ROADMAP menulis "18 widget atas endpoint YANG SUDAH ADA" dengan
   daftar isi yang berakhir "…". Dua hal dalam daftar itu tidak punya endpoint yang sudah
   ada:
   - **RAP vs realisasi tingkat portofolio TIDAK dibangun.** Yang ada adalah
     `finance/reports/project-profitability/{projectId}` — per proyek, butuh sebuah id, dan
     memilihkan satu proyek untuk pembaca adalah mengarang. Angka portofolio anggaran vs
     realisasi adalah isi **F-2** (Fase 2: "layar per proyek × bulan dan portofolio, angka
     SAMA dengan `BudgetGateService`, uji kesetaraan"). Membuat endpoint baru di sini
     melanggar batasan paket ini dan mendahului paket yang memilikinya.
   - **Ubin uang dasbor P1-C tidak disebut daftar itu sama sekali** — proyek berjalan,
     piutang, hutang, ketiganya dijumlah di SQL sejak Temuan 79. Katalog yang berhenti di 18
     akan MENGHAPUS ketiganya dari dasbor. Menukar ubin Temuan 79 demi angka 18 adalah
     pertukaran yang salah arah, jadi `ringkasan-uang` masuk sebagai widget ke-19.
2. **Laci "Atur dasbor" adalah modal, bukan panel geser.** Aplikasi sudah punya SATU tumpukan
   overlay dengan perangkap fokus, Escape bertingkat, penjaga "perubahan belum disimpan" dan
   pembersih combobox. Laci kedua berarti aturan fokus kedua yang harus benar sendiri, dan
   yang salah di sana bukan tampilan melainkan orang yang tidak bisa keluar dengan papan
   ketik. Yang dijanjikan ROADMAP adalah kemampuannya (tambah/hapus/ukuran/urut), bukan arah
   gesernya.
3. **`views/widgets/aging.js` bukan widget** (tidak punya `build(`): ia satu definisi
   keranjang umur untuk dua widget. Kesetaraannya dengan `reports.js` dipaku uji, karena
   salinan yang disengaja tetap salinan.

## Yang diperbaiki di luar lingkup, dan kenapa

- **PANDUAN §1.7a berbohong tentang kotak masuk.** Ia masih menulis "kartu ini hanya
  mencakup **11 jenis dokumen**" dan menyebut sembilan jenis — pengajuan cuti, pembayaran,
  BAST, ketiga izin lapangan — sebagai TIDAK tercakup. Itu benar sampai 2 September 2026;
  sejak `GET core/inbox` satu permintaan melayani **28 registri**, dan P1-C tidak
  memperbarui kalimatnya. Sebuah panduan yang memberi tahu seorang direktur bahwa pengajuan
  cuti tidak akan sampai kepadanya adalah alasan ia berhenti memeriksanya. Diperbaiki di
  sini karena paket ini memindahkan kartu itu menjadi widget.
- **Palet titik kalender pindah** dari `views/dashboard.js` ke `js/kalenderpalette.js`.
  Nilainya tidak berubah satu hex pun; yang berubah adalah arah ketergantungan — sejak
  kalender menjadi salah satu dari 19 widget, dasbor tidak lagi memilikinya.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Tidak ada verifikasi adversarial.** Aturan per paket menuntut dua verifier baca-saja
   sebelum merge. Belum dijalankan. Pola yang sama menemukan 16 temuan di P1-C, sepuluh di
   antaranya cacat sungguhan.
2. **Harness Playwright tidak bisa dijalankan di lingkungan ini** (`python3 -c "import
   playwright"` → `ModuleNotFoundError`; tidak ada peramban terpasang). Karena itu **tidak
   ada satu pun angka UI yang diukur** untuk paket ini: skenario **S23** ("dasbor diatur &
   gagal-jujur", yang diminta ROADMAP §4) belum ditulis maupun dijalankan, tidak ada
   tangkapan layar baru, dan `docs/bukti-uji/results-phase-1.json` **tidak** memuat P1-D.
3. **Tidak ada runtime JS di host ini**, jadi 24 berkas JS baru tidak pernah diurai satu kali
   pun — tidak oleh `node --check`, tidak oleh peramban. Yang menjaga mereka hari ini hanyalah
   uji grep PHP (yang memang menangkap satu rute salah: `proyeksi-kas` menaut ke `cashflow`,
   rute yang tidak pernah didaftarkan). Kesalahan sintaks atau nama impor yang salah **tidak
   akan terlihat** sampai seseorang membuka dasbor.
4. **Angka yang dijanjikan paket ini belum diukur**: jumlah permintaan dasbor per peran
   (target Fase 1 ≤ 10 direktur / ≤ 5 warehouse), jumlah modul JS yang benar-benar diunduh
   per susunan bawaan, dan perilaku laci di 390 × 844.

Yang bisa dikatakan hari ini adalah: seluruh uji Core+Iam hijau, dan setiap sifat yang
disebut laporan ini punya ujinya. Yang **tidak** bisa dikatakan adalah bahwa dasbornya sudah
pernah tergambar.

## Keputusan pemilik yang masih terbuka (dibawa dari P1-C)

1. **Jumlah permintaan dasbor naik satu** (`prefs.load()` pada setiap boot; 11 → 12, target
   ≤ 10). P1-D **menambah lagi ke arah lain**: jumlahnya kini ditentukan susunan orangnya,
   bukan kode — susunan bawaan direktur berisi 8 widget, dan `ncr` menghabiskan dua
   permintaan bila dipasang. Angka yang harus diukur S23 karena itu bukan "berapa permintaan
   dasbor", melainkan "berapa permintaan susunan BAWAAN tiap peran". Pilihan pemilik yang
   lama tetap berlaku (lipat preferensi ke `iam/auth/me`, atau catat baseline baru).
2. **Angka KPI tidak bisa diklik** (ubin launcher menaut ke beranda modul yang mengulang
   angkanya lalu menawarkan kartu tanpa filter). P1-D menjawabnya **sebagian dan hanya untuk
   dasbor**: setiap widget punya `route` ke layar yang memuat angkanya lengkap, dan tiga
   widget keuangan kini mendarat di TAB yang benar lewat `?tab=`. Usulan verifier — menambah
   `link` (rute + query) di setiap entri registri `ModuleCounts` sehingga launcher pun
   menunjuk daftar yang tersaring — **belum dikerjakan**.
