# Impor Data Warisan (P8 — kriteria #10, D12)

Empat layout XLS warisan dari korpus pemilik dapat dimuat lewat layar
**Impor Dokumen** (`document-import`), memakai tata bahasa berkas yang sama
dengan impor penawaran/BOQ/AHSP/RAP: satu baris kepala per kolom `tipe`, satu
kolom grup `dokumen`, baris `abaikan` untuk subtotal/hiasan, dan satu dokumen
ditolak utuh bila satu barisnya tidak terbaca. Template terbaru selalu diunduh
dari layarnya (`GET api/core/document-import/{jenis}/template`) — tabel di
bawah memetakan **kolom lembar warisan** ke **kolom template**, karena lembar
lama tidak pernah seragam dan menyalin ulang ke template adalah langkah yang
memang diminta dari operator.

Dua aturan rumah berlaku dobel untuk data warisan:

1. **Forward-only.** Semua dokumen mendarat **draft** (laporan harian: baris
   hidup biasa — memang tidak pernah memposting apa pun). Tidak ada jurnal,
   mutasi stok, atau tagihan yang lahir dari sebuah unggahan; yang mengubah
   buku tetap persetujuan manusia di layar dokumennya.
2. **Penanda sumber.** Setiap dokumen yang mendarat dicap `import_source` =
   nama berkas yang diunggah, oleh mesin impor sendiri. Kolom itu tidak bisa
   diketik dari layar mana pun; `NULL` selalu berarti "dientri manusia".

Izin: masing-masing jenis menuntut `create` **dan** `update` modul pemiliknya
(prj / inv / scm), persis seperti impor dokumen lain.

---

## §1 Laporan Harian (`daily-reports`)

Lembar korpus: *Laporan Harian FM-10-12* (satu lembar per proyek per hari;
tabel tenaga per jabatan, alat, material masuk, material dipakai, dan kolom
cuaca/jam kerja di kepala lembar).

Satu laporan = satu baris `laporan` + baris rincian di bawahnya. Mendarat via
`DailyReportService` — semua aturan P0-A ikut: `manpower_count` diturunkan
dari rincian begitu baris `tenaga` ada (angka manual yang menyimpang ditolak
422), `qty_rejected ≤ qty_received`, `work_end > work_start`, satu laporan per
(proyek, tanggal) — duplikat ditolak dengan menyebut kode laporan yang sudah
ada.

| Lembar warisan | Kolom template | Baris `tipe` | Catatan |
|---|---|---|---|
| Nama proyek (kop) | `proyek_kode` | `laporan` | kode proyek, bukan nama; tak dikenal = ditolak |
| Tanggal | `tanggal` | `laporan` | `dd/mm/yyyy` |
| Cuaca pagi / sore | `cuaca_pagi`, `cuaca_sore` | `laporan` | cerah / mendung / hujan (sinonim: berawan, gerimis) |
| Jam kerja | `jam_mulai`, `jam_selesai` | `laporan` | `HH:MM` |
| Jumlah tenaga (angka tunggal lembar lama) | `jumlah_tenaga` | `laporan` | hanya bila TIDAK ada rincian per jabatan |
| Uraian pekerjaan (teks bebas) | `kegiatan` | `laporan` | layout warisan memang satu rangkuman bebas |
| Hambatan | `kendala` | `laporan` | |
| Catatan K3 | `catatan_k3` | `laporan` | |
| Tabel tenaga: jabatan, jumlah | `jabatan`, `jumlah_orang`, `keterangan` | `tenaga` | jabatan memakai kata pad: mandor sipil, produksi, petugas k3, … |
| Tabel alat: uraian, jumlah, jam | `uraian`, `jumlah_alat`, `jam_operasi` | `alat` | |
| Tabel material masuk | `uraian`, `volume_diterima`, `volume_ditolak`, `satuan`, `alasan_tolak` | `material_masuk` | penerimaan tercatat sebagai teks laporan, TIDAK menambah stok |
| Tabel material dipakai | `item_kode`, `volume`, `satuan` | `material_dipakai` | item gudang per kode; TIDAK memotong stok — bon gudang tetap dokumennya sendiri |

**Tidak diimpor:** foto (unggah lewat kartu lampiran laporan), tabel uraian
per-WBS (fitur P0-A; lembar warisan tidak memilikinya — teks bebasnya masuk
`kegiatan`).

## §2 Kartu Stok (`stock-cards`)

Lembar korpus: *Stock Card / Kartu Gudang* (satu kartu per item per gudang;
baris mutasi tanggal, masuk, keluar, saldo).

**Keputusan pemetaan yang disengaja:** baris mutasi kartu lama **tidak
diputar ulang**. Memutar ulang mutasi berarti memposting jurnal dan HPP ke
periode yang sudah tutup — persis yang dilarang forward-only. Yang dibawa
adalah **saldo penutup** tiap kartu, dan seluruh kartu satu gudang menjadi
**satu stock opname (ADJ) draft**: qty hitung = saldo penutup kartunya.
Stok dan jurnal selisih baru bergerak saat opname itu disetujui dan diposting
orang gudang dari layarnya — sejak saat itulah kartu stok ERP dimulai; sejarah
sebelumnya tinggal di kertas kartunya.

| Lembar warisan | Kolom template | Baris `tipe` | Catatan |
|---|---|---|---|
| Nama gudang (kop kartu) | `gudang_kode` | `kartu` | satu dokumen = satu gudang + satu tanggal saldo |
| Tanggal saldo penutup | `tanggal` | `kartu` | tanggal baris terakhir kartu |
| — | `alasan` | `kartu` | kosongkan (= opname) |
| Keterangan | `catatan` | `kartu` | mis. "Saldo penutup kartu manual Juni 2026" |
| Kode/nama item (kop kartu) | `item_kode` | `saldo` | kode item ERP (Impor Master Data untuk membuat item) |
| Saldo akhir kartu | `saldo_akhir` | `saldo` | desimal koma (80,5) |

**Tidak diimpor:** kolom mutasi masuk/keluar/tanggal per baris kartu, harga
satuan kartu lama (HPP rata-rata dihitung ERP dari transaksi hidup sesudahnya).

## §3 Opname/SP3 Mandor (`sp3`)

Lembar korpus: *Opname SP3 / SPK Mandor* (kepala SP3: mandor, proyek, judul;
baris pekerjaan: volume kontrak × tarif upah; kolom opname kumulatif s/d lalu
dan saat ini).

**Keputusan pemetaan yang disengaja:** yang diimpor adalah **SP3 Induknya**
(`scm_labor_contracts`, draft). Kolom opname kumulatif lembar lama **tidak
diimpor** — opname mandor (OPM) harus disusun di aplikasi atas SP3 hidup yang
sudah disetujui, supaya plafon volume per baris ditegakkan pada data hidup dan
tidak lahir klaim yang menghitung ulang sejarah kertas. Urutannya untuk
migrasi: impor SP3 → periksa → setujui (maker-checker) → susun opname baru
dari sisa volume.

Semua aturan service ikut: vendor harus bertipe **mandor**, gate kualifikasi
K3L/pakta ditagih (kolom `alasan_override_kualifikasi` adalah jalan darurat
yang tercatat), nilai SP3 = Σ baris, tarif PPh final UMKM di-snapshot service
(bukan dari berkas), `pph21_ter` ditolak "belum diaktifkan".

| Lembar warisan | Kolom template | Baris `tipe` | Catatan |
|---|---|---|---|
| Nama mandor | `mandor_kode` | `sp3` | kode vendor bertipe mandor |
| Proyek | `proyek_kode` | `sp3` | |
| Judul / paket pekerjaan | `judul` | `sp3` | |
| Skema PPh | `skema_pph` | `sp3` | kosong = PPh final UMKM (asumsi #3) |
| Periode | `tanggal_mulai`, `tanggal_selesai` | `sp3` | |
| — | `alasan_override_kualifikasi` | `sp3` | isi bila dokumen kualifikasi mandor belum ada di ERP |
| Baris: uraian, WBS, volume, satuan, tarif | `uraian`, `wbs`, `volume`, `satuan`, `tarif_upah` | `item` | `jumlah` = kolom pemeriksa (tidak disimpan) |

**Tidak diimpor:** kolom opname s/d lalu & saat ini, potongan kasbon, nilai
PPN/PPh (semua milik dokumen hidup sesudah SP3 disetujui).

## §4 Progress Pay (`progress-pay`)

Lembar korpus: *Progress Payment / Opname ke Owner* (volume terpasang per item
BoQ untuk satu periode, menuju berita acara pembayaran).

Mendarat sebagai **opname progres ke pemilik (OPN) draft** via
`MeasurementService`: hanya **volume periode ini** per item yang dibawa
berkas; kumulatif s/d lalu (`qty_prev`), harga, nilai opname, dan plafon
volume (BOQ + CCO disetujui) dihitung service dari data hidup. Tagihan ke
pemilik baru bisa lahir setelah opname disetujui — impor tidak pernah membuat
piutang.

| Lembar warisan | Kolom template | Baris `tipe` | Catatan |
|---|---|---|---|
| Proyek (kop) | `proyek_kode` | `opname` | |
| — | `boq_kode` | `opname` | kode BOQ kontraknya; jangkar pencarian `item_boq` (A.1 berarti hal berbeda di tiap BOQ) |
| Periode | `periode_mulai`, `periode_selesai` | `opname` | |
| No. item BoQ | `item_boq` | `item` | nomor WBS baris BOQ |
| Volume periode ini | `volume_ini` | `item` | boleh negatif (koreksi); kumulatif tak boleh < 0 |
| Keterangan baris | `keterangan` | `item` | |

**Tidak diimpor:** kolom kumulatif s/d lalu, bobot %, harga satuan, nilai
rupiah (dihitung dari BOQ kontrak yang hidup), retensi/uang muka (milik
tagihan).

---

## Yang berlaku untuk keempatnya

- Dokumen yang sudah lewat draft **tidak bisa ditimpa** impor ("berstatus …
  dan tidak dapat diperbarui") — aturan mesin impor, bukan pengecualian
  warisan.
- Kolom `jumlah` (bila ada) tidak pernah disimpan: ia pemeriksa pembacaan
  angka, dan selisih di atas pembulatan menolak barisnya.
- Sel kosong pada dokumen yang SUDAH ada tidak mengubah nilai tersimpan;
  kolom yang tidak ada di berkas tidak pernah ditulis.
- Nilai uang/volume memakai format Indonesia (1.500.000 / 80,5) dan **harus
  berupa teks atau angka polos** — lembar yang selnya sudah bertipe angka
  Excel juga terbaca benar.
