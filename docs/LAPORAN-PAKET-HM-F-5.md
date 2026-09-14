# Laporan Paket HM F-5 — Timesheet & lembur dari absensi (kebijakan yang disebut pemilik, dan uang yang bergerak satu arah)

**Cabang:** `feat/phase2-f5` (dari `main` 51fb7f1) · **Tanggal:** 14 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 2 / F-5 (3 hari-orang), tugas T5.1–T5.7 —
**paket terakhir Fase 2**
**Status:** selesai di cabang — **belum di-merge, belum di-deploy** (keduanya langkah pemilik;
deploy dilarang untuk agen di alur kerja ini).

---

## 0. Satu kalimat

Paket ini mengubah sebuah **pengakuan** menjadi **perbaikan**: `PayrollService` sudah menuliskan
sendiri sejak P0 bahwa 1,5x rata atas total bulanan **membayar kurang** dan bahwa yang dibutuhkannya
adalah rincian harian, dan rincian itu persis yang F-4 tulis ke `check_in_at`/`check_out_at`. Dua
keputusan membentuk sisanya: **tidak ada tabel turunan** (yang dibekukan adalah UANG, pada slipnya,
§2), dan **rincian harian hanya dipakai ketika totalnya sama persis dengan rekap yang dibayar**
(§3.1) — karena membelah 10 jam ILB menurut bentuk 7 jam absensi adalah mengarang hari lembur.

Yang membuat paket ini berbeda dari paket fitur biasa: **produksi memegang NOL baris
`hr_attendances` pada hari ini**. Setiap layar dan setiap angka paket ini akan pertama kali dilihat
orang **dalam keadaan kosong**, jadi keadaan kosong bukan kasus tepi — ia keadaan pertama, dan
dibedakan dengan tegas dari nol yang terukur (§4).

Bukti peramban menemukan satu cacat yang **tidak dilihat satu pun uji PHP**: ubin "Hari terukur" di
layar ponsel berbunyi **"0 · setiap hari bercap jam lengkap"** pada bulan yang tidak punya satu pun
catatan — pujian tentang orang yang tidak pernah menekan tombolnya (§7.1).

Tidak ada sentuhan pada `bootstrap/*`, `routes/*` akar, `composer.json`, atau `DatabaseSeeder`
(`git diff --stat main..HEAD -- bootstrap/ routes/ database/seeders/DatabaseSeeder.php composer.json`
= **0 baris**). Tidak ada dependensi baru.

---

## 1. Tugas → status → bukti

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T5.1 | Kebijakan pemilik sebagai SETELAN (`hr.timesheet.*`, 9 kunci), tipe registri baru `time`, batas Kepmenaker ditandai bukan dipotong, tarif hari libur dikatakan tidak dibangun | ✅ | `a268bd8` — `TimesheetPolicySettingsTest` **7 uji / 44 asersi**; 4 mutasi merah (§5) |
| T5.2 | `TimesheetService` + `TimesheetDayState`: menit kerja/terlambat/lembur, **empat keadaan**, pembulatan SEKALI dengan tepi-tepinya dipaku | ✅ | `a268bd8` — `TimesheetDerivationTest` **26 metode / 33 kasus / 71 asersi**; 7 mutasi merah (§5) |
| T5.3 | Migrasi **001094** (`overtime_basis` + `overtime_rate_detail`) + 1,5x/2x per hari di `PayrollService` | ✅ | `ca5b945` — `PayrollOvertimeDailySplitTest` **14 / 33**; 7 mutasi merah, satu hanya merah sebagai mutasi **gabungan** (§5) |
| T5.4 | Layar `#/timesheet` + `#/timesheet-saya` dengan pembanding ILB, keadaan kosong, ekspor CSV; SHELL_VERSION 14 → 15 | ✅ | `103f481` — `TimesheetSpaWiringTest` **10 / 50**; 4 mutasi merah (§5) |
| T5.5 | `overtime_hours` keluar dari `not_proposed` dengan syarat, kalimat lama diganti kalimat yang benar sekarang | ✅ | `103f481` — `AttendanceRecapOvertimeProposalTest` **9 / 27**; 4 mutasi merah (§5) |
| T5.6 | Tiga pintu izin, **404 yang sama** untuk milik orang lain dan id yang tidak ada; perubahan setelan masuk audit | ✅ | `103f481` — `TimesheetApiTest` **12 / 41** + audit di `TimesheetPolicySettingsTest`; 4 mutasi merah (§5) |
| T5.7 | CONVENTIONS §43, PANDUAN-PENGGUNA §21, PANDUAN-ADMINISTRATOR §14, ROADMAP (penundaan dicabut), harness S42/S42m | ✅ | commit dokumen ini; **29 syarat** desktop + **12 syarat** ponsel, `console_errors: []` keduanya; kunci `results-phase-2.json` 29 → **31** |
| 8 | Gerbang per-direktori + pint | ✅ | §8 |
| 9 | Laporan ini | ✅ | berkas ini |

**Uji baru paket ini: 6 berkas, 78 metode (85 kasus dengan data provider).** Berkas lama yang
ikut berubah: tidak satu pun asersinya digeser — 9 berkas payroll yang sudah ada
(`PayrollOvertimeTest`, `PayrollRunCalculationTest`, `PayrollBpjsTest`, `PayrollThrTest`,
`PayrollDecemberTrueUpTest`, `PayrollPostingTest`, `Pph21RecapTest`, `AttendanceRecapProposalTest`)
tetap hijau apa adanya.

> **Dikoreksi pada putaran verifikasi (§10).** Kalimat di atas semula ikut menyebut
> `AttendanceIsNotPayrollInputTest` sebagai bukti bahwa tidak ada yang berubah. Itu bukan bukti: uji
> itu hijau karena jaring jarumnya tidak menjangkau `TimesheetService`, dan uji perilakunya memakai
> rekap berlembur 0 jam sehingga `overtimeComputation()` pulang lebih awal. Paket ini MEMANG
> menyeberangi pemisahan yang dijaganya — absensi kini menggeser TARIF lembur, tidak pernah jumlah
> jamnya — dan berkas itu dibangun ulang untuk memaku batas barunya. Lihat §10 dan CONVENTIONS §29.

---

## 2. KEPUTUSAN BENTUK PENYIMPANAN RINCIAN HARIAN — tidak ada tabel turunan

Perintah paket ini menyerahkan bentuk penyimpanannya ("tabel baru migrasi 001094, atau kolom") dan
menuntut alasannya ditulis. Ini alasannya.

### 2.1 Yang dipilih

**Rincian harian tidak disimpan di mana pun.** Ia dihitung ulang dari
`hr_attendances.check_in_at`/`check_out_at` setiap kali ditanya
(`TimesheetService::day()` / `measuredOvertimeShape()`). Migrasi **001094** menambahkan **dua kolom
pada `hr_payslips`**, bukan sebuah tabel harian:

- `overtime_basis` (string 30, nullable) — `rincian_harian` · `rata_jam_pertama` · `tanpa_lembur`
- `overtime_rate_detail` (json, nullable) — tarif dan pembagi **yang berlaku saat slip dihitung**,
  jam pada tiap tarif, hari-hari lemburnya, dan sebab dalam satu kalimat bila jalur lama terpakai.

### 2.2 Kenapa bukan tabel turunan

1. **Cap jam BOLEH BERUBAH, dan memang dirancang begitu.** F-4 membangun pintu koreksi pengawas
   justru untuk "lupa absen pulang". Sebuah tabel turunan mulai berbohong pada koreksi pertama, dan
   harus dibatalkan-kan di setiap pintu yang menyentuh absensi. Migrasi 001091 sudah menolak
   menyimpan `outside_geofence` dengan kalimat yang sama persis: *"kolom turunan yang disimpan
   adalah kolom yang suatu hari melenceng dari sumbernya"*.
2. **Aturannya SETELAN.** Baris yang dihitung di bawah pembulatan 15 menit lalu ditinggalkan di
   tabel akan tetap terbaca sebagai hasil aturan hari ini ketika aturannya sudah 30 — tanpa apa pun
   yang menyebutkan perbedaan itu. Dihitung di tempat, layar selalu menampilkan kebijakan yang
   berlaku, dan ia **mencetak kebijakan itu di sebelah angkanya** supaya bisa diperiksa.
3. **Yang benar-benar harus beku adalah UANG**, dan slip sudah tempat payroll membekukan segalanya:
   `project_id` ("assignments change, and a payslip that re-allocated itself afterwards would make
   the same approved run post to different projects on different days"), `has_tax_id`, `ter_rate`.
   Satu kolom + satu json di sana menjawab "dengan dasar apa slip ini dibayar" **selamanya**, tanpa
   satu pun tabel yang harus dijaga tetap sinkron.

### 2.3 Harganya, dikatakan apa adanya

Layar timesheet menghitung ulang satu bulan setiap kali dibuka: satu kueri `hr_attendances` per
periode ditambah satu kueri ILB, lalu aritmetika per hari di PHP. Pada 8 pegawai × 30 hari itu
tidak terasa; pada ratusan pegawai ia akan terasa, dan jawabannya saat itu adalah **cache**, bukan
tabel turunan — sebuah cache yang basi hanya lambat, sebuah tabel turunan yang basi membayar orang
dengan angka yang salah.

Harga kedua: **rekap bulanan tetap satu-satunya tempat payroll membaca TOTAL jam.** Itu memang yang
diminta ("rekap bulanan yang ada TETAP jadi tempat payroll membaca totalnya"), dan akibatnya ada di
§3.1.

---

## 3. APA YANG BERUBAH PADA UANG — dan apa yang sengaja tidak

### 3.1 Yang berubah: tarifnya, tidak pernah jumlahnya

Sebelum paket ini, seluruh jam lembur sebulan dibayar **1,5x upah sejam**:

```php
$overtimePay = round($overtimeHours * (($basic + $allowancesTotal) / $divisor) * 1.5, 2);
```

Sesudahnya, **ketika rincian harian ada dan totalnya sama persis dengan rekap**, jam PERTAMA setiap
hari lembur dibayar 1,5x dan sisanya 2x. Terukur pada upah sebulan 11.000.000 dan pembagi 173
(upah sejam 63.583,815028901736):

| Bentuk 6 jam lembur | Jalur lama | Jalur baru | Selisih |
|---|---|---|---|
| 3 hari × 2 jam | 572.254,34 | **667.630,06** | +95.375,72 |
| 1 hari × 6 jam | 572.254,34 | **731.213,87** | +158.959,53 |

Arah kesalahan jalur lama **satu arah**: ia membayar kurang. Perbaikan yang membayar sama atau
kurang bukan perbaikan, dan itu dipaku sendiri
(`test_the_daily_split_pays_more_than_the_flat_rate_it_replaces`).

**TOTAL JAMNYA TIDAK BERUBAH SAMA SEKALI.** Ia tetap `hr_attendance_recaps.overtime_hours`, yaitu
tetap dari ILB. Rincian harian menentukan TARIF, dan tidak pernah jumlahnya.

**Syarat kesamaan total, dan kenapa ia keras.** Bila rekap membayar 10 jam sementara absensi
mengukur 7, bentuk harian yang diketahui **bukan** bentuk dari jam yang dibayar. Tiga jalan keluar
dipertimbangkan dan dua ditolak:

- *menskala bentuk 7 jam menjadi 10 jam secara proporsional* — aritmetika di atas hipotesis;
  ia mencetak hari lembur yang tidak ada catatannya;
- *membayar 7 jam menurut bentuknya dan 3 jam sisanya rata* — memotong bentuk pada batas yang
  dipilih urutan tanggal, dan diam-diam memperlakukan jam ILB sebagai kelas kedua;
- **yang dipilih:** seluruh periode itu dibayar dengan **jalur lama**, dan slipnya menyebutkan
  **kedua angka** di `reason`. Konservatif, bisa diperiksa, dan tidak mengarang apa pun.

Akibat praktis yang harus disebut: **ketika ILB dipakai, jalur baru tidak menyala**, karena
`OvertimeRecapService` menulis total ILB ke rekap dan angka itu jarang sama persis dengan turunan
absensi. Jalur baru menyala pada alur yang justru dipakai pemasangan ini hari ini: HR membuka
Usulan Rekap, menerapkan jam lembur turunan, menyimpan rekap — dan total rekap **memang** sama
dengan turunannya. Memperluas pembelahan ke bentuk harian ILB (yang punya `overtime_date` sendiri)
adalah langkah berikutnya yang **sengaja tidak** dikerjakan paket ini (§10).

### 3.2 Yang sengaja TIDAK berubah

- **Slip yang sudah diposting.** `PayrollService::calculate()` menolak run yang statusnya tidak
  editable (`assertEditable`), jadi periode approved/closed tidak pernah sampai ke perhitungan ini.
  Dipaku oleh `test_a_posted_payroll_run_can_never_be_recalculated_by_this_package`, yang
  **melengkapi absensi bulan itu SESUDAH payroll disetujui** lalu membuktikan `overtime_pay` tidak
  bergerak satu rupiah pun.
- **Periode tanpa rincian harian.** Dibayar 953.757,23 untuk 10 jam — **angka yang sama persis**
  dengan `PayrollOvertimeTest` sejak P0, dan uji baru menyebut angka itu secara literal supaya
  keduanya bisa dibandingkan langsung.
- **Tidak ada backfill.** `overtime_basis` NULL berarti "slip dihitung sebelum 14 Sep 2026" —
  keadaan yang **berbeda** dari `tanpa_lembur`, dan bedanya dijaga uji. Enam belas slip produksi
  tetap kosong. Menebak dasar sebuah slip yang sudah diposting adalah mengarang bukti tentang uang
  yang sudah keluar.
- **ILB sendiri.** Tidak satu baris `prj_overtime_permits` disentuh, dan tidak satu pun endpoint
  Projects berubah. Jam ILB **dibaca** lewat query builder atas nama tabelnya — preseden
  `PayrollService::projectAssignments` — jadi HrPayroll tetap **nol impor** dari Projects.
- **Rekap.** Tidak ada jalur otomatis dari absensi ke `hr_attendance_recaps`. `AttendanceRecap`
  dibaca, tidak pernah ditulis, oleh paket ini.
- **Cuti/izin, tarif hari libur, perhitungan ulang payroll yang sudah diposting** — batas paket,
  tidak disentuh.

---

## 4. KEADAAN KOSONG — keadaan produksi hari ini

`hr_attendances` **0 baris**, `hr_attendance_corrections` **0**, `prj_overtime_permits` **0**,
`prj_overtime_permit_workers` **0**; `hr_attendance_recaps` hanya 8 baris benih bertanggal
26 Juli 2026. F-4 di-deploy 8 September dan **belum ada satu orang pun yang absen lewat layarnya**.

Karena itu keadaan kosong diperlakukan sebagai keadaan PERTAMA, bukan kasus tepi:

| Tempat | Keadaan kosong berbunyi | Yang TIDAK pernah muncul |
|---|---|---|
| `TimesheetService::forPeriod()` | `rows: []` | 8 baris "0 jam" untuk 8 pegawai |
| Layar `#/timesheet` | "Register bulan ini kosong … bukan berarti tidak ada yang masuk kerja" | tabel nol |
| Ubin "Hari terukur" (ponsel) | "belum ada satu hari pun dengan cap jam masuk dan pulang" | "setiap hari bercap jam lengkap" (§7.1) |
| Sel jam pada hari yang tidak terukur | garis `—` dengan `title` yang menyebut sebabnya | `0` |
| Ekspor CSV | sel benar-benar **kosong** | `0` |
| Usulan rekap | `overtime_hours` **tetap ditolak**, dengan kalimat yang benar sekarang | usulan 0 jam |
| Slip dengan rekap 0 jam | `overtime_basis = 'tanpa_lembur'` | kolom kosong |

**Dan bedanya dengan NOL YANG TERUKUR dijaga sama kerasnya.** Hari yang punya dua cap jam dan
bekerja tepat 8 jam mendapat `overtime_minutes = 0` — bukan null — karena harinya **diukur** dan
lemburnya memang tidak ada. Bedanya dipikul oleh **keadaan hari**, bukan oleh angkanya
(`TimesheetDayState`, empat kasus, §5 mutasi M7).

---

## 5. Mutasi — 29 dijalankan, 29 merah (satu hanya sebagai mutasi GABUNGAN)

Setiap pin baru dibuktikan merah dengan merusak kode produksi, menjalankan ujinya, lalu
mengembalikannya dan membuktikannya hijau lagi.

| # | Mutasi | Uji yang memerah |
|---|---|---|
| M1 | `rounding_minutes` bawaan 15 → 20 di `config/erp.php` | `TimesheetPolicySettingsTest` 1 gagal |
| M2 | arm validasi `'time'` dibuang dari `rulesFor()` | 2 gagal (jam mulai + kalimat galat) |
| M3 | kalimat grup `hr` lama ("TIDAK satu pun … menggerakkan payroll") dikembalikan | 1 gagal |
| M4 | kunci `hr.timesheet.overtime_first_hour_pct` dihapus dari registri | 1 gagal |
| M5 | pembulatan `round()` → `floor()` (selalu ke bawah) | 2 dari 8 tepi gagal (+29 menit) |
| M6 | minimum diterapkan SEBELUM pembulatan | 1 tepi gagal (+29 menit) |
| M7 | hari setengah terukur memulangkan `0`, bukan `null` | 2 gagal |
| M8 | hari non-kerja dihitung dengan rumus hari kerja | 1 gagal |
| M9 | batas harian MEMOTONG (`min($overtime, $cap)`) | 1 gagal |
| M10 | terlambat dihitung dari jam mulai, bukan dari batas toleransi | 3 gagal |
| M11 | periode kosong memulangkan setiap karyawan sebagai baris nol | 1 gagal |
| M12 | pembelahan dibatalkan: setiap jam memakai tarif jam pertama | 4 gagal |
| M13 | penjaga kesamaan total dibuang (rincian selalu dipakai) | 2 gagal |
| M15 | `assertEditable()` dibuang dari `calculate()` | 1 gagal (slip terposting berubah) |
| M16 | `tanpa_lembur` dijadikan kolom kosong | 1 gagal |
| M17 | **gabungan**: hari libur dihitung rumus hari kerja **dan** ikut ke bentuk bayar | 1 gagal |
| M18 | tarif dikembalikan menjadi `1.5` / `2.0` di kode | 1 gagal |
| M19 | milik orang lain ditolak **403**, bukan 404 yang sama | 1 gagal |
| M20 | gerbang kepemilikan dibuang seluruhnya dari `show()` | 1 gagal |
| M21 | `timesheet/me` didaftarkan SESUDAH `timesheet/{employee}` | 1 gagal |
| M22 | `permission:hr.view` dicabut dari rute daftar periode | 1 gagal |
| M23 | `session.can('hr.view')` dicabut dari rute layar HR | 1 gagal |
| M24 | keadaan kosong diganti kalimat "Tidak ada lembur: 0 jam pada …" | 1 gagal |
| M25 | `js/views/timesheet.js` dihapus dari SHELL `sw.js` | 2 gagal (termasuk `PwaServiceWorkerTest`) |
| M26 | ekspor CSV menuliskan `'0'` untuk yang tidak diukur | 1 gagal |
| M27 | lembur diusulkan walau tidak ada satu hari pun terukur | 2 gagal |
| M28 | kalimat penolakan lama dikembalikan ke muatan | 2 gagal |
| M29 | baris tanpa hari terukur diberi `0`, bukan `null` | 1 gagal |
| M30 | formulir rekap disodori `overtime_hours ?? 0` | 1 gagal |

**M14 TIDAK ADA, dan itu kelalaian penomoran — bukan mutasi yang disembunyikan.** Judul bagian ini
semula berbunyi "30 dijalankan, 30 merah" sementara tabelnya berisi 29 baris (M1–M13, M15–M30):
nomor M14 terlewat saat tabel disusun, dan tidak ada mutasi yang hilang bersamanya. Dikoreksi pada
putaran verifikasi, bersama satu pengakuan yang lebih penting: **tidak satu pun dari kedua puluh
sembilan mutasi itu menyentuh jalur pembulatan kedua** (`TimesheetService` baris `hours` per hari),
dan §6 mendaftarkan "Pembulatan SEKALI" sebagai aturan yang dipaku delapan tepi — padahal kedelapan
tepi itu semuanya berjalan pada langkah 15 menit, yang desimalnya kebetulan tepat. Cacat A-2/B-1
karena itu tidak bisa merah di gerbang mana pun. Sebuah tabel bukti yang menghitung dirinya salah
adalah tabel yang pembacanya berikutnya tidak bisa percayai; §10 membawa dua puluh lima mutasi
putaran verifikasi, dan yang pertama di antaranya adalah mutasi yang celah ini tinggalkan.

**M17 perlu penjelasan, karena ia satu-satunya yang tidak merah sendirian.** Penjaga hari non-kerja
**berlapis dua**: `day()` memulangkan `overtime_minutes = null` untuk hari itu, DAN
`measuredOvertimeShape()` menyaringnya sekali lagi. Melepas lapisan kedua saja tidak mengubah
satu angka pun (lapisan pertama masih menahannya), jadi uji uangnya tetap hijau. Lapisan kedua
tetap dipertahankan — ini uang, dan sebuah penjaga cadangan yang menahan perubahan berikutnya pada
`day()` lebih berharga daripada kerapian — dan kemerahannya dibuktikan dengan melepas **keduanya**
sekaligus. Itu dicatat di sini alih-alih disembunyikan sebagai "30 dari 30".

---

## 6. Permukaan — setiap aturan baru, satu per satu

| Aturan | Di mana ditegakkan | Yang memakukannya |
|---|---|---|
| Sembilan kunci kebijakan bisa disunting operator | `SettingService::definitions()` grup `hr` | `TimesheetPolicySettingsTest::test_every_policy_value_is_an_editable_setting_and_not_a_constant` |
| Tipe `time` divalidasi `date_format:H:i`, bukan `regex` | `SettingService::rulesFor()` + `UpdateSettingsRequest::messages()` | `…_refuses_anything_that_is_not_a_wall_clock`, `…_with_a_sentence_about_clocks` |
| Perubahan kebijakan masuk audit | `AuditedModels` (sudah ada) + jalur tulis `SettingService::set()` | `…_leaves_an_audit_row` |
| Empat keadaan hari, tidak satu pun runtuh menjadi 0 | `TimesheetService::day()` + `TimesheetDayState` | 8 uji `TimesheetDerivationTest` |
| Pembulatan SEKALI, tepi 7/8/22/29/30 | `TimesheetService::overtimeMinutes()` | data provider 8 tepi |
| Terlambat dari BATAS toleransi | `TimesheetService::lateMinutes()` | 4 uji |
| Batas Kepmenaker ditandai, tidak dipotong | `day()` + `weeksOverCap()` | 2 uji |
| Hari libur tidak diusulkan, tetapi dilaporkan | `day()` + `measuredOvertimeShape()` | 2 uji + 1 uji uang |
| Total jam selalu dari rekap | `PayrollService::overtimeComputation()` | `…_disagrees_with_the_measured_detail_falls_back…` |
| Slip terposting tidak berubah | `assertEditable()` (sudah ada) | `…_can_never_be_recalculated_by_this_package` |
| Tarif dibekukan pada slip | `overtime_rate_detail` | `…_does_not_move_when_the_policy_changes_afterwards` |
| `timesheet/me` tanpa izin, tanpa parameter | rute + `TimesheetController::mine()` | 3 uji |
| 404 yang SAMA untuk milik orang lain dan id tak ada | `TimesheetController::show()` | `…_exactly_the_same_404_as_an_id_that_does_not_exist` (membandingkan kedua badan jawaban) |
| Lembur diusulkan hanya bila ada hari terukur | `AttendanceRecapProposalService::propose()` | 4 uji |
| Kalimat lama tidak kembali ke layar | — | `…_gone_from_every_line_that_can_reach_a_screen` |
| Layar tidak menulis apa pun | — | `…_the_screen_writes_nothing_at_all` (melarang `api.post/put/patch/del`) |

---

## 7. Bukti peramban (pelajaran: rilis SPA belum terverifikasi sampai DIMUAT)

Dijalankan pada Chromium headless terhadap basis data demo yang di-`migrate:fresh --seed`, server
`php artisan serve`. April 2026 dipilih karena benih tidak punya apa-apa di sana — jadi "kosong"
benar-benar kosong dan fixture-nya tidak bercampur.

- **S42** `S42_timesheet_lembur_dari_absensi` — 1440×900, `admin@`: **29 syarat, semuanya hijau**,
  `console_errors: []`, 5 klik, 14,8 s.
- **S42m** `S42_timesheet_saya_ponsel` — 390×844, `teknisi@` (tertaut EMP-0007, **tanpa satu pun
  izin `hr.*`**): **12 syarat, semuanya hijau**, `console_errors: []`, 9,2 s.

Keduanya menjalankan **keadaan kosong lebih dulu, lalu keadaan berisi** pada bulan yang sama, dan
membersihkan fixture-nya di `finally` supaya skenario yang jatuh di tengah tidak meninggalkan April
berisi. Hasilnya digabung **berdasarkan kunci** ke `docs/bukti-uji/results-phase-2.json`: 29 → **31**
kunci, tidak satu kunci lain pun disentuh.

### 7.1 Cacat yang HANYA peramban temukan

**Ubin "Hari terukur" memuji bulan yang kosong.** Ubin itu punya dua kalimat: "N hari hanya satu cap
jam — belum terukur", atau **"setiap hari bercap jam lengkap"**. Pada bulan tanpa satu pun catatan,
`measured_days = 0` dan `half_measured_days = 0`, jadi yang terpilih adalah yang kedua — dan layar
berbunyi **"0 · setiap hari bercap jam lengkap"** tentang orang yang tidak pernah menekan tombolnya
sama sekali.

Tidak satu pun uji PHP bisa melihatnya: muatan JSON-nya **benar** (0 dan 0), kalimatnyalah yang
bohong. Ini bentuk cacat yang sama persis dengan yang ditemukan verifikasi F-4 pada baris kerani
murni ("0 hari · semua di dalam radius").

Diperbaiki menjadi **tiga** kalimat, dan sekarang dijaga dua kali: oleh syarat S42m
`an_empty_month_is_not_congratulated_for_clocking_in_every_day` dan — supaya gerbang PHP ikut
menjaganya — oleh `TimesheetSpaWiringTest::test_an_empty_month_is_not_congratulated_for_clocking_in_every_day`.

### 7.2 Tiga syarat harness yang JATUH lebih dulu, dan ternyata uji yang salah

Putaran pertama S42 gagal pada tiga syarat, dan ketiganya cacat **uji**, bukan cacat layar —
dicatat di sini karena keduanya hanya bisa dibedakan dengan menjalankan peramban:

1. `"2 jam" in cells` — layar mencetak **"2,00 jam"** (dua desimal, karena seperempat jam lembur
   adalah angka yang nyata). Syaratnya diperbaiki, bukan layarnya.
2. sama untuk `"3 jam"` → `"3,00 jam"`.
3. `"Lembur turunan" in head` — judul kolom digambar **huruf besar** oleh CSS, dan `innerText`
   memulangkan apa yang TERGAMBAR. Syaratnya kini membandingkan tanpa memandang besar-kecil huruf.

---

## 8. Gerbang

| Perintah | Hasil |
|---|---|
| `./vendor/bin/phpunit tests/Feature/HrPayroll` | **310 uji / 1.334 asersi** hijau |
| `./vendor/bin/phpunit tests/Feature/Core tests/Unit` | **1.973 uji / 13.565 asersi** hijau (11 dilewati, semuanya bawaan yang sudah ada sebelum paket ini) |
| `./vendor/bin/pint` pada berkas yang disentuh | bersih |
| `php artisan route:list --path=hr/timesheet` | 3 rute, `timesheet/me` **di atas** `timesheet/{employee}` |
| harness `S42 S42m` | 2 skenario `ok`, 41 syarat, 0 galat konsol |

Suite penuh dijalankan sesi utama, sesuai perintah paket ini.

---

## 9. Keputusan pemilik yang TERBUKA

1. **Jam mulai kerja hanya SATU untuk seluruh perusahaan** (`hr.timesheet.day_start`, bawaan
   08:00). Pemilik menyebut toleransi terlambat tetapi tidak menyebut jam mulainya; 08:00 dipilih
   di sini dan dikatakan di layar. Bila lapangan dan kantor berbeda jamnya, ini butuh keputusan —
   dan mengakalinya dengan satu angka akan salah untuk salah satu kelompok setiap hari.
2. **Kalender hari libur nasional tidak ada di sistem ini.** 17 Agustus terbaca sebagai hari kerja
   biasa. Membangunnya adalah paket tersendiri (tabel hari libur + sumbernya + siapa yang
   memeliharanya).
3. **Tarif akhir pekan/hari libur Kepmenaker (2x/3x/4x sejak jam pertama)** tidak dibangun.
   Sampai ia ada, lembur hari libur adalah keputusan manual lewat ILB.
4. **Apakah pembelahan 1,5x/2x diperluas ke bentuk harian ILB.** ILB punya `overtime_date` sendiri,
   jadi bentuk hariannya **ada** dan bisa dibaca — tetapi membacanya berarti payroll mulai
   bergantung pada tabel Projects untuk menentukan uang, dan itu keputusan arsitektur yang tidak
   pantas diambil diam-diam di dalam paket ini.
5. **Istirahat 60 menit** (`hr.timesheet.break_minutes`, ditambahkan pada putaran verifikasi §13).
   Angka **kesebelas**, dan seperti `day_start` ia angka yang pemilik tidak sebut. Ia harus ada:
   tanpanya, rentang masuk→pulang dibaca sebagai jam kerja dan hari kerja 08:00–17:00 menghasilkan
   satu jam lembur setiap hari untuk setiap orang. 60 menit adalah bentuk yang paling umum di
   lapangan dan lantai UU 13/2003 Pasal 79 adalah 30 menit; bila regu tertentu memang bekerja tanpa
   istirahat, angkanya 0 — dan layar mengatakan mana yang sedang berlaku.

---

## 10. Yang TIDAK dikerjakan (per butir)

- **Tidak ada perubahan pada cuti/izin.** `LeaveService` dan `hr_leave_requests` tidak disentuh.
- **Tidak ada tarif hari libur.** Jamnya diukur dan dilaporkan terpisah, tidak pernah diusulkan.
- **Tidak ada perubahan pada ILB.** Dibaca, tidak pernah ditulis; tidak satu berkas Projects diubah.
- **Tidak ada penulisan otomatis ke rekap.** Tidak ada endpoint POST/PUT di paket ini sama sekali.
- **Tidak ada perhitungan ulang payroll yang sudah diposting**, dan tidak ada backfill kolom baru.
- **Tidak ada kalender hari libur**, tidak ada shift, tidak ada jam mulai per proyek/regu.
- **Tidak ada cache** untuk perhitungan timesheet — belum dibutuhkan pada 8 pegawai, dan menambahnya
  sekarang berarti menambah lapisan yang bisa basi tanpa satu pun bukti ia perlu.
- **Tidak ada kolom `overtime_basis` pada `hr_attendance_recaps`.** Dasarnya adalah fakta tentang
  SLIP, bukan tentang rekap; rekap tidak tahu dan tidak perlu tahu.

---

## 11. Commit (urut lama → baru)

| Commit | Isi |
|---|---|
| `a268bd8` | T5.1 + T5.2 — kebijakan pemilik menjadi setelan (9 kunci + tipe `time`), kalimat grup `hr` diperbaiki, `TimesheetService` + `TimesheetDayState` dengan empat keadaan dan pembulatan sekali |
| `ca5b945` | T5.3 — migrasi 001094, 1,5x/2x per hari, syarat kesamaan total, jalur lama yang tetap hidup dan menyebutkan dirinya |
| `103f481` | T5.4 + T5.5 + T5.6 — layar `#/timesheet` + `#/timesheet-saya`, tiga pintu izin dengan 404 yang sama, `overtime_hours` keluar dari `not_proposed` dengan syarat, SHELL_VERSION 15 |
| (commit T5.7) | T5.7 — CONVENTIONS §43, PANDUAN-PENGGUNA §21, PANDUAN-ADMINISTRATOR §14, ROADMAP (penundaan dicabut), harness S42/S42m, laporan ini, dan perbaikan cacat §7.1 |

---

## 12. Penyimpangan konvensi yang disengaja

1. **Tipe registri Pengaturan baru (`time`) ditambahkan ke Core oleh paket HrPayroll.** Alternatifnya
   adalah menyimpan jam sebagai teks bebas (`max:255`), yang menerima "25:61" dan membuat setiap
   penilaian "terlambat" tidak punya acuan. Satu arm `match` di `rulesFor()`, satu `case` di
   `settings.js`, satu kalimat galat — dan tipe itu tersedia untuk setiap setelan jam berikutnya.
2. **`timesheet/{employee}` tidak memakai pengikat model rute.** 404 bawaan pengikat berbentuk lain
   daripada 404 controller, dan dua bentuk penolakan yang berbeda adalah cara membedakan "id ini
   ada tapi bukan milik Anda" dari "id ini tidak ada". Id-nya diambil sebagai string dan
   diselesaikan sendiri.
3. **Sembilan kunci `hr.timesheet.*` ditaruh di grup `hr`, yang kalimat pembukanya harus diubah.**
   Alternatifnya adalah grup ke-N yang baru, yang akan memisahkan kebijakan absensi dari kebijakan
   yang dihitung DARI absensi. Yang dipilih: satu grup, dengan kalimat yang **membedakan** separuh
   yang menggerakkan uang dari separuh yang tidak — karena kalimat menyeluruh mana pun akan bohong
   untuk salah satu separuh.

---

## 13. PUTARAN VERIFIKASI — 14 September 2026

Tiga lensa verifikasi menjalankan paket ini di tiga pohon terpisah dan mengembalikan 22 temuan.
Setiap temuan di bawah berakhir **DIPERBAIKI** (dengan pin yang dibuktikan merah oleh mutasi) atau
**DITOLAK** (dengan bukti). Tidak ada keadaan ketiga.

Enam commit verifikasi: `91b9801` `2f28a20` `e6e3909` `1bb977f` `cbe54e3` `3d2ee68` (+ commit ini).
**Dua puluh lima mutasi baru dijalankan (M31–M55), dua puluh lima merah**, kecuali satu yang dicatat
apa adanya di bawah.

### 13.1 Uang — diperiksa paling dulu dan paling teliti

**A-1 · Istirahat tidak pernah dipotong (TINGGI) — DIPERBAIKI, `91b9801`.**
`TimesheetService` memperlakukan RENTANG masuk→pulang sebagai jam kerja, jadi hari kerja
**08:00–17:00** — bentuk hari kerja yang paling biasa yang ada di Indonesia, karena istirahat satu
jam tidak termasuk jam kerja (UU 13/2003 Ps. 79) — menghasilkan **satu jam lembur setiap hari**.
Gagal diam-diam dalam arti paling murni: batas 3 jam/hari tidak tersentuh, tidak ada bendera, dan
angkanya persis sebesar yang orang percaya masuk akal. 26 hari kerja → 26 jam lembur karangan,
Rp 2.479.768,79 pada upah 11 jt (22,5% upah sebulan). Jalannya menjadi uang pendek: Usulan Rekap
menyodorkannya ke formulir rekap, HR menekan Simpan.
Perbaikan: setelan `hr.timesheet.break_minutes` (bawaan 60), dipotong **bertahap**
`min(istirahat, rentang − 4 jam)`, hari membawa ketiga angkanya, kartu kebijakan mencetak
kalimatnya. **M31–M33 merah.** Fikstur uji yang memakai 08:00–16:00 sebagai "delapan jam"
diperbaiki ke 17:00 — mereka memaku model yang salah; seluruh angka rupiah tetap sama persis.

**A-2 / B-1 · Pembulatan terjadi DUA KALI (TINGGI) — DIPERBAIKI, `2f28a20`.**
`total_hours` dari jumlah menit, tetapi tiap `days[].hours` dari menit hari itu; gerbang kesamaan
membandingkan yang pertama, uang dihitung dari jumlah yang kedua. Pada pembulatan 10 menit slip
membayar Rp 953,76 **lebih** dari jam yang tertulis di kolomnya sendiri; pada 20 menit Rp 953,75
**kurang**. Tidak terlihat pada bawaan 15 menit, dan tidak satu pun dari 29 mutasi asli
menyentuhnya. Perbaikan: menit dibawa sampai tempat uang dihitung, dibagi 60 sekali di akhir;
`minutes_at_first_rate`/`minutes_at_next_rate` ikut dibekukan di slip, dan jam berikutnya menjadi
SISA supaya dua angka yang ditampilkan selalu berjumlah persis kolom `overtime_hours`.
**M34–M35 merah**, dan M34 menyebut kedua rupiahnya.

**C-2 · Penjaga absensi↔payroll tidak bisa melihat jalur baru (TINGGI) — DIPERBAIKI, `e6e3909`.**
Paket ini menyeberangi pemisahan yang `AttendanceIsNotPayrollInputTest` jaga, dan lewat begitu saja
— persis yang pesan galat uji itu larang. Jaringnya tidak menjangkau `TimesheetService`, dan uji
perilakunya memakai rekap 0 jam sehingga separuh yang penting tidak pernah dijalankan. Uji HIJAU
sementara mengoreksi satu cap jam menggeser upah lembur Rp 250.000. Tautannya **tidak dicabut** (ia
benar); yang dicabut kalimat yang menyangkalnya. Batas barunya — *absensi boleh menggeser TARIF,
tidak pernah JUMLAH JAM* — dipaku tiga lapis, CONVENTIONS §29 ditulis ulang, §43 menautnya balik,
dan §1 laporan ini tidak lagi mengutip kehijauan uji itu sebagai bukti. **M36–M37 merah.**

### 13.2 Kalimat yang berpisah dari yang terjadi

**A-3 / B-2 / C-1 · Slip tidak pernah menyebutkan dasarnya (TINGGI) — DIPERBAIKI, `1bb977f`.**
Seluruh pembenaran migrasi 001094 adalah satu kalimat yang diulang di empat tempat dan di
PANDUAN-PENGGUNA §21, dan kalimat itu tidak benar: `grep -rn 'overtime_basis' public/ resources/`
memulangkan NOL baris. Yang membedakan kedua jalur dalam praktik adalah satu hari **lupa absen
pulang**, dan orang yang dirugikan disuruh melihat sebabnya di slip yang tidak memuatnya.
Perbaikan: baris "Dasar: …" pada `payslip.blade.php` (NULL berbunyi berbeda dari "Tanpa lembur"),
dasarnya di tabel slip layar run gaji supaya pemeriksa melihatnya SEBELUM menyetujui, dan
`PayslipSaysItsOvertimeBasisTest` memaku janji terhadap gambar. **M38–M39 merah.**

**A-4 · PANDUAN-PENGGUNA memberi DUA rumus lembur (TINGGI) — DIPERBAIKI, `1bb977f`.**
Bab payroll masih berbunyi "pemisahan tarif 1,5×/2× **tidak diterapkan**", seribu delapan ratus
baris sebelum §21 mengatakan kebalikannya; data yang sama membayar 572.254,34 menurut yang satu dan
667.630,06 menurut yang lain. Bab payroll kini menyebut kedua jalurnya dan menunjuk §21 alih-alih
menyalin rumusnya, dan `OvertimeDocsDoNotContradictEachOtherTest` menjaring kalimat usang.
**M41 merah.**

**A-5 · Tarif 2x dijanjikan tanpa syarat (SEDANG) — DIPERBAIKI, `cbe54e3`.**
Kartu kebijakan mencetaknya sebagai fakta di layar yang dibuka untuk tukang, padahal pada alur ILB
ia tidak pernah menyala (terukur: 190.751,45 dibayar, 222.543,35 dijanjikan). Kalimatnya kini
membawa syaratnya. **M45 merah.**

### 13.3 Angka yang diukur pada hari yang tidak mengukurnya

**A-6 · Batas 14 jam/pekan buta pada pekan lintas bulan (SEDANG) — DIPERBAIKI, `3d2ee68`.**
2026-W27 dengan 17 jam dilaporkan `[]` oleh KEDUA bulan. Jendela kueri dilebarkan ke pekan ISO di
kedua tepi; hari di luar bulan dipakai hanya menjumlahkan pekan, dan pekan terbelah ditandai
`spans_periods` beserta menit yang jatuh di bulan sebelah. Pelebarannya tidak melahirkan baris
hantu. **M49–M50 merah.**

**B-5 · Shift malam dinilai terlambat 13j 50m (SEDANG) — DIPERBAIKI, `3d2ee68`.**
Keterlambatan tidak lagi diukur bila jam masuk jatuh lebih dari setengah hari dari jam mulai —
setengah hari, bukan satu jam, supaya keterlambatan sungguhan tetap terukur. Batas ketiga
ditambahkan ke daftar kejujuran layar dan ke §21. **M48 merah.**

**B-6 · Cap jam tidak diperiksa terhadap tanggal barisnya (SEDANG) — DIPERBAIKI, `3d2ee68`.**
Satu salah ketik bulan = 721 jam lembur dalam satu hari; varian dua hari geser lolos tanpa satu
tanda pun. Dua lapis: `AttendanceUpdateRequest` menuntut `after:check_in_at`, dan `day()` menolak
mengangkat hari menjadi Terukur bila capnya tidak berhubungan dengan tanggalnya — shift malam
22:00→06:00 tetap sah. **M46 merah.**

**B-7 · Cap terbalik tetap melaporkan keterlambatan (SEDANG) — DIPERBAIKI, `3d2ee68`.**
Satu sel yang membantah keterangannya sendiri. Kedua sebab "setengah terukur" kini dipisah: cap
pulang HILANG tetap melaporkan keterlambatannya, cap TERTUKAR tidak. **M47 merah.**

### 13.4 Layar yang menulis, dan yang diam

**B-3 · Ubin "Hari terukur" memuji bulan 1-dari-26 (TINGGI) — DIPERBAIKI, `91b9801`.**
Perbaikan 14 Sep hanya menutup `measured_days === 0` dan meninggalkan lubang yang bentuknya sama
persis. Pujian kini menuntut ketiganya, memakai `unrecorded_days` yang sudah ada di muatan sejak
awal dan tidak pernah dipakai.

**B-4 · Peringatan setengah terukur hilang tepat saat ada angka (TINGGI) — DIPERBAIKI, `cbe54e3`.**
Ia hanya disusun di dalam cabang `overtime_hours === null`. Kini berdiri di bawah nama karyawan,
seperti layar Timesheet. **M43 merah.**

**A-7 · Usulan Rekap tidak memperingatkan payroll terposting (SEDANG) — DIPERBAIKI, `cbe54e3`.**
`propose()` membawa `period.payroll_posted`, dan kalimat spanduknya dipakai ulang dari
`timesheet.js`. **M42 merah.**

**C-5 · Ketiadaan ILB digambar sebagai ketiadaan baris (SEDANG) — DIPERBAIKI, `cbe54e3`.**
Kini "tanpa ILB disetujui" dengan title yang menyebut artinya. Isian awalnya **sengaja tetap
disodorkan**: menahannya akan membuat angka turunan tidak pernah bisa dipakai perusahaan yang tidak
menjalankan ILB, dan keputusannya memang HR. **M44 merah.**

### 13.5 Gerbang, jejak, dan bukti

**C-3 · Properti pengganti gerbang izin tidak dipaku (SEDANG) — DIPERBAIKI, commit ini.**
Tiga tempat menyatakan "tidak ada satu parameter pun di pintu itu yang menyebut orang lain", dan
tidak ada satu asersi pun untuknya. **M51** — mutasi satu baris yang verifier laporkan sebagai tak
terlihat — kini merah.

**C-4 · Kalender lembur harian orang lain keluar lewat pintu slip (SEDANG) — DIPERBAIKI, `1bb977f`.**
F-5 menaruh `overtime_rate_detail.days` ke `PayslipResource`, yang dilayani rute tanpa gerbang izin
(keadaan pra-F-5). `days` disaring; kuncinya DIBUANG, bukan dikosongkan. Menutup rutenya sendiri
adalah pekerjaan gerbang izin. **M40 merah.**

**C-6 · Log Audit tanpa "dari" pada perubahan pertama (SEDANG) — DIPERBAIKI, commit ini.**
Kesepuluh kunci `hr.timesheet.*` dikirim sebagai bawaan config tanpa baris `core_settings`, jadi
suntingan pertama — satu-satunya yang meninggalkan kebijakan pemilik — tercatat `created` dengan
`from: null`. Jalur "efektif dari→ke" yang sudah ada untuk `approvals.*` diperluas lewat awalan,
**hanya untuk jejaknya** (gerbang izin direktur tetap milik `approvals.*`). Uji audit kini memaku
ISI `changes`, bukan keberadaan barisnya. **M54–M55 merah.**

**A-8 · Uji "tidak ada backfill" tidak pernah bisa merah (RENDAH) — DIPERBAIKI, commit ini.**
Uji lama menulis sendiri kedua kolom menjadi null lalu menegaskan keduanya null: melumpuhkan
seluruh fitur pencatatan dasar memerahkan 10 dari 14 uji berkasnya dan meninggalkannya hijau.
Diganti dua pin yang menguji produksi: slip "lama" disisipkan lewat query builder tanpa kedua kolom
tetap NULL sesudah setiap pintu paket ini dijalankan, dan migrasi 001094 tidak menulis ke baris yang
sudah ada. **M52 merah.** Dicatat apa adanya: mutasi "lumpuhkan pencatatan dasar" (M53) TIDAK
memerahkan pin backfill — dan memang tidak boleh, karena baris lama harus tetap null di kedua
keadaan; yang dipaku di sini adalah ketiadaan backfill, dan M52 adalah mutasi yang menguji itu.

**A-9 / B-8 · Tabel mutasi berjumlah salah (RENDAH) — DIPERBAIKI, commit ini.**
Judul §5 berbunyi "30 dijalankan" atas tabel berisi 29 baris; M14 terlewat saat penomoran, dan
tidak ada mutasi yang hilang bersamanya. Dikoreksi menjadi 29, bersama pengakuan yang lebih penting:
tidak satu pun dari kedua puluh sembilan mutasi itu menyentuh jalur pembulatan kedua, dan kedelapan
tepi yang §6 sebut semuanya berjalan pada langkah 15 menit yang desimalnya kebetulan tepat.

### 13.6 Satu temuan yang tidak bisa ditutup

**C-7 — muatan temuannya terpotong** pada perintah yang sampai ke pohon ini: yang terbaca hanya
nomornya dan awal kata tingkat keparahannya (`"severity": "sed…"`). Tidak ada judul, lokasi, atau
langkah reproduksi. Ia **tidak** diperbaiki dan **tidak** ditolak — menebak isinya berarti mengarang
temuan, dan menutupnya diam-diam berarti melaporkan pekerjaan yang tidak dikerjakan. Kirim ulang
butir C-7 dan ia akan ditutup dengan aturan yang sama seperti dua puluh satu lainnya.

### 13.7 Keputusan pemilik yang bertambah

Daftar §9 bertambah satu, dan ia **satu jenis** dengan `day_start` 08:00 yang sudah ada di sana:
**`hr.timesheet.break_minutes` = 60 menit** adalah angka yang pemilik tidak sebut pada 14 September
2026. Ia dipilih di sini karena tanpanya setiap hari kerja biasa menghasilkan lembur palsu, ia
dicetak layar apa adanya, dan ia satu suntingan di layar Pengaturan — bukan satu rilis.
