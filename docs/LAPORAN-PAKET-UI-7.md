# Laporan Paket UI-7 — sapuan regresi: instrumen yang harus diperbaiki EMPAT kali sebelum ia jujur

> Fase terakhir. Responsif · cetak · PWA · papan ketik dan kontras · gerbang yang tidak menggerbangi ·
> dan empat keputusan yang UI-6 ukur lalu serahkan ke sini.

---

## 1. Sapuan: 143 rute × 6 putaran

Seluruh katalog rute disapu — **48 rute berjalur-literal + 95 sumber daya** — pada empat lebar, dua
tema, dan di kertas. Yang diukur per halaman: tabrakan teks (instrumen v3 UI-6b, kotak baris dipotong
tiap leluhur yang menggulung), kontras WCAG terhadap latar EFEKTIF, elemen yang meluber keluar
induknya, gulir menyamping, dan sasaran sentuh.

Tabel di bawah adalah putaran TERAKHIR, atas pohon yang digabung, dengan instrumen yang sudah
dikeraskan (§2). Kolom `belum siap` ada karena ia harus ada: sebuah rute yang tidak tergambar
melaporkan nol temuan di setiap dimensi, dan tanpa kolom itu nol tersebut tidak bisa dibedakan
dari nol yang berarti bersih.

| putaran | rute | belum siap | tabrakan | kontras < AA | meluber | gulir-X | elemen terukur |
|---|---|---|---|---|---|---|---|
| 1440 terang | 143 | 0 | 0 | 0 | 0 | 0 | 16.107 |
| 1440 gelap | 143 | 0 | 0 | 0 | 0 | 0 | 17.052 |
| 1024 terang | 143 | 0 | 0 | 0 | 0 | 0 | 20.294 |
| 768 terang | 143 | 0 | 0 | 0 | 0 | 0 | 17.052 |
| 390 ponsel + sentuh | 143 | 0 | 0 | 0 | 0 | 0 | 20.294 |
| **cetak 1440** | 143 | 0 | 0 | 0 | 0 | **0** | 20.294 |

Permukaan regresinya bersih, termasuk di kertas — gulir menyamping yang satu itu (§4) sudah
tertutup. Satu cacat sejati ditemukan di jalan ke sana, dan ia membuka pintu ke tiga lagi.

**Kolom terakhir itu yang paling penting di tabel ini.** Putaran yang sama sebelum instrumennya
dikeraskan mengukur 6.238–8.433 elemen per putaran — sekitar SEPERTIGA. "Nol temuan" waktu itu
sebagian besar berarti "nol temuan pada sepertiga aplikasi yang sempat tergambar", dan §5
menunjukkan apa yang bersembunyi di dua pertiga sisanya.

## 2. Instrumennya sendiri salah empat kali — tiga di antaranya "bersih"

Angka di tabel atas baru bisa dipercaya sesudah empat koreksi, dan **tiga dari empat akan melaporkan
nol temuan dengan percaya diri**:

1. **Thread.** API sync Playwright memakai greenlet dan tidak bisa dipakai lintas thread. Versi
   paralel pertama mati dengan `Cannot switch to a different thread` — keras, dan karena itu tidak
   berbahaya. Diganti pecahan PROSES.
2. **"48 layar rusak di tema gelap."** Jeda tetap 1.400 ms, enam peramban berebut CPU: halamannya
   belum tergambar. Layar yang sama, sendirian dengan jeda 4 detik, menggambar 204/47/214 elemen di
   KEDUA tema. Artinya angka "nol kegagalan kontras" pada putaran gelap itu mengukur **halaman
   kosong**. Diganti penjaga siap-gambar yang menunggu `.main` berisi, dan melaporkan
   `belum_siap` supaya kehabisan waktu tidak pernah lagi terbaca sebagai kelulusan.
3. **Enam kali masuk per menit.** Tiap pecahan masuk sendiri; tiga putaran berurutan melewati batas
   10 kali/menit, dan yang tertolak melanjutkan sebagai TAMU — **setiap halaman lalu menampilkan
   layar masuk, dan sapuan melaporkan 143 rute "kosong" dengan nol temuan di setiap dimensi.**
   Diganti satu kali masuk ke berkas keadaan sesi bersama, ditambah **penjaga sesi** yang
   MEMBATALKAN sapuan bila halaman ujinya tidak benar-benar masuk.

Penjaga itu membuktikan dirinya dalam hitungan menit: sesi tersimpan menjadi tidak berlaku di tengah
jalan, dan tiga putaran menolak berjalan alih-alih melaporkan 143 rute bersih. Penyebab akhirnya pun
sesuatu yang tidak akan pernah saya duga dari kode aplikasi: `php -S` **berulir tunggal**, sehingga
lima peramban paralel mengantre di server, bukan di aplikasi.

**Dan yang keempat, ditemukan sesudah laporan ini pertama kali ditulis.** Penjaga siap-gambar di
butir 2 berbunyi "`.main` berisi" — dan itu benar SEKETIKA sesudah pindah rute, karena DOM rute
SEBELUMNYA masih terpasang sampai SPA menggambar ulang. Sebuah sapuan bisa mengukur layar yang
sama dua kali sambil menamainya dua rute berbeda. Ia ketahuan bukan di sapuan melainkan di probe
A/B paket ini, yang melaporkan tinggi `.main` yang sama persis untuk rute yang jelas berbeda.

Sapuan enam putaran yang sudah berjalan **tidak** terkena, dan itu diperiksa sebelum dipercaya:
`h1` tiap rute dibandingkan antara dua putaran yang pecahannya BERBEDA — jadi tetangga tiap rute
berbeda — dan **143 dari 143 sama**, yang mustahil bila salah satunya mengukur isi basi. Tetapi
penjaga yang hanya SELAMAT dari sebuah jebakan belum menutupnya: instrumennya kini menandai isi
lama sebelum pindah dan menunggu penanda itu HILANG, lalu menunggu jumlah elemen berhenti berubah
tiga pemeriksaan berturut-turut. Sapuan di tabel §1 adalah hasil versi yang sudah dikeraskan itu.

Instrumennya sendiri sekarang ikut disimpan — `docs/bukti-uji/sapuan-regresi-ui7.py` dengan
katalog rutenya di `docs/bukti-uji/rute-katalog.json`. Angka yang tidak bisa dijalankan ulang
orang lain adalah angka yang harus dipercaya begitu saja.

## 3. `#/asset-utilization` — layar yang tidak pernah dibuka kampanye ini

Satu-satunya rute yang menggulung menyamping di kertas. Mengejarnya menemukan layar yang tidak
pernah muncul di daftar sasaran paket mana pun — ia punya **nol gaya sebaris**, jadi tidak ada
sapuan yang pernah menyebutnya.

Ia merefleksikan `Object.keys(rows[0])` dan menebak kolom uang dengan `/(value|cost|amount|rate)/`.
Tebakan itu meleset, dan tiga cacat lain menumpang di belakangnya:

| | sebelum | sesudah |
|---|---|---|
| kolom uang yang lolos tebakan | `505000000` | **Rp 505.000.000** |
| kepala kolom dari nama kunci | `INTERNAL CHARGE SUGGESTION` | **USULAN BEBAN INTERNAL** |
| enum mentah | `active` | **Aktif** |
| tanggal ISO mentah | `2026-03-02` | **02 Mar 2026** |

505.000.000 = 202 hari × Rp 2.500.000. Ia uang, tergambar sebagai bilangan bulat telanjang **di
sebelah** saudaranya `daily_rate_internal` yang tampil benar — di layar berbahasa Indonesia dengan
empat belas kepala kolom berbahasa Inggris.

Perbaikannya **aditif**: kunci yang dikenal mendapat label dan jenis, kunci yang tidak dikenal jatuh
ke perilaku lama. Sifat yang membuat refleksi ini berharga — kolom baru dari server tetap muncul —
tidak dibuang.

## 4. Kepala tabel boleh membungkus di kertas

Tabelnya 1.474 px di lembar 1.440 px, dan lebarnya dikuasai **KEPALA**, bukan data: "USULAN BEBAN
INTERNAL" 182 px untuk memuat "Rp 505.000.000" yang butuh ~120 px.

`table.data th { white-space: nowrap }` benar di layar — tabel lebar menggulung di dalam
`.table-wrap` dan kepala ragged terbaca buruk. Di KERTAS blok cetak baru saja mencabut wrapper itu,
jadi kepala yang tidak boleh membungkus MEMAKSA tabelnya melewati tepi lembar. Satu aturan
print-only menutupnya, dan ia menolong setiap tabel lebar, bukan hanya yang ini: **1.474 → 1.438 px**.

## 5. Sasaran sentuh — dan angka yang saya laporkan terlalu bagus

Di `pointer: coarse`, `.btn.sm` dinaikkan ke 36 px sejak lama — tetapi `.btn` biasa tetap 34 px.
**Varian "kecil" karena itu LEBIH BESAR daripada varian normal, tepat di perangkat tempat ukuran
sasaran paling penting.** Lantai 36 px diperluas ke `.btn`, `.btn.icon`, medan isian, `<select>`,
`.check-row`, dan `.combo .combo-input` — yang terakhir butuh barisnya sendiri karena
`.combo .combo-input` (0,2,1) menang atas selektor jenis.

Sampai di sini laporan ini berbunyi **"568 → 4"**, dan angka itu SALAH. Ia benar tentang apa yang
diukurnya; yang keliru adalah apa yang tergambar saat diukur. Sesudah penjaga kesiapan diperbaiki
(§2, koreksi keempat), putaran 390 px yang sama mengukur **20.294 elemen di 143 rute, bukan 6.661** —
sapuan lama melihat sekitar sepertiga aplikasi, dan "4 sasaran tersisa" adalah empat yang tersisa
**di sepertiga itu**.

Dengan halaman yang benar-benar tergambar, sisanya **545**, dan begitu dikelompokkan ia bukan ekor
panjang melainkan tiga kelas yang lantai sebelumnya memang tidak pernah sentuh:

| kelas | jumlah | tinggi | sebabnya |
|---|---|---|---|
| `select` "baris per halaman" di `.pager` | 70 | 28 px | **gaya sebaris `height: '28px'` di `list.js`** |
| kotak centang `.pick` dan `.check-row` | 181 | 16 px | lantai menaikkan BARISNYA, bukan kotaknya |
| tombol urut kepala kolom `.th-sort` | 230 | 17 px | tidak pernah masuk daftar |
| `input[type=time]` · `[type=tel]` · tanpa atribut `type` | 3 | 27 px | **lantainya mendaftar JENIS satu per satu** |

Dua di antaranya cacat di aturan saya sendiri, dan keduanya berpola sama — **pagar yang menyebut
anggotanya**:

* **Gaya sebaris mengalahkan media query.** `list.js` menulis tinggi pemilih halaman sebaris,
  dengan alasan tertulis "ini geometri, bukan tipografi". Alasan itu melewatkan bahwa lantai
  sentuh HIDUP di `@media (pointer: coarse)` — dan gaya sebaris menang atas media query mana pun.
  Satu kontrol, 70 layar daftar, lantai yang tidak pernah berlaku. Geometrinya sekarang
  `.pager select` di `app.css`, dengan barisnya sendiri di blok coarse (0,1,1 lawan 0,0,1).
* **Daftar jenis bocor pada jenis berikutnya.** `input[type=text], [type=password], …` tidak
  pernah cocok dengan `time`, `tel`, atau `<input>` yang ditulis tanpa `type` sama sekali — yang
  sah dan berarti `text`. Sekarang `input:not([type=checkbox]):not([type=radio]):not([type=file])
  :not([type=range]):not([type=hidden])`.

Untuk kontrol DI DALAM tabel lantainya **24 px, bukan 36**, dan angkanya diukur bukan diselerakan:
pada 36 px tinggi baris kepala 42 → 62 px, pada 24 px ia 42 → **50 px**, sementara baris ISI tidak
bergeser sepersepuluh piksel pun pada keduanya (133,8/153,8 dan 116/77,5/97 byte-identik). Yang
dibayar hanya kepala tabel, di layar sentuh saja; kepadatan yang pemilik bekukan tidak tersentuh.
24 px juga bukan angka rumah — ia **ambang WCAG 2.5.8 AA**, dan kotak centang baris memang bukan
sasaran tunggalnya: barisnya sendiri yang dibuka orang.

| tahap | sasaran < 36 px di 390 px | < 24 px |
|---|---|---|
| sebelum lantai apa pun (sepertiga aplikasi terukur) | 568 | — |
| sesudah lantai `.btn` + medan + combobox, **halaman penuh terukur** | **545** | 411 |
| + `.pager select`, jenis-agnostik, lantai 24 px kontrol tabel | **496** | **6** |

Keenam yang tersisa di bawah ambang WCAG: dua `input[type=file]` 22 px (kontrol NATIF — menyetel
tingginya memotong tombol bawaan peramban), tiga entri agenda `#/kalender` 20 px (menaikkannya
mengubah kisi bulan di 390 px, kepadatan kalender adalah keputusan tersendiri), dan satu tautan
sebaris di dalam kalimat — yang WCAG 2.5.8 kecualikan secara eksplisit. Sisa 490 yang di antara
24 dan 36 px seluruhnya kontrol tabel yang baru saja dinaikkan ke ambang itu.

Kepadatan baris tidak bergeser sepersepuluh piksel pun: S21m mencatat compact `[42,5; 43]` dan
comfortable `[54,5; 55]` **byte-identik** sebelum dan sesudah.

## 6. Gerbang yang tidak menggerbangi: 7 → 4

Lihat butir 5 rencana kampanye untuk daftar lengkapnya. Ringkasnya: **S8**, **S20** dan **S20m** kini
mengembalikan `ok`, masing-masing dibuktikan merah lewat sabotase; empat sisanya sengaja dibiarkan
mencatat karena tinjauan 28 agen **menolak enam dari tujuh usulan** — hanyut data, gerbang mati, dan
saringan yang menanggung beban. Menulis gerbang lemah untuk keempatnya menukar satu kebohongan
dengan kebohongan lain.

## 7. Empat keputusan yang UI-6 serahkan ke sini

Keempatnya datang dengan angkanya dari paket sebelumnya, dan keempatnya dijawab dengan mengukur
lagi — dua di antaranya **membantah catatan saya sendiri**.

### 7.1 Ukuran `.value` antar-layar — DUA ukuran tetap, aturannya yang ditulis

Sensus segar di 143 rute: **tujuh baris tingkat atas seragam 22 px, enam seragam 16 px**. Yang
membelahnya bukan layar dan bukan kelalaian, melainkan **bentuk angkanya**:

| ukuran | apa yang dibawanya | contoh |
|---|---|---|
| 22 px | bentuk ringkas, persentase, hitungan | `Rp 33,68 M` · `55%` · `1/2` |
| 16 px | rupiah PENUH yang direkonsiliasi orang terhadap kertas | `Rp 10.534.455.000` |

Catatan UI-6d saya berkata menyatukan ke 22 px "mengubah apa yang angkanya KATAKAN (penuh →
ringkas)". Itu **salah**, dan saya mengoreksinya sebelum memakainya: pada 1440 dan 1280 px
keenam baris 16 px muat di 22 px tanpa membungkus — **0 dari 21 ubin**. Objeksinya tidak berdiri.

Tetapi yang berdiri adalah lebar sempit: **4 dari 21 ubin membungkus pada 1024 px dan 12 dari 21
pada 390 px** — rupiah penuh pecah jadi dua baris di dalam ubin setinggi satu baris. Menukar satu
ketidakseragaman yang bisa dibaca dengan satu yang tidak bisa dibaca bukan perbaikan.

Dan ada alasan kedua yang lebih dalam, yang baru terlihat ketika saya menengok `#/csat`: slot itu
kadang berisi **kalimat** — "Belum ada penilaian" — karena `views/csat.js` memegang aturan
tertulis bahwa nol penilaian adalah kalimat, bukan angka, "tidak pernah 0,0 dan tidak pernah `—`
telanjang". Jadi slot ini bukan "sebuah angka" melainkan **jawabannya, dalam bentuk apa pun
jawaban itu datang**; satu ukuran akan memaksakan satu bentuk.

**Keputusan: dua ukuran tetap.** Yang berubah adalah pembenarannya di `app.css`, yang selama ini
berbunyi `.value.sm` adalah "dua ubin berdampingan DI DALAM kartu widget dasbor" — kalimat yang
sensus ini membantah telak: enam baris tingkat atas memakainya, di luar kartu mana pun.

### 7.2 dan 7.3 Sapuan tambalan margin dan `.card-body > p` — satu jawaban, dan sebuah koreksi

Rencana menyebut keduanya butir terpisah dan menjawab keduanya dengan "adopsi `.stack` pada badan
kartu". Diukur, **jawaban itu tidak bisa dipakai apa adanya**: 202 pasang saudara di dalam kartu
berjarak NOL hari ini, dan hampir seluruhnya struktur kartu itu sendiri (`.card > .list-body` 95,
`.card > .card-body` 47, `.card > .table-wrap` 24). `.stack` di `.card` akan menyisipkan 12 px di
antara kepala kartu dan tabelnya. Delapan belas lagi berjarak nol DI DALAM `.card-body` dan memang
harus rapat — keterangan di bawah nilainya, label di atas medannya.

Yang benar-benar cacat ada di tempat lain, dan ia lebih besar daripada yang dicatat UI-6b:

| | terukur |
|---|---|
| badan kartu yang berakhir dengan 14 px EKSTRA di bawah anak terakhirnya | **26** (25 paragraf + satu `dl.kv`) |
| …di atas padding kartunya sendiri | 30 px lawan 16 px pada kartu tetangga |
| `p.muted` yang berakhir pada 0 px karena ditambal `style: { margin: 0 }` | 13 |
| `p.muted` yang berakhir pada 14 px karena tidak ditambal | 6 |
| jarak berbeda antar-saudara di dalam kartu | **tujuh**: 4 · 6 · 8 · 10 · 11 · 12 · 14 px |

Tidak seorang pun memutuskan 14 px itu: ia margin bawaan peramban (`1em`), yang tidak ada di skala
mana pun dan ditambahkan pada padding kartu.

**Koreksi terhadap UI-6b.** Catatan itu berkata "42 paragraf terukur, nol di antaranya masih
bermargin". Yang diukurnya hanya paragraf **anak-tunggal** — dan paragraf bersaudara, yang justru
mayoritas, tidak pernah masuk hitungan. Ini kesalahan yang persis sama dengan yang dikoreksi
UI-6d pada sensus ubinnya: mengukur sampel yang nyaman, lalu menulis kesimpulannya untuk populasi.

Perbaikannya tiga baris aturan, bukan migrasi:

```css
.card-body > p, .card-body > dl { margin: 0 0 var(--space-3); }
.card-body > p:last-child, .card-body > dl:last-child { margin-bottom: 0; }
.divided > * + * { margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--border); }
```

`.divided` menutup butir 2: satu ide visual — garis yang memisahkan blok bertumpuk — yang sampai
paket ini ditulis ulang setiap kali dibutuhkan, dengan jarak berbeda setiap kali (`.info-group`
16/12 · `.form-section` 20/18 · `.absensi-side` 10/10 · `periods.js` **11/11 SEBARIS, satu objek
gaya per baris daftar**). Yang dipindahkan hanya yang sebaris dan berulang; `periods.js` kehilangan
empat properti gaya per baris dan sebuah `index` yang hanya ada untuk memutuskan "baris pertama
tidak bergaris".

Dua bilah ringkasan yang menulis ulang `.card-foot` sebaris (`custom.js`, `kaskecil.js`) memakai
kelas yang sudah ada, dan bilah simpan lengket `settings.js` turun dari `11px 14px` ke `12px 16px`.

Kedua bilah itu **tidak bisa dicapai dengan data demo** — tabel `fin_kasbons` kosong dan kedua
pembayaran sudah `posted`, jadi kartu alokasinya tidak pernah digambar. Kesetaraannya karena itu
diukur langsung terhadap `app.css` pada DOM sintetis: dari sembilan properti terhitung, tujuh
IDENTIK dan yang berbeda hanya yang memang dituju — `padding` 11px → 12px, `align-items` `normal`
→ `center`, tinggi 43 → 45 px. Menyebut sebuah perubahan "terverifikasi" karena layarnya tidak
bisa dibuka adalah bentuk lain dari gerbang hampa.

**Yang TIDAK disapu, dan kenapa:** `app.css` sendiri memakai 11 px delapan kali sebagai padding
komponen (`.filters`, `.alert`, `.toast`, `.offline-ribbon`, `.fin-line.total`, `.login-brand`,
`.module-card`, `.palette-field`), sebagian disalin persis dari prototipe. Menggesernya menggeser
tinggi baris yang S21/S53 pakukan — itu keputusan kepadatan tersendiri, bukan sapuan jarak, dan
paket regresi bukan tempatnya. Klaim "11 px tidak ada di mana pun" yang sempat saya tulis di
komentar CSS karena itu dicabut sebelum sempat terbit: ia hanya benar untuk lapisan view.

### 7.4 Sumbu grafik pada deret KONSTAN — satu tick, pada nilainya sendiri

`#/harga-satuan` menggambar dua titik yang keduanya Rp 62.000 di atas sumbu ber-tick
`Rp 61,23 rb · Rp 61,61 rb · Rp 62 rb · Rp 62,39 rb · Rp 62,78 rb`. Pita ±1,3 % itu lahir dari
ruang 25 % yang menjaga garis datar tidak menempel di tepi plot — ruang yang memang perlu — lalu
**dilabeli**, di layar yang justru dibuat untuk membaca pergerakan harga.

Yang dicabut karena itu bukan pitanya melainkan labelnya: `lineChart` menerima `yTicks`, dan deret
datar memakai satu tick pada nilainya sendiri. Terukur sesudahnya: **5 label sumbu → 1**
(`Rp 62 rb`), 6 garis kisi → 2, sementara `marks` dan `<title>` tetap 2/2 — batasan keras yang
baru saja digerbangi S20 tidak tersentuh. Sumbu tidak-dari-nol itu sendiri tetap disengaja dan
tertulis untuk pengguna (`PANDUAN-PENGGUNA` §4.6); yang diperbaiki hanya kasus rentang NOL.

## 8. Yang sengaja tidak dikerjakan

* **Menyatukan ukuran ubin antar-layar** — lihat 7.1: diukur, dan alasannya menolak.
* **Padding komponen 11 px di `app.css`** (delapan situs) — lihat 7.2: keputusan kepadatan.
* **`.absensi-side` (10/10) ke `.divided`** — garisnya juga memisahkan blok PERTAMA dari kepala
  kartunya, jadi konversinya bukan penggantian setara; menukar 10 px dengan 12 px di satu layar
  lapangan tidak membayar risikonya.
* **Empat skenario yang masih hanya MENCATAT** (S7 · S19 · S22 · S22m) — lihat §6: tinjauan 28
  agen menolak enam dari tujuh usulan, dan menulis gerbang lemah menukar satu kebohongan dengan
  kebohongan lain.
* **Laporan Bebas** (`custom.js`, 2.429 baris) — terbuka sejak UI-6a; ia pembangun laporan generik
  dan prototipenya menuntut `.builder` + `.pick-list` yang belum ada. Paket ini hanya menyentuh
  bilah ringkasannya.

## 9. Verifikasi

| gerbang | hasil |
|---|---|
| Sapuan regresi, 143 rute × 6 putaran (instrumen dikeraskan) | `belum_siap` 0 · tabrakan 0 · kontras 0 · meluber 0 · gulir-X 0 — termasuk di kertas |
| Sasaran sentuh 390 px | 545 → **496** di bawah 36 px, **6** di bawah ambang WCAG 24 px, dan keenamnya beralasan |
| A/B geometri, 18 rute × 4 lebar | **40 dari 72 identik piksel-demi-piksel**; 32 sisanya bergeser persis di tempat yang dituju |
| Derau instrumen A/B | dua potret dari server yang SAMA: **0 elemen berbeda** di enam rute |
| `.card-foot` yang layarnya tak bisa dibuka data demo | 7 dari 9 properti terhitung IDENTIK; yang berbeda hanya padding 11→12, `align-items`, tinggi 43→45 |
| Harness gerbang rilis, 29 skenario | semua **ok**, keluar 0 — 25 mengembalikan `ok`, 4 mencatat (§6) — `docs/bukti-uji/results-ui-7.json` |
| Suite PHP | **5.370 lulus**, 11 dilewati, 36.036 asersi, 0 gagal (929 s) |
| Gaya sebaris `views/` | objek **538 → 536**, properti **982 → 964** (plafon `SpaInlineStyleBudgetTest` diturunkan) |
| `SHELL_VERSION` | **33 → 34** |

Plafon gaya sebaris menghitung OBJEK, dan paket ini menunjukkan mengapa itu proksi lemah: ia turun
dua sementara yang benar-benar dicabut delapan belas PROPERTI. Objek tujuh properti yang menjadi
objek satu properti tidak terlihat sama sekali di plafonnya.

Ringkasan keenam putaran sapuan disimpan di `docs/bukti-uji/results-ui-7-sapuan.json`, dan
instrumennya di `docs/bukti-uji/sapuan-regresi-ui7.py` dengan katalog rutenya.

## 10. Satu hal yang sapuan ini munculkan dan paket ini TIDAK tutup

Lantai 24 px untuk kontrol tabel (§5) hanya berlaku di `pointer: coarse`. Di lebar meja angkanya
terukur juga, dan ia tidak nol: **381 (1440 terang) · 478 (gelap/768) · 503 (1024) · 419 (kertas)**
sasaran di bawah 24 px, didominasi kelas yang sama — kotak centang 16 px dan `.th-sort` 17 px.

Keduanya tidak setara di mata WCAG 2.5.8, dan bedanya yang membuat ini bisa ditunda dengan jujur:

* **Kotak centang** memakai ukuran BAWAAN peramban dan aplikasi ini tidak pernah menyetelnya —
  itu persis pengecualian "user agent default" di 2.5.8. (Di layar sentuh aplikasi kini
  menyetelnya, ke 24 px, jadi pengecualian itu tidak lagi dibutuhkan di sana.)
* **`.th-sort` 17 px digambar oleh aplikasi sendiri**, jadi tidak ada pengecualian yang
  menaunginya. Ia celah AA di lebar meja, dan menutupnya berarti menaikkan tinggi baris KEPALA di
  95 layar daftar sekaligus — keputusan kepadatan, jenis yang pemilik pegang, bukan sapuan
  regresi. Dicatat dengan angkanya, tidak diam-diam diperbaiki di paket terakhir.
