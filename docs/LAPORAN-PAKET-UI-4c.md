# Laporan Paket UI-4c — tampilan tersimpan: sebuah tautan yang diberi nama

> Paket ketiga UI-4 (UI sumber daya generik). Chip filter ditutup UI-4a, seleksi dan aksi
> massal UI-4b; formulir halaman penuh, kepala dokumen dan jalan keyboard papan kanban menyusul.

---

## Yang ditutup

Keadaan layar daftar — pencarian, saringan, rentang tanggal, urutan — bisa **diberi nama dan
disimpan**, lalu diterapkan kembali dengan satu klik. Tersimpan pada **akun**, bukan pada peramban.

---

## Keputusan yang ditunda UI-4a, dan kenapa ia tidak pernah benar-benar terbuka

Laporan UI-4a menulis: *"menyimpannya butuh tempat menyimpan, dan itu keputusan (preferensi server
vs lokal), bukan gaya."* Membuka kodenya menjawabnya sendiri:

`core_user_preferences` sudah ada sejak P1-C dengan **server sebagai kebenaran dan localStorage
sebagai cermin**, dan CONVENTIONS §15 menyebut isinya persis "apa pun yang seseorang PILIH untuk
dirinya sendiri". Menyimpan tampilan di localStorage berarti mengulang cacat yang P1-C pindahkan
favorit ke server untuk memperbaikinya: disusun di desktop kantor, tidak ada di tablet lapangan,
hilang saat peramban dibersihkan.

Maka UI-4c **tidak menambah satu tabel pun dan tidak menambah satu endpoint pun** — sebagaimana
dijanjikan komentar kelas itu sendiri: *"kunci berikutnya adalah satu entri array"*:

```php
'list.views' => [
    'label' => 'Tampilan tersimpan',
    'max_bytes' => 8192,
    'max_entries' => 20,
    'validate' => …
],
```

---

## Yang disimpan adalah query string-nya

Satu tampilan = `{ route, name, query }`, dan `query` adalah **persis** string yang `syncUrl()` tulis
ke bilah alamat dan yang `seedFromUrl()` sudah tahu cara membacanya.

Sebuah tampilan tersimpan karena itu bukan bentuk data baru: **ia sebuah tautan yang disalin dari
bilah alamat, dengan nama.** Bentuk lain — `{filters:{…}, sort:…}` — berarti penyerialisasi kedua
dan pembaca kedua untuk keadaan yang sama, dan keduanya akan hanyut terhadap daftar filter yang
berubah setiap paket.

Yang ikut berubah di `list.js`: badan `syncUrl()` diangkat menjadi `stateQuery()`, satu
penyerialisasi yang dipakai bilah alamat **dan** penyimpan tampilan. Dua salinan akan hanyut, dan
hanyutnya berarti tampilan yang disimpan hari ini memulihkan saringan yang berbeda besok.

**`page` sengaja tidak ikut.** "Halaman 3 dari saringan saya" bukan sebuah tampilan — ia sebuah
posisi, dan posisi itu menunjuk baris yang sama sekali berbeda besok pagi.

### Servernya memeriksa bentuk, bukan hanya ukuran

| ruas | aturan |
|---|---|
| `route` | harus `r/<sumber daya>` **dan** ada di NAV (`SpaNav::hasRoute`, jalan yang sama dengan favorit) |
| `name` | wajib, ≤ 60 karakter |
| `query` | kosong, atau `k=v&k=v` **ruas per ruas**, ≤ 240 karakter |

Kosakata karakternya persis yang bisa dihasilkan `URLSearchParams.toString()` (form-urlencoded:
spasi jadi `+`, sisanya persen). Tanpa pemeriksaan ruas itu, kolom ini adalah string bebas 240 byte
per entri yang ikut ke setiap backup selamanya — pintu yang sama yang ditutup whitelist per kunci,
satu lapis lebih dalam.

---

## Tiga cacat yang hanya muncul saat dijalankan sungguhan

### 1. `''` tidak pernah sampai ke validator

Tampilan **"semua"** — query kosong — adalah jalan kembali dari tampilan tersaring ke daftar utuh,
dan validatornya menerimanya. Uji satuan yang memanggil `reject()` langsung: hijau.

Lewat HTTP ia tidak pernah berbentuk `''`. **`ConvertEmptyStringsToNull` adalah middleware global**
(terukur di `bootstrap/app.php`: ia dan `TrimStrings` ada di daftar global), jadi `query: ''` yang
dikirim SPA mendarat sebagai `null` dan **disimpan sebagai `null`**:

```
PUT core/me/preferences/list.views   {"query": ""}
→ 200 {"value":[{"route":"r/projects","name":"Semua","query":null}]}
```

Kontraknya berkata "string", middleware-nya menjamin "null". Validator kini menerima keduanya dan
menyebut alasannya, dan ujinya **lewat HTTP** — sebuah uji yang memanggil `reject()` saja tidak
pernah bisa melihat ini.

### 2. Router menyimpan hash penuh, dan `syncUrl` menulisnya di belakang punggungnya

Ini cacat **yang lebih tua dari paket ini**, ditemukan karena tampilan tersimpan membuatnya bisa
ditemui dalam satu alur biasa.

`router.js` menyimpan hash **penuh** — query dan semuanya — di `current`, dan pendengar
`hashchange`-nya melewatkan hash yang sama dengan `current`. `syncUrl()` selalu menulis URL lewat
`history.replaceState()` langsung, jadi `current` menjadi basi setiap kali saringan berubah:

1. buka `?status=draft` → `current = …?status=draft`
2. ganti saringan ke `approved` → `syncUrl` menulis URL baru, **`current` masih `draft`**
3. ikuti tautan ke `?status=draft` → hash-nya **sama dengan `current`**, router **melewatkannya**

Layarnya tetap menampilkan `approved` sementara bilah alamat berkata `draft`. Nol galat, nol
peringatan. Terukur di harness: setelah menerapkan tampilan "Semua PO" lalu membuka tautan ke
keadaan yang tadi dibuka, URL berbunyi `q=PO&status=approved` sementara layarnya menggambar **nol
chip saringan**.

`router.js` sudah mengekspor obatnya sejak layar bertab: **`replacePath()`** menulis URL *dan*
memberi tahu router bahwa itulah lokasi sekarang. `syncUrl()` dan `applyView()` kini memakainya.

### 3. Baris kedua ber-class sama mencuri selector baris pertama

Baris tampilan memakai class `.chips`, sama dengan baris chip saringan — itu memang bentuk yang
sama, dan prototipe pun menulisnya begitu (`.chips#views` dan `.chips#chips`). Tetapi ia berada
**lebih dulu** di DOM, jadi `document.querySelector('.chips')` berhenti memulangkan baris saringan.

Nol kode aplikasi memanggilnya; yang memanggilnya adalah **S44**, gerbang chip filter UI-4a — dan ia
langsung memerah di enam syarat sekaligus ("q+status memberi 0 chip", "'Hapus semua' tidak
mengosongkan barisnya"). Layarnya sendiri tidak pernah rusak: yang rusak adalah kalimat yang
menanyainya.

Diperbaiki di sumbernya, bukan hanya di ujinya: baris saringan kini `.chips.active-filters`
(nol pemakai sebelumnya, diperiksa lebih dulu di seluruh `public/app` — aturan yang sama dengan
primitif UI-2), dan kedua baris punya nama sendiri. Menambal selector S44 saja akan meninggalkan
perangkap yang sama untuk pemanggil `.chips` berikutnya.

---

## Urutan mengisi filter tidak boleh mengubah keaktifan

`ui.filters[key] = value` menyisipkan kunci **sesuai urutan orangnya mengisi**, jadi `Status →
Lingkup` menghasilkan `status=draft&scope_type=construction` sementara `Lingkup → Status`
menghasilkan `scope_type=construction&status=draft` — untuk saringan yang sama persis. Tanpa
pembakuan, tampilan yang baru saja diterapkan langsung terlihat "tidak aktif".

Perbandingannya karena itu membakukan kedua sisi: `page` dibuang, pasangan diurutkan, lalu
dibandingkan.

**Gerbang pertama untuk ini palsu, dan sabotase yang membuktikannya.** Versi pertamanya membuka dua
URL dengan urutan parameter terbalik — dan `seedFromUrl()` selalu membangun `ui.filters` urut
deklarasi skema, jadi kedua URL menghasilkan string yang **sama persis**. Gerbangnya tetap hijau saat
`.sort()` dibuang. Ia kini menggerakkan **kontrolnya sendiri** di layar dengan dua filter `<select>`
(`crm/quotations`), dan kedua string-nya benar-benar berbeda.

---

## Plafon yang menolak, bukan yang membuang diam-diam

20 tampilan per orang. Saat penuh, penyimpanan **ditolak** dengan kalimat yang menyebut angkanya dan
jalan keluarnya — bukan `.slice(-limit)` seperti `toggleFavorite()`.

Perbedaannya disengaja: membuang bintang tertua diam-diam bisa dimaafkan karena memasangnya lagi
satu klik. Sebuah tampilan bernama membawa saringan yang **disusun** orangnya, dan yang hilang
diam-diam baru ketahuan berminggu-minggu kemudian — saat ia mencarinya.

---

## Kapan tombol "Simpan tampilan ini" ada

Ia hilang dalam dua keadaan, dan keduanya berarti tidak ada yang baru untuk disimpan:

* yang tampil **persis** sebuah tampilan yang sudah tersimpan — tombol yang menyimpan salinan kedua
  dengan nama lain adalah cara paling cepat mengumpulkan tampilan kembar;
* daftarnya polos **dan** belum ada satu pun tampilan tersimpan — penyimpanan pertama yang
  menawarkan "semuanya, apa adanya".

Begitu sebuah tampilan ada, keadaan polos justru layak dinamai: ia jalan kembali dari tampilan
tersaring, dan "Semua PO" nama yang lebih jelas daripada mengingat bahwa Reset melakukannya.

Menghapus tampilan **dikonfirmasi**, walau ia "hanya" sebuah preferensi: tidak ada urungkan, dan
tanda `×` yang sama di baris chip tepat di bawahnya hanya membuang satu saringan — dua akibat yang
sangat berbeda di balik glyph yang sama.

---

## Verifikasi

S46 dijalankan dengan **dua konteks peramban**, dan itu syarat utamanya: konteks kedua tidak pernah
menyimpan apa pun, jadi kalau ia melihat tampilannya, yang menyimpan adalah `core_user_preferences`.

| | hasil |
|---|---|
| Polos, belum ada tampilan | baris **tidak ada di layar** |
| Tersaring | baris muncul, menawarkan `Simpan tampilan ini`, nol chip |
| Setelah disimpan | chip lahir, **aktif** (`aria-pressed=true`), tawaran menyimpan hilang, toast menyebut namanya |
| Baris di server (dibaca lewat API) | `{route: "r/procurement/purchase-orders", name: "Menunggu saya", query: "q=PO&status=approved"}` |
| Keadaan lain | chip padam, tawaran menyimpan kembali, latar chip **berbeda** dari chip aktif |
| Terapkan | URL kembali persis `q=PO&status=approved` |
| Urutan mengisi terbalik | `status=draft&scope_type=…` vs `scope_type=…&status=draft` → chip **tetap aktif** |
| Tampilan "semua" | URL kosong, **nol** chip saringan |
| Tautan ke keadaan sebelumnya | digambar lagi — 2 chip saringan, bukan layar basi |
| **Peramban kedua** | melihat kedua tampilan yang sama |
| Hapus | dikonfirmasi, chip hilang, baris server menyusut, **saringan yang tampil tidak berubah** |

### Ukuran

| | 1440 px | 390 px (sentuh) |
|---|---|---|
| gulir samping halaman / `.main` | **nol** | **nol** |
| tinggi baris tampilan | 38 px | **80 px** (membungkus dua baris) |
| chip aktif | tepi `#1a56db`, latar `#e8effc` | sama |
| chip biasa | tepi `#e2e6eb`, latar `#fafbfc` | sama |
| sasaran tombol hapus | 18 × 18 px | **30 × 28 px** (`@media (pointer: coarse)` yang sudah ada) |

Barisnya membungkus dua baris di ponsel dengan tiga tampilan tersimpan, dan itu dibiarkan: ia hanya
ada saat orangnya memang punya tampilan, dan saat itu ia jawaban atas "saya sedang melihat yang
mana".

### Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.419 / 11.621**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S8 S14 S18 S21 S21m S43 S44 S45 S46` | **hijau, keluar 0** — `results-ui-4c.json` |
| `SHELL_VERSION` | 23 → **24** |

**S46 baru** dibuktikan **merah dua kali**:

```
# sabotase 1 — history.replaceState() langsung, bukan replacePath()
GAGAL: tautan ke keadaan yang tadi dibuka tidak menggambarnya lagi — URL
       'q=PO&status=approved' tetapi 0 chip saringan, layar masih menampilkan
       tampilan 'Semua PO' padahal URL-nya tersaring
STATUS KELUAR: 1

# sabotase 2 — pembakuan urutan dibuang
GAGAL: saringan yang SAMA, diisi dengan urutan berbeda, dibaca sebagai tampilan lain:
       'status=draft&scope_type=construction' vs 'scope_type=construction&status=draft'
STATUS KELUAR: 1
```

Keduanya dipulihkan byte-per-byte (md5 sama). Empat uji server baru di
`UserPreferencesTest` memaku rute, nama, bentuk query, dan jalur HTTP untuk tampilan "semua".

---

## Yang sengaja tidak dikerjakan

* **Tampilan bersama satu tim.** Bukan ditunda karena besar, melainkan karena ia **bukan
  preferensi**: yang dibagikan butuh tabel sungguhan, pemilik, dan gerbang izin — dan preferensi
  boleh dihapus tanpa jejak. Alasan yang sama membuat opt-in WhatsApp berkolom sendiri (§15).
* **Ganti nama dan urutkan ulang tampilan.** Hari ini menyimpan dengan nama yang sama menimpanya,
  dan itu menutup kebutuhan terbesarnya dengan nol kontrol tambahan.
* **Tampilan bawaan per peran** ("PO menunggu persetujuan saya" yang sudah ada sejak hari pertama).
  Ia menarik, tetapi ia daftar yang harus dirawat per resource per peran — dan tidak satu pun
  pemilik yang memintanya.
* **Formulir halaman penuh** — keputusan pemilik #2, paketnya sendiri.
