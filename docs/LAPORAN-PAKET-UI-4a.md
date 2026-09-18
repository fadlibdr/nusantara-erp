# Laporan Paket UI-4a — chip filter: menggambar keadaan yang sudah ada

> Paket pertama UI-4 (UI sumber daya generik). Seleksi + aksi massal, tampilan tersimpan,
> formulir halaman penuh, kepala dokumen dan jalan keyboard papan kanban menyusul.

---

## Yang ditutup

Chip filter aktif di layar daftar generik: satu baris yang menyebut **apa yang sedang mempersempit
daftar ini**, dengan tiap filter bisa dibuang satu per satu.

Ia **tidak mengarang keadaan**. `ui.q`, `ui.filters`, `ui.dateFrom`/`ui.dateTo` dan `resetFilters()`
sudah ada sebelum paket ini; chip hanya menggambarnya. Yang benar-benar baru cuma satu hal: sampai
sekarang satu-satunya cara berhenti menyaring adalah tombol **Reset**, yang membuang **semuanya
sekaligus**.

### Mengapa ini sepadan, diukur

97 sumber daya mendeklarasikan **200 filter** — rata-rata 2,1, maksimum 4. Ditambah kotak cari dan
pasangan tanggal (saat server mengumumkan `meta.date_column`), daftar tersibuk bisa punya **tujuh**
kontrol aktif sekaligus. Menemukan mana dua di antaranya yang sedang menyala berarti memindai
seluruh bilah.

---

## Label nilainya tidak dihitung ulang

Ini keputusan rancangan paket ini. Tiap kontrol **sudah tahu** label nilainya sendiri:

| bentuk filter | dari mana labelnya |
|---|---|
| enum · bool · bulan | `<option>` yang **terpilih** di `<select>`-nya |
| lookup | `labelOf()` milik comboboxnya |
| tanggal · angka | nilainya sendiri, lewat `format.date()` |

Jadi `buildFilter()` **melaporkan** labelnya ke baris chip, alih-alih baris chip menghitungnya lagi
dari `ENUMS`/`MONTHS`/cache lookup. Dua sumber kebenaran akan hanyut, dan chip yang menyebut nilai
**berbeda** dari kontrol tepat di atasnya lebih buruk daripada tidak ada chip sama sekali.

Ia juga mewarisi kejujuran yang sudah ada di sana. `labelOf()` membedakan id yang belum dimuat dari
id yang **tidak ada di sumber**, dan chipnya ikut:

| keadaan | chip |
|---|---|
| lookup selesai | `Proyek: PRJ-2026-001 — Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)` |
| id tidak ada | `Proyek: #999999 (tidak ada di daftar)` |
| enum | `Status: Draf` — bukan `draft` |

**`text` dibaca setiap kali chip digambar, bukan sekali saat bilah dibangun.** Sumber lookup tiba
asinkron; sebuah string yang dibekukan saat bilah lahir akan berbunyi `Memuat…` selamanya. `sync()`
milik combobox karena itu ikut memanggil `paintChips()`.

Nilai yang tidak punya `<option>` — seed URL basi — memberi `selectedIndex` 0, yaitu option
placeholder yang berlabel **nama filter**. Chipnya sengaja dibiarkan **kosong** alih-alih berbunyi
`Status: Status`.

---

## Verifikasi

| | hasil |
|---|---|
| Tanpa filter | baris **tersembunyi**, bukan kosong-tapi-memakan-ruang |
| `?q=PO&status=draft` | 2 chip: `Cari: PO` · `Status: **Draf**` |
| Membuang satu chip | menyisakan 1 chip, URL tetap `status=draft` — filter lain **tidak** ikut terbuang |
| Hapus semua | baris tersembunyi, URL bersih, baris kembali muncul |
| 1440 px | nol gulir halaman; chip panjang 496 px utuh |
| 390 px | nol gulir halaman; chip panjang **dielipsiskan**; sasaran buang **30 × 28 px** |

Pada 390 px baris chip setinggi **136 px** untuk dua filter aktif — tiga baris. Itu dibiarkan: tidak
seperti bilah aksi kepala halaman yang selalu ada, baris ini **hanya muncul saat daftarnya memang
sedang disaring**, dan di saat itu ia menjawab pertanyaan "kenapa barisnya sedikit".

### Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.414 / 11.599**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S8 S14 S18 S21 S21m S43 S44` | hijau, keluar 0 — `results-ui-4a.json` |
| `SHELL_VERSION` | 21 → **22** |

**S44 baru** (79 skenario) memaku sebelas syarat dan dibuktikan **merah** dengan membuat chip enum
menampilkan nilai mentah:

```
GAGAL: chip enum menampilkan nilai mentah 'draft', bukan label <option>-nya
STATUS KELUAR: 1
```

Rutenya dipilih karena ia punya **kedua** bentuk yang sulit sekaligus: enum (label dari `<option>`)
dan lookup (label asinkron, dan jujur soal id yang hilang). Sabotase dipulihkan byte-per-byte.

---

## Yang sengaja tidak dikerjakan

* **Seleksi + aksi massal** — UI-4b. Ia bangunan baru: kolom centang, bilah seleksi, eksekusi lewat
  `runAction()` yang sudah ada, berurutan, dan kegagalan menyebut dokumennya tanpa menghentikan
  sisanya.
* **Tampilan tersimpan** (`.chips#views` di prototipe) — UI-4c. Hari ini keadaannya hanya ada di URL;
  menyimpannya butuh tempat menyimpan, dan itu keputusan (preferensi server vs lokal), bukan gaya.
* **Formulir halaman penuh** — keputusan pemilik #2, paketnya sendiri.
