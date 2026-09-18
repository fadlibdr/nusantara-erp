# Laporan Paket UI-4d — formulir berbaris menjadi halaman, dan angka yang membenarkannya ternyata basi

> Keputusan pemilik #2. Paket keempat UI-4.

---

## Aturan pemutusnya, dan berapa layar yang pindah

Rencana sudah menulis aturannya: **apakah sumber daya menyatakan `lines`.** Diukur pada
`schema.js` — **92 sumber daya berformulir, 35 menyatakan `lines`.** Ketiga puluh lima itu menjadi
halaman; **57 sisanya tetap dialog**, karena navigasi halaman untuk empat medan master-data adalah
ongkos tanpa untung.

`prefill` dan `endpoint` **memaksa dialog**, dan itu batas yang jujur: keduanya membawa konteks yang
hanya dimiliki pemanggilnya ("Tagih termin ini" sudah tahu kontrak, pelanggan, dan termin mana), dan
sebuah URL tidak bisa menyusunnya kembali.

---

## Angka yang membenarkan keputusan ini sudah basi

Rencana dan ASESMEN-UX §1.2 menyebut modal 960 px menaruh tabel baris pada **y = 843 px**, dan
prototipe mencapai **y = 754**. Diukur ulang hari ini pada formulir PO yang sebenarnya, dua pohon
dilayani berdampingan:

| viewport | modal (`main`) | halaman (UI-4d) |
|---|---|---|
| 1440 | y = **1006**, gulir dalam **305 px** | y = **966**, gulir dalam **0** |
| 1024 | y = 1006, gulir dalam 305 px | y = 1036 |
| 900 | y = 1286, gulir dalam 585 px | y = 1333 |

**Modalnya bukan 843; ia 1006.** Dan prototipe mencapai 754 pada kumpulan medan yang **berbeda** —
mock-upnya tidak punya empat textarea lebar-penuh yang formulir PO sungguhan bawa (Alasan tanpa PR,
Alamat pengiriman, Catatan, Alasan override prakualifikasi), yang sendirian menyumbang **313,7 px**.

Jadi hasil sesungguhnya lebih sempit dari yang dijanjikan, dan tidak seragam:

* **Di 1440 px halaman menang** (966 lawan 1006) dan grid tiga kolomnya menghemat 70 px.
* **Di 1024 dan 900 px halaman sedikit kalah** — sebab strukturalnya: **dialog MENUTUPI sidebar dan
  mendapat 960 px; halaman tidak dan hanya mendapat 730 / 606 px.** Itu ongkos yang melekat pada
  keputusan ini, bukan sesuatu yang bisa distel.

**Kemenangan sesungguhnya bukan y itu.** Modal membawa **wadah gulir dalam** setinggi 305–585 px;
halaman membawa **nol**. Itulah yang membuat `thead` lengket tabel baris berperilaku benar — catatan
rencana sendiri memperingatkan bahwa `thead` lengket mengukur terhadap wadah gulir TERDEKAT — dan
itulah yang membuat formulir berhenti harus muat di dalam viewport.

---

## Yang benar-benar dibangun: seam presenter, dan penjaga yang belum pernah ada

`form.js` membangun kontrol, tabel baris, snapshot, draf otomatis dan pemetaan galat 422 **satu
kali**, tanpa tahu ia akan duduk di dialog atau halaman. Seam-nya sesempit **dua metode** —
`requestClose()` yang bertanya lebih dulu dan `close()` yang tidak — karena hanya itu yang benar-benar
membedakan keduanya. Tidak ada satu pun cabang `if (halaman)` di dalam `form.js`.

Perbedaan sesungguhnya ada di **jalan keluarnya**. Dialog punya satu pintu, dan `modal()` menjaganya
dengan `dirty()`. Halaman **tidak punya pintu sama sekali**: yang meninggalkannya adalah navigasi —
tautan sidebar, tombol Kembali peramban, Ctrl+K lalu Enter — dan tidak satu pun dari itu pernah lewat
`form.js`. Sampai paket ini `router.js` tidak punya cara menolak sebuah navigasi.

`setLeaveGuard()` / `clearLeaveGuard()` karena itu ditambahkan ke router. Penjaganya **asinkron**
(pertanyaannya sebuah dialog); selama ia terbuka bilah alamat sudah memuat hash baru, dan jawaban
"tetap di sini" menulis hash lama kembali lewat `history.replaceState` — yang **tidak** memicu
`hashchange`, jadi tidak ada putaran yang perlu diredam. Izinnya sekali pakai.

Pembersihan draf **menumpang di penjaga itu**, bukan di tombol Batal: kalau tidak, meninggalkan
halaman lewat sidebar akan menyisakan draf yatim selamanya.

---

## Verifikasi

| Jalur | Hasil |
|---|---|
| Sumber daya berbaris | halaman · remah **3** (`Pengadaan › Pesanan Pembelian (PO) › PO baru`) · tombol `Batal` + `Simpan` · **gulir dalam 0** |
| Sumber daya tanpa baris | tetap **dialog** |
| Formulir bersih ditinggalkan | pergi diam-diam, tanpa pertanyaan |
| Formulir kotor ditinggalkan | ditanya "Tinggalkan formulir ini?" |
| "Tetap di sini" | hash kembali ke formulir · halaman bertahan · **isian bertahan utuh** |
| "Tinggalkan" | benar-benar pergi |
| Navigasi berikutnya | penjaga **tidak tertinggal** |

### Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.419 / 11.621**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S8 S14 S18 S21 S21m S43 S44` + **S47** | hijau, keluar 0 — `results-ui-4d.json` |
| `SHELL_VERSION` | 24 → **25** |

**S47 baru** (82 skenario) memaku sebelas syarat dan dibuktikan **merah** dengan mencabut satu baris
`setLeaveGuard(leaving)`:

```
GAGAL: formulir KOTOR tidak menanyakan apa pun saat ditinggalkan,
       'Tetap di sini' tidak mengembalikan hash: '#/dashboard',
       'Tetap di sini' tetap meninggalkan halamannya,
       'Tetap di sini' kehilangan isiannya: ''
```

Nomornya **S47, bukan S45** — S45 dan S46 sudah dipakai UI-4b dan paket sebelumnya, dan putaran
pertama saya menamainya S45: fungsinya bernama sama, definisi yang belakangan menang, dan runner
menjalankan `S45_seleksi_massal` sambil mencetak "ok". Tertangkap karena hasilnya dibaca, bukan
karena status keluarnya.

---

## Yang sengaja tidak dikerjakan

* **Dua puluh lima tempat panggil `openForm` lain tidak disentuh.** Yang dialihkan hanya jalan masuk
  GENERIK — tombol Tambah dan Ubah di daftar, dan Ubah di layar dokumen — yang mencakup ketiga puluh
  lima sumber daya berbaris. Layar custom (SPK subkon, klaim progres, RFQ) membuka formulirnya
  sendiri dan tetap memakai dialog; mengalihkannya berarti mengubah alur sepuluh layar khusus
  sekaligus, dan itu UI-6.
* **Memendekkan kepala formulir PO.** Empat textarea lebar-penuh menyumbang 313,7 px dan merekalah
  alasan y-nya masih 966. Melipat yang jarang dipakai ke balik disclosure adalah keputusan ISI per
  sumber daya, bukan tata letak generik — UI-6.
* **Melebarkan halaman di bawah 1024 px.** Dialog menang di sana karena ia menutupi sidebar. Membuat
  halaman melakukan hal yang sama berarti menyembunyikan navigasi, yang justru salah satu hal yang
  halaman ini dapatkan.
