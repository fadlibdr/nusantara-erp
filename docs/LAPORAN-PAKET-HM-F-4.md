# Laporan Paket F-4 (ROADMAP-HASHMICRO Fase 2) — Absensi masuk/pulang GPS + selfie

Branch: `feat/phase2-f4` (dari main `58426c3`) · 8 September 2026

> Status jujur: dibangun, diperiksa sendiri di peramban sungguhan (empat cacat), lalu diverifikasi
> adversarial dua lensa — **28 temuan lagi, enam di antaranya bug yang menyentuh orang**. Semuanya
> diperbaiki dan dicatat di bawah beserta gejala yang dibaca pemakainya. Dua migrasi baru (kolom
> absen maju-saja + tabel jejak koreksi). Gerbang rilis dua driver di § Gerbang rilis.

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

## Verifikasi adversarial — 28 temuan

Dua verifier baca-saja berjalan paralel di atas commit `3691211`: satu berlensa kebenaran server
dan kejujuran angka, satu berlensa UX ponsel, antrean luring dan mutu bukti. Keduanya
mengembalikan pohon kerja bersih.

### Enam bug yang menyentuh orang

| # | Gejala yang dibaca pemakainya | Sebab |
|---|---|---|
| 1 | Karyawan yang satu-satunya catatannya jatuh di tanggal TERAKHIR bulan itu lenyap dari usulan rekap, dan layarnya mencetak "register bulan ini kosong" tentang orang yang ada di dalamnya | `whereBetween` atas kolom yang menyimpan tengah malam; **SQLite** membandingkan string, `'2026-06-30 00:00:00' > '2026-06-30'`. MySQL benar — jadi yang salah justru driver produksi hari ini |
| 2 | Jejak koreksi "tambah-saja" bisa DIMUSNAHKAN pemegang `hr.delete` — persis orang yang punya alasan | FK cascade + `hr_attendances` tanpa softDeletes. Uji lama hanya memeriksa tidak adanya RUTE update/delete, jadi tetap hijau |
| 3 | Absen pertama hari itu yang berbalapan menjawab **HTTP 500** dan absennya hilang — pada satu-satunya pintu yang aturannya "tidak pernah menolak" | SELECT lalu INSERT tanpa penjaga tabrakan kunci unik. Lembar kerani punya lubang yang sama |
| 4 | SETIAP foto yang mengantre di ponsel orang saat rilis mendarat lenyap dari layar dan memakan kuota selamanya | butir versi lama tidak punya `kind`; yang tidak masuk `readQueue()` tidak pernah sampai ke `forget()` |
| 5 | Selfie kebesaran (kamera ponsel modern rutin >5 MB) MEMBATALKAN absennya; toastnya hanya bicara soal foto, dan orangnya pulang mengira sudah absen | `return` di klien sebelum `enqueue()` — servernya sendiri sudah benar |
| 6 | Panel koreksi menulis ulang jam absen pada SETIAP simpan: mengubah catatan saja menggeser 11:23:00 → 04:23:00 dan menulis dua baris jejak palsu | `toDateTimeInput()` merender dengan zona PERAMBAN, server mem-parse dalam `app.timezone`. Indonesia punya tiga zona |

### Satu paku yang bocor

`AttendanceIsNotPayrollInputTest` mencari `hr_attendances`, `Models\Attendance;`, `Attendance::` —
dan tidak satu pun melihat `$employee->attendances()`. Verifier memotong gaji pokok dari register
GPS dan **kedua lensa tetap hijau**: lensa sumber tidak mengenali relasi `hasMany`, lensa perilaku
luput karena 30 baris fixture-nya ber-`check_in_at` NULL. Sembilan jarum sekarang, dan mutasi yang
sama (M20) merah.

### Sisanya (diperbaiki, satu baris masing-masing)

`device_at` bersampah dulu 422 dan menghilangkan absennya · `Carbon::parse('0000-00-00')` tidak
melempar dan MySQL ketat menolak menulis tahun nol · kartu karyawan yang diarsipkan disuruh
"menautkan akun yang sudah tertaut" · proyek yang diarsipkan lolos validasi lalu diam-diam
menghasilkan "jarak tidak terukur" bagi orang yang berdiri di titiknya · "Lokasi tidak terukur"
tepat di atas "8,2 km" · indeks yang dijanjikan komentar migrasi tidak pernah dibuat · radius
geofence duduk di grup "BPJS & Lembur" yang berbunyi "berlaku pada perhitungan payroll berikutnya"
· lembar cetak menggaris di atas jam yang sudah tercatat · panggilan geolokasi yang tidak dijawab
menggantung selamanya (penghitung `timeout` baru jalan sesudah izin) · kunjungan PERTAMA yang
luring hanya berisi panel galat sementara pita luring menyuruh menekan tombol yang tidak ada ·
kartu "belum terkirim" menyebut absensi sebagai "foto yang akan tampil di dokumennya" · `.txt`
tersimpan sebagai "Selfie pulang" · tombol layar ponsel 34 px, bukan 46 · kolom "Absen ponsel"
menghitung jam server sehingga jam yang diketik pengawas dilaporkan sebagai absen ponsel · selfie
pada absen kedua dibuang diam-diam · docblock menyebut uji yang tidak ada.

### Tiga celah bukti

S31 tidak idempoten — jalan kedua kali merah karena absen masuk sudah tercatat, perilaku yang benar
tetapi bukti yang hanya bisa direproduksi di atas basis data perawan. S31/S31s tidak pernah
mengirim satu selfie pun, jadi jalur `AttachableDocuments`/`AttachmentService` — butir keenam
spesifikasi paket — tidak tersentuh, sementara syarat "ada kartu Lampiran" hijau di atas basis data
tanpa lampiran karena judul kartunya selalu ada. Dan syarat tombol hanya mengukur LEBAR, dimensi
yang CSS-nya sudah benar; ia lolos pada tombol setinggi 1 px. Ketiganya ditutup: S31 membersihkan
baris hari ini lebih dulu (dijalankan dua kali berturut-turut, hijau), benar-benar mengirim satu
selfie dan menghitungnya di basis data, dan mengukur tinggi tombol.

## Uji

- baru: `AttendanceClockTest` (28 uji / 120 asersi), `AttendanceCorrectionTest` (15 / 54),
  `AttendanceIsNotPayrollInputTest` (4 / 87), `AttendanceRecapProposalTest` (9 / 46).
- dipindah: `LapanganUploadQueueTest` → `UploadQueueTest` (9 / 30) — pakunya menempel pada
  antreannya, bukan pada satu layar yang kebetulan dulu memilikinya.
- **20 mutasi dipaku merah.** M1 jarak null→0 · M2 stempel ambang selalu ditulis · M3 tanggal selalu
  jam server · M4 jam server := jam ponsel · M5 `outsideGeofence` null→false · M6 absen masuk kedua
  menimpa · M7 ambang dipatri 500 · M8 setengah koordinat diterima · M9 kirim ulang dianggap
  peristiwa baru · M10 acuan proyek tidak disimpan · M11 `unmeasured_days` tanpa syarat absen ·
  M12 `outside_days` dengan angka dipatri · M13 alasan koreksi opsional · M14 jejak PUT tidak
  ditulis · M15 lembar kerani menimpa kolom jam · M16 jejak lembar kerani tidak ditulis ·
  M17 zona waktu perangkat diabaikan · M18 `whereBetween` kembali (tanggal terakhir bulan) ·
  M19b penjaga balapan kunci unik dicabut · M20 gaji pokok dipotong dari register GPS lewat
  `$employee->attendances()` — mutasi yang dipakai verifier, dan yang dulu lolos hijau.
- per-direktori di HEAD: `tests/Feature/HrPayroll` 196 uji / 819 asersi hijau;
  `tests/Feature/Core` 951 uji / 8.790 asersi hijau (11 dilewati) sebelum putaran perbaikan.
- harness: `S31_absensi_gps` **22 syarat** hijau di 390×844 (di lokasi 0 m; di luar 8,0 km ditandai
  bukan ditolak; izin lokasi ditolak → "Lokasi tidak terukur" dan jarak bergaris; luring → baris
  bertahan melewati muat ulang lalu terkirim; akun tanpa kartu karyawan mendapat kalimatnya;
  0 galat konsol; selfie benar-benar dikirim dan terhitung di basis data; tombol 328×46 px),
  `S31_absensi_gps_supervisor` 6 syarat hijau dengan lampiran yang benar-benar ada,
  `S15_lapangan_upload` dijalankan ulang dan **identik** kecuali derau waktu. S31 dijalankan DUA
  KALI berturut-turut pada server yang sama: hijau keduanya. Lima PNG `s31-*.png`.
- **suite penuh di commit rilis `0636ad5`**, dijalankan dari `git worktree` sendiri dengan
  `cp -a vendor` (bukan symlink): **SQLite 4.364 uji / 24.196 asersi hijau** (11 dilewati,
  12 mnt 58 dtk) dan **MySQL 8.0.46 4.364 uji / 24.209 asersi hijau** (6 dilewati, 35 mnt 49 dtk).

## Gerbang rilis

| Leg | Commit | Uji | Asersi | Dilewati | Waktu |
|---|---|---|---|---|---|
| SQLite | `0636ad5` | 4.364 | 24.196 | 11 | 12:58 |
| MySQL 8.0.46 | `0636ad5` | 4.364 | 24.209 | 6 | 35:49 |

Selisih asersi (13) dan jumlah yang dilewati (11 vs 6) sama persis dengan gerbang-gerbang
sebelumnya: `SqlitePragmaTest` dilewati di MySQL, `MysqlModeTest` dilewati di SQLite, dan lima
uji yang memaku perilaku khusus driver menambah asersinya di sisi MySQL.

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

## Catatan tentang putaran verifikasi ini

Kedua verifier berjalan **paralel di atas satu pohon kerja**, dan keduanya melakukan mutasi
sementara. Satu di antaranya mengamati `AttendanceResource` berubah dari `'—'` menjadi `'0 m'` lalu
kembali dalam 47 detik — itu mutasi milik verifier yang lain, bukan bug. Lensa UX juga melaporkan
satu pembacaan `check_out_geofence_m: 5000` yang tidak bisa diulang; jendelanya bertepatan dengan
mutasi `config/erp.php` milik lensa server. **Verifier paralel di satu pohon mencemari pengukuran
satu sama lain** — putaran berikutnya sebaiknya memberi tiap verifier `git worktree` sendiri, atau
menjalankan keduanya berurutan.

## Deviasi baru yang ditemukan

- **`api.get()`/`api.upload()` membuka amplop `{data}`, `api.list()`/`api.postRaw()` tidak.** Empat
  fungsi dengan dua perilaku berbeda dan tanpa penanda di nama; cacat #1 dan #2 keduanya lahir dari
  situ. Belum diseragamkan — mengubahnya menyentuh setiap pemanggil di SPA.
- **`Carbon::parse()` atas ISO-8601 ber-offset mempertahankan zona sumbernya.** Setiap tempat lain
  yang menerima waktu dari klien layak diperiksa dengan lensa yang sama; F-4 hanya memperbaiki
  miliknya sendiri.
- **Kunci unik `(employee_id, date)` tidak menjaga apa pun di SQLite bila dua penulis mengeja
  harinya berbeda.** `'2026-09-08'` dan `'2026-09-08 00:00:00'` adalah dua nilai berbeda di sana
  (di MySQL kolomnya DATE dan keduanya sama). Seluruh kode aplikasi menulis lewat cast `date`
  sehingga selalu sepakat, tetapi impor mentah atau perintah artisan yang menulis ejaan pendek akan
  menghasilkan dua baris untuk satu hari tanpa satu pun galat. Idiom `whereDate()` yang sudah
  dipakai di modul ini lahir dari akar yang sama.
- **`AttachmentController::reachable()` menolak lampiran yang kelasnya tidak ada di
  `AttachableDocuments`.** Menyimpan lampiran lewat `AttachmentService` tanpa mendaftarkan slugnya
  menghasilkan berkas yang tersimpan tetapi tidak bisa dibuka siapa pun — termasuk oleh orang yang
  menjadi alasan berkas itu diminta.
