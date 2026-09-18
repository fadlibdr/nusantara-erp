# Laporan Paket UI-5 — dasbor: ubin yang akhirnya sejajar, dan dua batasan keras yang tidak pernah dijaga

> Dasbor · beranda · beranda modul, dan 19 widget.

---

## Sebagian besar brief UI-5 sudah terpenuhi sebelum paket ini

Diukur sebelum menyentuh apa pun — 19 widget, 37 ubin:

| yang diminta brief | keadaan sebenarnya |
|---|---|
| "label · nilai · konteks kecil" | **sudah**: 37 dari 37 ubin punya ketiganya |
| "tanpa ikon raksasa" | **sudah**: nol ikon di dalam ubin mana pun |
| "tanpa gradien" | **sudah**: dua gradien di seluruh app.css, keduanya jauh dari ubin |
| "tanpa nilai yang lebih besar dari yang lain" | **sudah**: tiap nilai widget memakai `.value.sm`; tidak ada pemanggil yang melewatkan `small: false` |
| "grafik lebih sunyi dari angkanya" | **tidak berlaku**: keluarga dasbor tidak menggambar satu grafik pun — semuanya di layar UI-6 |
| "tren opsional" | **tidak ada**, dan bukan perapian: `.delta` sudah dipakai kalimat konteks |

Jadi UI-5 bukan pekerjaan menghapus ikon raksasa dan gradien yang tidak pernah ada. Yang benar-benar
terbuka ada di bawah.

---

## 1. Angkanya tidak sejajar

Dari **enam** baris ubin di `#/dashboard`, **dua tidak sejajar** — nilainya meleset **18 px** karena
sebagian labelnya membungkus dua baris ("Sudah lewat / tanpa tanggal" 35 px lawan "Menipis" 17 px).
Tiap ubin benar sendiri-sendiri; barisnya yang tidak pernah disuruh sepakat — dan yang berantakan
justru angkanya, satu-satunya hal yang ubin ini ada untuk menyampaikan.

`subgrid` membuat ketiga baris dalam ubin (label · nilai · konteks) memakai trek yang **sama** di
seluruh baris. Sesudahnya: **0 dari 6** tidak sejajar. Peramban tanpa subgrid jatuh kembali ke
perilaku hari ini.

## 2. Tiga ukuran untuk satu peran

Angka utama sebuah ubin berukuran **22 px di beranda, 20 px di beranda modul, 20 px di `.stat`**.
`--fs-kpi` ditambahkan UI-1 sebagai **target** — laporan UI-1 mengatakannya apa adanya — dan sampai
paket ini ia tidak punya **satu pun** pemakai; begitu juga `--fs-label`.

Sesudahnya: beranda dan beranda modul keduanya **22 px**, label 11,5 → 11 px, unit 12,5 → 12 px.
`--fs-kpi` kini 3 pemakai, `--fs-label` 1.

`.value.sm` (16 px) **sengaja** dibiarkan di luar skala: ia peran yang berbeda — dua ubin
berdampingan di dalam kartu widget, bukan satu angka pahlawan — dan pelajaran UI-2 berlaku sama,
begitu ada nama untuk setiap ukuran, skalanya berhenti menjadi skala.

## 3. Tujuh ubin dirakit tangan

`ap-aging`, `ar-aging` dan `proyeksi-kas` menulis `.stat`/`.label`/`.value.sm` sendiri tanpa
memanggil `stat()`. Bentuknya kebetulan cocok; yang tidak ikut adalah setiap perubahan **berikutnya**
pada ubin — dan yang pertama datang di paket ini juga, berupa perataan subgrid.

Satu-satunya hal yang menahan `proyeksi-kas` adalah pewarnaan saldo negatif, yang ditulis sebagai
**tiga gaya sebaris** karena `stat()` tidak menyediakannya. `valueTone` sekarang menyediakannya
sebagai kelas (`.tone-neg`, kosakata UI-2), dan ketiga gaya sebaris itu hilang.

**Dan widget aging tidak sepakat dengan dirinya sendiri.** `format.js:33` menamai `rupiahShort`
*"Compact rupiah for dashboard tiles"*, bilah di bawah ubinnya memakainya — tetapi ubin di atasnya
menulis `Rp 14.550.000.000` tepat di atas bilah yang menulis angka yang sama sebagai `Rp 14,5 M`.
Ubinnya kini memakai bentuk yang sudah dinamai codebase-nya sendiri.

`proyeksi-kas` **tetap** memakai rupiah penuh: ia saldo kas, tidak punya bilah yang bertentangan
dengannya, dan memilih presisi sebuah angka uang adalah keputusan isi — bukan perapian gaya.

---

## 4. Dua batasan keras yang tidak pernah dijaga

Rencana kampanye menyebut lima hal sebagai batasan keras keluarga dasbor. **Dua di antaranya
dihitung dengan rapi dan tidak pernah dibaca:**

**`structure_ok`** — `<ul>`/`<li>`, `.module-section` sebagai `<h2 id>`, `aria-labelledby` yang
menunjuk balik. Dihitung sejak P1-B, muncul **tepat sekali** di seluruh harness.

**Rentang `gridColumn`** — dan ini lebih buruk. Harness memang membacanya, tetapi lewat
`getComputedStyle(c).gridColumnEnd`, yang untuk `grid-column: span N` **selalu `'auto'`**. Properti
yang benar adalah `gridColumnStart`. Diukur pada kartu lebar: `inline 'span 4'`, `start 'span 4'`,
`end 'auto'`. Baris itu membaca `'auto'` untuk setiap kartu sejak ditulis; perlindungannya kosong.

Keduanya kini digerbangi, dan **keduanya sempat lolos sebagai gerbang mati sebelum itu ketahuan:**

* Versi pertama gerbang rentang memakai data `'auto'` yang lama — kondisinya mengecualikan semua
  kartu, jadi ia tidak pernah bisa menyala. Sabotase `SPAN sedang 2 → 3` lewat **hijau**.
* Versi pertama gerbang struktur membaca `out["module_home_fin"]`, sementara nilainya hidup **per
  tema** di `out["light"]["module_home_fin"]`. Ia menemukan `{}`, tidak memeriksa apa pun, dan
  sabotase `<ul>` → `<div>` lewat **hijau**. Datanya sendiri sudah benar — `False` di kedua tema.

Sesudah diperbaiki, keduanya merah dengan sebab yang disebut:

```
GAGAL: light: struktur beranda modul rusak — {'grid_tag': 'DIV', …}, dark: …
GAGAL: syarat ok            (rentang: size=sedang menggambar span 3)
```

Kedua sabotase dipulihkan byte-per-byte.

---

## Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.420 / 11.626**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S21 S21m S22 S23 S23s S23f S43 S44 S49` | hijau, keluar 0 — `results-ui-5.json` |
| `SHELL_VERSION` | 27 → **28** |

---

## Yang sengaja tidak dikerjakan

* **Tren opsional.** Ia bukan perapian — slot yang akan ditempatinya sudah dipakai kalimat konteks,
  dan sebuah ubin dengan tren DAN konteks akan punya dua baris berwarna yang bersaing. Ia butuh
  keputusan bentuk lebih dulu.
* **"Grafik lebih sunyi dari angkanya".** Keluarga dasbor tidak menggambar grafik; semuanya ada di
  `evm.js`, `hargasatuan.js`, `jadwal.js`, `project.js` — layar UI-6.
* **Urutan panel "berurutan prioritas".** Urutannya sudah bisa diatur pengguna lewat laci "Atur
  dasbor" dan tersimpan; mengurutkan ulang bawaannya adalah keputusan pemilik tentang apa yang
  paling penting, bukan gaya.
* **`.value.sm` 16 px diberi token.** Lihat bagian 2.
* **29 `.value` telanjang di luar keluarga dasbor** (`reorder.js`, `bankrecon.js`, …) — UI-6.
