# Laporan Paket UI-3b — medan perintah, dan palet yang akhirnya bisa dijalani papan ketik

> Paket kedua UI-3 (Cangkang): header dan palet perintah. Kepala halaman dan penyatuan `icon()`
> menyusul di UI-3c.

---

## Yang ditutup

| Butir | Keadaan |
|---|---|
| Tombol hantu "Cari" → medan perintah | ✅ ≥ 1200 px, tetap sebuah `<button>` |
| Palet: kerangka sendiri (620 px) | ✅ `.modal.palette` + `.palette-field` + `.palette-foot` |
| Kolom kanan pembeda | ✅ `.search-where` — satu baris, bukan dua |
| Penandaan bagian yang cocok | ✅ `<mark>`, substring, tanpa `innerHTML` |
| Lapisan papan ketik | ✅ ↑ ↓ Home End, `aria-activedescendant`, Enter membuka yang TERPILIH |
| Gerbang S14 | ✅ dibuktikan MERAH |

---

## Mengapa medan itu tetap sebuah tombol

Brief meminta "medan perintah sungguhan". Ia tetap `<button>`, dan itu keputusan, bukan kompromi:

1. **Nama aksesibelnya datang dari isinya** — `<span>Cari</span>` — dan itulah yang aturan ≤ 760 px
   sembunyikan secara visual sambil mempertahankan namanya. Nama sebuah `<input>` tidak bisa datang
   dari isinya.
2. **Ia tidak punya apa pun untuk diketik.** Ctrl+K membuka palet yang sudah punya kotak ketiknya
   sendiri; kotak kedua hanya menduplikasi keadaan yang sama di dua tempat.
3. Urutan fokus header bertambah satu perhentian yang tidak menuju ke mana-mana.

Diverifikasi: `role=button` dengan nama **tepat "Cari"** di 1440, 1280, 1200, 1199, 768 dan 390 px.
Kedua hiasan (`.gs-hint`, `.gs-kbd`) `aria-hidden`, jadi keduanya tidak masuk perhitungan nama.

*(Probe pertama saya membaca `textContent` dan melaporkan nama `'Carimenu, dokumen, pelanggan…Ctrl K'`
— `textContent` tidak tahu apa-apa tentang `aria-hidden`. Yang menjawab benar adalah pemilih peran
Playwright, yang menghitung nama aksesibel sungguhan.)*

## Ambangnya diukur, bukan dipilih

| viewport | ruang bebas header | medan tampil? | remah roti |
|---:|---:|---|---:|
| 1440 | 348 px | ya (320 px) | 377 px, utuh |
| 1280 | 188 px | ya (301 px) | 354 px, utuh |
| 1200 | 0 | ya (275 px) | 321 px, **tidak terpotong** |
| 1199 | — | tidak (73 px) | 377 px |
| 1024 | **0** | tidak | 337 px |
| 768 | **0** | tidak | 149 px |
| 390 | — | tidak (30 px ikon) | 73 px |

Pada 1024, 820 dan 768 px spacer header **sudah habis** sebelum paket ini, dan remah roti sendiri
sudah dimampatkan 377 → 149 px. Mencuri ruang di sana untuk sebuah petunjuk berarti menukar "di mana
saya" dengan hiasan. Karena itu medannya hanya muncul ≥ 1200 px; di bawah itu tombolnya persis
seperti sebelumnya. **Nol gulir menyamping di ketujuh lebar, dan tidak ada remah yang terpotong
karena paket ini** (768 dan 390 px memang sudah terpotong sebelumnya — aturan `crumb-screen ~ b`
yang disengaja).

---

## Palet

Lima layar bernama "Opname" di tiga modul — contoh yang prototipe pakai — kini terbaca sekali lihat:

| kode | kolom kanan |
|---|---|
| **Opname** Owner (OPN) | Proyek |
| **Opname** | Persediaan |
| **Opname** Subkon | Subkontrak |
| **Opname** Mandor | Subkontrak |
| Variasi Kontrak (Plafon **Opname**) | Proyek |

Modulnya **selalu ada di data** sejak T2.5; yang kurang hanya tempatnya. Bertumpuk di bawah kode, ia
terbaca sebagai keterangan; di kanan, ia pembeda. Baris kini 35 px satu baris, bukan dua.

`<mark>` memakai **substring**, tidak peduli-huruf — walau layar dicocokkan pada **awal kata**. Itu
disengaja: yang ditandai adalah apa yang MATA cari di baris itu, bukan aturan yang membuat baris itu
lolos. Teksnya dipasang lewat `createTextNode`, tidak ada `innerHTML`: kode dokumen datang dari
server dan nama pelanggan datang dari pengguna, dan keduanya tidak pernah boleh jadi markup.

**Papan ketik.** Sebelumnya Enter selalu mengklik `.search-hit` pertama di DOM dan tidak ada cara
memilih yang lain tanpa melepas papan ketik. Sekarang ↑ ↓ (melingkar), Home/End, dan Enter membuka
yang **terpilih** — diverifikasi: dua kali panah bawah lalu Enter membuka *Penyesuaian Stok (Opname)*,
bukan *Opname Owner (OPN)*. **Fokus sengaja tetap di kotak ketik**: memindahkannya ke baris berarti
huruf berikutnya yang diketik hilang. Barisnya ditandai `.active` + `aria-selected`, dan input
menunjuknya lewat `aria-activedescendant`.

Ponsel 390 px: modal menyusut ke 350 px, **nol gulir menyamping**, kelima baris dan kelima kolom
kanannya tampak, tidak ada kode yang terpotong.

---

## Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.409 / 11.588**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S5 S7 S8 S14 S18 S19 S20 S20m S21 S21m S22m S43` | hijau, keluar 0 — `results-ui-3b.json` |
| `SHELL_VERSION` | 19 → **20** (search.js dipracache service worker) |

**S14 kini sebuah gerbang**, dan dibuktikan merah dengan mencabut kolom kanannya:

```
[S14_search_screens] GAGAL: tidak satu pun baris punya kolom kanan .search-where
STATUS KELUAR: 1
```

Sabotase dipulihkan byte-per-byte (md5 cocok). Ia memaku sebelas syarat: grup "Layar" dan
jumlahnya, kerangka palet, kotak ketik, kaki papan ketik, kolom kanan, `<mark>`, baris terpilih,
fokus yang tetap di input, `aria-activedescendant`, Enter yang membuka yang terpilih, dan palet yang
tertutup sesudahnya.

---

## Yang sengaja tidak dikerjakan

* **Grup "Tindakan"** yang prototipe gambarkan. Kedua sumber palet adalah *lookup* — baris NAV di
  sisi klien, dokumen di sisi server — dan **tidak ada apa pun di codebase yang bisa menghasilkan
  sebuah perintah**. Menggambar judulnya berarti menjanjikan jenis hasil ketiga yang tidak pernah
  datang. Ia butuh registri tindakan lebih dulu; itu pekerjaan, bukan gaya.
* **Kepala dialog "Pencarian" dibiarkan.** Prototipe menggambar palet tanpa bilah judul. Bilah itu
  membawa nama aksesibel dialognya (`aria-labelledby`) dan tombol Tutup yang berguna di layar
  sentuh; menukarnya dengan tampilan yang lebih bersih menukar sesuatu yang bekerja dengan sesuatu
  yang terlihat benar.
* **Kolom kanan untuk baris DOKUMEN.** Sebagian grup server menjawab `title` kosong (terlihat pada
  "Pesanan Pembelian": kedua barisnya tanpa kolom kanan). Barisnya cukup tidak punya kolom itu —
  tanpa tempat kosong yang dipesan — dan mengisinya menuntut perubahan kontrak API.
* **Kepala halaman dan penyatuan `icon()`** — UI-3c.
