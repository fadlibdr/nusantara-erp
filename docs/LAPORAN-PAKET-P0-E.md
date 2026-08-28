# Laporan Paket P0-E — Dokumen vendor K3L & pakta integritas, dan gerbang kontrak

Branch: main (langsung; disiplin feat/<paket> mulai P1) · Commit: 561efae ·
28 Agustus 2026

> Laporan ini disusun-ulang 28 Agustus dari pesan commit, pohon kode, dan keluaran
> verifikasi adversarial paketnya — laporan §6 tidak sempat ditulis pada sesinya.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.5 Komitmen K3L vendor — "bisa dipaksa via `is_mandatory` tipe `lainnya`; tanpa tipe dokumen; kontrak tidak memeriksa" | 🟡 | ✅ dua tipe dokumen sendiri + gerbang submit SPK/PO untuk subkontraktor (wajib hadir & belum kedaluwarsa); override beralasan tetap tercap | `Modules/Procurement/Enums/VendorDocumentType.php:23–24`; `VendorQualificationService.php:66–121`; `tests/Feature/Procurement/VendorK3lGateTest.php` |
| 3.1 Pakta Integritas — sebagian | ⬜ | sebagian: pakta integritas kini jenis dokumen VENDOR yang diperiksa gerbang; pakta sisi penawaran tender tetap ⬜ (P7) | idem |
| Formulir F/K3V (template PERSYARATAN K3L untuk Vendor) | tidak ada | ✅ formulir rumah ke-41 — kop vendor, identitas, 14 garis kosong, blok tanda tangan; TANPA klausul karangan | `Modules/Core/Support/PrintableDocuments.php:1723` (`persyaratan-k3l-vendor`); `test_f_k3v_tercetak_bergaris_tanpa_klausul_karangan` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

Tidak ada asumsi bernomor Bagian 2 yang terpakai. Dua pembacaan sadar atas spek yang
perlu konfirmasi pemilik:

- Gerbang BUTA terhadap tanda `is_mandatory` — spek menulis "dokumen bertanda
  `is_mandatory` bertipe di atas"; yang dibangun: untuk subkon, komitmen K3L & pakta
  integritas wajib HADIR dan belum kedaluwarsa apa pun tandanya, dan baris TERSEGAR
  yang menang. Alasannya: orang yang mengirim pekerjanya ke site tanpa komitmen K3L
  bukan "register yang belum rapi". Filosofi lama ("absen bukan pelanggaran") tetap
  utuh untuk vendor non-subkon dan jenis dokumen lain.
- F/K3V tanpa klausul: pemilik belum menitipkan teks K3L — lembarnya judul, identitas
  vendor, 14 garis isian, dan blok tanda tangan; uji menegaskan kata
  wajib/dilarang/sanksi ABSEN. Menunggu teks dari `docs/` pemilik.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

Tidak ada migrasi. `prc_vendor_documents.doc_type` adalah string(30) tanpa CHECK —
`k3l_commitment` (14) dan `pakta_integritas` (16) muat; enum PHP
`VendorDocumentType` dan `enums.js` bertambah dua nilai dengan label yang sama.
Tidak ada pertanyaan MySQL.

## Uji

- baru: 10 — `VendorK3lGateTest`: subkon tanpa K3L ditolak di pintu SPK, tanpa pakta
  ditolak menyebut nama dokumennya, kedaluwarsa ditolak menyebut tanggal (dan hari
  terakhir `valid_until` = hari ini masih sah — batas setengah-terbuka), berdokumen
  lengkap lolos, vendor material murni tak tersentuh, override beralasan lolos dan
  tercap, F/K3V bergaris tanpa klausul, plus tiga uji penutup temuan verifikasi
  (K3L non-wajib kedaluwarsa tetap memblokir; lembar wajib-kedaluwarsa disebut SEKALI;
  pembaruan dengan baris basi tertinggal tetap lolos).
- lama yang diubah: 13 uji lama merah karena "vendor sehat" BERUBAH MAKNA, bukan karena
  cacat — ditutup lewat fixture: `SubcontractFixtures::makeVendor` kini menyemai kedua
  dokumen (opt-out `k3l_documents => false` untuk uji yang justru menguji
  ketiadaannya); `VendorQualificationTest` di-scope ke vendor non-subkon (tempat
  filosofi lamanya memang masih utuh) dan `SpkBudgetGateTest` disesuaikan — alasan
  tertulis di tiap doblok. `PrintCatalogueBespokeTest`: asersi katalog 40→41.
- suite penuh: OK (3.098 uji, 14.096 asersi; run verifikasi terekam 398 dtk pada
  3.095/14.091, sebelum tiga uji penutup temuan).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Tidak ada endpoint baru — gerbang menempel pada submit SPK
(`POST api/subcontract/subcontracts/{id}/submit`) dan submit PO
(`POST api/procurement/purchase-orders/{id}/submit`). Pesan 422 gabungan
`VendorNotQualifiedException`, dengan fragmen blocker baru:

> "Vendor {kode} ({nama}) belum lolos prakualifikasi: subkontraktor tanpa dokumen
> komitmen K3L — wajib ada sebelum SPK/PO diajukan. Sertakan alasan override
> (qualification_override_reason) bila tetap harus diajukan."

Fragmen untuk pakta yang absen: "subkontraktor tanpa dokumen pakta integritas — wajib
ada sebelum SPK/PO diajukan"; untuk yang kedaluwarsa: "dokumen komitmen K3L
kedaluwarsa sejak {dd-mm-yyyy}". Probe verifikasi mereproduksi keduanya di kedua
pintu; `valid_until` = HARI INI lolos, kemarin memblokir. Cetak: F/K3V lewat katalog
(`persyaratan-k3l-vendor`, izin `prc.view`; admin melihat 41 baris).

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

PANDUAN-PENGGUNA: §2.8 (tombol cetak F/K3V), §13.1, §13.3 (daftar menjadi "41
formulir"). PANDUAN-ADMINISTRATOR: daftar jenis dokumen vendor bertambah dua.
`enums.js` label. README, CONVENTIONS, ARCHITECTURE: tidak ada.

## Yang sengaja tidak dikerjakan, dan mengapa

- Mengarang klausul K3L pada F/K3V: panduan keselamatan karangan dipercaya orang
  persis di saat yang salah; lembar menunggu teks pemilik.
- Pakta integritas sisi tender/penawaran (3.1): milik P7 (paket tender).
- Vendor non-subkon: filosofi gerbang lama tidak diubah untuk mereka — penyempitan
  hanya untuk subkontraktor pada dua jenis dokumen ini.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

Verifikasi adversarial (11 konfirmasi), empat temuan — semuanya ditutup dengan uji:

1. Cabang kedaluwarsa klausul baru tidak revert-proof: `giveDocument()` uji selalu
   `is_mandatory=true` sehingga skenario kedaluwarsa selalu juga ditangkap klausul
   lama. Ditutup: `test_k3l_non_wajib_yang_kedaluwarsa_tetap_memblokir_subkon`.
2. Jangkar uji garis isian salah sasaran: regex `fill` hanya menangkap `fill-line` kop
   yang ada di SEMUA formulir — ujinya tidak bisa gagal. Ditutup: hitung persis 14
   `<div class="rule"></div>`, dengan riwayat temuannya di komentar uji.
3. Lembar K3L wajib-kedaluwarsa disebut DUA KALI dalam satu pesan (klausul lama + baru
   atas dokumen fisik yang sama). Ditutup: dua jenis subkon dikecualikan dari klausul
   wajib-kedaluwarsa lama; `test_k3l_wajib_kedaluwarsa_disebut_sekali_dalam_pesan`.
4. Subkon yang MEMPERBARUI K3L (baris segar ditambah, basi dibiarkan wajib) tetap
   terblokir baris basinya. Ditutup oleh pengecualian yang sama;
   `test_pembaruan_k3l_dengan_baris_basi_tertinggal_tetap_lolos`.

Masih terbuka (kosmetik, ditemukan verifikasi/backfill dan belum diperbaiki):

- Doblok dan nama metode `PrintCatalogueBespokeTest` masih berbunyi "40"/"forty"
  sementara asersinya sudah 41.
- Pesan commit 561efae menulis "Suite: 3066 -> 3098" — angka awal yang benar 3.088
  (pasca P0-D); angka akhirnya benar.
- README masih menyebut angka suite milik P0-G (2.995 uji / 13.610 asersi); belum
  dikejar sejak T4 padahal suite kini 3.098/14.096.
