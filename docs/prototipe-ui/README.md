# Prototipe UI — kampanye modernisasi front-end

`nusantara-erp-2026.html` adalah **mockup statis** dari tampilan yang diusulkan.
Buka berkasnya langsung di peramban; ia tidak butuh server, tidak memanggil API,
dan tidak memuat satu pun berkas dari luar.

## Cara membacanya

Bilah atas memegang tiga sakelar, dan ketiganya sungguhan:

| Sakelar | Untuk apa |
|---|---|
| **Tema** Terang · Gelap · Sistem | token warna ditukar di `<html data-theme>`, sama seperti aplikasi |
| **Kepadatan** Padat · Normal · Lega | `<html data-density>` → `--row-h` dan padding sel |
| **Lebar** 1440 · 1024 · 768 · 390 | halaman digambar di dalam `<iframe>`, jadi `@media` mengukur lebar bingkai — bukan lebar jendela Anda |

Kolom kiri memilih salah satu dari **dua puluh layar**:

* **Orientasi** — beranda, dasbor, beranda modul
* **Sumber daya generik** — daftar (chip filter, seleksi, tampilan tersimpan),
  dokumen (dengan `detail.groups`), formulir halaman penuh
* **Alur kerja** — alur persetujuan, papan kanban (dengan jalan keyboard)
* **Domain** — workspace proyek, lapangan (ponsel), jadwal/gantt,
  umur piutang, laba rugi
* **Pengaturan & laporan** — matriks persetujuan, Laporan Bebas, lembar cetak
* **Masih terbuka** — navigasi & pencarian, keadaan sistem
* **Sistem desain** — panel & dialog, spesimen komponen

Sesudah ini tidak ada pola BERSAMA yang belum digambar. Yang tersisa adalah
pekerjaan menerapkan sistem ini pada layar yang sudah ada — terutama enam
berkas yang memegang utang gaya sebaris terbesar (`evm`, `custom`, `tender`,
`project`, `periods`, `bankrecon`) — bukan merancang sesuatu yang baru.

## Yang diusulkan, dan yang tidak

**Diusulkan** — skala jarak (`--space-1..7`), skala tipografi (`--fs-*`),
`--radius-lg`, satu `--focus-ring`, peran permukaan (`--surface-hover`,
`--surface-selected`), primitif tata letak (`.stack .row .cluster .grid-auto`)
dan nada angka (`.tone-pos` / `.tone-neg`) — pengganti 643 objek `style` inline
di lapisan view. Ditambah tiga mekanik baru pada daftar: **chip filter aktif**,
**bilah seleksi + aksi massal**, dan angka rata kanan yang selalu tabular.

**Tidak diubah** — palet, delapan slot aksen (ΔE tervalidasi), token grafik, dan
**metrik baris tabel**: Padat 32 px · Normal 38,5 px · Lega 48 px, kaki tabel
Normal 41 px. Diukur di peramban pada prototipe ini dan cocok persis; itulah
kesepakatan yang dijaga harness S21.

## Angka di dalamnya diperiksa

Angka pada layar proyek dan kedua laporan **saling menutup**, bukan diisi asal:
bobot WBS berjumlah 100%, bobot x progres menghasilkan 78,0% terhadap rencana
81,0% (SPI 0,96), biaya terserap 5,13 M terhadap nilai perolehan 5,33 M
menghasilkan CPI 1,04, dan sisa per paket menjumlah 1,71 M. Di laba rugi setiap
subtotal, marjin, dan kolom selisih dihitung ulang dari barisnya; umur piutang
menjumlah 2,70 M ke empat ember dan cocok dengan akun kontrol 1-1210.

Ini disengaja. Aplikasinya menolak menggambar `Rp 0` untuk angka yang gagal
dimuat; prototipe yang angkanya tidak menutup akan merusak kebiasaan itu sejak
hari pertama.

## Status

Ini paket **UI-0**. Tidak satu baris pun di `public/app/` disentuh. Rencana
lengkapnya, beserta batasan yang dipaku uji, ada di pesan rencana kampanye ini.
