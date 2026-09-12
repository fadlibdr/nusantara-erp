# Berkas contoh resmi DJP & BPJS — `docs/samples/pajak/`

**Folder ini KOSONG pada 12 September 2026, dan itu disengaja.** Tidak ada satu pun
berkas di sini yang boleh ditulis oleh agen atau oleh aplikasi. Yang boleh ada di sini
hanya berkas yang **diunduh pemilik atau konsultan pajak dari portal resmi** DJP / BPJS
Ketenagakerjaan, diberi tanggal, dan dicatat di tabel §3.

Alasannya adalah prinsip P-3b (`docs/ROADMAP-HASHMICRO.md` Fase 3): **tidak mengarang tata
letak berkas DJP.** Selama folder ini kosong, registri format
`Modules/Finance/Support/DjpFormats.php` menjawab **"BELUM DIVERIFIKASI terhadap template
DJP"** untuk setiap format — di jawaban API `GET api/finance/tax-exports`, di lencana layar
**Keuangan › Ekspor Pajak**, dan di nama serta baris pertama setiap berkas yang diunduh.
Kalimat itu baru berubah ketika sebuah berkas benar-benar ada di sini DAN `verified_against`
pada registri menunjuknya (`DjpFormatsTest` menolak klaim tanpa berkas).

README ini **tidak memuat satu pun contoh kolom, contoh baris, atau cuplikan XML** — nama
kolom hanya ada di berkas resminya sendiri.

## 1. Konvensi nama berkas

```
<kunci-registri-dengan-strip>-<YYYY-MM-DD>.<ekstensi asli berkas resmi>
```

- `<kunci-registri-dengan-strip>` = kunci di `DjpFormats` dengan `_` diganti `-`
  (`efaktur_csv_legacy` → `efaktur-csv-legacy`).
- `<YYYY-MM-DD>` = **tanggal unduh**, bukan tanggal terbit peraturan.
- Ekstensi mengikuti berkas resmi apa adanya (`.csv`, `.xml`, `.xlsx`, …). Jangan
  mengonversi; berkas yang dikonversi bukan lagi berkas resmi.
- Bila portal memberi beberapa berkas (template + petunjuk pengisian), simpan keduanya dengan
  stem yang sama dan akhiran `-petunjuk`.
- Berkas lama TIDAK dihapus saat versi baru diunduh: registri menunjuk satu tanggal, dan
  riwayatnya menjelaskan mengapa sebuah masa dulu diekspor dengan tata letak yang berbeda.

## 2. Berkas yang harus diunduh — satu baris per kunci registri

| Kunci registri | Berkas yang ditunggu (stem) | Sumber resmi | Keadaan writer di aplikasi hari ini |
|---|---|---|---|
| `efaktur_csv_legacy` | `efaktur-csv-legacy-<YYYY-MM-DD>.<ekstensi asli>` — template impor CSV faktur keluaran aplikasi e-Faktur desktop (rekaman FK/LT/OF) | Aplikasi e-Faktur desktop DJP (menu impor faktur keluaran → unduh template/skema); portal `efaktur.pajak.go.id` | **Ada** (`TaxExportService::eFaktur`), disalin dari skema desktop yang lama — **belum diverifikasi** |
| `efaktur_coretax_xml` | `efaktur-coretax-xml-<YYYY-MM-DD>.<ekstensi asli>` — template impor XML faktur keluaran Coretax | Portal Coretax DJP (`coretaxdjp.pajak.go.id`) → menu impor faktur keluaran → unduh template | **Menunggu template** — tidak ada writer; parameter `format=coretax_xml` SENGAJA tidak ada |
| `ebupot_unifikasi_csv` | `ebupot-unifikasi-csv-<YYYY-MM-DD>.<ekstensi asli>` — template impor bukti potong e-Bupot Unifikasi (PPh 23, PPh final 4(2)) | Coretax DJP → e-Bupot Unifikasi → impor bukti potong → unduh template | **Ada** (`TaxExportService::eBupot`) — **belum diverifikasi**; kolomnya harus dicocokkan terhadap berkas ini |
| `ebupot_2126_bulanan` | `ebupot-2126-bulanan-<YYYY-MM-DD>.<ekstensi asli>` — template impor bukti potong PPh 21/26 bulanan (pegawai tetap) | Coretax DJP → e-Bupot 21/26 → impor → unduh template | **Menunggu template** — yang ada hanya **rekap internal** (`SDM & Payroll › Rekap PPh 21 Bulanan`) untuk diisi manual ke e-Bupot oleh petugas pajak; **bukan** berkas impor DJP |
| `sipp_bpjs` | `sipp-bpjs-<YYYY-MM-DD>.<ekstensi asli>` — template unggah data upah/iuran SIPP Online | SIPP Online BPJS Ketenagakerjaan (`sipp.bpjsketenagakerjaan.go.id`) → unggah data → unduh template | **Menunggu template** — tidak ada writer. Bila template resminya XLSX, penulisnya membutuhkan `phpoffice/phpspreadsheet` — **keputusan pemilik** (ROADMAP-HASHMICRO §5 baris 9), belum diputuskan |

Nama menu di portal berubah antar rilis; yang di tabel adalah petunjuk arah, bukan kutipan.
Jangan menuliskan jalur menu yang lebih rinci di sini — ia basi lebih cepat daripada
berkasnya.

## 3. Yang harus dilakukan sesudah berkas diletakkan

1. **Pemilik/konsultan** meletakkan berkasnya sesuai §1 dan menambah baris di tabel §4.
2. **Pengembang** mengisi `verified_against` (`path` + `date`) pada entri registri di
   `DjpFormats::entries()` — HANYA sesudah writer-nya dicocokkan kolom demi kolom terhadap
   berkas itu — lalu memperbarui `DjpFormatsTest` (uji "hari ini tidak satu pun format
   terverifikasi" memang dirancang merah pada hari itu).
3. **Konsultan pajak** mengimpor **satu masa nyata** ke **sandbox Coretax** (ledger #9) dan
   mencocokkan totalnya dengan layar Ekspor Pajak. Hasilnya (tanggal, masa, pesan importer)
   dicatat di tabel §4. Sebelum baris itu ada, `verified_against` tidak boleh diisi.
4. Sesudah itu barulah kalimat "BELUM DIVERIFIKASI" hilang dari layar, API, dan berkas —
   dengan sendirinya, dari registri; tidak ada kalimat yang disunting tangan di SPA.

## 4. Register verifikasi (diisi manusia, bukan oleh agen)

| Tanggal | Kunci | Berkas | Diunduh oleh | Diuji di sandbox oleh | Masa yang diuji | Hasil |
|---|---|---|---|---|---|---|
| — | — | (belum ada) | — | — | — | — |
