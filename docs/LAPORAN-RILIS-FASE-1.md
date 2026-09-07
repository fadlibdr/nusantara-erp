# Catatan Rilis Fase 1 (P1-A … P1-G) — 5–7 September 2026

Main: `f51f18b` (P1-B) → **`0937dec`** (P1-C…P1-G) → `8bcd0ae` (perbaikan padam) → `89ba8c9`
(perbaikan isolasi uji MySQL). Ter-deploy ke erp1.pi2.co.id.

## Yang dikapalkan

| Paket | Isi | Laporan |
|---|---|---|
| P1-A | Fondasi vendor (SortableJS + sprite Lucide, aturan tanpa-CDN jadi uji) + `charts.js` lima jenis grafik | `LAPORAN-PAKET-HM-P1-A.md` |
| P1-B | Aksen modul ΔE tervalidasi, kepadatan per pengguna, satu pembuat remah roti, keadaan kosong berilustrasi | `…-P1-B.md` |
| P1-C | Preferensi pengguna di server, registri `ModuleCounts`, app launcher `#/home`, beranda modul | `…-P1-C.md` |
| P1-D | Dasbor yang bisa diatur, 19 widget, set bawaan per peran, laci susunan | `…-P1-D.md` |
| P1-E | Migrasi tiga grafik tangan ke `charts.js` | `…-P1-E.md` |
| P1-F | Report builder "Laporan Bebas" v1 | `…-P1-F.md` |
| P1-G | Papan kanban PR & NCR (0 endpoint baru) | `…-P1-G.md` |

**Belum:** P1-H (gantt) setengah jadi di `feat/phase1-h`; P1-I (PWA) belum mulai.

## Gerbang rilis

- SQLite di `4a73e31`: **3.991 uji / 20.656 asersi hijau** (11 dilewati).
- MySQL 8.0.46 di `89ba8c9`: **3.991 uji / 20.669 asersi hijau** (6 dilewati, 23 mnt 16 dtk).
  Leg ini semula tidak bisa dijalankan — kredensial Fase 0 hilang bersama scratchpad `/tmp`;
  pemilik memberi kata sandi baru 7 Sep.

## Padam 7 Sep 2026 ±00:00–00:30 UTC — sebab, akibat, pelajaran

**Gejala:** `https://erp1.pi2.co.id` "terus memuat". Server sehat sepanjang waktu: `/up` menjawab
200 dalam 0,19 dtk, nginx/php-fpm/mysql aktif, tidak ada penyimpangan izin.

**Sebab:** saat merge `0937dec` saya SENGAJA mengeluarkan `public/app/js/views/jadwal.js` dengan
alasan "yatim, belum terpasang rute" — pemeriksaan saya hanya menyapu `app.js` dan `schema.js`.
`views/project.js:17` sudah mengimpornya. Satu modul yang 404 menjatuhkan SELURUH graf modul ES:
SPA memuat cangkangnya lalu berhenti.

**Perbaikan:** berkas dikembalikan (`8bcd0ae`) dan di-deploy; peramban sungguhan memuat aplikasi,
formulir masuk tergambar, 0 galat konsol, 0 permintaan gagal.

**Pelajaran, ditulis supaya tidak terulang:**

1. Sebelum menyatakan sebuah rilis SPA terverifikasi, **muat aplikasinya di peramban sungguhan**.
   Memeriksa berkas satu per satu mengembalikan 200 dan justru TIDAK BISA melihat graf impor yang
   putus.
2. Menilai sebuah berkas "yatim" berarti menyapu **seluruh** pohon (`grep -rn` di `public/app/js`),
   bukan dua berkas yang paling mungkin.
3. Agen perbaikan menulis commit ke cabang yang sedang di-checkout: ketika sesi lain memindahkan
   HEAD, commit-nya mendarat di cabang baru dan `git add -A` menyapu berkas sesi itu. Beri agen
   nama cabang yang eksplisit dan suruh ia menyetel jalur bernama saja.

## Keputusan yang menunggu pemilik

1. Cut-over MySQL (runbook `DEPLOYMENT.md` §10.9) — kredensial siap; sandi `erp` saat ini pendek
   dan sebaiknya diganti sebelum akun itu memegang data produksi.
2. Langkah 3–5 pemasangan unit systemd antrean/penjadwal (`deploy/systemd/README.md`).
3. Lima keputusan rancangan Fase 1 (dasbor 11→12 permintaan; angka KPI tidak bisa diklik; abu-abu
   cetak PO vs GRN; penanda "di luar sumbu" hanya terlihat saat hover; ekspor tidak membedakan
   "tidak ada baris" dari "ada baris, nilainya kosong"); dan **teknisi melihat 0 sumber Laporan
   Bebas, gudang 1**, padahal keduanya melihat menunya.
