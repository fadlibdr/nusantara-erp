# Laporan Paket P1-D (ROADMAP-HASHMICRO Fase 1) — Dasbor yang bisa diatur

Branch: `feat/phase1-d` (dari `feat/phase1-c` — paket ini berdiri di atas preferensi
`dashboard.layout` dan registri P1-C, yang belum di-merge ke main) · 6 September 2026

> Status jujur: **dibangun, diukur di peramban, dan DIVERIFIKASI ADVERSARIAL satu putaran
> (dua lensa, 6 September 2026).** 22 temuan diangkat dan **22 diperbaiki**, masing-masing
> dengan uji atau syarat harness yang lebih dulu dibuktikan MERAH. Yang terpenting di
> antaranya, karena ketiganya kehilangan data atau membacanya salah tanpa satu galat pun:
>
> - **Susunan tersimpan diabaikan pada kunjungan pertama di peramban baru** — `prefs.load()`
>   selesai sesudah gambar pertama, jadi yang tergambar adalah bawaan peran (7 kartu di atas
>   baris server 3 kartu) dan tidak pernah memperbaiki dirinya; satu klik "Simpan" pada laci
>   yang terbuka di atas gambar itu MENIMPA susunan orangnya, permanen, dengan toast
>   "tersimpan". Laci juga menghapus entri yang izinnya sedang dicabut — kebalikan dari yang
>   dijanjikan docblock `resolveLayout`.
> - **Gambar dasbor lama tidak berhenti** saat digantikan: tiga klik "Muat ulang" berjarak
>   120 ms membayar 27 permintaan dengan 12 berjalan bersamaan, sementara paket ini menjual
>   "tidak pernah lebih dari 4". Terukur 9 permintaan / 4 serentak sesudah perbaikan.
> - **10 dari 19 kartu tidak menggambar kaki**, dan empat widget yang gagal tidak menawarkan
>   satu tombol pun — pemulihan satu-satunya adalah "Muat ulang" sehalaman, yaitu perilaku
>   P1-C yang paket ini menyatakan digantikannya. Terukur 71/71 kartu berkaki sesudahnya.
>
> Baris konsol harness juga berbohong: `scenario()` mencetak "ok" untuk skenario yang
> menjatuhkan syaratnya sendiri, dan seluruh kalimat "empat bagian, semuanya hijau" di atas
> bersandar pada baris itu. S23 kini menguji persistensi di KONTEKS PERAMBAN BARU (muat ulang
> di konteks yang sama dijawab cermin localStorage, dengan atau tanpa baris server).
> Putaran verifikasi KEDUA belum dijalankan. Tidak ada migrasi baru.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-D → status)

| Tugas | Status | Bukti |
|---|---|---|
| Katalog widget atas endpoint YANG SUDAH ADA | ✅ **19** (roadmap menulis 18 — lihat § Deviasi) | `views/widgets/registry.js` + 19 berkas `views/widgets/<id>.js`; 0 endpoint baru, 0 migrasi |
| 12 set bawaan per peran (T4.1) | ✅ | `DEFAULTS` di registry.js, satu baris per peran RoleSeeder; kesetaraan daftar peran dipaku `DashboardDefaultsTest` |
| Laci "Atur dasbor" (tambah/hapus/ukuran/urut) | ✅ | `views/dashsetup.js`; ukuran kecil/sedang/lebar, Naik/Turun tanpa vendor, seret-lepas SortableJS dimuat malas |
| Muat per batch 4 | ✅ | `const BATCH = 4` di dashboard.js, dipaku `DashboardWidgetRegistryTest::test_widgets_load_four_at_a_time` |
| `DashboardTileFailureTest` ditulis ulang memindai `views/widgets/*` | ✅ | berkas ber-`build(` **adalah** widget: wajib bercabang `failure(` di kode, wajib berpasangan satu-satu dengan katalog |
| Harness S23 ("dasbor diatur & gagal-jujur", ROADMAP §4) | ✅ | empat bagian di `docs/bukti-uji/harness-playwright.py`, hasil di `results-phase-1.json`, 5 PNG |
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
merah pada paket ini; ia lalu ditulis ulang ke rantai gerbang yang baru. Setelah perbaikan
dan setelah keenam temuan harness: **804 hijau / 6.072 asersi** (11 dilewati, 152 s).

**Suite penuh (SQLite) di `c6aadcf`: 3.907 uji / 19.730 asersi hijau, 11 dilewati, 560 s.**
MySQL: (diisi — job CI nightly).

### Harness S23 (Playwright, Chromium 1440×900 dan 390×844)

Empat bagian, semuanya hijau; hasil di `docs/bukti-uji/results-phase-1.json`, lima PNG
`s23-*-p1d.png`.

- **`S23_dashboard_per_role`** — 12 akun demo, satu per peran. **0 peran tanpa kartu**,
  **71 kartu** seluruhnya, **0 kartu berbadan kosong** (setiap kartu menggambar angkanya,
  keadaan kosongnya, atau kalimat gagalnya). Permintaan serentak per peran tidak pernah
  melewati anggarannya:

  | peran | kartu | permintaan seluruhnya | permintaan widget | serentak / anggaran |
  |---|---|---|---|---|
  | admin | 8 | 15 | 8 | 4 / 4 |
  | direktur | 9 | 15 | 9 | 4 / 4 |
  | project-manager | 8 | 15 | 9 | 5 / 5 |
  | site-manager | 5 | 12 | 6 | 5 / 5 |
  | estimator | 4 | 10 | 4 | 4 / 4 |
  | procurement | 4 | 10 | 4 | 4 / 4 |
  | warehouse | 5 | 11 | 5 | 4 / 4 |
  | finance | 9 | 15 | 9 | 4 / 4 |
  | finance-manager | 7 | 13 | 7 | 4 / 4 |
  | hr | 3 | 9 | 3 | 3 / 4 |
  | sales | 5 | 12 | 5 | 4 / 4 |
  | teknisi | 4 | 10 | 4 | 4 / 4 |

  "Anggaran" = 4 + jumlah widget dua-permintaan dalam susunan itu (hanya `ncr`), karena
  batch membatasi **widget**, bukan permintaan — project-manager dan site-manager memakai 9
  dan 6 permintaan untuk 8 dan 5 kartu justru karena `ncr` menjumlah dua status.

- **`S23_setup_drawer`** — tambah (Kalender acara), hapus (`ringkasan-uang`), ubah ukuran
  (`inbox` lebar → sedang), naikkan urutan (`proyeksi-kas` ke atas), Simpan, lalu **muat
  ulang halaman penuh**: sembilan kartu kembali dalam urutan dan ukuran yang persis sama,
  dan baris `dashboard.layout` di server memuat sembilan `{id,size}` yang sama. "Kembalikan
  ke bawaan" mengembalikan persis susunan awal direktur. 8 klik.

- **`S23_honest_failure`** — `core/dashboard/summary` dijatuhkan sungguhan (route abort) pada
  akun finance. Kartu **tetap digambar**, ketiga ubinnya menulis `—` + "Gagal dimuat",
  **tidak ada "Rp 0"**, dan **kedelapan kartu lain tetap termuat**. Ini bukti yang tidak bisa
  diberikan uji PHP: uji itu hanya membuktikan bahwa berkasnya MEMANGGIL `failure()`.

- **`S23_dashboard_mobile`** — teknisi di 390×844: kisi **satu kolom**, keempat kartu selebar
  362 px dan mulai di tepi kiri yang sama, **halaman tidak menggulung mendatar**, tinggi
  1.667 px.

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

## Yang ditemukan harness — enam cacat yang tidak satu pun tertangkap uji PHP

Semuanya sudah diperbaiki dan diukur ulang; dicatat di sini karena bentuknya berulang.

1. **Kartu 'lebar' tetap dua kolom di 390 px.** Pembatal `@media (max-width: 760px)` ditulis
   `.card.widget { grid-column: span 1 !important; }` — kurang spesifik daripada
   `.card.widget[data-size="lebar"]` di blok 1180 px, dan **di antara dua deklarasi
   `!important` yang menang adalah yang lebih spesifik, bukan yang belakangan**. Akibatnya
   kisi satu kolom menumbuhkan kolom IMPLISIT: terukur 84,6 px + 261,4 px, tiga kartu
   'sedang' terjepit di 85 px.
2. **Kisi tiga kolom membuang sepertiga layar.** 'sedang' adalah ukuran yang paling banyak
   dipakai, dan dua di antaranya tidak muat bersebelahan di tiga kolom (2 + 2 > 3): tiga
   baris berturut-turut pada dasbor direktur kosong di kanannya. Kisi sekarang **empat**
   kolom (kecil 1, sedang 2, lebar 4) — tanpa `grid-auto-flow: dense`, yang akan menyusun
   ulang kartu dan mematahkan urutan yang justru dipilih pemakainya.
3. **Win-rate ditulis "10.000%".** `PipelineReportService::rate()` sudah mengirim PERSEN
   (`won/decided × 100`); widget mengalikannya lagi. Formatnya kini sama persis dengan layar
   Analitik Win-Rate.
4. **`payroll` membaca `run.period`** yang tidak pernah dikirim `PayrollRunResource`
   (`period_year` + `period_month`; kolom "Periode" adalah kolom komposit schema.js) — em
   dash di setiap baris, tanpa satu galat pun.
5. **`pajak` membaca `row.label`/`row.period`** dengan cara yang sama;
   `TaxObligationResource` mengirim `tax_type_label`, `masa_year`, `masa_month`.
6. **`ncr` membaca `row.title`** yang tidak ada di `NcrResource` (yang ada `description`).

Cacat 4–6 satu keluarga: **membaca nama field yang tidak pernah dikirim server menggambar
em dash yang sempurna dan diam.** Tidak ada uji PHP yang bisa menangkapnya, karena tidak ada
kontrak yang dilanggar — hanya sebuah properti `undefined`. Yang menangkapnya adalah membuka
halamannya. Sisa 13 widget diperiksa satu per satu terhadap bentuk jawaban endpointnya yang
sebenarnya (diambil hidup, 6 Sep 2026); tidak ada temuan lain.

Ditambah satu cacat di harness sendiri: penghitung serentaknya mula-mula menghitung SELURUH
permintaan API, sehingga `prefs.load()` di boot yang tumpang tindih dengan batch pertama
terbaca sebagai pelanggaran batch pada finance-manager. Ia sekarang menghitung permintaan
widget saja dan mencatat jumlah seluruhnya terpisah.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Tidak ada verifikasi adversarial.** Aturan per paket menuntut dua verifier baca-saja
   sebelum merge. Belum dijalankan. Pola yang sama menemukan 16 temuan di P1-C, sepuluh di
   antaranya cacat sungguhan.
2. **Suite MySQL belum dijalankan** (job CI nightly). SQLite penuh hijau.
3. **Yang diukur S23 adalah data demo**: dua proyek, nol invoice AR terbuka, nol baris NCR,
   nol baris kewajiban pajak. Tiga widget (`ncr`, `pajak`, `stok-minimum`) karena itu hanya
   pernah terlihat dalam keadaan KOSONGNYA; tabel dan angkanya belum pernah tergambar dengan
   baris sungguhan. Nama field-nya sudah dicocokkan tangan dengan resource masing-masing,
   tetapi itu bukan hal yang sama dengan melihatnya.
4. **Seret-lepas SortableJS belum diuji di harness** — yang diuji S23 adalah jalur papan
   ketik (Naik/Turun), yang memang jalur yang dijanjikan bekerja tanpa vendor. Pemuatan malas
   vendornya sendiri belum pernah diukur.
5. **Target metrik Fase 1 tidak tercapai, dan bentuknya perlu diputuskan ulang** — lihat
   keputusan pemilik #1 di bawah.

## Keputusan pemilik yang masih terbuka (dibawa dari P1-C)

1. **Target metrik Fase 1 ≤ 10 permintaan (direktur) / ≤ 5 (warehouse) tidak tercapai, dan
   sekarang ada angkanya.** Terukur 6 Sep 2026: **direktur 15** (9 widget + 6 shell),
   **warehouse 11** (5 widget + 6 shell). Sebabnya bukan pemborosan melainkan perubahan
   bentuk: jumlah permintaan dasbor kini ditentukan **susunan orangnya**, bukan kode —
   susunan bawaan direktur berisi 9 widget, masing-masing satu angka yang dulu tidak ada di
   dasbor sama sekali (umur piutang, proyeksi kas, EVM, pipeline). Sebuah target berupa satu
   angka tetap sudah tidak bisa dipenuhi oleh layar yang isinya dipilih pemakainya.

   Tiga pilihan, dan ini keputusan pemilik:
   - **catat baseline baru per peran** (tabel S23 di atas) dan ubah targetnya menjadi
     "per WIDGET satu permintaan" — yang sudah benar hari ini kecuali `ncr`;
   - **kecilkan susunan bawaan** (mis. direktur 9 → 6 kartu) sampai muat di ≤ 10 seluruhnya;
   - **kurangi permintaan shell**: lipat preferensi ke `iam/auth/me` yang sudah diambil
     (keputusan P1-C #1 yang masih terbuka) — menghemat satu, tidak cukup sendirian.
2. **Angka KPI tidak bisa diklik** (ubin launcher menaut ke beranda modul yang mengulang
   angkanya lalu menawarkan kartu tanpa filter). P1-D menjawabnya **sebagian dan hanya untuk
   dasbor**: setiap widget punya `route` ke layar yang memuat angkanya lengkap, dan tiga
   widget keuangan kini mendarat di TAB yang benar lewat `?tab=`. Usulan verifier — menambah
   `link` (rute + query) di setiap entri registri `ModuleCounts` sehingga launcher pun
   menunjuk daftar yang tersaring — **belum dikerjakan**.

## Gerbang rilis Fase 1 (ditambahkan 7 September 2026)

Paket ini ikut rilis `0937dec` (P1-C…P1-G) yang di-merge ke main dan ter-deploy 6–7 Sep.
Suite penuh di commit rilis: **SQLite 3.991 uji / 20.656 asersi hijau** (11 dilewati).
Leg MySQL semula TIDAK bisa dijalankan (berkas kredensial Fase 0 hilang bersama scratchpad);
pemilik memberi kata sandi baru 7 Sep dan leg itu dijalankan: pertama **29 kegagalan**, semuanya
satu sebab — `Schema::drop()` di dalam uji adalah COMMIT IMPLISIT di MySQL, sehingga satu
`Schema::drop('ast_assets')` di uji ekspor menjatuhkan 22 uji Finance sesudahnya. Diperbaiki
lewat `FixtureSchema::withMissingTable()` (ganti nama, bukan hapus; `89ba8c9`), dan **MySQL 8.0.46
di rilis Fase 1 kini 3.991 uji / 20.669 asersi hijau** (6 dilewati, 23 mnt 16 dtk).

