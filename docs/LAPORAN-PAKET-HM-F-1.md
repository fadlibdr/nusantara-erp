# Laporan Paket F-1 (ROADMAP-HASHMICRO Fase 2) — Matriks persetujuan, delegasi "a.n.", setujui massal

Branch: `feat/phase2-f1` (dari `main` 1ce8a42) · 7 September 2026 · **paket pertama Fase 2**

> **Klaim tengah paket ini bukan "layarnya jadi" — klaimnya adalah bahwa memasangnya TIDAK MENGUBAH
> SATU PUN KEPUTUSAN PERSETUJUAN.** Dua puluh delapan baris matriks dikirim membawa nilai yang
> mengatur jenis dokumen itu sebelum paket ini ada; delapan izin `*.approve-director` baru dicetak
> tetapi tidak satu pun diperiksa sampai sebuah baris membawa ambang; setujui massal mati; delegasi
> nol baris. OQ-4 dan OQ-5 tetap milik pemilik — yang dikirim adalah **layarnya dan lembar
> jawabannya**, bukan kebijakannya (ROADMAP §5 baris 12).
>
> Dua migrasi Core (`000198`, `000199`) — **slot terakhir blok Core §2**. Satu migrasi Iam
> (`000251`). Nol dependensi, nol pustaka vendor, nol perubahan konfigurasi server.
>
> **Verifikasi peramban sungguhan dijalankan**: harness S28 (23 syarat) + S28m ponsel (3 syarat),
> semuanya hijau atas antrean yang BERISI — lihat § Harness.

## Yang ditutup (ROADMAP-HASHMICRO Fase 2 / F-1, baris 207 → status)

| Klausa kontrak | Status | Bukti |
|---|---|---|
| `ApprovalPolicy` per 28 jenis (ambang, mode, tingkat-3) di Pengaturan | ✅ | `Core\Support\ApprovalPolicy` + kelompok registri `approval_matrix` (39 kunci, 38 sel + plafon massal) · S28 `matrix_renders_every_approvable_type` (28 baris terukur di Chromium) |
| dikirim dengan NILAI EFEKTIF HARI INI | ✅ | tabel 28 baris di bawah · `ApprovalMatrixScreenTest::test_the_matrix_ships_with_the_values_that_govern_each_type_today` · S28 `po/spk/award_row_carries_todays_threshold` |
| kebijakan distempel di baris `submitted` (tidak retroaktif) | ✅ | `core_approvals.policy` + `ApprovalStamp` (observer) · `ApprovalPolicyStampTest` (6 uji: naik/turun/HTTP penuh) |
| mengubah `approvals.*` butuh `core.update` + `*.approve-director` + audit | ✅ | `UpdateSettingsRequest::rejectApprovalPolicyWithoutDirector` + `SettingService::assertMayChangeApprovalPolicy` + `auditApprovalPolicyChange` · S28 `core_update_alone_is_refused` (422 sungguhan) |
| gerbang lama PO/SPK TIDAK dimigrasikan, kesetaraan DIBUKTIKAN 6 nilai | ✅ | `ApprovalPolicyEquivalenceTest` — 6 nilai × PO, 6 × SPK terhadap `needs_director_approval` yang benar-benar dicap `submit()`; 12 nilai untuk jenjang award |
| izin `*.approve-director` untuk awalan lain | ⚠️ **8, bukan 9** | derivasi di § Delapan, bukan sembilan |
| `core_approval_delegations` + `Gate::before` HANYA `*.approve*` | ✅ | `ApprovalDelegationTest::test_a_delegation_never_grants_anything_but_an_approve_ability` (6 ability diuji) |
| patch `NotificationService::approvers` | ✅ | `ApprovalDelegationTest::test_the_delegate_is_told_a_document_is_waiting` |
| delegat tidak boleh menyetujui pengajuan dirinya MAUPUN pemberinya | ✅ | dua uji terpisah, keduanya 422/`SelfApprovalException` |
| trail & cetakan "Budi a.n. Sari" | ⚠️ **trail ya, cetakan TIDAK ADA yang menamai penyetuju** | § Yang tidak bisa dicetak |
| setujui massal `approvals.batch_cap` (bawaan kosong = mati) | ✅ | S28 `bulk_is_invisible_while_the_cap_is_empty` diukur pada antrean **berisi 4 baris** |
| loop di klien memanggil endpoint modul masing-masing | ✅ | `test_there_is_no_server_side_bulk_approve_endpoint` memindai tabel rute · S28 menyetujui 2 dokumen sungguhan lewat 2 panggilan |

## Lembar jawaban OQ-4 — 28 baris, apa yang berlaku HARI INI

Ini yang harus dibaca pemilik. Kolom "ambang hari ini" adalah bawaan yang dikirim layar; **selama
pemilik tidak mengubah satu pun sel, perilaku aplikasi identik dengan sebelum paket ini.**

| # | Awalan | Jenis dokumen | Ambang direktur **hari ini** | Mode | Tingkat ke-3 | Dapat diisi pemilik? |
|---:|---|---|---|---|---|---|
| 1 | `crm` | Penawaran | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 2 | `crm` | Pekerjaan tambah-kurang | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 3 | `est` | BOQ / RAB | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 4 | `est` | RAP | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 5 | `prj` | BAST | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 6 | `prj` | Baseline proyek | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 7 | `prj` | Izin kerja lapangan | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 8 | `prj` | Izin kerja lembur | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 9 | `prj` | Izin masuk/keluar material | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 10 | `eng` | Ijin pelaksanaan pekerjaan | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 11 | `qc` | Inspeksi mutu | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 12 | `prj` | Opname progres owner | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 13 | `prc` | Permintaan pembelian | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 14 | `prc` | Pesanan pembelian | **Rp 100.000.000** | satu penyetuju (terkunci) | — | ambang **ya**, mode **tidak** (gerbang modulnya sendiri) |
| 15 | `prc` | Keputusan pemenang | **Rp 100.000.000** | **tambahan tingkat** | **Rp 1.000.000.000** | ya — ketiganya |
| 16 | `prc` | PPK alat & jasa | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 17 | `inv` | Penyesuaian stok | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 18 | `scm` | SPK subkontraktor | **Rp 200.000.000** | satu penyetuju (terkunci) | — | ambang **ya**, mode **tidak** (gerbang modulnya sendiri) |
| 19 | `scm` | Addendum SPK | Rp 200.000.000 (dari SPK) | satu penyetuju (terkunci) | — | **tidak** — mengikuti baris SPK subkontraktor |
| 20 | `scm` | Opname subkon | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 21 | `scm` | BAST subkontraktor | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |
| 22 | `scm` | SP3 mandor | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 23 | `scm` | Opname mandor | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 24 | `fin` | Invoice termin | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 25 | `fin` | Tagihan vendor | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 26 | `fin` | Pembayaran keluar | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 27 | `hr` | Payroll | *tanpa ambang* | satu penyetuju | — | ya — ketiganya |
| 28 | `hr` | Pengajuan cuti | *tanpa nilai rupiah* | — | — | **tidak** — tabelnya tidak punya kolom nilai |

**Ringkasnya: 3 jenis punya aturan bernilai hari ini** (PO, SPK, keputusan pemenang) **+ 1 yang
mengikuti** (addendum SPK). **11 jenis lain BISA diberi ambang** oleh pemilik dan dikirim tanpa.
**13 jenis tidak akan pernah bisa** — tabelnya tidak punya kolom nilai.

### Kenapa 13 baris tidak punya kotak isian

Diukur dari skema 7 Sep 2026: `crm_contract_change_orders`, `prj_bast`, `prj_baselines`,
`prj_work_permits`, `prj_overtime_permits`, `prj_gate_passes`, `prj_progress_measurements`,
`eng_work_permits_ipp`, `qc_inspections`, `prc_purchase_requisitions`, `inv_stock_adjustments`,
`scm_handovers`, `hr_leave_requests` **tidak membawa satu pun kolom nilai**
(`ApprovalQueue::AMOUNT_KEYS`). Sebuah izin kerja lapangan tidak berharga rupiah — itu bukan
kekurangan yang perlu ditambal, itu memang jenis dokumennya.

Menawarkan kotak isian di sana berarti menawarkan kendali yang tidak akan pernah berbunyi, yaitu
kegagalan yang **sama persis** dengan `needs_director_approval` yang dulu dicap, ditampilkan
sebagai "Perlu persetujuan direktur", dan tidak dibaca siapa pun saat menyetujui — SPK/2026/II/0001,
Rp 6,5 miliar, disetujui satu login non-direktur. Baris-baris itu karena itu mencetak aturannya:
**"Tanpa nilai rupiah — ambang tidak berlaku"**.

**Kalau pemilik menginginkan ambang pada salah satunya**, yang dibutuhkan adalah kolom nilai pada
tabelnya (mis. nilai estimasi pada permintaan pembelian) — sebuah paket sendiri, bukan sebuah sel.
Ini keputusan yang terbuka, dan dicatat di § Untuk pemilik.

## Lembar jawaban OQ-5 — delegasi, seperti yang dikirim

| Pertanyaan | Yang dikirim | Dapat diubah pemilik |
|---|---|---|
| Siapa boleh membuat delegasi? | pemiliknya sendiri, atau pemegang `iam.update` | lewat peran |
| Apa yang dipinjamkan? | **hanya** `<awalan>.approve` dan `<awalan>.approve-director` | tidak — dipaku uji |
| Lingkupnya? | seluruh hak approve pemberinya, atau satu awalan modul | per baris delegasi |
| Jendelanya? | tanggal mulai wajib, tanggal selesai boleh kosong (= sampai dicabut) | per baris |
| Berantai? | tidak — pemberinya harus memegang izinnya sendiri | tidak |
| Delegat menyetujui pengajuan pemberinya? | **tidak pernah** | ikut saklar maker-checker |
| Terlihat sebelum dipakai? | spanduk di Tugas Saya menyebut pemberi, lingkup, jendela, alasan | tidak |
| Terlihat sesudah dipakai? | jejak berbunyi "Budi a.n. Sari", selamanya | tidak |
| Dicabut = dihapus? | **tidak** — barisnya menjelaskan setiap "a.n." yang ditinggalkannya | tidak |

## Delapan, bukan sembilan

ROADMAP menaksir "izin `*.approve-director` untuk 9 prefix lain". **Registrinya berkata delapan**,
dan angka yang diukur yang dipakai.

Derivasinya: `ApprovableDocuments` memuat 28 jenis dokumen di **sepuluh** awalan — `crm`, `est`,
`prj`, `eng`, `qc`, `prc`, `inv`, `scm`, `fin`, `hr`. Dua sudah punya izin direktur sejak migrasi
Iam 000240 (`prc`, `scm` — PO dan SPK), jadi yang baru **delapan**: `crm`, `est`, `prj`, `eng`,
`qc`, `inv`, `fin`, `hr`.

Empat awalan lain yang ada di `PermissionSeeder::PREFIXES` — `core`, `iam`, `ast`, `svc` — **tidak**
mendapatkannya: tidak satu pun memiliki dokumen ber-`submit → approve`, jadi tidak ada baris matriks
yang bisa menuntutnya, dan sebuah izin yang tidak diperiksa apa pun terbaca sebagai kendali yang ada.

Daftarnya **diturunkan** (`PermissionSeeder::directorApprovals()` membaca registri), jadi jenis
dokumen ke-29 dari modul baru membawa izin direkturnya sendiri tanpa satu suntingan pun, dan
`erp:permission-check` menghitung yang sama karena ia membaca fungsi itu.

## Aturan yang dikirim — rujukan, bukan ringkasan

| Aturan | Di mana ia hidup |
|---|---|
| slug jenis = `Str::snake(class_basename)`, dan itulah bentuk kunci yang SUDAH dikirim | `ApprovalPolicy::slugFor` |
| baris PO/SPK menulis kunci `threshold_two_level` yang sudah ada — bukan kunci kembar | `ApprovalPolicy::keysFor` |
| "tanpa ambang" = `null`, dirender sebagai aturan, tidak pernah `Rp 0` | `ApprovalPolicy::amount` + `config/erp.php` |
| jenis tanpa kolom nilai tidak mendapat sel ambang | `ApprovalPolicy::hasMeasurableAmount` |
| tabel ber-`needs_director_approval` tidak mendapat sel mode, dan Core tidak menggerbanginya ulang | `ApprovalPolicy::modeIsLocked` (dibaca dari SKEMA) |
| addendum SPK memantul ke kunci SPK | `ApprovalPolicy::FOLLOWS` |
| yang mengikat adalah aturan saat DIAJUKAN | `core_approvals.policy` + `Approvable::requiredApprovalLevels` |
| stempel ditulis di SATU tempat yang menangkap semua jalur | observer `Approval::creating` → `ApprovalStamp` |
| `Gate::before` mengembalikan `true` atau `null` — tidak pernah `false` | `ApprovalDelegations::grants` |
| delegasi tidak berantai | `ApprovalDelegations::holdsNatively` (`hasPermissionTo`, bukan `can`) |
| delegat tidak menyetujui pengajuan pemberinya | `SegregationOfDuties::assertNotSubmitter` |
| "a.n." dicap hanya bila delegasinya yang membuatnya mungkin | `ApprovalDelegations::actingForId` |
| satu perender jejak untuk 25 resource | `Core\Http\Resources\ApprovalTrail` |
| tidak ada endpoint massal di server | dipaku `test_there_is_no_server_side_bulk_approve_endpoint` |

## Satu regresi yang tertangkap saat menulisnya

`definitions()` sempat membangun kelompok matriks lewat `ApprovalPolicy::all()`, yang **membaca
nilai setelan**. `definitions()` dipanggil dari dalam `set()`, dan `Erp::` membaca lewat instance
`SettingService` yang di-scope kontainer — jadi sebuah penulisan oleh instance LAIN
(`(new SettingService)->set(...)`, yang dilakukan tiga uji cache dan setiap pemanggil di luar
kontainer) menghangatkan memo kontainer **di tengah penulisan itu**, dan unit kerja itu kemudian
membaca peta yang sudah basi.

Terukur: `SettingServiceTest::test_a_reset_by_another_process_is_seen_on_the_next_unit_of_work`
membaca **11,0 sesudah menulis 5,0**, dan `test_a_cold_read_of_sixty_parameters_costs_four_queries`
melihat 1 kueri alih-alih 4 karena cache-nya sudah hangat.

Registri sekarang hanya menyentuh label, awalan dan nama kunci
(`ApprovalPolicy::documentTypes/documentEntry/directorPermissions` — tidak satu pun membaca setelan);
nilainya diambil `overview()`, sesudah semua definisi disusun.

## Yang tidak bisa dicetak "a.n." — dan kenapa itu bukan kelalaian

Kontrak ROADMAP berbunyi «trail & cetakan "Budi a.n. Sari"». **Trail: ya.** Cetakan: **tidak ada
formulir rumah yang menamai penyetuju sama sekali**, jadi tidak ada tempat untuk menaruh "a.n."-nya.

Itu keputusan yang sudah tertulis dan sengaja: `FormPrintService::partySignatures` dan
`PrintableDocuments` meninggalkan setiap kolom tanda tangan TANPA NAMA, dengan alasannya sendiri —
«core_approvals tahu siapa menekan Setujui di aplikasi ini; itu bukan klaim yang sama dengan "orang
ini menandatangani dokumen", dan mencetak yang satu di bawah yang lain berarti memalsukan baris
tanda tangan pada lembar yang difile orang». Satu-satunya nama yang dicetak formulir adalah
**pemohon** (`$permit->requestedBy?->name`), bukan penyetuju.

Menambahkan nama penyetuju ke kolom tanda tangan demi memenuhi klausa itu berarti membatalkan
keputusan tersebut — dan memalsukan tanda tangan pada kertas jauh lebih buruk daripada sebuah
klausa yang tidak terpenuhi. **Klausanya dinyatakan sebagian terpenuhi, dan sisanya adalah
keputusan pemilik** (§ Untuk pemilik).

"a.n." karena itu muncul di: jejak persetujuan setiap layar detail (25 resource), pita status di
atas dokumen, dan kolom `on_behalf_of_user_id` yang bisa dibaca laporan mana pun.

## Uji

**53 uji PHP baru**, empat berkas, semuanya di `tests/Feature/Core/`:

| Berkas | Uji | Yang dipakukannya |
|---|---:|---|
| `ApprovalPolicyEquivalenceTest` | 9 | kesetaraan gerbang lama ↔ kebijakan baru di **6 nilai** sekitar tiap ambang (nol, setengah, −1, TEPAT, +1, ×10) untuk PO dan SPK; 12 nilai untuk jenjang award; addendum memantul; nilai TEPAT di batas jatuh ke tingkat lebih tinggi; dokumen tanpa nilai ≠ dokumen bernilai nol; 24 jenis tanpa ambang |
| `ApprovalPolicyStampTest` | 6 | naikkan ambang sesudah pengajuan → tuntutan tidak turun; turunkan → tidak naik; ajukan-ubah-setujui lewat HTTP; ambang yang dipasang pemilik berlaku HANYA untuk yang diajukan sesudahnya; stempel menamai aturan yang dikutip penolakannya; baris tanpa stempel jatuh ke jalur lama |
| `ApprovalDelegationTest` | 21 | apa yang diberikan (4) dan apa yang **tidak** (7: ability lain, lingkup, hak yang tak dipegang, berantai, di luar jendela, dicabut, pemberi nonaktif); dua penolakan maker-checker; pemberitahuan; lima uji endpoint |
| `ApprovalMatrixScreenTest` | 17 | nilai hari ini; null bukan nol; 13 baris tanpa sel; 3 baris mode terkunci; setiap baris menamai izinnya; dua penjaga izin; audit dari→ke termasuk reset; plafon massal mati/hidup/nol; ketiadaan endpoint massal |

**Empat uji yang sudah ada disesuaikan, semuanya karena kontraknya memang berubah:**

- `SettingApiTest::GROUP_COUNT` 9 → **10** (kelompok Matriks Persetujuan).
- `SettingServiceTest::test_every_registry_key_resolves_to_a_config_default` →
  `..._is_declared_in_the_shipped_config`. Cacat yang dijaga sama; yang berubah adalah bahwa **null
  yang DINYATAKAN adalah bawaan yang sah**. Memaksa bukan-null berarti menuliskan `0`, dan ambang
  nol berarti setiap dokumen menuntut direktur.
- `ApprovalTrailOnShowTest` (8 modul) + `PurchaseRequisitionApprovalTrailTest`: bentuk jejak kini
  `['id','action','note','created_at','user','on_behalf_of']`. Uji itulah yang membuat penggantian
  25 salinan penutup dengan satu perender boleh dilakukan sekaligus — ia menuntut kunci yang sama
  persis dari setiap layar detail, jadi resource yang tertinggal terlihat di sana dan bukan di
  produksi.

## Harness — S28, dua skenario, 26 syarat, semuanya hijau

`docs/bukti-uji/results-phase-2.json` (digabung per kunci skenario; delapan skenario era
ROADMAP-DEVIASI yang sudah ada di berkas itu **tidak** ditimpa).

| Skenario | ms | klik | Syarat |
|---|---:|---:|---:|
| `S28_matriks_persetujuan` (1440×900) | 21.849 | 1 | 23 |
| `S28_matriks_persetujuan_mobile` (390×844) | 5.464 | 0 | 3 |

Yang benar-benar diukur di Chromium, bukan diasumsikan:

- **28 baris** tergambar, label kartunya "28 jenis dokumen", **38 sel yang bisa diedit**.
- PO membawa `Rp 100.000.000`, SPK `Rp 200.000.000`, award `Rp 100.000.000` + `Rp 1.000.000.000`,
  addendum "Mengikuti SPK subkontraktor", izin kerja lapangan "Tanpa nilai rupiah".
- **`zero_rows: []`** — tidak satu pun dari 28 baris mencetak `Rp 0`. Ini syarat terpenting paket ini.
- **13** baris tanpa nilai, **1** baris mengikuti.
- Penyunting ber-`core.update`-tanpa-direktur **dibuat di dalam skenario** (tidak satu pun login demo
  memegang kombinasi itu) → **422** dengan kalimat yang menyebut `*.approve-director`; admin → 200;
  lalu ambangnya dikembalikan ke bawaan dan dibaca ulang = `100000000`.
- Setujui massal **tidak ada** saat plafon kosong — diukur pada antrean **4 baris** milik `direktur`,
  bukan pada kotak masuk kosong. Syarat `the_queue_measured_for_bulk_off_is_not_empty` ada persis
  untuk itu: `admin` mengajukan hampir seluruh dataset demo, jadi antreannya nol dan "tidak ada
  kotak centang" akan hijau tanpa satu fakta pun di belakangnya.
- Sesudah plafon 5: 4 kotak centang, bilahnya mencetak "maksimum 5", dua dipilih
  (`CTI/2026/VIII/0002`, `RAP/2026/0001`), satu klik, toast berbunyi
  **"2 dokumen disetujui: CTI/2026/VIII/0002, RAP/2026/0001."**, antrean turun 4 → 2.
- Jejaknya berbunyi **"Dewi Lestari a.n. Administrator Sistem"**.
- Ponsel: 28 baris tergambar, tabel menggulir DI DALAM `.table-wrap`, halaman **tidak pernah**
  menggulir mendatar.

Tangkapan layar: `s28-matriks-persetujuan-f1.png`, `s28-spanduk-delegasi-f1.png`,
`s28-matriks-ponsel-f1.png`.

**Empat cacat harness ditemukan saat menulisnya, semuanya jenis "hijau yang salah":** `'\n'` dalam
string non-raw Python yang memecah literal JS; tabel kotak masuk dikenali dari kata "Dokumen" yang
juga ada di kartu Delegasi; kode dokumen dibaca dari `innerText.split('\n')[0]` yang sejak ada kolom
kotak centang mengembalikan sel kosong; dan toast dibaca sesudah `sleep(7)` sementara toast hidup 6
detik — "tidak ada laporan" untuk laporan yang ada adalah bukti yang berbohong.

## Deviasi dari kontrak ROADMAP

| Klausa | Yang dikirim | Alasan |
|---|---|---|
| "izin untuk **9** prefix lain" | **8** | registri memuat 10 awalan berdokumen, 2 sudah punya. `core`/`iam`/`ast`/`svc` tidak punya dokumen ber-approve |
| "**cetakan** Budi a.n. Sari" | trail ✅, cetakan **tidak ada penyetuju yang dicetak** | formulir rumah sengaja tidak menamai penyetuju sejak P0 — § Yang tidak bisa dicetak |
| "ambang per **28** jenis" | 15 baris dapat diisi (14 kunci ambang + 1 memantul), 13 tidak | 13 tabel tidak punya kolom nilai; ambang di sana tidak akan pernah berbunyi |
| "results-phase-2.json **BARU**" | **digabung** ke berkas yang sudah ada | berkas itu sudah memuat 8 skenario era ROADMAP-DEVIASI; menimpanya berarti menghapus bukti. Digabung per kunci — tidak satu pun ditimpa |
| mode `extra_level` untuk PO/SPK | **tidak ditawarkan** | ROADMAP juga berkata gerbang lama tidak dimigrasikan; menawarkan mode yang tidak ada penegaknya = layar yang berbohong |

## Tinjauan sendiri — tiga temuan, plus satu yang bukan milik paket ini

Bukan pengganti verifikasi adversarial ganda (§ Yang BELUM diverifikasi #1) — hanya tiga hal yang
ditemukan dengan membaca kembali diff-nya sendiri sesudah semuanya hijau. Temuan 1 dan 3 ditutup di
`beb7e32`, temuan 2 di `4636d47`.

**1. Sebuah delegasi persetujuan MEMBUKA matriksnya — kendali uang, bukan ketidaknyamanan.**

Penjaga "mengubah `approvals.*` butuh `*.approve-director`" memakai `can()`. `can()` melewati
`Gate::before`. `Gate::before` **adalah** delegasi. Maka Budi yang memegang delegasi Sari **plus**
`core.update` dapat menurunkan ambang PO dari Rp 100 juta menjadi Rp 10 miliar — sebuah kendali uang
yang berpindah tangan sebagai efek samping cuti, dan berpindah **untuk selamanya**, karena ambang
barunya tidak ikut kedaluwarsa bersama delegasi yang membukanya.

Delegasi meminjamkan hak **menyetujui dokumen**; ia tidak boleh menjadi hak menulis ulang apa arti
menyetujui. Kedua penjaganya kini membaca izin yang dipegang SENDIRI
(`ApprovalDelegations::holdsNatively` — fungsi yang sudah ada untuk alasan yang sama persis di sisi
delegasi). Dipaku `test_a_delegated_director_right_does_not_unlock_the_matrix`, yang lebih dulu
menegaskan haknya memang dipinjam (delegat BISA menyetujui) sebelum menuntut matriksnya tetap
tertutup.

**2. PO dan SPK tergambar DUA KALI di layar Pengaturan.**
`approvals.purchase_order.threshold_two_level` dan `approvals.subcontract.threshold_two_level`
adalah dua field yang sudah ada di kelompok "Proyek & Persetujuan" — dan matriks membawa BARIS untuk
keduanya, dengan kunci yang sama persis. Hasilnya dua kontrol untuk satu ambang uang di satu layar:
seorang operator dapat mengetik dua angka berbeda dan Simpan memilih salah satunya tanpa memberi
tahu siapa pun. Field lamanya dihapus; matriks adalah satu-satunya tempat ambang per jenis dokumen
disunting. (Dua kunci `approvals.*` yang tersisa di kelompok itu — umur antrean dan pemisahan tugas
— memang bukan aturan per jenis dokumen dan tetap di sana.) Layar Pengaturan turun dari 148 menjadi
**146 baris**.

**Ditemukan tetapi TIDAK diperbaiki, karena bukan milik paket ini:** `cashflow.termin_collection_days`
juga tergambar dua kali, di kelompok "PPh Final Jasa Konstruksi" DAN "Proyeksi Arus Kas". Itu sudah
begitu sebelum F-1; memindahkannya berarti menyunting kelompok yang tidak ada hubungannya dengan
paket ini. Dicatat di sini supaya paket berikutnya yang menyentuh Pengaturan tahu.

**3. Dua kueri yang berulang di tiap pemeriksaan izin.** `Gate::before` berjalan pada SETIAP
pemeriksaan izin dan satu permintaan memeriksa izin puluhan kali. Baris delegasinya sudah dimemo per
unit kerja — tetapi pencarian PEMBERINYA (satu `SELECT users` per baris per pemeriksaan) tidak, jadi
memo itu hanya memindahkan kuerinya. Dan
`Schema::hasColumn('core_approvals','policy')` dipanggil **dua kali pada tiap persetujuan** (sekali
dari `requiredApprovalLevels()`, sekali dari `assertStampedDirectorLevel()`), sementara di MySQL itu
kueri `information_schema`. Keduanya kini dimemo.

**Satu catatan proses:** dua putaran uji dijalankan di atas pohon yang sedang disunting dan
melaporkan kegagalan yang tidak nyata (`Call to private method holdsNatively`, dan delapan
`ApprovalTrailOnShowTest` yang berkasnya sudah diperbaiki di tengah jalan). Itu persis jebakan
"phantom flake" yang tercatat di memori repositori ini — kali ini ditimbulkan sendiri. Angka yang
dilaporkan di § Gerbang di bawah berasal dari putaran yang dijalankan **sesudah** pohonnya diam.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Verifikasi adversarial ganda belum dijalankan.** ROADMAP §4 menuntut dua verifier baca-saja
   sebelum merge; paket ini keluar dari agen build saja. Pola itulah yang menemukan ~40 cacat di
   P2–P8, termasuk tiga bug uang.
2. **Belum dijalankan di MySQL.** Seluruh angka di laporan ini dari SQLite. Dua hal yang khusus
   perlu dilihat di MySQL: kolom `json` `core_approvals.policy` (SQLite menyimpannya sebagai TEXT
   dan cast `array` menyembunyikan bedanya) dan `whereDate` pada jendela delegasi.
3. **Suite penuh belum hijau di laporan ini** — yang dijalankan adalah direktori tersentuh
   (Core, Iam, Procurement, Subcontract, Estimation, Finance). Gerbang rilis milik orkestrator.
4. **Tidak diuji: delegasi berpapasan dengan penghapusan akun.** FK `cascadeOnDelete` menghapus
   barisnya bersama pemberinya; baris `core_approvals.on_behalf_of_user_id` yang menunjuk ke akun
   itu **tidak** ber-FK dan tetap tinggal sebagai angka. Itu disengaja (jejak harus selamat), tetapi
   layar yang membacanya akan mencetak `null` sebagai nama — belum ada uji yang memaksanya
   mencetak sesuatu yang jujur.
5. **Tidak diuji: dua delegasi bersamaan dari dua pemberi berbeda ke satu orang.** Kodenya
   menanganinya (loop atas semua baris aktif) dan spanduk memformat jamak, tetapi tidak ada uji.
6. **Tidak diukur: biaya `Gate::before` pada permintaan yang berat.** Memonya per unit kerja, jadi
   biayanya satu SELECT per permintaan untuk pengguna yang punya delegasi dan nol kueri untuk yang
   tidak (tabel dicek `Schema::hasTable` lalu dimemo). Belum ada angka yang diukur.
7. **Tidak diuji lewat peramban: penolakan setujui massal di tengah antrean.** Kodenya melanjutkan
   dan menamai yang gagal; S28 hanya menjalankan dua dokumen yang keduanya berhasil.
8. **Tidak dijalankan di erp1.** Paket ini belum menyentuh produksi.
9. **Tidak diputuskan: apakah delegat boleh mengisi tingkat KEDUA sebuah jenjang yang tingkat
   pertamanya diisi pemberinya sendiri.** Hari ini boleh — `ApprovalLevels` menghitung penyetuju
   BERBEDA menurut `user_id`, dan Budi memang orang lain yang benar-benar melihat dokumennya.
   Bacaan yang lebih ketat ("hak yang dipakai sama, jadi bukan mata kedua") akan menolaknya. Yang
   sudah pasti tertutup adalah kasus terburuknya: delegat tidak pernah boleh menyetujui dokumen
   yang DIAJUKAN pemberinya.
10. **Seeder demo tidak menambahkan satu pun delegasi.** Layar Delegasi Persetujuan pada dataset demo
   kosong sampai seseorang membuat satu — itu jujur, tetapi berarti tidak ada contoh yang bisa
   dilihat pemilik tanpa mengetik.

## Untuk pemilik — setiap bawaan yang sekarang dibawa layar

**Tidak ada yang perlu Anda lakukan agar aplikasi berperilaku seperti kemarin.** Daftar ini adalah
apa yang bisa Anda ubah, dan apa yang Anda ubah kalau Anda menyentuhnya.

1. **Tabel 28 baris di atas adalah jawaban OQ-4 apa adanya.** Tiga jenis punya aturan bernilai; 11
   lagi bisa diberi; 13 tidak punya nilai untuk diukur.
2. **Ambang PO Rp 100 juta dan SPK Rp 200 juta tidak berubah** — sel itu sekarang bisa Anda edit dari
   layar, dan kunci yang ditulisnya adalah kunci yang sama yang sudah dibaca gerbangnya.
3. **Keputusan pemenang tetap berjenjang** Rp 100 juta (2 penyetuju) / Rp 1 miliar (3), sekarang
   terbaca di layar dalam kosakata yang sama dengan baris lain.
4. **Mode `extra_level` tersedia untuk 12 jenis** yang punya nilai dan tidak bergerbang sendiri.
   Menyalakannya pada, misalnya, Pembayaran keluar berarti pembayaran di atas ambang menuntut
   **dua orang berbeda**, yang kedua pemegang `fin.approve-director`.
5. **Setujui massal mati.** Isi angkanya hanya bila Anda memang menginginkan satu klik untuk banyak
   dokumen. Pertimbangkan bahwa setiap dokumen tetap satu permintaan dan laju API 120/menit.
6. **Delegasi kosong.** Fiturnya ada; tidak ada satu baris pun sampai seseorang membuatnya.
7. **Delapan izin `*.approve-director` baru sudah ada di peran `direktur` dan `admin`.** Kalau
   perusahaan Anda ingin, misalnya, "manajer keuangan" memegang `fin.approve-director` tanpa menjadi
   direktur, itu tinggal ditambahkan di layar Peran.
8. **Keputusan yang masih terbuka**: apakah salah satu dari 13 jenis tanpa nilai perlu kolom nilai
   supaya bisa diberi ambang (kandidat paling masuk akal: permintaan pembelian, yang punya harga
   estimasi per baris tetapi tidak punya total). Itu paket sendiri, bukan sel.
9. **Keputusan yang masih terbuka**: apakah formulir rumah boleh mencetak nama penyetuju. Hari ini
   tidak satu pun mencetaknya, dengan alasan yang tertulis. Kalau pemilik memutuskan boleh, "a.n."
   ikut ke kertas; kalau tidak, ia tetap hanya di layar dan di basis data.

## Blok migrasi Core HABIS

`core_approvals` (000198) dan `core_approval_delegations` (000199) mengambil **dua slot terakhir**
yang disisakan CONVENTIONS §2 untuk Core. Tabel Core berikutnya menuntut keputusan blok lanjutan;
ledger #5 menyarankan "Core 001400–" tetapi §2 sudah memberikan 001400–001499 kepada Quality dan
Quality memakainya. **Ini keputusan pemilik yang harus diambil sebelum paket Fase 2 berikutnya yang
menambah tabel Core.**

## Gerbang rilis

Dijalankan di atas pohon yang **sudah diam** — semua suntingan selesai dan tree bersih. Dua putaran
sebelumnya dijalankan di atas pohon yang sedang disunting dan melaporkan kegagalan yang tidak nyata;
angka di bawah bukan angka itu (§ Tinjauan sendiri, catatan proses).

| Leg | Cakupan | Uji | Asersi | Dilewati | Waktu | Hasil |
|---|---|---:|---:|---:|---:|---|
| SQLite | Core, Iam, Procurement, Subcontract, Estimation, Finance (Feature + Unit) | 2.543 | 15.857 | 11 | 06:54.616 | ✅ hijau |
| MySQL 8 | berkas F-1 + seluruh registri Pengaturan + Iam | 202 | 3.248 | 0 | 02:08.051 | ✅ hijau |
| Peramban | S28 desktop 1440×900 + S28m ponsel 390×844 | 2 skenario | 26 syarat | — | 21.7 s + 5.4 s | ✅ hijau |

Leg MySQL sengaja tidak menjalankan seluruh direktori: yang perlu dilihat di sana adalah kolom
`json` `core_approvals.policy` (SQLite menyimpannya sebagai TEXT dan cast `array` menyembunyikan
bedanya) dan `whereDate` pada jendela delegasi — keduanya ada di berkas F-1, dan seluruh registri
Pengaturan ikut karena matriks menambah 39 kunci padanya.

`php artisan erp:permission-check` pada salinan dataset demo yang sudah dimigrasi:
**94 izin diharapkan (14 awalan × 6 aksi + 10 persetujuan direktur), 94 di basis data, 12 peran
sesuai seeder, nol penyimpangan** — 86 → 94 adalah kedelapan izin baru. Ini gerbang deploy
(`deploy/sync-erp1.sh` menjalankannya), jadi ia harus hijau sebelum merge.

Pint: hijau pada 60 berkas PHP yang disentuh paket ini. (Enam berkas lain di repositori memang
dilaporkan `pint --test` dan sudah begitu sebelum paket ini; tidak satu pun disentuh di sini.)

## Commit

| # | Commit | Isi |
|---:|---|---|
| 1 | `8a731c9` | T1.1+T1.3 — matriks 28 jenis, nilai hari ini, dua penjaga izin, audit dari→ke |
| 2 | `719ae26` | T1.2 — stempel kebijakan pada baris `submitted` (Core 000198) |
| 3 | `478d21a` | T1.4 — delapan izin direktur diturunkan dari registri (Iam 000251) |
| 4 | `2516613` | T1.5 — delegasi "a.n." (Core 000199), `Gate::before`, dua penolakan, satu perender jejak |
| 5 | `1b8b147` | T1.3 — berkas uji kesetaraan yang tertinggal dari commit 1 |
| 6 | `18b5581` | T1.6 — setujui massal, plafon kosong = mati, loop di klien |
| 7 | `2afdf69` | T1.7 — harness S28 + S28m, hasil digabung ke `results-phase-2.json` |
| 8 | `ff0226a` | T1.8 — CONVENTIONS §22–§23, PANDUAN ×2, FRONTEND, laporan ini |
| 9 | `beb7e32` | tinjauan sendiri 1+2 — delegasi tidak membuka matriks; dua kueri berulang |
| 10 | `b9caf4c` | bukti UI diambil ulang di atas pohon yang diam |
| 11 | `0e4008e` | stempel menjawab tiap pertanyaan sekali |
| 12 | `4636d47` | tinjauan sendiri 3 — PO/SPK tergambar dua kali di Pengaturan |
