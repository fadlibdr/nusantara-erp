# Laporan Paket P2 — Tata kelola pengadaan
Branch: feat/p2 · Commit: (diisi saat merge) · 2026-08-28

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.5 baris skor tender | ⬜ manual/absen | ✅ berbobot terhitung | `Modules/Procurement/Services/BidEvaluationService.php` (hargaScore + rank); `tests/Feature/Procurement/BidEvaluationTest.php` |
| 3.5 risalah nego | ⬜ | ✅ BAN | `prc_negotiation_minutes`; `NegotiationMinuteTest` |
| 3.5 keputusan pemenang | ⬜ | ✅ AWD Approvable n-level | `Modules/Procurement/Services/AwardDecisionService.php`; `AwardDecisionTest` |
| 3.5 rencana (Pola Belanja) | ⬜ | ✅ RPB dari RAP | `prc_procurement_plans`; `ProcurementPlanTest` |
| D3 tabulasi berbobot | ⬜ | ✅ | F/TBP diperluas — `PrintableDocuments::procurement() banding-penawaran` (bidEvaluations, rank) |
| Kriteria #4 | ⬜ | ✅ dibuktikan dua arah | `AwardDecisionService::assertApprovedAward`:128; award nilai berubah tanpa BAN → 422 |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **Skor harga DAN 4.8:** tabel rasio→skor milik pemilik tidak dapat diverifikasi dari
  korpus
  publik, jadi dipakai **fallback linear terdokumentasi** (rumus sistem nilai LKPP:
  `nilai = pembanding/penawaran × 100`, berpatokan RAB/HPS, dibatasi 100) dengan komentar
  sumber
  di `BidEvaluationService::hargaScore`. Ganti isi method bila tabel DAN 4.8 asli muncul;
  pemanggil
  tidak berubah. **PERLU KONFIRMASI PEMILIK.**
- **Kode award = AWD** (bukan BAP), sengaja menghindari bentrok dengan BAPP (P3,
  prj_zone_certificates).
- **deviation_reason wajib hanya saat awarded_amount > rab_amount** (memutuskan DI ATAS
  nilai wajar
  harus dipertanggungjawabkan; di bawah RAB adalah penghematan, tidak butuh alasan).

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

- Blok Procurement 000861–000866: `prc_bid_evaluations`, `prc_negotiation_minutes`+`_items`,
  `prc_award_decisions`, `prc_procurement_plans`+`_items` — CREATE TABLE murni, aman.
- Blok Subcontract 000961: `scm_subcontracts.rfq_id` nullable unsignedBigInteger+index tanpa
  constraint — ADD COLUMN nullable, aman; inert (null) untuk setiap SPK lama.
- Semua rujukan lintas modul unsignedBigInteger+index tanpa `constrained()` (CONVENTIONS
  §3).
- `config/erp.php`: `approvals.award_decision.ladder` ditambah (kunci PO/SPK lama TAK
  diubah);
  `procurement.bid_weights` ditambah; **satu blok `procurement` duplikat pra-P2 dihapus**
  (PHP hanya
  menyimpan kemunculan terakhir — blok pertama diam-diam terbuang; footgun laten ditutup,
  nilai
  identik jadi tanpa perubahan perilaku).

## Uji
- baru: ~41 (BidEvaluationTest, NegotiationMinuteTest, AwardDecisionTest,
  ProcurementPlanTest,
  ApprovalLevelsTest / n-level ladder di batas, BidWeights boot-refusal, Criterion #4 dua
  arah)
- lama yang diubah: PurchaseOrderDirectorApprovalTest / SubcontractDirectorApprovalTest
  tetap
  HIJAU tanpa perubahan — PO/SPK mempertahankan mekanisme dua-level lamanya; hanya
  AwardDecision
  yang menaiki tangga penghitung. Pin DocumentFormatValidationTest 44→47.
- suite penuh: OK (3.270 uji, 14.965 asersi, ~8 menit) — dari 3.229 baseline P1-QC.

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

- Kriteria #4(a), PO/SPK dari RFQ tanpa award disetujui:
  > `{DOK} {KODE} berasal dari RFQ {N} namun keputusan pemenang (award) untuk vendor ini belum
  > ada
  > atau belum disetujui; terbitkan dan setujui keputusan pemenang dulu sebelum menyetujui
  > {DOK}.`
- deviasi di atas RAB tanpa alasan:
  > `Nilai keputusan melampaui RAB; alasan deviasi (deviation_reason) wajib diisi karena
  > memutuskan
  > di atas nilai wajar harus dapat dipertanggungjawabkan.`
- bid_weights boot: konfigurasi berbobot ≠ 100 melempar `BidWeightConfigException` saat boot
  (`ProcurementServiceProvider::boot` → `BidWeights::assertValidConfig`), tabulasi tidak
  pernah
  tercetak dengan bobot salah.

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- CONVENTIONS §2: (tidak ada baris modul baru — P2 memperluas Procurement, bukan modul
  baru).
- PANDUAN-PENGGUNA / PANDUAN-ADMINISTRATOR: bab pengadaan (skor berbobot, nego, award,
  tangga
  persetujuan n-level; dua penolakan kriteria #4 kata-demi-kata) diperbarui oleh lane docs;
  hitungan formulir 48→50.

## Yang sengaja tidak dikerjakan, dan mengapa

- **PO/SPK TIDAK dipindahkan ke tangga penghitung.** Keduanya mempertahankan mekanisme
  `needs_director_approval` + `DirectorApproval` yang sudah teraudit dan hijau: menulis
  ulang
  gerbang itu ke semantik penghitung akan memaksa panggilan approve() kedua pada setiap
  PO/SPK
  ≥100/200 juta dan merah-kan hampir semua uji persetujuan lama tanpa keuntungan perilaku.
  Mereka
  ADALAH tangga degenerate [<ambang→1, ≥ambang→2]; hanya AwardDecision yang baru yang
  menaikinya.
- **Boot check bid_weights** dilempar sebagai RuntimeException, bukan divalidasi lunak:
  bobot salah
  adalah kesalahan konfigurasi yang harus menghentikan boot, bukan diam-diam menghasilkan
  peringkat yang salah pada lembar bertanda tangan.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

- **Blok `procurement` duplikat di `config/erp.php`** (pra-P2, ditemukan verifikasi): dua
  kunci
  larik tingkat-atas dengan nama sama; PHP membuang yang pertama diam-diam. Resolusi benar
  hari
  ini tetapi footgun laten — blok pertama yang berlebih **dihapus** di paket ini.
