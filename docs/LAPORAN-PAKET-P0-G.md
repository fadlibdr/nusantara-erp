# Laporan Paket P0-G — Cacat runtime T1–T4

Branch: main (langsung; disiplin feat/<paket> mulai P1) · Commit: c55f566 (+ a6c6e7e
untuk susulan T4) · 27 Agustus 2026

> Laporan ini disusun-ulang 28 Agustus dari pesan commit, pohon kode, dan keluaran
> verifikasi adversarial paketnya — laporan §6 tidak sempat ditulis pada sesinya.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| Bagian 10 T1 — laporan harian tanggal duplikat → HTTP 500 `UNIQUE constraint failed` | 🟡 cacat | ✅ 422 Bahasa Indonesia | `Modules/Projects/Rules/UniqueDailyReportDate.php:53` (whereDate); `tests/Feature/Projects/DailyReportDateUniquenessTest.php` |
| Bagian 10 T2 — README contoh `GET /api/projects/projects` → 404 | 🟡 dokumentasi | ✅ | `README.md:114` (`curl -s http://localhost:8000/api/projects`) |
| Bagian 10 T3 — katalog `GET api/core/print/forms` 33 baris; 7 formulir rumah proyek absen | 🟡 konsistensi | ✅ 40 baris, izin per formulir | `Modules/Core/Services/FormPrintService.php:83` (`catalogueRows()`); `tests/Feature/Core/PrintCatalogueBespokeTest.php` |
| Bagian 10 T4 — angka uji README 2.305 vs aktual | ℹ️ | ✅ | `README.md:10–11` (161 migrasi; 2.995 uji / 13.610 asersi) |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

Tidak ada asumsi Bagian 2 yang terpakai. Satu keputusan teknis T1 tertulis di doblok
`UniqueDailyReportDate`: alternatif ganti-cast `date:Y-m-d` DITOLAK, karena baris baru
`2026-03-25` yang berdampingan dengan baris lama `2026-03-25 00:00:00` akan membuat
indeks (yang membandingkan string) MENERIMA duplikat dari setiap tanggal lama.
`whereDate` dipilih karena tidak mengubah satu pun data yang sudah tersimpan.
Tidak ada yang menunggu konfirmasi pemilik.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

Satu migrasi, tanpa penulisan data:

- `2026_08_28_000721_scope_daily_report_unique_index_to_live_rows.php`
  (Modules/Projects) — indeks unik `(project_id, report_date)` pada
  `prj_daily_reports` dibangun ulang
  sebagai indeks PARSIAL (`WHERE deleted_at IS NULL`), sehingga validasi dan indeks
  menanyakan pertanyaan yang sama dan hari yang laporannya dihapus bisa dicatat ulang.

Aman untuk data lama: tidak ada baris yang ditulis ulang. Catatan MySQL (tertulis di
doblok migrasinya): deployment ini memakai SQLite di produksi maupun uji, dan SQLite
mendukung indeks parsial; MySQL tidak — port kelak harus meniru lewat kolom generated
atau menerima indeks penuh sebagai jaring terakhir di sana.

## Uji

- baru: 9 — `DailyReportDateUniquenessTest` (6: duplikat 422 dengan pesan persis, tanggal
  sama proyek lain 201, update simpan-ulang tanggal sendiri vs mencuri tanggal laporan
  lain, project_id pengecoh pada update, hari terhapus dicatat ulang, report_date integer
  ditolak) dan `PrintCatalogueBespokeTest` (3: katalog 40, param baris bespoke, pemanggil
  tanpa `prj.view` tidak menerima tujuh formulir rumah). Tiga uji keunikan terakhir
  adalah pin tambalan temuan verifikasi, ditambahkan sesudah run verifikasi.
- lama yang diubah: tidak ada. `PrintRegistryTest`, `PrintFormReachabilityTest`, dan
  `DocumentFormatValidationTest` diverifikasi tak tersentuh dan tetap hijau.
- suite penuh: OK (2.995 uji, 13.610 asersi; waktu terekam run verifikasi 5 m 23 dtk
  pada 2.992 uji, sebelum tiga uji tambalan).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Tidak ada endpoint baru; dua endpoint lama berubah jawaban. POST
`api/projects/daily-reports` dengan tanggal yang sudah punya laporan pada proyek yang
sama → 422:

> "Sudah ada laporan harian untuk proyek ini pada tanggal tersebut."

Probe verifikasi mengirim tanggal duplikat yang sama dalam tujuh bentuk string
(`2026-03-25T00:00:00`, `2026-03-25 08:30:00`, `25-03-2026`, `03/25/2026`,
`...000000Z`, `...+07:00`, `20260325`) — semuanya 422, tidak satu pun 500, tidak ada
baris duplikat tertulis. `GET api/core/print/forms` sebagai admin → tepat 40 baris
tanpa slug ganda; pemanggil tanpa `prj.view` tidak menerima tujuh formulir rumah.

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

README.md: contoh curl (T2), angka migrasi dan suite (T4; a6c6e7e menyusulkan angka
final 2.995 / 13.610 setelah tiga uji tambalan verifikasi ditambahkan).
PANDUAN, CONVENTIONS, ARCHITECTURE: tidak ada.

## Yang sengaja tidak dikerjakan, dan mengapa

- Ganti-cast `date:Y-m-d` pada model: ditolak tertulis (lihat Asumsi) — merusak makna
  indeks atas data lama.
- Normalisasi baris lama `... 00:00:00` di basis data: tidak disentuh — forward-only,
  `whereDate` membuat bentuk simpanan lama tidak lagi penting.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

Empat temuan verifikasi adversarial; semuanya ditutup sebelum commit:

1. `project_id` pada PUT dipercaya aturan tanpa divalidasi — pengecoh membuat aturan
   memeriksa proyek yang salah lalu indeks menjawab 500. Ditutup: proyek pada update
   selalu milik route model; uji `test_a_decoy_project_id_on_update_cannot_fool_the_rule`.
2. Kembar terhapus-lunak tetap menduduki slot indeks → 500 selamanya (paritas dengan
   aturan lama, tetapi klaim doblok berlebihan). Ditutup: indeks parsial (migrasi
   000721); uji `test_a_deleted_days_report_can_be_reentered`.
3. `report_date` integer JSON (`20260325`) lolos semua jaring dan tersimpan sebagai
   tanggal 1970. Ditutup: `'string'` sebelum `'date'` di kedua request; uji
   `test_an_integer_report_date_is_refused_not_stored_as_1970`.
4. Empat baris katalog bespoke ber-`resource` kunci hantu `projects/projects` yang tidak
   ada di RESOURCES SPA. Ditutup: memakai kunci nyata `projects`; tanpa tombol ganda,
   dibuktikan layar per layar (dedup `printButtonsFor` per slug).
