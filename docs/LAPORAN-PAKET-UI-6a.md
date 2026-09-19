# Laporan Paket UI-6a — Keuangan: laporan yang akhirnya menyebut namanya sendiri

> Domain pertama dari empat (keputusan pemilik): **Keuangan** → Proyek → Pengadaan+Persediaan →
> SDM/Layanan/Aset. Layar yang disentuh: `#/reports` (delapan tab), `#/buku-besar`,
> `#/bank-recon`, `#/kas-kecil`, `#/tax-exports`, `#/periods`.

---

## 1. Cacat yang membuka paket ini: delapan laporan, satu judul

§27 menuntut audit lebih dulu. Auditnya dijalankan dengan `emulate_media('print')` pada kedelapan
tab `#/reports` di pohon UI-5, 18 Sep 2026, dan hasilnya sama untuk kedelapannya:

```
-- trial-balance   judul di kertas: ['h1:Laporan Keuangan', 'div:Dihitung dari jurnal yang sudah diposting.']
-- profit-loss     judul di kertas: ['h1:Laporan Keuangan', 'div:Dihitung dari jurnal yang sudah diposting.']
-- balance-sheet   judul di kertas: ['h1:Laporan Keuangan', 'div:Dihitung dari jurnal yang sudah diposting.']
-- cash-flow       judul di kertas: ['h1:Laporan Keuangan', 'div:Dihitung dari jurnal yang sudah diposting.']
…  (delapan dari delapan)
```

Sebabnya ada di blok cetak `app.css` dan sudah ada sejak lama, dengan alasan yang benar:

```css
.nav, .brand, .header, .page-head .actions, .filters, .pager,
.toasts, .overlay, .tabs { display: none !important; }
```

`.tabs` menyebut laporan mana ini. `.filters` menyebut periodenya. Keduanya adalah **kontrol**, dan
kontrol memang tidak ikut ke kertas. Tetapi begitu keduanya hilang, tidak ada apa pun yang tersisa
untuk menjawab dua pertanyaan pertama yang ditanyakan siapa pun yang memegang lembarnya: *laporan
apa ini, dan untuk periode mana.* Dua lembar **Laba Rugi** untuk dua rentang tanggal berbeda keluar
dari printer **tidak bisa dibedakan sama sekali**.

Prototipe UI-0 sudah menamai masalahnya, di komentar bloknya sendiri: *"laporan tanpa kepala tidak
bisa dilampirkan ke mana pun"*. Aplikasi juga sudah mengakuinya — **di satu layar**: `.print-title`
ada di `app.css` khusus untuk layar run PSAK 115, dengan komentar *"kertas yang dilampirkan KAP
sebagai working paper harus menyebut nama laporannya"*. Satu layar punya jawabannya; sembilan
laporan keuangan tidak.

Dua pengecualian yang ditemukan audit, dan keduanya kebetulan — keduanya kalimat di dalam sebuah
`.alert` yang ada untuk mengabarkan **keseimbangan**, bukan untuk menamai laporan:

* Neraca Saldo membawa *"Periode September 2026 — buku seimbang."* Periodenya memang ada di kedua
  cabang kalimat itu, jadi ia bertahan; tetapi ia satu-satunya dari delapan tab yang begitu, dan
  ia tetap tidak menyebut **laporan apa** ini.
* Neraca membawa *"Neraca seimbang per 18 September 2026"* — dan tanggal itu **hilang persis ketika
  bukunya tidak seimbang**, karena cabang gagalnya berbunyi *"Neraca TIDAK seimbang: aset … vs
  kewajiban+ekuitas …"* tanpa satu pun tanggal. Saat lembarnya paling perlu diberi tanggal,
  tanggalnya pergi.

## 2. `reportHead()` — dan tiga baris yang sengaja tidak ada

Satu primitif baru di `ui.js`:

```js
reportHead('Laba Rugi', [
  ['Periode', '01 Jan 2026 – 18 Sep 2026'],
  ['Proyek',  'PRJ-2026-002 — Instalasi ELV & Data Center Bank Artha Nusantara'],
  ['Mata uang', 'IDR — rupiah penuh'],
])
```

Barisnya hanya parameter yang **benar-benar** dikirim klien atau digemakan server; baris bernilai
kosong dibuang oleh `reportHead()` sendiri, supaya pemanggil boleh menulisnya tanpa syarat dan tetap
tidak menjanjikan apa pun yang tidak ia punya. Itulah sebabnya baris **Proyek** muncul di Laba Rugi
hanya ketika sebuah proyek benar-benar disaring.

Tiga baris yang prototipe punya dan paket ini **tidak** ambil:

* **Entitas (nama PT, NPWP).** Tidak ada data perusahaan di sesi. Menambahkannya berarti mengubah
  kontrak API, yang brief melarang tanpa alasan kuat — keputusan yang **sama** yang membatalkan slot
  konteks perusahaan di UI-3a. Sebuah kop surat yang mengarang nama entitasnya lebih buruk daripada
  kop surat tanpa nama.
* **"Dihitung <jam>".** Jam klien tidak dipercaya di basis kode ini, dan itu bukan pendapat saya:
  `cashflow.js` sudah menuliskannya sebagai komentar — *"Jam klien tidak dipercaya: 'hari ini'
  adalah as_of dari server"*. Stempel waktu yang salah pada kertas yang dilampirkan ke working paper
  lebih buruk daripada tidak ada stempel.
* **Basis akrual/kas sebagai pilihan.** Tidak ada sakelarnya; endpoint-nya menghitung dari jurnal
  terposting, titik. Yang ditulis adalah yang benar: Arus Kas menyebut **"Langsung (PSAK 2)"**
  karena kontrak modulnya memang itu, dan Umur Piutang menyebut **"Sejak jatuh tempo"** karena
  `agingReport()` memang menghitung `daysOverdue` dari `due_date`.

Ukuran judulnya **berbeda di layar dan di kertas, dan itu keputusan**: di layar halaman sudah punya
`h1` dan tab yang menyebut laporannya, jadi judul ini kepala seksi (`--fs-section`, 14 px); di
kertas keduanya hilang, jadi di sanalah ia menjadi judul dokumen (`--fs-page`, 20 px). Tepi dan
latarnya juga hanya untuk layar — di kertas ia bukan kartu di antara kartu, ia kop.

Sesudahnya, sembilan laporan dengan sembilan judul, semuanya 20 px di kertas:

| laporan | parameter yang tercetak |
|---|---|
| Neraca Saldo | Periode · Keseimbangan · Mata uang |
| Laba Rugi | Periode · Proyek* · Mata uang |
| Neraca | Per tanggal · Keseimbangan · Mata uang |
| Laporan Arus Kas | Periode · Metode · Rekonsiliasi · Mata uang |
| Proyeksi Kas | Per tanggal · Horizon · Piutang lewat jatuh tempo · Mata uang |
| Umur Piutang Usaha | Per tanggal · Basis umur · Dokumen terbuka · Mata uang |
| Umur Hutang Usaha | Per tanggal · Basis umur · Dokumen terbuka · Mata uang |
| Profitabilitas Proyek | Proyek · Anggaran · Mata uang |
| Buku Besar | Akun · Periode · Saldo normal · Proyek* · Mata uang |

\* muncul hanya bila saringannya dipasang.

## 3. Separuh klien dari sebuah perbaikan server yang tidak pernah datang

Saat membaca `ReportController` untuk mengetahui parameter apa yang **boleh** disebut kepala
laporan, endpoint umur ternyata membawa komentar ini:

> `arAging()/apAging()` took no Request, so `GET /api/finance/reports/ar-aging?as_of=2026-06-30`
> answered 200 with as_of 2026-08-03 — an accountant reconciling the June aging against the June
> balance sheet got today's figures and no error saying so. Same validation and same default as
> `balanceSheet()`, because the two are read side by side and have to be capable of the same date.

Sisi server perbaikan itu **sudah mendarat**: `as_of` diterima, divalidasi, dan digemakan di
jawabannya. **Sisi kliennya tidak pernah.** Sampai paket ini layar Umur Piutang dan Umur Hutang
tidak punya kotak tanggal sama sekali dan tidak pernah mengirim parameternya — jadi akuntan yang
merekonsiliasi umur Juni terhadap Neraca per 30 Juni tetap mendapat angka hari ini, dan layarnya
tidak berkata apa-apa. Neraca di tab sebelahnya punya kotak itu sejak dulu.

Kotaknya ditambahkan, dan aturan pengirimannya dipilih supaya bawaannya **tidak berubah sebit pun**:
yang dikirim hanya tanggal yang pengguna pilih; kosong berarti parameternya tidak ikut sama sekali,
persis perilaku hari ini. Kotaknya lalu **diisi dari `report.as_of`** — jam server, bukan jam klien.

Terverifikasi di peramban: `finance/reports/ar-aging?as_of=2026-06-30`, dan kepalanya menggemakan
kembali **"30 Juni 2026"**.

## 4. `.tone-*` yang selama ini dikalahkan kelas komponen

`.tone-pos` / `.tone-neg` / `.tone-warn` lahir di UI-2 dan duduk di baris ~343 `app.css`. Dua kelas
komponen menyetel warnanya sendiri **sesudah** itu, pada spesifisitas yang sama: `.muted` (baris
386) dan `.cell-sub` (baris 1099). Akibatnya `.cell-sub.tone-neg` tergambar **abu-abu, bukan merah**
— dan tidak ada yang gagal, tidak ada peringatan.

Ketiganya dipindah ke **akhir** berkas, dengan komentar yang menyebut sebabnya di tempat kejadian,
dan **S43** mendapat gerbang `tone_cascade` yang mengukur `.tone-neg`, `.cell-sub.tone-neg` dan
`.muted.tone-neg` terhadap probe `var(--danger)` hidup. Dibuktikan merah di **kedua tema** sebelum
dipercaya:

```
GAGAL: light: .neg_on_cell_sub rgb(94, 104, 116) != --danger rgb(198, 40, 40) …
```

Buahnya langsung terpakai: `reports.js` punya `el('.cell-sub', { style: { color: variance < 0 ?
'var(--danger)' : 'var(--muted)' } })` di kartu realisasi-vs-anggaran — persis bentuk yang tidak
mungkin ditulis sebagai kelas sebelum pemindahan ini.

## 5. Sapuan gaya sebaris: 551 → 540

| berkas | main | UI-6a |
|---|---|---|
| `reports.js` | 10 | **8** |
| `bukubesar.js` | 8 | **5** |
| `kaskecil.js` | 15 | **14** |
| `taxexport.js` | 13 | **10** |
| `periods.js` | 26 | **24** |
| `bankrecon.js` | 20 | 20 |
| `cashflow.js` | 0 | 0 |
| **seluruh `views/`** | **551** | **540** |

`SpaInlineStyleBudgetTest::CEILING` diturunkan 551 → 540.

`bankrecon.js` sengaja tidak disapu, dan alasannya bukan kehabisan waktu. Dari ke-20 gaya
sebarisnya, **11** memuat `minWidth: 0`, `overflowWrap: 'anywhere'` atau `whiteSpace: 'normal'` pada
sel yang berisi **jalur berkas** dan **pesan galat impor** — geometri yang menahan tabel
folder-terpantau supaya tidak menembus layar, dengan komentar di tempat kejadian yang menyebut
pengukurannya (*"kartu meluber 22 px di 390 px"*). Menggantinya menuntut kelas komponen baru per
situs. **Sembilan sisanya** (`margin: 0` ×3, dua pembungkus `.filters`, `cursor: 'pointer'`,
`flexWrap`, `display: grid`) memang bisa disapu, tetapi menyapu setengah berkas hanya memindahkan
angkanya tanpa menyelesaikan berkasnya; ia paket sendiri.

## Uji

| gerbang | SQLite | MySQL 8.0.46 |
|---|---|---|
| `tests/Feature/Core` | **1.420 uji / 11.626 asersi**, 11 dilewati | **1.420 / 11.633**, 9 dilewati |
| `tests/Unit` | **630 uji / 2.201 asersi** | **630 / 2.201** |
| `tests/Feature/Finance` | **984 uji / 5.235 asersi** | **984 / 5.235** |
| harness Playwright, 26 skenario gerbang | semua **ok**, keluar 0 — termasuk S50 baru | — |

Kedua driver menjalankan **1.420** uji Core yang sama; yang berbeda hanya berapa yang **dilewati**
(SQLite 11, MySQL 9) dan karenanya jumlah asersinya (11.626 lawan 11.633). Selisihnya tidak
ditelusuri per-uji di sini — yang diperiksa adalah keduanya keluar 0. Unit dan Finance identik
angka per angka di kedua driver, yang memang diharapkan: paket ini tidak menyentuh satu baris PHP
pun kecuali satu konstanta plafon.

Bukti harness lengkap: `docs/bukti-uji/results-ui-6a.json` (26 skenario, nol `ok: false`, nol
`ERROR`) dan `docs/bukti-uji/s50-kepala-laporan.png`.

> Satu catatan tentang cara membaca berkas bukti itu, karena harness-nya **menggabung**: baris
> `R.update(prev)` memuat `results.json` yang sudah ada sebelum menjalankan apa pun, sehingga sebuah
> berkas hasil bisa memuat entri dari putaran-putaran sebelumnya dan **terbaca lengkap padahal
> bukan**. Untuk paket ini: ke-26 entri datang dari satu putaran di direktori kosong; empat di
> antaranya (S7, S8, S43, S50) lalu ditulis ulang oleh putaran ulang sesudah satu **komentar** di
> `app.css` disunting, supaya keempat skenario yang membaca gaya benar-benar mengukur pohon yang
> di-commit.

**Bukti A/B terhadap `main` (4f6f28e), 13 rute keuangan, 1440 px.** Kepala laporan adalah anak
pertama yang baru, jadi perbandingan berbasis jalur DOM akan melaporkan setiap elemen sesudahnya
sebagai "pindah"; yang ingin dijawab justru sebaliknya. Jadi kepala laporan (dan kotak tanggal baru
di layar umur) **dicabut dulu dari pohon B**, lalu 19 properti terhitung + kotak pembatas
dibandingkan per elemen:

```
#/reports?tab=trial-balance a=182 b=182 beda=0   #/buku-besar  a=33  b=33  beda=0
#/reports?tab=profit-loss   a=99  b=99  beda=0   #/bank-recon  a=28  b=28  beda=0
#/reports?tab=balance-sheet a=107 b=107 beda=0   #/kas-kecil   a=15  b=15  beda=0
#/reports?tab=cash-flow     a=130 b=130 beda=0   #/tax-exports a=103 b=103 beda=0
#/reports?tab=cash-projection a=208 b=208 beda=0 #/periods     a=239 b=239 beda=0
#/reports?tab=ar-aging      a=86  b=86  beda=0
#/reports?tab=ap-aging      a=65  b=65  beda=0
#/reports?tab=project-profitability a=24 b=24 beda=0
TOTAL BEDA (di luar kepala laporan): 0
```

**Responsif dan tema.** Kepala laporan diukur di 1440 / 1024 / 768 / 390 px × terang/gelap: nol
gulir menyamping, nol teks terpotong, treknya 6 → 4 → 2 → 2, dan warnanya mengikuti tema (latar
`rgb(255,255,255)` → `rgb(21,26,33)`, `dt` `rgb(94,104,116)` → `rgb(139,149,163)`).

Satu-satunya tempat blok ini **menyimpang dari prototipe** juga datang dari pengukuran: prototipe
memakai `auto-fit`, paket ini memakai **`auto-fill`**. `auto-fit` meruntuhkan trek kosong, sehingga
jumlah parameter yang sedikit membagi rata SELURUH lebar — pada lembar Laba Rugi tanpa saring
proyek, kedua parameternya terlempar **550 px** berjauhan dan terbaca sebagai kecelakaan tata letak.
Kepala prototipe membawa enam baris dan treknya memang penuh; kepala di sini membawa dua sampai
lima. Membatasi treknya ke 220 px sempat dicoba dan **lebih buruk**: kotak isi kepala pada 768 px
hanya 434 px dan `220 + 20 + 220 = 460` tidak muat, jadi kisinya runtuh ke satu kolom dan kepalanya
tumbuh **169 → 269 px**. `auto-fill` menahan trek kosongnya dan menang di kedua ujung.

**S50 dibuktikan MERAH tiga kali** sebelum dipercaya — aturan yang lahir di UI-5, ketika dua gerbang
sempat lolos sebagai gerbang mati:

| sabotase | yang dikatakan gerbang |
|---|---|
| `.report-head { display: none }` di blok cetak (= keadaan sebelum paket ini) | `trial-balance: kepala laporan hilang di media cetak — persis keadaan yang paket ini perbaiki` (×9) |
| `as_of` tidak ikut dikirim (= keadaan sebelum paket ini) | `tanggal yang dipilih tidak ikut terkirim ke ar-aging: ['finance/reports/ar-aging', …]` |
| Arus Kas diberi judul "Neraca" | `dua laporan memakai judul yang sama di kertas: [… 'Neraca', 'Neraca' …]` |

Setiap pemulihan diverifikasi md5 terhadap salinannya, bukan dengan `git diff` — pohonnya memang
sudah termodifikasi oleh paket yang sedang berjalan, jadi `git diff` tidak bisa menjawabnya.

## Dua verifikasi yang hampir saya lewatkan

**A/B yang hijau karena tidak melihat apa-apa.** Putaran pertama A/B berbunyi `#/reports a=182 b=182
beda=0` dan saya hampir menulisnya sebagai bukti. Ia hanya membuka **tab bawaan**, sedangkan satu-
satunya perubahan sapuan di `reports.js` ada di tab **Laba Rugi**. Putaran berikutnya membuka
ketujuh tab, dan sebuah probe saksi menghitung kelas yang sapuan pasang: `profit-loss` `fin_line
0 → 7`, `fin_total 0 → 3`, gaya sebaris `11 → 4`. Baru sesudah itu `beda=0` berarti sesuatu.

**Dua berkas yang saksinya berkata SAMA.** Probe yang sama menunjukkan `#/buku-besar` dan
`#/kas-kecil` **tidak berubah sama sekali** — bukan karena sapuannya tidak mendarat, tetapi karena
kodenya tidak tercapai:

* Buku besar tidak menaruh akun di URL; `summary()` dan `ledgerTable()` baru tergambar sesudah satu
  akun dipilih lewat combobox. Diverifikasi dengan memilih **1-1210** (tutup **minus**, jadi cabang
  `.tone-neg` benar-benar tergambar) dan **1-1400** (tutup positif, cabang sebaliknya). Satu-satunya
  beda properti yang tersisa adalah **tempat cat latar** baris "Saldo awal": lama pada `<tr>`, baru
  pada tiap `<td>` lewat `table.data tr.emphasis td`. Karena `table.data` memakai `border-collapse:
  collapse` itu **seharusnya** tergambar sama — dan "seharusnya" bukan pengukuran, jadi tabelnya
  ditangkap layar di kedua pohon: **PNG-nya identik byte-per-byte** (md5 `a67444bb…`, 32.384 B).
* Kas kecil **tidak bisa dicapai sama sekali**: basis data demo memuat **nol** dana kas kecil dan
  **nol** kasbon, jadi `renderFund()` dan `renderKasbon()` tidak punya jalan masuk. Yang tersisa
  untuk diuji adalah satu-satunya hal yang penggantian kelas-untuk-gaya-sebaris bisa rusak —
  kaskade. Markup **lama** dipasang di pohon lama dan markup **baru** di pohon baru, di rantai
  leluhur yang sama persis (`.stat-row > .stat`, `td.center`, `.card > div`), lalu gaya terhitungnya
  dibandingkan: **5 dari 5 identik**. Probe itu sendiri dibuktikan merah (mencabut `.tone-pos` dan
  menggeser `--fs-body` membalik 3 dari 5). Ini **bukan** pengganti render sungguhan, dan disebut di
  sini justru supaya tidak terbaca begitu.

## Cacat yang ditemukan dan TIDAK diperbaiki di sini

**Memilih akun di Buku Besar melempar `Unhandled rejection` setiap kali.**

```
NotFoundError: Failed to execute 'removeChild' on 'Node': The node to be removed is no longer
a child of this node. Perhaps it was moved in a 'blur' event handler?
    at clear (ui.js:51) → render (bukubesar.js:349) → reload (bukubesar.js:369)
    → combobox commit (combobox.js:646)
```

Diukur di **kedua** pohon — `main` 2 galat, UI-6a 2 galat — jadi ia **bukan** bawaan paket ini.
Layarnya tetap tergambar, tetapi ini interaksi utama layar itu, dan sebuah unhandled rejection
menutupi galat lain. Ia perbaikan perilaku, bukan gaya; dicatat di sini dan didaftarkan sebagai
tugas terpisah.

## Empat layar prototipe yang dipetakan ke UI-6 Keuangan — keadaannya

Tabel "Rujukan rancangan" di rencana kampanye memetakan empat layar prototipe ke UI-6 (Keuangan).
Diukur satu per satu:

| layar prototipe | keadaan |
|---|---|
| **Umur piutang** (`scr-laporan`) | ✅ paket ini — kepala laporan + kotak `as_of` |
| **Laba rugi** (`scr-labarugi`) | ✅ paket ini — kepala laporan |
| **Matriks persetujuan** (`scr-matriks`) | ✅ **sudah ada sebelum kampanye dimulai**, dan barisnya di rencana itu basi |
| **Laporan Bebas** (`scr-pembangun`) | ☐ belum — paketnya sendiri |

Baris ketiga layak disebut apa adanya. Prototipe menggambarkan matriks persetujuan sebagai *"satu
grup pengaturan digambar sebagai tabel, bukan kolom field"*, dan itu persis yang `#/settings` sudah
lakukan: kartu **Matriks Persetujuan** merender `table.data` **29 baris × 4 kolom dengan 17 kontrol
di dalam selnya** (diukur di peramban 18 Sep 2026; `settings.js:246` membangunnya dari kunci
`approval_matrix` yang datang dengan `matrix` dari server). Tidak ada yang dikerjakan di sini karena
tidak ada yang kurang.

**Laporan Bebas** (`custom.js`, **2.429 baris, 40 gaya sebaris** — berkas dengan utang terbesar di
seluruh lapisan view) tidak disentuh. Ia bukan laporan keuangan melainkan **pembangun** laporan
generik atas delapan sumber terkatalog, dan prototipenya menuntut tata letak dua kolom `.builder`
plus `.pick-list`/`.pick-opt` yang belum ada satu pun aturannya. Menumpangkannya ke paket yang sudah
menggeser sembilan layar laporan akan membuat bukti A/B-nya tidak terbaca.

## Yang sengaja tidak dikerjakan

* **`.bucket-bar` / `.bucket-legend` prototipe** untuk layar umur. Aplikasi sudah menggambar sebaran
  embernya dengan `progressBar()` + `.bar-row`, yang bekerja dan punya nada per ember. Menggantinya
  adalah **rancang ulang**, bukan perapian — dan ia menyentuh satu-satunya bagian layar umur yang
  tidak bermasalah.
* **`table.data.statement` prototipe** (hierarki akun `.lvl-2`, `.head-row`, kolom anggaran dan
  selisih). Laba Rugi aplikasi menjawab pertanyaan yang sama dengan bentuk lain: satu kartu per
  seksi lewat `sectionTable()`. Kolom "Anggaran s.d. Sep" prototipe juga **tidak punya sumber** —
  endpoint laba rugi tidak mengembalikan anggaran per akun. Menggambarnya berarti menjanjikan kolom
  yang tidak pernah terisi.
* **Menyeragamkan `.page-head` dan mengadopsi `.zone`** di layar keuangan. Utang yang sama yang
  UI-3c catat: `.page-head` dibangun 69 kali di 54 berkas tanpa pembangun bersama. Paket ini
  menambah satu produsen `.zone` (lantai naik 3 → 4) tetapi tidak menyentuh 40 bilah rata lainnya.
* **`bankrecon.js`** — lihat §5.
* **Menurunkan `h1` "Laporan Keuangan" menjadi nama laporannya.** Terlihat menggoda dan salah: `h1`
  adalah nama **layarnya**, tab adalah pemilih laporannya, dan remah roti sudah berbunyi
  `Keuangan › Laporan Keuangan`. Yang kurang bukan judul yang berubah-ubah, melainkan kepala
  laporan — dan itulah yang dibangun.
