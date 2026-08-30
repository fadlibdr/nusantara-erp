# Laporan Paket P6 — HSE terstruktur

Branch: feat/p6 · Commit: lane backend = commit yang membawa laporan ini · Tanggal: 2026-08-30

K3 harian berhenti menjadi satu kolom prosa. **Formulir K3 harian** (FM-10-13, mask
`HSE`, cetak **F/K3H**) mencatat toolbox meeting, hitungan APD per kategori sebagai
BARIS DATA, dan temuan & tindak lanjut — tertaut ke laporan harian **proyek+tanggal yang
sama** lewat resolusi server dua arah (laporan yang lahir belakangan menaut-balik).
**Register IBPRP** (`prj_risk_register`, cetak **F/IBPRP** satu lembar per proyek)
menyimpan aktivitas–bahaya–L×S–pengendalian–risiko sisa dengan skor yang **selalu
dihitung, tidak pernah diketik**, dan banding tingkat risiko di SATU tempat
(`RiskLevel::fromScore`, matriks 5×5 Permen PUPR 10/2021). **Checklist 5R** adalah satu
kolom, bukan satu mesin: `qc_inspection_templates.jenis` (`quality`|`5r`) menjadikan
patroli 5R inspeksi P1-QC biasa. **Foto insiden K3** akhirnya menempel pada insidennya
(`AttachableDocuments` + galeri proyek — temuan panduan §7.7). Katalog cetak 56 → 58.

## Keputusan modul (diminta roadmap §5: "Projects atau Quality — pilih satu, tulis alasannya")

**HSE tinggal di `Projects`** (prefix `prj_`, blok migrasi 000700–000799):

1. Tautan FM-10-13 ↔ laporan harian adalah relasi dua arah di dalam satu modul — laporan
   harian yang dibuat belakangan MENULIS `prj_hse_daily.daily_report_id`
   (`DailyReportService` → `HseDailyService::relink`). Bila HSE di Quality, tulisan itu
   berarah Projects → Quality, panah yang ARCHITECTURE.md haramkan (satu-satunya baca
   Projects atas data Quality adalah `qc_ncr` by-value di balik `Schema::hasTable`,
   dicatat eksplisit sebagai BUKAN dependensi kode).
2. Keluarga K3 sudah di Projects: `prj_safety_incidents` (K3), Laporan K3, dan
   `daily_reports.safety_notes` yang digantikan strukturnya. Satu prefix izin (`prj`),
   satu bagian panduan (§7.7).
3. Satu-satunya bagian Quality dari paket ini — checklist 5R — memang tetap di Quality:
   kolom `jenis` pada `qc_inspection_templates`, tanpa panah baru (Quality → Projects
   sudah ada dan tidak bertambah).
4. Nama tabel `prj_hse_daily` / `prj_risk_register` di roadmap adalah prefix-berdasar-
   asumsi, bukan ikatan; karena modulnya Projects, prefix `prj_` kebetulan tepat dan
   nama roadmap dipakai apa adanya.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.9 Form K3 harian (FM-10-13) — "`daily_reports.safety_notes`; tanpa APD/toolbox terstruktur" | 🟡 | ✅ | migrasi `2026_08_30_000742`; `HseDailyService` (tautan + baris); `HseDailyTest` (7); cetak F/K3H (`PrintableDocuments` entri `k3-harian`) |
| 3.9 BA Kecelakaan — "tanpa foto" | ✅ (minus foto) | ✅ | `AttachableDocuments` `projects/safety-incidents`; `ProjectPhotoController::sources`; `SafetyIncidentAttachmentTest` (2) |
| 3.9 HSE plan/IBPRP | ⬜ | ✅ | migrasi `2026_08_30_000743`; `RiskRegisterService` (skor aritmetika); `RiskLevel` (banding satu tempat); `RiskRegisterTest` (7); cetak F/IBPRP |
| 3.9 5R | ⬜ | ✅ | migrasi `2026_08_30_001440` (kolom `jenis`); `TemplateKindTest` (6); seeder template `5R1` |
| 3.9 Security log, register prosedur | ⬜ | ⬜ | **sengaja tidak dikerjakan** — roadmap §5 P6 tidak menyebutnya; paket terkecil, tidak digelembungkan |
| 3.9 Biaya SMKK di BoQ — "seksi BoQ manual, tanpa template" | 🟡 | 🟡 | milik P7 (`crm_rkk_documents`: biaya SMKK → baris BoQ) |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **Banding tingkat risiko TIGA pita, bukan empat.** Sumber yang sudah dikutip repo
  untuk K3 (Permen PUPR 10/2021 — `SafetyIncidentService`, migrasi 000780) memakai
  matriks 5×5 dengan keterangan **1–4 kecil · 5–12 sedang · 15–25 besar**. Pita keempat
  ("ekstrem") tidak dinyatakan peraturan itu, dan ambang yang tidak dinyatakan sumbernya
  adalah angka karangan — bila pemilik memakai matriks internal berpita empat,
  `RiskLevel::fromScore` adalah SATU tempat untuk mengubahnya.
- **Resolusi tautan FM-10-13 EAGER dua arah** (dipaku `HseDailyTest`): dicari saat
  formulir dibuat/diubah; laporan harian yang lahir belakangan menaut-balik; laporan
  pindah tanggal melepas/mengambil tautan; laporan dihapus melepasnya. `daily_report_id`
  dari payload DIBUANG — tautan adalah fakta turunan (proyek, tanggal).
- **APD per kategori = baris data** (kategori teks bebas + jumlah), bukan kolom per
  kategori: daftar APD kontraktor terbuka (helm, rompi, sepatu, harness, kacamata, …)
  dan FM-10-13 tiap perusahaan menyusunnya berbeda. Kategori tak tercatat tidak punya
  baris; F/K3H menggarisinya (bukan 0).
- **Penomoran**: FM-10-13 meniru DRP (dokumen harian): mask `HSE/{Y}/{M2}/{N4}`,
  `HasDocumentNumber`. Baris IBPRP TANPA kode — baris register, dicetak satu lembar per
  proyek (jangkar `project_id`, pola daftar-temuan), diidentifikasi urutan pada lembar.
- **Satu formulir K3 per proyek per hari** — indeks unik parsial baris hidup (pelajaran
  migrasi 000721), 422: *"Formulir K3 harian untuk proyek dan tanggal ini sudah ada."*
- **Risiko sisa berpasangan-atau-kosong**; separuh ditolak 422. Sisa yang belum dinilai
  = NULL tersimpan, sel bergaris di F/IBPRP.
- **HSE daily & baris IBPRP TIDAK Approvable** — mereka mencatat yang terjadi (alasan
  yang sama dengan `SafetyIncident`); `ApprovableDocuments` tidak disentuh.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

| Migrasi | Isi | MySQL dengan data lama |
|---|---|---|
| `2026_08_30_000742_create_prj_hse_daily_tables` | `prj_hse_daily` (header, FK in-module ke `prj_projects` & `prj_daily_reports`) + `prj_hse_daily_apd` + `prj_hse_daily_findings` | Tabel baru — aman. **Kecuali** indeks unik parsial `(project_id, report_date) WHERE deleted_at IS NULL` (DDL SQLite): MySQL harus meniru lewat kolom generated atau menerima indeks penuh — catatan yang sama dengan 000721, ditulis di migrasi |
| `2026_08_30_000743_create_prj_risk_register_table` | `prj_risk_register` (baris register, FK in-module ke `prj_projects`) | Tabel baru — aman |
| `2026_08_30_001440_add_jenis_column_to_qc_inspection_templates_table` | `qc_inspection_templates.jenis` string(20) default `'quality'` + backfill eksplisit | Aman: ADD COLUMN dengan default; baris lama menjadi `'quality'` — makna tak berubah (forward-only) |

Kolom lintas modul baru: `created_by` (users) pada kedua tabel — `unsignedBigInteger` +
index, tanpa constraint (kontrak §3). `config('erp.documents')` bertambah kunci `HSE`.

## Uji

- baru: **22** —
  `TemplateKindTest` (6: bawaan quality, buat+saring 5r via API, jenis asing ditolak,
  checklist 5R = inspeksi biasa dengan verdict turunan, guard template terisi tetap
  menolak tulis-ulang, update tanpa kunci jenis mempertahankan tersimpan);
  `HseDailyTest` (7: tautan saat laporan ada, tercatat tanpa laporan, id kiriman
  dibuang, laporan belakangan menaut-balik, satu-per-hari 422, APD baris data,
  cetak F/K3H + sel tautan bergaris);
  `RiskRegisterTest` (7: skor dihitung & klaim dibuang, banding di batas 1/4/5/12/15/25,
  batas 1–5, sisa separuh ditolak, sisa belum dinilai = NULL bukan 0, isolasi
  per proyek, cetak F/IBPRP hanya baris proyeknya);
  `SafetyIncidentAttachmentTest` (2: foto menempel + izin prj.update).
- lama yang diubah: **2** —
  `PrintCatalogueBespokeTest` paku katalog 56 → **58** (49+2=51 registri + 7 bespoke;
  alasan di komentar uji — P6 menambah `k3-harian` dan `ibprp`);
  `DocumentFormatValidationTest::SHIPPED_DOCUMENT_TYPES` 54 → **55** (+`HSE`) — paku ini
  jugalah yang menangkap bahwa mask baru wajib berlabel di
  `SettingService::DOCUMENT_LABELS` agar bisa disunting di layar Pengaturan
  (`'HSE' => 'Formulir K3 harian'` ditambahkan).
- suite penuh: **OK (3.498 uji, 15.885 asersi, 08:51)** — dari 3.475 pra-P6: +22 uji
  baru +1 baris data-provider (mask `HSE` ikut sapuan
  `test_every_shipped_document_format_satisfies_the_rule`).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Terhadap basis data scratch hasil `migrate:fresh --seed` (sqlite hidup tidak disentuh),
login `admin@nusantara.test`:

- `GET /api/projects/hse-daily` → `HSE/2026/03/0001 2026-03-25 -> DRP/2026/03/0001 | temuan: 1`
  (tautan ter-seed lewat resolusi yang sama dengan service).
- `GET /api/projects/risk-register` → tiga baris; `Pengecoran plat & balok lantai atas | 15 besar`,
  `Pengangkatan material dengan tower crane | 10 sedang`, `Pekerjaan pembesian | 6 sedang | sisa: None`.
- `GET /api/core/print/forms/ibprp/{project}` → judul `IDENTIFIKASI BAHAYA, PENILAIAN
  RISIKO DAN PENGENDALIAN (IBPRP)`, `Form F/IBPRP`, `Risiko besar` ×1 + `Risiko sedang` ×4.
- `GET /api/core/print/forms/k3-harian/{id}` → `FORMULIR K3 HARIAN (FM-10-13)`,
  `Form F/K3H`, baris `harness`, sel `DRP/2026/03/0001`.
- `GET /api/core/print/forms` → **58** baris; `k3-harian` dan `ibprp` termuat.
- `POST /api/core/attachments` (`projects/safety-incidents`, PNG asli) →
  `attached: titik-jatuh.png image/png`.
- Pesan 422 dipaku di uji (kata demi kata): *"Formulir K3 harian untuk proyek dan
  tanggal ini sudah ada."* · *"Risiko sisa dinilai lengkap: kemungkinan DAN keparahan,
  atau kosongkan keduanya."* · *"Kategori APD yang sama tercantum dua kali."* (rule
  distinct) · guard P1-QC lama tetap: *"Template ini sudah dipakai inspeksi yang
  terisi; …"*

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- `PANDUAN-PENGGUNA.md` §7.7 — paragraf temuan "Insiden K3 tidak bisa menampung foto"
  DIGANTI (kartu Lampiran + Galeri Proyek; foto lama tidak dipindahkan); dua layar baru
  didokumentasikan di §7.7 (Formulir K3 Harian, Register IBPRP) dengan pesan penolakan
  kata demi kata; §13.1 & §13.3 56 → 58 + blok "dua lembar P6"; §17.5 jenis checklist +
  cara mengisi 5R lewat layar QCI; tabel menu §1; "dua belas formulir mendatar" §13.1 →
  "lima belas" (angka 12 sudah basi sebelum P6 — deviasi baru #2).
- `PANDUAN-ADMINISTRATOR.md` §9 — 56 → 58; lanskap 14 → 15 (+F/IBPRP).
- README Modules: tidak ada modul baru — tidak ada baris baru.
- CONVENTIONS/ARCHITECTURE: tidak diubah (tidak ada panah baru; keputusan modul ditulis
  di migrasi 000742 dan laporan ini).

## Yang sengaja tidak dikerjakan, dan mengapa

- **Security log & register prosedur K3** (3.9 ⬜) — tidak disebut roadmap §5 P6; paket
  terkecil, DoD ≥8 uji, tidak digelembungkan.
- **Biaya SMKK / template BoQ** — milik P7 (`crm_rkk_documents`).
- **Mesin checklist 5R tersendiri** — sengaja: satu kolom `jenis`; butir, hasil,
  verdict, foto, guard, cetak F/QI semuanya mesin P1-QC yang sudah teruji.
- **Layar khusus 5R di SPA** — patroli diisi lewat layar Inspeksi Mutu (QCI) dengan
  template ber-jenis 5r; saringan Jenis di daftar template memisahkan pustakanya.
- **Approvable untuk HSE daily / IBPRP** — dokumen pencatat, bukan dokumen persetujuan.
- **Pita "ekstrem"** — lihat Asumsi.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **`projects/progress-measurements` (foto opname, P3) tidak termuat di
   `ProjectPhotoController::sources()`** — slug-nya attachable dan barisnya ber-
   `project_id` langsung, tetapi foto opname tidak muncul di Galeri Proyek DAN tidak
   disebut di daftar "Deliberately absent" docblock-nya (kontrak docblock itu: absen
   harus beralasan tertulis). Tidak disentuh P6 — bukan lingkup; satu baris + satu
   kalimat alasan untuk pemilik lane berikutnya.
2. **Hitungan "dua belas formulir mendatar" di PANDUAN-PENGGUNA §13.1 sudah basi
   sebelum P6** — PANDUAN-ADMINISTRATOR §9.3 menghitung empat belas untuk korpus yang
   sama. Keduanya kini lima belas (14 + F/IBPRP); sumber selisih lamanya tidak dilacak
   lebih jauh.
3. **Replay `php artisan db:seed` (tanpa fresh) pecah di `SubcontractDatabaseSeeder`
   sejak P4** — `seedLaborContract()` menghapus `scm_labor_contract_items` yang masih
   dirujuk FK baris opname mandor: `SQLSTATE[23000] … delete from
   "scm_labor_contract_items" where "labor_contract_id" = 1`. Pra-P6 (berkas terakhir
   disentuh commit P4 c5b37c2); `migrate:fresh --seed` bersih, dan kedua seeder P6
   terbukti idempoten pada replay langsung (hitungan stabil 1/4/1/3 + template `5R1`).
   Tidak disentuh — bukan lingkup.
