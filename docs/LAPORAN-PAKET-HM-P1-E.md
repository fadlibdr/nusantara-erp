# Laporan Paket P1-E (ROADMAP-HASHMICRO Fase 1) — Migrasi 3 grafik tangan ke `charts.js`

Branch: `feat/phase1-e` (dari `feat/phase1-d`) · 6 September 2026

> Status jujur: **dibangun dan diukur di peramban, belum diverifikasi adversarial.** Skenario
> harness **S20e** ditulis dan dijalankan: 20 syarat fitur hijau di tema terang DAN gelap, dan
> **diff piksel benar-benar
> DIUKUR** terhadap tangkapan layar sebelum migrasi yang kini ada di repositori. Angkanya
> **melampaui target 2 %** — 3,19 % / 7,16 % / 6,08 % — persis seperti yang diperkirakan
> laporan P1-A; § Kriteria "diff piksel ≤ 2 %" menjelaskan apa yang mengubah piksel itu dan
> mengusulkan kriteria pengganti. Tidak ada migrasi basis data, tidak ada endpoint baru.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-E → status)

| Tugas | Status | Bukti |
|---|---|---|
| Kurva-S proyek → `charts.js` | ✅ | `views/project.js`: ~95 baris SVG tangan → satu panggilan `lineChart` |
| Kurva EVM → `charts.js` (aturan sumbu > 100 % dipertahankan) | ✅ | `views/evm.js`; `yMax = Math.max(100, ceil(peak/25)*25)` tetap di pemanggil dan dipaku `ChartMigrationTest` |
| Tren harga satuan → `charts.js` | ✅ | `views/hargasatuan.js`; sumbu tidak-dari-nol dipertahankan lewat `yMin`/`yMax` sendiri |
| Diff piksel ≤ 2 % | ❌ **diukur, tidak tercapai** | 3,19 % / 7,16 % / 6,08 % (S20e) — lihat § Kriteria |
| Harness | ✅ | **S20e** (20 syarat fitur, dua tema), 7 PNG, hasil di `results-phase-1.json` |

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
`charts.js` menjarangkan di ruang piksel: 7 label tidak rata → 8 label rata.

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
| Kurva-S | 720×260 | 720×276 | **3,19 %** | 5,80 % |
| Kurva EVM | 720×261 | 720×277 | **7,16 %** | 5,78 % |
| Tren harga | 1112×372 | 1112×403 | **6,08 %** | 7,69 % |

Toleransi 16/255 per kanal (anti-alias sub-piksel tidak dihitung sebagai perubahan).

**Apa yang mengubah piksel itu**, seluruhnya disengaja dan tidak satu pun bisa dihindari sambil
tetap memakai `charts.js`:

1. **Legenda pindah KE DALAM svg** — tinggi natural bertambah 16 px (satu baris legenda) pada
   kurva-S dan EVM, 31 px pada tren harga yang dirender lebih lebar. Itu sendiri sudah
   5,8–7,7 % luas bingkai, sebelum satu piksel di dalam irisan berubah.
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
