# Laporan Paket P1-E (ROADMAP-HASHMICRO Fase 1) — Migrasi 3 grafik tangan ke `charts.js`

Branch: `feat/phase1-e` (dari `feat/phase1-d`) · 6 September 2026

> Status jujur: **dibangun dan diukur di peramban, belum diverifikasi adversarial.** Skenario
> harness **S20e** ditulis dan dijalankan: 20 syarat fitur hijau di tema terang DAN gelap, dan
> **diff piksel benar-benar
> DIUKUR** terhadap tangkapan layar sebelum migrasi yang kini ada di repositori. Angkanya
> **melampaui target 2 %** — 6,06 % / 4,63 % / 6,09 % sesudah putaran verifikasi
> (3,19 % / 7,15 % / 6,08 % sebelum) — persis seperti yang diperkirakan
> laporan P1-A; § Kriteria "diff piksel ≤ 2 %" menjelaskan apa yang mengubah piksel itu dan
> mengusulkan kriteria pengganti. Tidak ada migrasi basis data, tidak ada endpoint baru.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-E → status)

| Tugas | Status | Bukti |
|---|---|---|
| Kurva-S proyek → `charts.js` | ✅ | `views/project.js`: ~95 baris SVG tangan → satu panggilan `lineChart` |
| Kurva EVM → `charts.js` (aturan sumbu > 100 % dipertahankan) | ✅ | `views/evm.js`; `yMax = Math.max(100, ceil(peak/25)*25)` tetap di pemanggil dan dipaku `ChartMigrationTest` |
| Tren harga satuan → `charts.js` | ✅ | `views/hargasatuan.js`; sumbu tidak-dari-nol dipertahankan lewat `yMin`/`yMax` sendiri |
| Diff piksel ≤ 2 % | ❌ **diukur, tidak tercapai** | 6,06 % / 4,63 % / 6,09 % (S20e, diukur ulang sesudah verifikasi) — lihat § Kriteria |
| Harness | ✅ | **S20e** (dua tema, kini termasuk kertas dan sumbu harga naik) + **S20em** (390 px: teks terkecil yang benar-benar tergambar), PNG dan hasil di `results-phase-1.json` |

## Yang benar-benar berubah

Ketiga layar sekarang hanya menyusun **data**. Yang hilang dari ketiganya: perhitungan kisi,
penjarangan label sumbu, pembentukan `path`, `<title>` per titik, dan — yang paling penting —
tiga salinan aturan yang sama yang sudah mulai berselisih.

**Berselisihnya bisa disebutkan satu per satu**, dan itulah alasan paket ini ada:

- hanya kurva EVM yang membiarkan sumbunya naik melewati 100 %;
- hanya tren harga yang tidak memaksa sumbu mulai dari nol;
- kurva EVM memakai **warna yang sama** untuk garis fisik (EV) dan garis biaya, dibedakan hanya
  oleh `opacity: .55` — padahal SELISIH keduanya adalah pesan utamanya (EV ÷ AC = CPI);
- tidak satu pun dari ketiganya menambatkan label tepinya ke dalam kotak svg.

### Warna: token kategorikal, bukan token semantik

| grafik | seri | sebelum | sesudah |
|---|---|---|---|
| Kurva-S | Aktual | `--primary` #1a56db | `--chart-1` **#1a56db** (identik) |
| Kurva-S | Rencana (mingguan) | `--muted` abu-abu, putus `5 3` | `--chart-3` hijau, putus `5 3` |
| Kurva-S | Rencana baseline | `--text-2` abu-abu, putus `2 4` | `--chart-8` batu tulis, putus `2 4` |
| EVM | Rencana baseline | `--text-2`, putus `2 4` | `--chart-8`, putus `2 4` |
| EVM | Progres fisik (EV) | `--primary` @ opacity .55 | `--chart-1` #1a56db |
| EVM | Biaya aktual | `--primary` (warna SAMA dengan EV) | `--chart-2` #c2410c |
| Tren harga | garis | `--primary` | `--chart-1` (identik) |
| Tren harga | titik GRN | `--warning` (satu-satunya warna literal di layar itu) | `--chart-7` |

Dua garis rencana kurva-S dulu abu-abu tua dan abu-abu muda — hampir tak terbedakan kecuali dari
pola putusnya. Pola putusnya dipertahankan; warnanya sekarang benar-benar berbeda.

### Cacat yang DIPERBAIKI migrasi ini

**Label tepi tren harga terpotong.** Grafik tangan memakai `PAD.left` tetap 64 px dan menaruh
label x terakhir di tengah titik terakhir, yaitu `x = 720 − 16 = 704`; "01 Jul 2026" selebar
±62 px berarti tepi kanannya di ±735 px pada kotak selebar 720 — terpotong 15 px. Tangkapan layar
`s20e-tren-harga-sebelum-p1e.png` menunjukkannya apa adanya: **"01 Jul 20"**. Label sumbu Y
"Rp 62,78 rb" (±62 px, berjangkar akhir di `64 − 7 = 57`) mulai di ±−5 px, jadi terpotong di kiri
juga. `charts.js` menghitung `PAD.left` dari lebar label tick dan menambatkan label tepi ke dalam
svg; `s20e-tren-harga-sesudah-p1e.png` memuat keduanya utuh, dan S20e memaku sifat itu
(`no_text_outside_viewbox`, diukur dengan `getBBox` terhadap `viewBox`).

**Penjarangan label sumbu tanggal EVM.** Grafik tangan menjarangkan per INDEKS
(`ceil(rows/8)`), padahal sumbunya tanggal — jarak antar label karena itu tidak rata di layar.
`charts.js` menjarangkan di ruang piksel: **7 label menjadi 8, tidak ada yang bertumpuk, dan
label tepi kanan tidak lagi terpotong** ('30 Jun 20' → '30 Jun 2027', terlihat pada kedua PNG
sebelum/sesudah). Yang TIDAK terjadi adalah jarak yang rata: terukur pada layar hidup, pusat
kedelapan label ada di 36,4 / 120,0 / 203,6 / 288,6 / 413,4 / 498,4 / 579,3 / 704 — jarak
80,9–124,8 px, rasio 1,54. Penjarangan ruang piksel mencegah TABRAKAN; ia tidak meratakan
langkah. Versi pertama laporan ini menulis "7 label tidak rata → 8 label rata" (temuan
verifikasi P1-E).

**Nilai di luar sumbu tidak lagi dijepit diam-diam.** Kurva-S lama menulis
`Math.min(100, value)`, jadi minggu ber-105 % tergambar persis di garis 100 % dan tidak ada yang
bisa tahu. `charts.js` menempelkannya di tepi plot dengan `data-outside` dan menambahkan
"(di luar sumbu)" pada `<title>`-nya.

### Yang sengaja TIDAK berubah

- Interpolasi kurva baseline kurva-S: disampel pada **tanggal milik setiap minggu**, bukan pada
  indeksnya (titik baseline bulanan vs minggu mingguan), dan `null` sebelum sampel pertama —
  kurvanya tidak diketahui di sana, bukan nol. Kodenya dipindahkan tanpa satu baris berubah.
- Kalimat `<title>` ketiga grafik, kata demi kata.
- Aturan sumbu EVM > 100 % dan ruang sumbu tren harga (25 % di atas/bawah rentang; 5 % dari
  harganya sendiri bila seluruh harga sama).
- Ukuran titik: as-of EVM 4 px vs 2,5 px biasa; GRN 3,5 px vs PO 3 px.

## Kriteria "diff piksel ≤ 2 %" — diukur, dan tidak tercapai

| grafik | ukuran sebelum | ukuran sesudah | piksel berubah pada irisan | luas di luar irisan |
|---|---|---|---|---|
| Kurva-S | 720×260 | 720×277 | **6,06 %** | 6,14 % |
| Kurva EVM | 720×261 | 720×277 | **4,63 %** | 5,78 % |
| Tren harga | 1112×372 | 1112×403 | **6,09 %** | 7,69 % |

Toleransi 16/255 per kanal (anti-alias sub-piksel tidak dihitung sebagai perubahan).

Angka di atas **diukur ulang 6 September 2026, sesudah putaran verifikasi adversarial**, dan
karena itu berbeda dari yang dilaporkan versi pertama paket ini (3,19 % / 7,15 % / 6,08 %):
perbaikan verifikasi ikut memindahkan piksel — tebal garis seri terukur kembali ke 2,5 px,
jari-jari titik yang dikecualikan `dots:false` turun dari 4 ke 3, dan seri yang seluruh datanya
terpencil kini dilambangkan TITIK di legendanya. Dua catatan tentang angkanya sendiri:

- Versi pertama menuliskan **7,16 %** untuk EVM sementara `results-phase-1.json` merekam
  **7,15**; yang benar adalah berkas buktinya.
- Diff itu dulu hanya bisa diukur di mesin ber-Pillow, dan host tempat harness ini benar-benar
  dijalankan tidak memasangnya — jadi satu-satunya angka yang diminta roadmap untuk paket ini
  dilaporkan "tidak tersedia" setiap kali. Sejak verifikasi, `harness-playwright.py` membawa
  dekoder PNG-nya sendiri (`_read_png`, 8-bit non-interlaced — format yang memang ditulis
  Playwright) dan angkanya terukur tanpa satu dependensi pun.

**Apa yang mengubah piksel itu**, seluruhnya disengaja dan tidak satu pun bisa dihindari sambil
tetap memakai `charts.js`:

1. **Legenda pindah KE DALAM svg** — tinggi natural bertambah 16 px (satu baris legenda), dan
   itu berlaku untuk **kurva-S dan EVM saja**.
1b. **Tinggi bawaan `charts.js` 260 vs 240 grafik tangan** — inilah +31 px pada **tren harga**,
   yang justru satu-satunya dari ketiganya yang TIDAK memakai legenda svg (`legend: false`,
   legendanya tetap DOM karena pembedanya per titik). 1112/720 × 20 = 30,9 px, yaitu 372 → 403
   yang direkam berkas bukti. Versi pertama laporan ini menjelaskan +31 px itu dengan legenda
   yang grafik tersebut tidak punya — `results-phase-1.json` merekamnya sendiri:
   `S20e.charts.tren_harga.legend == []` sementara kurva-S dan EVM masing-masing tiga entri
   (temuan verifikasi P1-E).
2. **Warna pindah ke token kategorikal** — dua dari delapan seri kebetulan identik
   (`--chart-1` = `--primary`); enam sisanya berubah, dan dua di antaranya HARUS berubah
   (EV vs biaya yang dulu sewarna).
3. **Geometri plot bergeser** — `PAD.left` kini dihitung dari lebar label tick alih-alih
   ditulis tetap (38/42/64 px), yang justru perbaikan yang membuat label tidak terpotong.

Laporan P1-A sudah memperkirakan ini ("**diff piksel ≤ 2 % tidak tercapai secara konstruksi**")
dan mengusulkan kriteria pengganti. **Usulan itu sekarang punya angkanya**, dan bentuk
penggantinya sudah dijalankan sebagai uji:

> Ganti "diff piksel ≤ 2 %" dengan **"tidak ada fitur hilang (daftar per grafik) + S20e hijau"**.

20 syarat S20e yang menggantikannya, semuanya hijau: ketiganya benar-benar `chart-lib`; setiap
`.mark` membawa tepat satu `<title>`; kurva-S punya tiga seri + area + sumbu 0–100 langkah 25 +
label minggu + `<title>` yang menyebut rencana DAN aktual; EVM punya tiga warna BERBEDA + titik
as-of yang lebih besar + `<title>` bertiga angka + sumbu mencapai 100 %; tren harga bersumbu
bukan-nol + lima garis kisi + SATU seri dengan dua token titik; warna setiap garis benar-benar
nilai tokennya; **dan warna itu tetap datang dari token di tema GELAP** (setiap `--chart-*` punya
nilai sendiri di blok gelap, jadi sebuah warna ter-hardcode lolos di terang dan ketahuan di sana —
terukur: `--chart-1` #1a56db → #5b8def, `--chart-2` #c2410c → #fb923c, `--chart-3` #15803d →
#4ade80, `--chart-8` #475569 → #94a3b8); tidak ada teks di luar `viewBox`; nol galat halaman.

## Uji

- baru: `ChartMigrationTest` (5 uji / 33 asersi) — `createElementNS` tidak boleh kembali ke ketiga
  berkas; aturan sumbu EVM > 100 % masih ada DAN diteruskan; ruang sumbu tren harga masih ada DAN
  diteruskan; hanya tren harga yang boleh punya legenda DOM (dan swatch-nya token grafik, bukan
  `--warning`); gaya grafik tangan benar-benar dihapus dari app.css.
- harness: **S20e** di `docs/bukti-uji/results-phase-1.json`, 6 PNG
  `s20e-{kurva-s,evm,tren-harga}-{sebelum,sesudah}-p1e.png`.
- **Suite penuh (SQLite): 3.912 uji / 19.763 asersi hijau, 11 dilewati, 556 s** — lima uji dan
  33 asersi lebih banyak daripada P1-D, seluruhnya `ChartMigrationTest`; tidak ada uji lain yang
  berubah hasilnya. MySQL: (diisi — job CI nightly).

## Yang dihapus

`app.css`: `.chart .grid/.axis/text/.plan/.act/.act-fill/.pt/.base/.ev` dan
`.legend i.plan/.act/.base/.ev` — sepuluh selektor yang setelah migrasi tidak cocok dengan apa pun.
Dihapus, bukan ditinggalkan: selektor mati adalah warna yang menunggu dipakai lagi oleh grafik
berikutnya tanpa ada yang tahu dari mana asalnya — dan warna itu bukan token grafik, jadi ia tidak
ikut berubah di tema gelap maupun di kertas. `.chart` (pembungkus responsif) dan `.legend`
(dipakai tren harga) tetap.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Tidak ada verifikasi adversarial.** Dua verifier baca-saja belum dijalankan.
2. **Data demo tipis untuk tren harga**: setiap item hanya punya DUA titik, dan keduanya berharga
   sama (Rp 62.000 PO + GRN). Yang teruji karena itu justru cabang `room = (hi - lo) || …` untuk
   harga rata — tetapi kurva harga yang benar-benar naik-turun **belum pernah tergambar** dengan
   kode baru. Sifat "sumbu tidak dipaksa dari nol" terbukti dari tick (`Rp 61,23 rb` … tidak
   `Rp 0`), bukan dari bentuk kurvanya.
3. **Sumbu EVM > 100 % belum pernah benar-benar melewati 100 % di layar**: pada data demo puncaknya
   tepat 100. Yang dipaku uji adalah aturannya (`ChartMigrationTest`, literal) dan bahwa sumbunya
   MENCAPAI 100 (S20e) — bukan gambar sebuah proyek yang melewati anggarannya.
4. **Cetak belum diukur untuk ketiga layar ini.** S20 mengukur token di kertas, tetapi pada grafik
   SINTETIS di sandbox-nya; S20e mengukur dua tema layar dan TIDAK mengemulasi media cetak. Yang
   diketahui: ketiga grafik kini memakai token `--chart-*` yang blok `@media print` app.css memang
   menukar, dan pola putus per indeks seri berlaku otomatis — tetapi belum ada yang melihatnya di
   kertas.
5. **Suite MySQL** belum dijalankan.

## Gerbang rilis (ditambahkan 7 September 2026)

Paket ini dikapalkan di dalam rilis `0937dec` (P1-C…P1-G) — lihat `docs/LAPORAN-RILIS-FASE-1.md`.
Suite penuh di commit rilis itu: **SQLite 3.991 uji / 20.656 asersi hijau** (11 dilewati). Leg MySQL
semula tidak bisa dijalankan (kredensial Fase 0 hilang bersama scratchpad); sesudah pemilik memberi
kata sandi baru dan satu cacat isolasi uji diperbaiki (`Schema::drop()` di dalam uji = COMMIT
IMPLISIT di MySQL), **MySQL 8.0.46 di `89ba8c9`: 3.991 uji / 20.669 asersi hijau**.

Gerbang terakhir Fase 1 (sesudah P1-H dan P1-I, commit `069a41e`), yang juga menjalankan ulang
seluruh uji paket ini: **SQLite 4.025 / 21.029 dan MySQL 4.025 / 21.042, keduanya hijau.**
