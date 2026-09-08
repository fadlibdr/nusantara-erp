# Laporan Paket F-4 (ROADMAP-HASHMICRO Fase 2) — Absensi masuk/pulang GPS + selfie

Branch: `feat/phase2-f4` (dari main `58426c3`) · 8 September 2026

> Status jujur: dibangun dan diverifikasi sendiri di peramban sungguhan. Empat cacat yang lolos
> dari suite PHP hijau ditemukan dengan memuat layarnya di Chromium — semuanya dicatat di bawah
> beserta gejalanya. Dua migrasi baru (kolom absen maju-saja + tabel jejak koreksi). Verifikasi
> adversarial dua lensa dan gerbang rilis dua driver **belum** dijalankan pada saat laporan ini
> ditulis; angkanya diisi di § Gerbang rilis.

## Yang ditutup (ROADMAP-HASHMICRO Fase 2 / F-4 → status)

| Tugas | Status | Bukti (commit / angka terukur) |
|---|---|---|
| Kolom `check_in_*`/`check_out_*` maju-saja (waktu server + waktu perangkat, lat/lng, akurasi, jarak, ambang, proyek acuan, selfie) | ✅ | `b825267`; migrasi HrPayroll `001091`, 18 kolom nullable tanpa backfill |
| `clock-in`/`clock-out` memakai `Geotag::distanceMetres` ke koordinat proyek; di luar geofence DICATAT & DITANDAI, tidak pernah ditolak | ✅ | `b825267`; `AttendanceClockTest::test_a_punch_far_outside_the_geofence_is_recorded_and_flagged_never_refused` |
| Proyek tanpa koordinat / perangkat tanpa posisi → jarak **null**, bukan 0 | ✅ | `b825267`; dua uji terpisah (sisi proyek dan sisi ponsel) + `verdict: unknown` + `distance_text: "—"` |
| Ambang geofence dari Pengaturan (bawaan 500 m), distempel per baris | ✅ | `b825267`; `hr.attendance.geofence_metres`, mutasi M7 (angka dipatri) merah |
| Layar ponsel "Absensi Saya" dengan antrean luring | ✅ | `524586d`; `public/app/js/views/absensisaya.js`, rute `#/absensi-saya` di grup Ringkasan tanpa izin |
| Antrean unggah diekstrak ke `js/uploadqueue.js`, dipakai KEDUA layar | ✅ | `524586d`; `lapangan.js` 766 → 459 baris; S15 dijalankan ulang, hasil identik kecuali derau waktu |
| Koreksi pengawas append-only beralasan | ✅ | `b825267`, `524586d`; migrasi `001092`, `AttendanceCorrectionTest` 11 uji |
| Selfie lewat mesin lampiran yang sudah ada | ✅ | `b825267`; `AttachableDocuments` + slug `hr/attendances`, kartu Lampiran di panel Rincian |
| Usulan rekap — HR yang menerapkan | ✅ | `d7d6318`, `4f410a7`; `GET hr/attendance-recaps/proposal` (tanpa POST pasangan) + layar `#/usulan-rekap` yang mengisi formulir rekap lewat `openForm(prefill)` |
| Harness S31 (+ sisi pengawas) | ✅ | `524586d`; `S31_absensi_gps` 19 syarat, `S31_absensi_gps_supervisor` 6 syarat, hasil di `results-phase-2.json`, 5 PNG |
| Dokumentasi | ✅ | CONVENTIONS §27–§30 |

## Tiga aturan yang membentuk paket ini

1. **Posisi MENCATAT, tidak pernah MENOLAK.** Absensi yang ditolak karena GPS berarti orang yang
   tetap bekerja hari itu tidak punya catatan sama sekali — dan yang paling sering kena bukan orang
   yang berbohong, melainkan gudang berdinding beton, basement, dan ponsel murah.
2. **Tidak tahu BUKAN nol.** Nol berarti "berdiri tepat di titik proyek". Kolom `outside_geofence`
   sengaja tidak ada; ia dihitung dari (jarak, ambang) dan menjawab `null` untuk keadaan ketiga.
3. **Jam ponsel DICATAT di samping jam server, tidak pernah menggantikannya.** Satu pengecualian
   yang dinyatakan: TANGGAL barisnya, dan hanya dalam ±48 jam dari jam server.

## Empat cacat yang hanya terlihat di peramban

Suite PHP hijau sepanjang keempatnya. Ini kelanjutan langsung dari pelajaran P1-I ("suite hijau
bukan bukti fiturnya jalan"), dan alasan langkah "muat layarnya di Chromium" sekarang ada di setiap
paket.

| # | Gejala di layar | Sebab | Perbaikan |
|---|---|---|---|
| 1 | "Akun ini belum ditautkan ke data karyawan" untuk teknisi yang jelas tertaut | `api.get()` SUDAH membuka amplop `{data}`; `.data` sekali lagi mengambil larik barisnya dan `linked` menjadi `undefined` | `payload = await api.get(...)` |
| 2 | Toast berbunyi "Absensi terkirim." untuk absen 8 km di luar lokasi | `api.upload()` juga membuka amplopnya, jadi `message` server tidak pernah sampai | `api.upload(..., { raw: true })` |
| 3 | "Tercatat server 17:41 · ditekan di ponsel 10:41" untuk satu tombol yang ditekan sekali | `Carbon::parse()` atas ISO-8601 ber-Z menghasilkan objek ber-zona UTC. Konsekuensi yang lebih berat: absen 06.30 WIB = 23.30 UTC **kemarin**, jadi setiap absen pagi sebelum pukul tujuh diarsipkan ke tanggal kemarin | `->setTimezone(config('app.timezone'))`; mutasi M17 merah |
| 4 | Delapan tombol untuk empat | Dua `load()` beruntun keduanya menempel | penanda muatan (`loadToken`), idiom `absensi.js` |

Ditambah dua kalimat yang berbohong, keduanya diperbaiki sesudah dibaca:

- **Luring, layarnya hanya berisi panel galat** sementara pita luring menyuruh menekan "Kirim
  ulang" pada baris yang tidak ada di layar. Antrean dan tombol absen kini digambar DI LUAR kotak
  yang dilukis ulang `load()`.
- **"0 hari · semua di dalam radius"** pada baris kerani murni — pujian tentang orang yang tidak
  pernah menekan tombolnya. Kini "tidak ada absen dari ponsel bulan ini".

## Keputusan keamanan: `GET hr/attendances` kini menuntut `hr.view`

Komentar di rutenya dulu berbunyi "siapa yang di lokasi tidak membawa sebab maupun diagnosis". F-4
mencabut alasan itu: barisnya kini membawa koordinat, akurasi fix, jam datang dan selfie — riwayat
posisi seseorang hari demi hari, setara dengan register sertifikat dan pengajuan cuti yang sudah
dijaga `hr.view`. Menu SDM & Payroll sudah dijaga `hr.view` di `schema.js`, jadi **tidak ada layar
yang kehilangan pintunya**; yang ditutup adalah token yang memanggil API langsung.

"Absensi Saya" karena itu punya pintunya sendiri, `GET hr/attendances/me` — kueri yang secara
struktur tidak bisa mengembalikan baris orang lain, bukan penyaring di atas daftar yang sama.

## Uji

- baru: `AttendanceClockTest` (21 uji / 84 asersi), `AttendanceCorrectionTest` (11 / 40),
  `AttendanceIsNotPayrollInputTest` (4 / 45), `AttendanceRecapProposalTest` (6 / 35).
- dipindah: `LapanganUploadQueueTest` → `UploadQueueTest` (5 / 22) — pakunya menempel pada
  antreannya, bukan pada satu layar yang kebetulan dulu memilikinya.
- **17 mutasi dipaku merah.** M1 jarak null→0 · M2 stempel ambang selalu ditulis · M3 tanggal selalu
  jam server · M4 jam server := jam ponsel · M5 `outsideGeofence` null→false · M6 absen masuk kedua
  menimpa · M7 ambang dipatri 500 · M8 setengah koordinat diterima · M9 kirim ulang dianggap
  peristiwa baru · M10 acuan proyek tidak disimpan · M11 `unmeasured_days` tanpa syarat absen ·
  M12 `outside_days` dengan angka dipatri · M13 alasan koreksi opsional · M14 jejak PUT tidak
  ditulis · M15 lembar kerani menimpa kolom jam · M16 jejak lembar kerani tidak ditulis ·
  M17 zona waktu perangkat diabaikan.
- per-direktori di HEAD: `tests/Feature/HrPayroll` 184 uji / 717 asersi hijau;
  `tests/Feature/Core` 951 uji / 8.787 asersi hijau (11 dilewati).
- harness: `S31_absensi_gps` 19 syarat hijau di 390×844 (di lokasi 0 m; di luar 8,0 km ditandai
  bukan ditolak; izin lokasi ditolak → "Lokasi tidak terukur" dan jarak bergaris; luring → baris
  bertahan melewati muat ulang lalu terkirim; akun tanpa kartu karyawan mendapat kalimatnya;
  0 galat konsol), `S31_absensi_gps_supervisor` 6 syarat hijau, `S15_lapangan_upload` dijalankan
  ulang dan **identik** kecuali derau waktu. Lima PNG `s31-*.png`.
- **suite penuh di commit rilis**: SQLite — (diisi); MySQL — (diisi).

## Skema yang berubah — dan apakah aman di MySQL dengan data lama

- `2026_09_08_001091_add_clock_columns_to_hr_attendances_table` — 18 kolom **nullable**, tanpa
  backfill dan tanpa default. Baris absensi lama (lembar kerani) tetap sah; kosong berarti "tidak
  dicatat", bukan 0. `down()` simetris.
- `2026_09_08_001092_create_hr_attendance_corrections_table` — tabel baru, FK ke `hr_attendances`
  cascade. Tidak menyentuh data lama.

Keduanya aman di kedua driver: tidak ada `->change()`, tidak ada indeks unik baru, tidak ada
`Schema::drop()` di dalam uji (aturan `FixtureSchema::withMissingTable()` tidak terpanggil karena
paket ini tidak menjatuhkan tabel).

## Keputusan pemilik

1. **Radius bawaan 500 m** (ledger #14). Diubah di Pengaturan › SDM. Nilai yang berlaku saat menekan
   tombol ikut tersimpan di barisnya, jadi mengubahnya tidak menghapus tanda pada hari yang lewat.
2. **Selfie hanya bisa dibuka pemegang `hr.view`.** Karyawan melihat fotonya sendiri saat mengambil
   (pratinjau di ponselnya), tetapi tidak bisa membukanya lagi sesudah halaman ditutup. Menambah
   rute unduh ber-cakupan-sendiri adalah permukaan izin baru; belum dikerjakan.
3. **Formulir rekap mengisi Sakit/Cuti/Lembur dengan 0** — perilaku formulir yang SUDAH ADA, bukan
   dari usulan F-4 (usulan sengaja tidak mengisinya). Kalau 0 di kotak itu dianggap menyesatkan,
   perubahannya menyentuh formulir rekap yang dipakai juga tanpa usulan.
4. **Absen masuk kedua tidak menimpa yang pertama; absen pulang kedua menimpa.** Asimetri ini
   disengaja (jam datang yang bergeser maju adalah jam datang yang salah; orang benar-benar bisa
   pulang belakangan). Kalau lapangan menginginkan keduanya "yang pertama menang", itu satu baris.
5. **Toleransi jam ponsel ±48 jam.** Di luar itu tanggal server yang dipakai. Angka ini belum
   pernah diuji dengan ponsel lapangan sungguhan.

## Deviasi baru yang ditemukan

- **`api.get()`/`api.upload()` membuka amplop `{data}`, `api.list()`/`api.postRaw()` tidak.** Empat
  fungsi dengan dua perilaku berbeda dan tanpa penanda di nama; cacat #1 dan #2 keduanya lahir dari
  situ. Belum diseragamkan — mengubahnya menyentuh setiap pemanggil di SPA.
- **`Carbon::parse()` atas ISO-8601 ber-offset mempertahankan zona sumbernya.** Setiap tempat lain
  yang menerima waktu dari klien layak diperiksa dengan lensa yang sama; F-4 hanya memperbaiki
  miliknya sendiri.
- **`AttachmentController::reachable()` menolak lampiran yang kelasnya tidak ada di
  `AttachableDocuments`.** Menyimpan lampiran lewat `AttachmentService` tanpa mendaftarkan slugnya
  menghasilkan berkas yang tersimpan tetapi tidak bisa dibuka siapa pun — termasuk oleh orang yang
  menjadi alasan berkas itu diminta.
