# Laporan Paket UI-4f — kepala dokumen, dan sebuah kunci schema yang tidak boleh menghilangkan apa pun

> Paket terakhir UI-4. Menutup dua butir terakhir: kepala dokumen, dan kunci opsional `detail.groups`.

---

## Kepala dokumen

Rencana memintanya berisi **kode · status · lawan transaksi · nilai · zona aksi**. Tiga yang pertama
dan terakhir sudah ada; dua yang baru adalah **lawan transaksi** dan **nilai** — dua fakta yang
sebenarnya dipakai orang untuk mengenali dokumen mana ini, dan yang sampai sekarang hanya ada jauh
di bawah, di dalam panel Informasi yang rata.

```
PO/2026/II/0001   ● Disetujui                          [←] [Cetak ▾] [Tutup PO]
Proyek: PRJ-2026-001 — Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)   Total: Rp 232.545.000
```

**Keduanya tidak ditebak.** Skema sudah menyatakan tipe tiap kolom, jadi lawan transaksi adalah
kolom `rel` **pertama** dan nilainya kolom `currency` **pertama** — derivasi yang sama persis dengan
yang dipakai kartu papan kanban sejak F-3. Sumber daya yang tidak menyatakan keduanya tidak mendapat
baris ini, dan tidak ada yang dikarang untuknya: invoice AR hanya memajang `Total: Rp 10.767.000.000`
karena kolom `rel`-nya memang tidak terisi.

Nilainya digambar `renderCell`, bukan format sendiri — supaya angka di kepala dokumen tidak pernah
berbeda dari angka yang sama di tabel daftarnya.

**Satu cacat yang tertangkap saat diukur:** putaran pertama memajang `Proyek: —` dan `Total: —` pada
dokumen yang jelas terisi. Dua sebab sekaligus — `renderCell(row, column)`, bukan
`renderCell(column, row)`, dan pemeriksaan keterisian memakai `record[key]` sementara yang
menggambarnya memakai `pluck(record, key)` untuk kunci bertitik. Memeriksa keterisian dengan cara
yang **berbeda** dari cara menggambarnya adalah cara memasang em-dash pada data yang ada.

---

## `detail.groups` — dan aturan yang membuatnya aman

Panel "Informasi" dibangun dari **apa pun yang API jawab**, disaring daftar pengecualian dan kamus
~500 kunci. Karena itu ia **tidak bisa** mengarang pengelompokan: tidak ada di dalam data yang
mengatakan "termin pembayaran dan PPN itu satu kelompok". Satu-satunya yang tahu adalah skema, jadi
skema yang menyatakannya:

```js
detail: {
  groups: [
    { label: 'Asal & tujuan',  keys: ['rfq_id', 'project_id', 'warehouse_id', 'delivery_address'] },
    { label: 'Jadwal',         keys: ['order_date', 'expected_date'] },
    { label: 'Termin & pajak', keys: ['payment_term_days', 'ppn_rate', 'needs_director_approval'] },
  ],
}
```

**SATU ATURAN: apa pun yang tidak disebut kelompok mana pun tetap tergambar**, di blok terakhir tanpa
judul. Sebuah kunci yang hilang karena seseorang lupa menyebutkannya adalah medan yang lenyap tanpa
satu pun galat — dan panel ini adalah satu-satunya tempat sebagian medan pernah terlihat. Blok sisa
sengaja **tanpa judul**: ia bukan kelompok bernama "Lain-lain", ia yang **belum** dikelompokkan, dan
memberinya judul akan menyembunyikan bahwa skemanya belum selesai.

Terukur:

| | kelompok | blok | medan |
|---|---:|---:|---:|
| PO (menyatakan `groups`) | 3 | **4** (3 bernama + 1 sisa) | **13** |
| Invoice AR (tidak) | 0 | 0 | 14 — rata, persis seperti sebelumnya |

Sembilan kunci disebut kelompok; ketiga belas medan tetap tergambar.

---

## Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.420 / 11.626**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S2 S8 S14 S18 S21 S43 S44 S47 S48` + **S49** | hijau, keluar 0 — `results-ui-4f.json` |
| `SHELL_VERSION` | 26 → **27** |

**S49 baru** (84 skenario) membandingkan sumber daya yang menyatakan `groups` dengan yang tidak —
kunci opsional yang diam-diam mengubah layar yang tidak memintanya bukan kunci opsional — dan
dibuktikan **merah** dengan membuang blok sisanya:

```
GAGAL: blok sisa tidak tergambar: 3 blok untuk 3 kelompok — medan yang tidak disebut lenyap,
       panel Informasi PO hanya 9 medan; sembilan di antaranya disebut kelompok, jadi sisanya hilang
```

Ia juga memaku bahwa kepala dokumen tidak pernah memajang `—`, yaitu persis cacat yang paket ini
jatuhkan sekali.

---

## Yang sengaja tidak dikerjakan

* **Adopsi `detail.groups` di luar PO.** Rencana menugaskannya ke UI-6, per sumber daya. PO dipakai
  sebagai contoh pertama karena ia memajang 13 medan — kasus tempat satu daftar rata berhenti bisa
  dibaca — dan supaya jalurnya teruji, bukan sekadar didukung.
* **Lawan transaksi untuk sumber daya yang tidak menyatakan kolom `rel`.** Menebaknya dari nama
  kunci (`vendor_id`, `customer_id`, …) berarti mengarang di layar yang justru dibangun untuk tidak
  mengarang.
* **Layar dokumen custom (12 sumber daya ber-`customDetail`).** Semuanya melewati `renderDetail`
  sepenuhnya dan merakit kepalanya sendiri; menyentuhnya berarti mengubah sepuluh layar khusus
  sekaligus — UI-6.
