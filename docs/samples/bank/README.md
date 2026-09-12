# Berkas ekspor bank NYATA — daftar belanja pemilik (P-3c)

> **Folder ini KOSONG pada 12 September 2026, dan itu disengaja.** Ia diisi oleh
> pemilik dengan berkas ekspor rekening koran yang **benar-benar diunduh dari kanal
> bank** — bukan oleh agen, bukan dari ingatan, bukan dari contoh internet. Selama
> folder ini kosong, registri `Modules\Finance\Support\BankPresets` menyatakan keempat
> bank **"BELUM ADA BERKAS EKSPOR NYATA"**, tidak satu pun preset bawaan bisa dipilih di
> layar Impor, dan job folder terpantau tidak memakai preset bawaan mana pun.

Prinsipnya sama dengan `docs/samples/pajak/` (P-3b): **tata letak berkas bank tidak
dikarang.** Tidak ada bank Indonesia yang menerbitkan spesifikasi kolom ekspornya, dan
bank yang sama mengubahnya antar kanal dan antar tahun. Preset yang ditulis dari ingatan
menghasilkan rekening koran yang **terimpor rapi dan salah** — kolom keluar terbaca
masuk, tie-out tetap nol karena saldo awal/akhir diketik operator. Karena itu README ini
**sengaja tidak menyebut satu nama kolom pun**; yang menyebutnya adalah berkas asli yang
Anda letakkan di sini, dan uji `BankPresetsTest` memaku bahwa README ini tetap begitu.

## 1. Yang bisa dipakai HARI INI tanpa menunggu siapa pun: preset per rekening

Layar **Keuangan › Rekonsiliasi Bank › Impor** menyimpan **preset per rekening bank**
(`Simpan sebagai preset rekening ini`) dari pemetaan kolom yang **baru saja berhasil
dipratinjau** atas berkas bank Anda sendiri. Preset itu mengingat pemisah, kolom, format
tanggal/angka, dan **sel baris judul pada kolom yang dipetakan** — bulan berikutnya
berkas yang judul kolomnya bergeser ditolak 422 dengan kalimat yang menyebut kolomnya,
bukan diimpor keliru-tetapi-seimbang. Preset per rekening itulah "berkas ekspor nyata"
yang tidak perlu menunggu folder ini; folder ini untuk preset **bawaan** yang dikirim di
dalam aplikasi, dan itu hanya boleh lahir dari berkas di bawah.

## 2. Daftar belanja — satu berkas per bank per kanal

| Kunci registri | Bank | Kanal ekspor yang ditunggu (unduh dari kanal ini) | Nama berkas yang diharapkan |
|---|---|---|---|
| `bca` | Bank Central Asia | KlikBCA Bisnis › mutasi rekening › ekspor; atau myBCA Bisnis | `bca-<kanal>-<YYYY-MM-DD>.<ekstensi asli>` |
| `mandiri` | Bank Mandiri | Kopra by Mandiri › mutasi rekening › ekspor (CSV) atau unduhan MT940; atau Mandiri Cash Management | `mandiri-<kanal>-<YYYY-MM-DD>.<ekstensi asli>` |
| `bni` | Bank Negara Indonesia | BNIDirect › mutasi rekening › ekspor | `bni-<kanal>-<YYYY-MM-DD>.<ekstensi asli>` |
| `bri` | Bank Rakyat Indonesia | Qlola by BRI atau BRImo Bisnis › mutasi rekening › ekspor | `bri-<kanal>-<YYYY-MM-DD>.<ekstensi asli>` |

`<kanal>` = nama kanalnya dalam huruf kecil tanpa spasi (mis. `klikbca`, `kopra`,
`bnidirect`, `qlola`); `<YYYY-MM-DD>` = tanggal berkas itu diunduh; ekstensi = apa
adanya dari bank (`.csv`, `.txt`, `.xls` — bila `.xls`/`.xlsx`, simpan juga versi CSV
yang diekspor kanalnya sendiri; aplikasi hanya membaca CSV dan MT940, dan tidak ada
dependensi pembaca spreadsheet).

Satu bank boleh punya **lebih dari satu berkas** (satu per kanal). Berkas yang dipakai
memverifikasi preset bawaan harus berkas **satu bulan penuh** dari rekening perusahaan
yang sesungguhnya — bukan potongan, bukan berkas yang disunting tangan. Boleh
menyamarkan nama lawan transaksi bila perlu, **jangan** mengubah kolom, urutan baris,
angka, atau baris judul.

## 3. Siapa memverifikasi, dan apa yang dilakukannya

1. **Pemilik** mengunduh berkas dari kanal bank dan meletakkannya di folder ini dengan
   nama sesuai §2, lalu mencatat baris di register §4.
2. **Pengembang** menulis pemetaan kolom entri itu di `BankPresets::entries()`
   (`mapping`, bentuk yang sama dengan pemetaan layar Impor tanpa periode/saldo) dan
   mengisi `verified_against` = `['path' => 'docs/samples/bank/<nama berkas>', 'date' =>
   '<YYYY-MM-DD>', 'by' => '<nama>']`. Pemetaan **wajib memetakan kolom saldo** bila
   berkasnya punya kolom saldo berjalan: itulah satu-satunya pemeriksaan yang tidak
   bergantung pada angka yang diketik orang.
3. **Uji `BankPresetsTest::test_today_no_bank_has_a_real_export_file_so_none_is_selectable`
   dirancang MERAH pada hari itu** — memperbaruinya adalah bagian pekerjaan yang sama,
   bersama uji baru yang mem-pratinjau berkas ini dengan pemetaan itu dan menuntut
   tie-out nol.
4. Barulah kalimat "BELUM ADA BERKAS EKSPOR NYATA" hilang untuk bank itu dari API, kartu
   registri di layar Impor, dan README ini — dengan sendirinya, karena ketiganya membaca
   `BankPresets::describe()`. Klaim tanpa berkas di pohon diturunkan otomatis.

## 4. Register verifikasi

| Tanggal | Bank / kanal | Berkas | Diverifikasi oleh | Catatan |
|---|---|---|---|---|
| — | — | (belum ada) | — | Folder kosong pada 12 Sep 2026 |

## 5. Dua berkas di `docs/samples/` adalah CONTOH DEMO — bukan berkas bank

`docs/samples/rekening-koran-bca-2026-04.csv` dan
`docs/samples/rekening-koran-mandiri-2026-02.sta` dibuat untuk **mencoba layar Impor
dengan data demo** (README di folder itu berkata sendiri: "tanpa berkas bank sungguhan").
Keduanya cocok dengan data demo, bukan dengan ekspor bank mana pun, dan **tidak
dinaikkan** menjadi preset `bca` / `mandiri`. Registri menyebutnya apa adanya sebagai
`demo_note` — dan harness bukti (S39) memakainya untuk membuktikan jalur folder
terpantau, bukan untuk membuktikan tata letak bank.

## 6. Yang tidak ada di sini, dengan sengaja

- Tidak ada berkas contoh yang "dibuat mirip" ekspor bank.
- Tidak ada tabel pemetaan kolom per bank di README ini — pemetaan hidup di kode,
  di sebelah `verified_against` yang menunjuk berkas aslinya.
- Tidak ada klien SFTP, tidak ada kredensial bank, tidak ada koneksi host-to-host:
  berkas sampai ke sini (dan ke folder terpantau produksi) dari tangan pemilik atau
  alat milik pemilik di luar aplikasi — `docs/KEPUTUSAN-INTEGRASI.md` §10.
