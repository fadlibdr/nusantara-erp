# Laporan Paket UI-6c — Pengadaan + Persediaan: yang ditemukan audit domain ini ada di lapisan generik

> Domain ketiga dari empat (keputusan pemilik). Layar: PR · RFQ · PO · evaluasi vendor · saldo stok ·
> usulan pesan ulang · aturan reorder · pindai barcode.

---

## Audit: domain ini TIDAK punya cacat yang dimiliki dua domain sebelumnya

Sebelum menyentuh apa pun, sebelas layar diukur. Yang dicari adalah tiga bentuk yang UI-6a dan UI-6b
temukan; tidak satu pun ada di sini:

| yang dicari | keadaan di domain ini |
|---|---|
| grafik lebih keras dari angkanya | **tidak berlaku** — nol grafik di kesebelas layar |
| ubin berempat anak (regresi subgrid UI-5) | **tidak ada** — semua ubin dua atau tiga anak |
| laporan tanpa kepala | **tidak berlaku** — tidak ada laporan keuangan di sini |

`#/pindai` diperiksa terpisah karena ia satu-satunya layar domain ini yang dipakai di tangan, di
gudang: pada 390 px dengan `has_touch`, medan kodenya **sudah** mendapat fokus otomatis, **nol**
sasaran sentuh di bawah 40 px, dan nol gulir menyamping. Tidak ada yang dikerjakan di situ, dan itu
hasil pengukuran — bukan kelalaian.

Satu yang ADA, dan bentuknya sudah dikenal dari UI-6b: `#/stock` menggambar **"Nilai persediaan"**
— subjek layarnya — pada **16 px** di sebelah **"Baris saldo"** pada **22 px**. Pembelahnya sama
seperti di workspace proyek: panjang string, bukan kepentingan. Obatnya juga sama, dan sekarang
sudah jadi idiom: bentuk ringkas pada ukuran yang sama, angka penuh turun ke baris konteks
("Rp 332,51 jt" dengan "Rp 332.510.000" di bawahnya).

## Temuan sebenarnya: lapisan generik membayar per BARIS

Menghitung gaya sebaris yang benar-benar **tergambar** (bukan yang tertulis di berkas) di lima layar
daftar memberi pola yang tidak terlihat dari sisi berkas:

| pola | jumlah tergambar | sumbernya |
|---|---|---|
| `display: flex; gap: 4px; justify-content: flex-end` | **16** | `views/list.js` `rowActions()` |
| `color: var(--success); font-weight: 700` | **14** | `js/cells.js` sel `bool` |
| `width: 1%` | 7 | `schema.js` — **deklarasi kolom, bukan gaya**; benar apa adanya |
| `width: auto; height: 28px; font-size: 12.5px` | 3 | `views/list.js` pemilih baris-per-halaman |

Dua yang pertama hidup di lapisan **generik**, jadi biayanya tumbuh bersama jumlah **baris**, bukan
jumlah layar — dan salahnya muncul di ke-97 sumber daya sekaligus. `cells.js` khususnya adalah
penggambar nilai yang dipakai SETIAP tabel dan SETIAP panel detail; sebuah penimpaan token di sana
adalah tempat terburuk untuk menaruhnya.

Keduanya kini kelas: **`.cell-actions`** dan **`.cell-yes`**. Dan pemilih baris-per-halaman memakai
`.fs-secondary` — 12,5 px adalah salah satu nilai di luar skala yang audit UI-1 daftarkan, dan ia
ada satu di tiap layar daftar.

## Nyaris terkirim: memakai ulang kelas yang "bentuknya sama"

Mencabut gaya sebaris dari `rowActions()` terlihat seperti pekerjaan memakai-ulang-kelas yang rapi.
`.row-actions` sudah ada di `app.css`, dipakai di enam tempat, dan bentuknya "sama": sebuah bilah
tombol mendatar. Saya memakainya, dengan satu pengubah untuk perataannya.

Ia **tidak** sama. `.row-actions` membawa `gap: 6px` dan `flex-wrap: wrap`; gaya sebaris yang
digantikannya membawa `gap: 4px` dan tidak membungkus. A/B menunjukkan akibatnya sebelum apa pun
dikirim:

```
#/r/inventory/items       beda=176   TR: [1144, 56] -> [1144, 81]   (9 baris)
#/r/procurement/vendors   beda=118   TR: [1144, 56] -> [1144, 81]   (7 baris)
#/r/hr/employees          beda=147   TR: [1144, 56] -> [1144, 81]   (8 baris)
```

Tinggi baris tabel naik **56 → 81 px, 45%**, karena tombol aksinya mulai membungkus ke baris kedua
di dalam selnya. **Kepadatan baris adalah keputusan pemilik yang DIBEKUKAN** (Padat 32 · Normal 38,5
· Lega 48 · kaki 41) — dan ia naik tanpa satu pun token kepadatan disentuh.

> **Koreksi (19 Sep 2026, saat tinjauan pra-gabung).** Kalimat aslinya di sini berbunyi "S21 tidak
> akan melihatnya: S21 memaku `--row-h`, bukan tinggi baris yang benar-benar tergambar." Itu
> **salah**, dan salahnya ke arah yang mengecilkan gerbang yang sudah ada. S21 mengasersi geometri
> TERGAMBAR — `near(dens['compact']['all'], 32)` dan `near(..., 48)`, setiap baris satu-garis dalam
> 0,5 px — dan baris yang ia ukur di `#/r/inventory/item-categories` adalah baris ber-TOMBOL, persis
> jenis yang membawa `.cell-actions`. Pada kepadatan rapat, pembungkusan di layar itu akan
> menjatuhkannya. Celah yang sebenarnya lebih sempit: S21 hanya mengunjungi tiga rute daftar, tidak
> satu pun di antaranya ketiga layar tempat pembungkusan itu terukur, dan pada kepadatan NORMAL ia
> membandingkan terhadap baseline alih-alih terhadap 38,5. Itulah yang S52 tutup.

Diganti kelas sendiri, `.cell-actions`, yang menyalin **persis** nilai sebaris yang digantikannya.
Sesudah itu A/B yang sama berbunyi **beda 0 di kelima belas rute**.

Pelajarannya bukan "jangan pakai ulang kelas". Pelajarannya: *bentuk yang sama bukan nilai yang
sama*, dan yang memutuskan adalah A/B, bukan pembacaan CSS. Kalau saya membaca `.row-actions` dan
menyimpulkan "ya, ini dia", tiga layar akan terkirim dengan baris 45% lebih tinggi.

## Uji

| gerbang | hasil |
|---|---|
| `tests/Feature/Core` (SQLite) | **1.420 uji / 11.626 asersi**, 11 dilewati |
| `tests/Unit` | **630 uji / 2.201 asersi** |
| `tests/Feature/Core` (MySQL 8.0.46) | **1.420 uji / 11.633 asersi**, 9 dilewati |
| harness Playwright, 28 skenario gerbang | semua **ok**, keluar 0 — `docs/bukti-uji/results-ui-6c.json` |

**A/B terhadap UI-6b (`da14d35`), 15 rute lintas EMPAT domain** — karena perubahannya di lapisan
generik, A/B satu domain tidak akan membuktikan apa pun:

```
#/r/inventory/items  #/r/procurement/vendors  #/r/procurement/purchase-orders
#/r/procurement/purchase-requisitions  #/r/procurement/rfqs  #/r/inventory/reorder-rules
#/r/procurement/vendor-evaluations  #/d/procurement/purchase-orders/1  #/stock
#/usulan-pesan-ulang  #/r/finance/ar-invoices  #/r/hr/employees  #/tugas
#/d/projects/1  #/dashboard
```

Sesudah `.cell-actions` menggantikan pemakaian-ulang `.row-actions`: **beda 0 di kelima belasnya**.
Lalu pemilih baris-per-halaman dipindah ke skala, dan beda yang muncul **hanya** kontrol itu beserta
kelima `<option>`-nya: `font-size` 12,5 → 12 px, `gap` warisan 6,25 → 6 px, dan lebarnya 120 → 117 px.
Tidak ada yang lain bergerak di layar mana pun.

**S52 dibuktikan MERAH tiga kali** sebelum dipercaya:

| sabotase | yang dikatakan gerbang |
|---|---|
| `.cell-actions` diberi `flex-wrap: wrap` (= yang nyaris terkirim) | `#/r/inventory/items: 9 dari 9 bilah aksi sel MEMBUNGKUS — itu menaikkan tinggi baris tabel…` |
| `.cell-yes` kehilangan `color: var(--success)` | `sel boolean tergambar ['rgb(20, 24, 29)'] bukan --success rgb(23, 113, 74)` |
| `#/stock` kembali campur ukuran | `baris ubin tingkat atas memakai 2 ukuran sekaligus: {'16px': 1, '22px': 1}` |

S52 juga membawa **penjaga anti-gerbang-hampa**: kalau himpunan layarnya tidak memuat satu pun
`.cell-actions` atau `.cell-yes`, ia gagal — karena uji yang tidak menemukan subjeknya bukan uji yang
lulus. (Angka hari ini: 24 bilah aksi, 14 sel boolean.) Penjaga itu ditambahkan sesudah versi
pertama S52 gagal dengan benar tetapi karena alasan yang salah: `#/r/procurement/purchase-orders`
memang **tidak menggambar tombol baris sama sekali**, dan "nol bilah di layar ini" bukan cacat.

Gaya sebaris `views/` **539 → 538** dan `cells.js` 7 → 6; `CEILING` diturunkan; `SHELL_VERSION`
**30 → 31**.

## Yang sengaja tidak dikerjakan

* **`width: '1%'` pada kolom tabel (7 tergambar).** Ia datang dari `schema.js` sebagai **lebar kolom
  yang dideklarasikan**, bukan sebagai gaya — dan `schema.js` memang katalog deklaratif. Mengubahnya
  jadi kelas akan memindahkan data ke stylesheet.
* **`tender.js` (31 gaya sebaris).** Paket Tender ada di **Penjualan** (`PANDUAN-PENGGUNA` §3.5a),
  bukan di domain ini. Ia utang terbesar ketiga di lapisan view dan tetap terbuka.
* **Sapuan margin di layar domain ini.** Sama seperti UI-6b: ia menunggu adopsi `.stack`, yang
  sebuah keputusan JARAK untuk seluruh aplikasi — UI-7.
