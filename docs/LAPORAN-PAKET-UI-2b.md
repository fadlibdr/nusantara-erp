# Laporan Paket UI-2b — sapuan gaya sebaris, dan permukaan yang berhenti melayang

> Lanjutan langsung `docs/LAPORAN-PAKET-UI-2a.md` (commit `f9fd766`), yang melahirkan
> primitifnya. UI-2b memakainya: sembilan berkas view disapu, enam permukaan datar
> kehilangan bayangannya, dan sebuah gerbang harness baru dipasang supaya tidak satu pun
> dari keduanya bisa terbalik diam-diam.

---

## Yang ditutup

| Butir | Keadaan | Angka |
|---|---|---|
| Sapuan gaya sebaris, 9 berkas | ✅ | **591 → 551** objek `style: {` di `public/app/js/views` (rekursif) |
| Permukaan datar kehilangan bayangan | ✅ | 6 kelas: `.card` · `.module-head` · `.module-card` · `.module-kpi` · `.home-tile` · `.stat` |
| Kepala tabel dipisah tepi yang terbaca | ✅ | `table.data th` border-bottom `--border` → `--border-strong` |
| Baris terpilih punya bentuk | ✅ | `tr.selected` + penanda `inset 3px` — dipakai UI-4 |
| Bug cascade `.mono` / `.fs-*` | ✅ | `.fs-*` dipindah SESUDAH `.mono`; dipaku S43 |
| `.spacer` tak berlingkup | ✅ | satu aturan global; dua pemakaian yang mati sejak ditulis kini hidup |
| Skenario harness UI-2 | ✅ | **S43** — 77 → **78** skenario |
| Plafon gaya sebaris | ✅ | `SpaInlineStyleBudgetTest::CEILING` 591 → **551** |

Per berkas (`style: {`, HEAD → pohon kerja):

| Berkas | Sebelum | Sesudah | |
|---|---:|---:|---|
| `custom.js` | 48 | 40 | −8 |
| `evm.js` | 41 | 38 | −3 |
| `tender.js` | 38 | 31 | −7 |
| `project.js` | 32 | 27 | −5 |
| `periods.js` | 32 | 26 | −6 |
| `profil.js` | 25 | 22 | −3 |
| `bankrecon.js` | 23 | 20 | −3 |
| `kalender.js` | 18 | 17 | −1 |
| `reports.js` | 14 | 10 | −4 |

Sapuannya **konservatif dengan sengaja**. Yang ditinggalkan agen penyapu: geometri yang
digerakkan data (lebar bilah, offset gantt), 12 `margin: 0` pada `<p>`, `white-space: pre-wrap`,
dan dorongan 3 px satu-satunya. Semuanya adalah nilai yang tidak punya kelas — dan menurut pesan
kegagalan uji plafon itu sendiri, yang kurang dalam kasus begitu adalah kelasnya, bukan plafonnya.

---

## Verifikasi

Uji pin dan uji plafon membuktikan *apa yang tertulis*. Yang harus dibuktikan di sini adalah
*apa yang tergambar* — jadi dibandingkan dua pohon yang dilayani berdampingan:

```bash
git worktree add $SP/head-tree HEAD          # 8098 — sebelum
#   pohon kerja                              # 8099 — sesudah
```

19 rute × 2 tema, setiap elemen di bawah `.main`, 27 properti terhitung + kotak batas. Kuncinya
**posisi struktural**, bukan nama kelas: nama kelas justru yang berubah, dan memasukkannya ke
kunci membuat setiap elemen yang disapu tampak "hilang" alih-alih "berubah".

**1.204 perbedaan, dan semuanya terhitung:**

| Kelompok | Jumlah | Putusan |
|---|---:|---|
| `box-shadow` → `none` | 178 | **disengaja** — enam permukaan datar |
| `border-bottom-color` pada `th` | 89 | **disengaja** — `--border-strong` |
| `font-size` 11,5 → 12 px | 138 | **disengaja** — nilai di luar skala dibulatkan ke token |
| `font-size` 12,5 → 13 px | 30 | **disengaja** — idem |
| `line-height` mengikuti | 168 | konsekuensi; 17,25→18 dan 18,75→19,5 = `--lh-body` 1,5 |
| geometri ≤ 1 px | ~120 | pembulatan dari kenaikan setengah piksel di atas |
| geometri > 4 px | 28 | dua `<p>` bertambah satu baris, kartunya ikut tumbuh 21–32 px; tidak ada yang terpotong |
| `.spacer` 0 → 744 / 633 px | 2 | **perubahan perilaku yang disengaja**, lihat Deviasi |
| pembungkus struktur di `profil.js` | 1 | **nol piksel** — diukur, lihat bawah |

Tidak ada satu pun perubahan `color`, `background-color`, `font-weight`, `padding` atau `margin`
yang tidak diminta. Ukuran huruf hanya bergerak dalam DUA transisi, keduanya naik setengah piksel
ke token terdekat — persis utang yang temuan audit #2 sebut (*"126 deklarasi `font-size` dengan 15
nilai berbeda termasuk 12,5/11,5/13,5 px"*). Tidak ada teks yang menyusut.

**Pembungkus `profil.js` diukur, bukan ditebak.** Agen penyapu membungkus tiga saudara di kartu
"Jam tenang" dalam `.stack.tight` dan mencabut dua `marginTop: '8px'`, mengandalkan `gap` 8 px.
Probe struktural melaporkannya sebagai elemen baru dan kartu yang tumbuh 34 → 135 px — keduanya
artefak jalur yang bergeser satu tingkat. Dikunci lewat teks, bukan jalur:

| | HEAD | UI-2b |
|---|---|---|
| tinggi kartu | 257,046875 px | **257,046875 px** |
| label sakelar | x=320 y=750 | **x=320 y=750** |
| medan "Mulai" | x=289 y=808 | **x=289 y=808** |
| catatan | x=289 y=843 | **x=289 y=843** |
| tombol simpan | x=289 y=889 | **x=289 y=889** |

**Tidak ada gulir menyamping** pada 23 rute × {1440, 390} × {terang, gelap} — 92 pengukuran, nol
temuan. Satu "pemotongan teks" yang sempat terlaporkan adalah `<text>` SVG di dalam grafik, tempat
`clientWidth`/`scrollWidth` tidak punya makna kotak CSS; dua putaran memberi jawaban berlawanan,
dan ia dibuang sebagai derau alat, bukan dicatat sebagai temuan.

**`.spacer` disapu menyeluruh sebelum aturannya diglobalkan.** 118 pemakaian di lapisan view,
diperiksa hidup-hidup di 28 rute: 17 di antaranya berada di luar sembilan wadah berlingkup dan kini
aktif, **semuanya mendatar**, dan **nol** berada di induk `flex-direction: column` — yang akan
menyerap tinggi dan meledakkan tata letak. Itulah satu-satunya bentuk kegagalan yang mungkin, dan
ia dicari sebelum, bukan sesudah.

---

## Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.409 uji / 11.588 asersi**, keluar 0 (11 dilewati, sudah dilewati sebelumnya) |
| `tests/Unit` | **630 uji / 2.201 asersi**, keluar 0 |
| Pin (aksen · remah · toast · grafik · PWA · plafon) | 76 uji / 1.168 asersi, keluar 0 |
| Harness `S7 S8 S18 S19 S20 S20m S21 S21m S43` | hijau — bukti di `docs/bukti-uji/results-ui-2b.json` |
| Kepadatan S21 | `compact_ok` · `comfortable_ok` · `normal_equals_baseline` · `tfoot_normal_41` **semuanya true** |

`S8_styles` dibandingkan terhadap bukti UI-1: **1 dari 31 kunci berbeda, dan kunci itu `_ms`** —
lama eksekusi. S8 memang tidak mengukur bayangan kartu maupun warna tepi `th`; itulah sebabnya
probe A/B di atas ada, dan itulah sebabnya S43 ditulis.

### S43 — gerbang yang dibuktikan bisa MERAH

Sebuah gerbang yang belum pernah gagal belum diketahui menjaga apa pun. S43 gagal pada putaran
pertamanya dengan tiga tuduhan, dan ketiganya **salah** — kesalahan alat, bukan kesalahan CSS:

* `--chart-n` "tak terdefinisi" — ia hanya muncul di **komentar** app.css (*"--accent-n memakai
  sudut hue yang sama dengan --chart-n"*). Regex yang membaca berkas apa adanya menuduh prosa.
  Komentar kini dibuang lebih dulu.
* `--module-accent` dan `--module-accent-soft` "tak terdefinisi" — keduanya memang **tidak**
  didefinisikan di `:root`, melainkan oleh `[data-accent="N"]` pada akar modul. Menanyakannya di
  halaman non-modul adalah menuduh rancangan yang benar. Sapuan token pindah ke `#/m/fin` dan
  diselesaikan dari elemen ber-`[data-accent]`.

Sesudah diperbaiki: **80 token dipakai, nol kosong, di terang DAN gelap DAN cetak.** Cetak ikut
karena paletnya hidup dalam lima salinan — token yang lupa disalin ke satu blok hanya terlihat di
blok itu, dan itu persis cacat yang UI-1 tutup.

S43 juga memaku dua keputusan yang mudah dibatalkan tanpa sadar: bahwa utilitas ukuran huruf
tetap **empat**, dan bahwa `.fs-*` tetap tertulis **sesudah** `.mono`.

---

## Yang sengaja tidak dikerjakan

* **Migrasi `icon()` → `svgIcon()`.** 85 pemanggil warisan lawan 4 pemakai sprite. Ia diucapkan
  di rencana UI-2 dan ia **tidak** dikerjakan: menyentuh 85 tempat panggil di paket yang sudah
  menggeser piksel di 19 layar berarti dua sumber regresi bercampur dalam satu bukti. Dipindahkan
  utuh ke UI-3, tempat cangkang memang dibongkar.
* **Rancang ulang tabel penuh** — pemotongan cerdas dengan `title`, hierarki bilah alat. Yang
  mendarat di UI-2 adalah perlakuan kepala, tepi, hover/terpilih, dan `tabular-nums`. Sisanya
  bertetangga langsung dengan seleksi dan aksi massal, yang memang milik UI-4.
* **551 gaya sebaris sisanya.** Plafonnya sekarang paku, bukan target.

---

## Deviasi baru

1. **Dua tombol "Muat ulang" pindah ke kanan.** Mengglobalkan `.spacer` menghidupkan markup yang
   mati sejak ditulis di bilah alat EVM dan Rekonsiliasi Bank; tombolnya kini terdorong ke tepi
   kanan, seperti setiap bilah alat lain di aplikasi (`.card-head`, `.modal-foot`, `.pager`).
   Ini **memperbaiki** niat penulisnya, tetapi ia perubahan yang terlihat dan dicatat di sini
   supaya tidak ditemukan sebagai kejutan nanti.
2. **`#/bank-recon` menjawab 422 dari server.** Diisolasi dengan menukar `bankrecon.js` ke versi
   `HEAD` dan menjalankan ulang: gejalanya **identik** (`chars=400 h1=Rekonsiliasi Bank galat=1`,
   422 yang sama). **Bukan akibat sapuan** — data demo tidak punya akun bank terkonfigurasi.
   Dibiarkan apa adanya; ia milik UI-6 (Keuangan) atau perbaikan fikstur demo, bukan paket ini.
3. **Probe pertama saya mengukur hal yang salah, dan hampir lolos.** Putaran pertama menukar
   *stylesheet*-nya saja di dalam satu halaman — sehingga KEDUA cuplikan merender DOM UI-2, dan
   kelas `.fs-secondary` sekadar tidak terdefinisi di bawah CSS HEAD. Ia melaporkan "0 beda" di
   11 dari 12 rute. Angka nol itu palsu karena alasan kedua juga: sebelas rutenya tidak ada
   (`#/fin`, `#/po`, … — sumber daya generik hidup di `#/r/<nama>`), jadi yang diukur adalah
   halaman "tidak ditemukan" yang sama, 1.143 elemen, berulang-ulang. **Dua gerbang dipasang
   permanen di probe**: rute dengan `.main` < 20 elemen berteriak KOSONG alih-alih melapor nol,
   dan perbandingannya kini benar-benar dua pohon di dua port.
