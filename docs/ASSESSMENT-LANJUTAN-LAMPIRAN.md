# Lampiran Asesmen Lanjutan — 81 Temuan Lengkap

Berkas pendamping [ASSESSMENT-LANJUTAN.md](ASSESSMENT-LANJUTAN.md): seluruh
temuan mentah dari lima auditor + kritikus, dengan bukti `berkas:baris`,
dampak, dan estimasi usaha apa adanya. Diurutkan menurut keparahan.

## 1. 🔴 Form / Lookup picker

**Temuan.** Seluruh 93 field bertipe 'lookup' di schema.js (plus 8 kolom baris item_id pada line-item PR/PO/GRN/opname/AHSP dan semua filter lookup) dirender sebagai <select> polos tanpa type-ahead. Untuk mengisinya, lookup.js memuat SELURUH isi sumber (500 baris per halaman, sampai plafon 10.000 baris) ke dalam DOM.

**Bukti.** public/app/js/views/form.js:81-98 (case 'lookup' = el('select')); public/app/js/lookup.js:41-46,61-113 (fetchAllPages, PAGE_SIZE 500, MAX_PAGES 20); grep "type: 'lookup'" schema.js = 93 hit; 8 kolom baris "key: 'item_id', type: 'lookup'"

**Dampak.** Memilih 1 item dari katalog ribuan baris pada setiap baris PO/GRN berarti scroll manual di dropdown native — lambat, salah pilih item bernomor mirip, dan form berat dimuat karena semua sumber di-preload. Ini layar yang dipakai staf pengadaan/gudang berkali-kali sehari.

**Usaha.** 3-4 hari (satu komponen combobox ber-filter teks menggantikan case 'lookup' di buildInput, dipakai otomatis oleh semua form) — **Rekomendasi.** Buat combobox ringan (input + daftar terfilter, keyboard-navigable) di ui.js dan ganti case 'lookup' + filter lookup di list.js; pertahankan cache lookup yang sudah ada.

## 2. 🔴 Form / Proteksi data belum tersimpan

**Temuan.** Modal form tertutup lewat tombol Escape, klik backdrop, atau tombol X tanpa konfirmasi apa pun; tidak ada dirty-check dan tidak ada handler beforeunload. openForm tidak membedakan form kosong dari form yang sudah diisi 20 baris.

**Bukti.** public/app/js/ui.js:154-192 (close() langsung; onKey Escape → close; host.onclick backdrop → close); public/app/js/views/form.js:342-347 (footer 'Batal' → dialog.close() tanpa cek)

**Dampak.** Voucher jurnal atau PO dengan belasan baris hilang total oleh satu ketukan Esc atau satu klik meleset ke backdrop — kejadian yang hampir pasti terjadi setiap minggu pada entry data intensif.

**Usaha.** 1 hari (flag dirty pada openForm/promptFields + confirmDialog sebelum close; opsional beforeunload) — **Rekomendasi.** Tandai form dirty pada event input pertama; intercept Escape/backdrop/X dengan confirmDialog 'Buang perubahan?'.

## 3. 🔴 Milestone → Penagihan (handoff PM ke Finance)

**Temuan.** Milestone 'syarat penagihan' yang tercapai tidak memicu apa pun: tidak ada notifikasi, tidak ada antrean penagihan, tidak ada kartu dasbor. MilestoneController::update hanya mengisi achieved_date; satu-satunya listener notifikasi adalah DocumentTransitioned→SendApprovalNotifications (approval dokumen), dan milestone bukan dokumen ber-workflow. Dasbor finance hanya menampilkan dokumen status 'submitted' dan piutang jatuh tempo — tidak ada widget 'termin siap ditagih'.

**Bukti.** Modules/Projects/Http/Controllers/MilestoneController.php:38-43 (update polos tanpa event); Modules/Core/Providers/CoreServiceProvider.php:93 (satu-satunya event listener); public/app/js/views/dashboard.js:128-166,193-217 (inbox approval + piutang saja). Bukti hidup di data demo: prj_milestones id=1 'Progres fisik 50% — syarat penagihan Termin 2' achieved_date=2026-03-27 terkait termin_id=2 (Rp 14.550.000.000), namun crm_contract_termins id=2 masih billed_at=NULL per 31-07-2026 dan fin_ar_invoices hanya berisi invoice DP — 4 bulan pekerjaan yang sudah berhak ditagih tidak tertagih.

**Dampak.** Uang tertahan di lapangan: Rp 14,55 miliar pekerjaan yang syarat penagihannya sudah terpenuhi tidak masuk proses tagih selama 4 bulan. Dengan term pembayaran 30 hari, setiap bulan keterlambatan menerbitkan invoice adalah sebulan tambahan pembiayaan proyek dari kas sendiri. Handoff PM→Finance sepenuhnya bergantung komunikasi di luar sistem.

**Usaha.** 1-2 hari: event MilestoneAchieved → notifikasi ke pemegang fin.create + kartu dasbor 'Termin siap ditagih' (milestone tercapai & termin belum billed). — **Rekomendasi.** Saat achieved_date terisi dan termin_id ada: kirim NotificationService::system('fin.create', ...) dengan tautan buat-invoice, dan tambahkan kartu dasbor yang mengelist termin siap tagih beserta nilainya.

## 4. 🔴 Penerimaan termin — pajak dipotong pemberi kerja (PPh final konstruksi & PPN wapu)

**Temuan.** Sistem tidak bisa mencatat pembayaran pelanggan yang datang neto pajak. Alokasi penerimaan (RCV) hanya boleh ke ar_invoice dan WAJIB berjumlah sama persis dengan uang masuk — tidak ada baris potongan pajak; fin_ar_invoices tidak punya kolom PPh; akun 1-1700 'Pajak Dibayar Dimuka PPh' di-seed di CoA tetapi tidak pernah dipakai satu baris kode pun; konsep PPN wapu/pemungut tidak ada sama sekali.

**Bukti.** Modules/Finance/Services/PaymentService.php:88-127 (alokasi in hanya TYPE_AR_INVOICE, guard 'Allocations must sum to the payment amount') dan :162-197 (settleInvoice hanya Cr 1-1300); Modules/Finance/Database/Migrations/2026_07_25_001140_create_fin_ar_invoices_table.php:23-30 (kolom pajak hanya ppn_rate/ppn_amount); Modules/Finance/Database/Seeders/ChartOfAccountsSeeder.php:37 + grep '1-1700' di Modules = hanya seeder; grep -i 'wapu|pemungut|bukti potong' sisi AR = nol (TaxExportService.php:17 e-Bupot hanya PPh yang KITA potong dari AP); seeder demo pun berpura-pura pelanggan membayar gross penuh (FinanceDatabaseSeeder.php:245-266).

**Dampak.** Hampir semua pelanggan perusahaan ini adalah badan usaha/instansi yang wajib memotong PPh final jasa konstruksi (PP 9/2022, 1,75%-6% dari DPP) saat membayar — dan pemilik BUMN/pemerintah juga memungut sendiri PPN-nya (wapu). Artinya SETIAP penerimaan termin riil tidak akan pernah bisa dialokasikan: untuk termin 2 kontrak 1 (DPP Rp 14,55 M) selisih PPh final 2,65% saja ≈ Rp 385 juta. Invoice menggantung 'kurang bayar' selamanya, umur piutang menyesatkan, kredit/beban PPh final tidak tercatat, dan rekonsiliasi bank tidak pernah ketemu — persis di proses tempat uang masuk.

**Usaha.** 1-2 hari: baris potongan pajak pada alokasi RCV (Dr 1-1700/beban PPh final + arsip no. bukti potong) dan flag wapu pada invoice yang mengeluarkan porsi PPN dari piutang. — **Rekomendasi.** Tambah tipe alokasi 'potongan pajak' pada posting RCV (whitelist akun: 1-1700, 2-1300-wapu) sehingga uang masuk neto tetap melunasi invoice penuh dengan jejak bukti potong per pembayaran.

## 5. 🔴 Procure-to-Pay — three-way match

**Temuan.** GRN tidak pernah terikat ke baris PO: form GRN di SPA tidak punya kolom po_item_id (schema.js:1126-1135 hanya item/qty/harga), dan seeder pun menulis po_item_id=null. Akibatnya PoService::registerReceipt tidak pernah terpanggil — qty_received di semua baris PO tetap 0 (dibuktikan di DB demo: 0 dari 5 baris PO, 0 dari 7 baris GRN), PO tidak pernah auto-close, guard over-receipt (PoService.php:167-171) tidak pernah aktif, dan gate ApBillService::assertStockCommitmentSettled (ApBillService.php:1092-1103) menolak SETIAP tagihan final PO barang sampai PO ditutup manual.

**Bukti.** public/app/js/schema.js:1126-1135 (form GRN tanpa po_item_id; grep po_item_id di public/app/js = nol); Modules/Inventory/Database/Seeders/InventoryDatabaseSeeder.php:399; Modules/Procurement/Services/PoService.php:150-185; Modules/Finance/Services/ApBillService.php:1092-1103; DB demo: prc_purchase_order_items.qty_received>0 = 0 baris.

**Dampak.** Kecocokan kuantitas dalam three-way match mati dalam praktik: kolom 'Diterima' di detail PO selalu 0, penerimaan melebihi pesanan tidak tertahan, dan alur harian pembelian barang macet di tagihan final — staf terpaksa menutup PO manual setiap kali, yang sekaligus menghapus disiplin penutupan PO.

**Usaha.** 1-2 hari (aksi 'salin baris dari PO' di form GRN yang membawa po_item_id + prefill harga) — **Rekomendasi.** Saat PO dipilih di header GRN, muat baris PO otomatis (item, sisa qty, harga PO) dan kirim po_item_id per baris; sembunyikan input item bebas bila PO dipilih.

## 6. 🔴 Retensi AR (pencairan)

**Temuan.** Layar pencairan retensi pelanggan tidak ada di SPA. Endpoint GET finance/ar-retentions (daftar outstanding + flag is_due dari BAST) dan POST ar-retentions/{id}/release sudah dibangun lengkap di backend, tetapi tidak satu file pun di public/app merujuknya — tidak ada resource di schema.js, tidak ada menu, tidak ada laporan. Laporan keuangan yang tersedia hanya neraca saldo, laba rugi, neraca, umur piutang/hutang, dan profitabilitas proyek.

**Bukti.** Modules/Finance/Routes/api.php:65-66 (rute ada); Modules/Finance/Services/ArRetentionService.php:46-92 (outstanding + is_due dihitung tapi tak pernah ditampilkan); grep 'ar-retentions' di seluruh public/ = nol hasil; public/app/js/views/reports.js:10-15 (daftar laporan tanpa retensi). Bandingkan: retensi subkon punya UI (public/app/js/views/custom.js:396-424).

**Dampak.** Langkah 'retensi cair' dalam quote-to-cash tidak bisa dijalankan dari aplikasi — finance tidak melihat retensi mana yang sudah jatuh tempo (BAST II lewat) dan pencatatan pencairan harus lewat API manual. Persis masalah yang didokumentasikan di docblock service-nya ('nobody chased it') terulang di lapisan UI: saldo 1-1350 tak tertagih tanpa ada yang menagih.

**Usaha.** 1 hari: layar daftar retensi outstanding (total, jatuh tempo, is_due merah) + tombol 'Catat pencairan' yang memanggil endpoint release. — **Rekomendasi.** Tambah entri nav Keuangan → Piutang Retensi memakai endpoint yang sudah ada; tampilkan due_now di dasbor finance.

## 7. 🔴 Segregation of duties

**Temuan.** Satu user role 'finance' bisa membuat, menyetujui, DAN memposting dokumen yang sama: role tersebut memegang seluruh aksi fin (termasuk approve dan post), tidak ada guard approver ≠ pembuat, dan Pembayaran bahkan tidak punya tahap approval sama sekali (draft langsung post). Role 'hr' sama untuk payroll — approve oleh HR langsung memposting beban gaji ke GL.

**Bukti.** Modules/Iam/Database/Seeders/RoleSeeder.php:56-59 (finance = semua aksi fin) dan :61-64 (hr = semua aksi hr); Modules/Core/Traits/Approvable.php:33-41 approve() tanpa cek self-approval; Modules/Finance/Routes/api.php:76-81 pembayaran hanya store→post tanpa submit/approve; PaymentService.php:42,153 status hanya Draft→Posted; PayrollRunController.php:113 memposting GL di dalam approve; threshold dua tingkat hanya untuk PO/subkontrak (Modules/Core/Services/SettingService.php:220-226).

**Dampak.** Jalur fraud kas satu orang: staf finance membuat tagihan AP fiktif, submit, menyetujui sendiri, lalu membuat dan memposting pembayaran ke rekening mana pun — tanpa satu pun mata kedua. Jejak audit merekamnya setelah kejadian, tetapi tidak ada kontrol preventif. Untuk auditor eksternal ini temuan pengendalian internal material.

**Usaha.** 1-2 hari (guard self-approval di trait Approvable + tahap approval pembayaran keluar + pisahkan fin.approve dari role finance di seeder) — **Rekomendasi.** Tolak approve bila approver = user yang submit/membuat; tambahkan submit→approve pada pembayaran keluar (minimal di atas nominal tertentu); keluarkan fin.approve dan hr.approve dari role pelaksana — biarkan di direktur sesuai desain yang sudah ada.

## 8. 🔴 Subkontrak — retensi

**Temuan.** Pelepasan retensi subkon hanya mencatat baris scm_retention_releases — tanpa jurnal (2-1500 Hutang Retensi Subkon tidak pernah didebit oleh dokumen apa pun; grep menunjukkan 2-1500 hanya dirujuk ApBillService sisi kredit dan seeder) dan tanpa dokumen hutang/pembayaran. PaymentService hanya bisa mengalokasikan ke ar_invoice/ap_bill (PaymentService.php:88-91), jadi uang retensi yang dilepas tidak bisa dibayarkan lewat sistem.

**Bukti.** Modules/Subcontract/Services/RetentionService.php:37-66 (release = create row saja); Modules/Subcontract/Http/Controllers/SubcontractController.php:137-146; Modules/Finance/Services/PaymentService.php:88-91,203-239; saldo 2-1500 dikredit di ApBillService.php:464-468 tanpa jalur debit.

**Dampak.** GL dan modul Subkontrak langsung berbeda begitu retensi dilepas: buku besar menyatakan retensi masih terutang selamanya (2-1500 menumpuk), sementara laporan SPK bilang sudah dilepas; pembayaran fisiknya harus lewat jurnal manual + transfer di luar alur, merusak rekonsiliasi bank dan jejak audit. Ini separuh yang belum selesai dari perbaikan retensi dua arah.

**Usaha.** 1-2 hari (release membuat AP bill retensi: Dr 2-1500 / Cr 2-1100, lalu dibayar via PaymentService) — **Rekomendasi.** Jadikan retention release menghasilkan tagihan AP khusus (tanpa PPN/PPh baru) sehingga alur bayar dan GL mengikuti mesin yang sudah ada.

## 9. 🔴 Tutup buku — kalender fiskal tahun baru

**Temuan.** Kalender fiskal hanya dibuat untuk tahun berjalan saat seeding; tidak ada mekanisme (UI, endpoint, command terjadwal) yang membuat periode tahun berikutnya.

**Bukti.** Modules/Finance/Database/Seeders/ProductionFiscalPeriodSeeder.php:20-27 hanya loop range(1,12) untuk now()->year; JournalService.php:168-170 melempar 'No fiscal period exists for the journal date; create it first' bila baris periode tidak ada; grep FiscalPeriod di seluruh Console/Commands hanya mengenai pembacaan di InventoryMethodCheck.php:428-436.

**Dampak.** Pada 1 Januari 2027 semua aksi yang memposting jurnal gagal serentak — approve invoice termin, approve tagihan vendor, posting pembayaran, approve payroll, posting penyusutan, posting run PSAK 115 — sampai seseorang membuat baris periode lewat tinker/seeder di server. Operasi keuangan berhenti tepat di hari sibuk awal tahun.

**Usaha.** 2-4 jam (auto-create periode 'open' saat pertama dibutuhkan, atau command terjadwal tahunan) — **Rekomendasi.** Buat periode baru otomatis berstatus open saat forDate() tidak menemukannya (dengan batas wajar), atau minimal sediakan tombol 'Buat kalender {tahun}' di layar periode fiskal.

## 10. 🔴 Tutup buku — periode fiskal

**Temuan.** Tidak ada mekanisme menutup (atau membuka kembali) periode fiskal sama sekali: tidak ada rute API, tidak ada layar, tidak ada artisan command. Satu-satunya konsumen status periode adalah guard posting jurnal.

**Bukti.** Modules/Finance/Routes/api.php:19-125 tidak memuat rute fiscal-periods; grep 'FiscalPeriod' hanya mengenai JournalService::assertPeriodOpen (Modules/Finance/Services/JournalService.php:164-175); komentar Modules/Finance/Database/Seeders/ProductionFiscalPeriodSeeder.php:12-13 menyebut periode 'closed through the API' padahal API itu tidak ada; grep 'fiskal' di public/app/js kosong; satu-satunya periode tertutup (Jan 2026) ditutup oleh seeder demo (FiscalPeriodSeeder.php:22-24).

**Dampak.** Semua periode tetap terbuka sepanjang tahun di produksi — siapa pun dengan fin.create+fin.post bisa mem-backdate jurnal/dokumen ke bulan yang laporannya sudah diterbitkan ke manajemen/bank/pajak, mengubah angka yang sudah dilaporkan tanpa jejak restatement. Guard periode yang sudah dibangun rapi di JournalService praktis tidak pernah aktif.

**Usaha.** 1-2 hari (CRUD + endpoint close/reopen dengan permission fin.post + layar daftar periode) — **Rekomendasi.** Tambahkan resource fiscal-periods dengan aksi tutup/buka (permission terpisah, idealnya hanya direktur/controller), tampilkan statusnya di layar Finance, dan catat siapa-kapan menutup.

## 11. 🟠 Aksesibilitas / Modal

**Temuan.** Modal tidak memiliki role="dialog", aria-modal, maupun focus-trap: Tab keluar ke konten di belakang overlay, dan fokus tidak dikembalikan ke pemicu setelah modal ditutup. Fokus awal di-set ke kontrol pertama (baik), tapi hanya itu.

**Bukti.** public/app/js/ui.js:149-194 (tidak ada atribut ARIA, tidak ada penanganan Tab, tidak ada penyimpanan fokus pemicu)

**Dampak.** Screen reader tidak mengumumkan konteks dialog; pengguna keyboard tersesat ke halaman belakang saat mengisi form — semua form CRUD aplikasi ini hidup di modal, jadi cacatnya menyentuh setiap alur tulis.

**Usaha.** 1 hari (role/aria-modal/aria-labelledby + trap Tab + restore fokus) — **Rekomendasi.** Tambahkan di satu tempat (ui.js modal()) — seluruh aplikasi ikut terperbaiki.

## 12. 🟠 Aksesibilitas / Navigasi keyboard

**Temuan.** Baris tabel daftar dibuka hanya lewat click-listener pada <tr> tanpa tabindex/href/keydown; tile dashboard yang clickable juga div biasa. Pengguna keyboard tidak dapat membuka detail dokumen apa pun dari layar daftar (satu-satunya jalur adalah pencarian global Ctrl+K yang hasilnya berupa <button>).

**Bukti.** public/app/js/views/list.js:249-251 (tr.addEventListener('click') saja); public/app/js/views/dashboard.js:16-19 (stat div onClick); bandingkan search.js:23-30 yang benar (button.search-hit)

**Dampak.** Operasi keyboard-only (power user entry data, pengguna AT) putus di gerbang paling dasar aplikasi; juga melanggar praktik dasar WCAG 2.1.1.

**Usaha.** 1 hari (jadikan sel pertama <a href=#/d/...> atau tr tabindex=0 + Enter/Space) — **Rekomendasi.** Render kolom pertama sebagai link hash asli — gratis pula membuka di tab baru dengan Ctrl+klik, yang saat ini juga tidak bisa.

## 13. 🟠 Alat berat — BBM & hour meter

**Temuan.** Tidak ada pencatatan BBM (fuel log) maupun hour meter/odometer untuk alat berat. Aset hanya punya biaya perolehan dan penyusutan; perawatan hanya berbasis tanggal, bukan jam operasi.

**Bukti.** Grep 'bbm|fuel|solar|bahan bakar' di seluruh Modules dan public/app/js: nol hasil. Modules/Assets/Database/Migrations/2026_07_25_000510_create_ast_assets_table.php:11-40 (tidak ada kolom meter); 2026_07_25_000530_create_ast_maintenances_table.php:20 (next_due_date berbasis tanggal saja).

**Dampak.** BBM adalah 10-30% biaya operasi alat berat dan titik kebocoran klasik di kontraktor — tanpa log per aset/proyek tidak ada kontrol konsumsi wajar (liter/jam), dan servis berbasis jam operasi (standar excavator/genset) tak bisa dijadwalkan sehingga alat rusak dini.

**Usaha.** 3-5 hari (log BBM + pembacaan hour meter per aset, posting biaya BBM ke fin_project_costs, interval servis per jam) — **Rekomendasi.** Entri BBM dari layar Lapangan (aset, liter, harga, hour meter) yang langsung membebani biaya proyek dan memicu jadwal servis.

## 14. 🟠 CCO ↔ jadwal termin

**Temuan.** Setelah CCO disetujui nilai kontrak berubah, tetapi jadwal termin tidak bisa mengikuti: komentar di ContractChangeOrderService menyatakan 'added scope is billed through new termins', namun jalur itu tidak ada — kontrak approved tidak editable (ContractService::update melempar exception, DocumentStatus::isEditable = draft/rejected saja) dan tidak ada endpoint tambah-termin. Termin lama juga tidak di-respread (memang by design agar yang sudah ditagih tidak berubah).

**Bukti.** Modules/Crm/Services/ContractChangeOrderService.php:22-24 (janji 'new termins'), :124-129 (approve hanya menyentuh crm_contracts); Modules/Crm/Services/ContractService.php:37-57,181-186 (assertEditable memblokir kontrak approved); Modules/Core/Enums/DocumentStatus.php:26-29; Modules/Crm/Routes/api.php (tidak ada rute tambah termin).

**Dampak.** Setelah CCO, jumlah termin ≠ nilai kontrak dan persentase termin kehilangan makna; nilai pekerjaan tambah hanya bisa ditagih lewat invoice manual tanpa jadwal, tanpa billed_at, tanpa pelacakan sisa tagih — persis jenis 'pendapatan tercecer' yang jadwal termin seharusnya cegah.

**Usaha.** 1-2 hari: endpoint 'tambah termin CCO' pada kontrak approved dengan guard total termin = nilai kontrak terkini dan tanpa mengubah termin yang sudah billed. — **Rekomendasi.** Saat CCO approved, tawarkan wizard 'jadwalkan penagihan nilai tambah' yang membuat termin baru senilai value_change.

## 15. 🟠 Denda keterlambatan (liquidated damages)

**Temuan.** Denda kontrak tidak ada di mana pun: tidak ada field denda pada kontrak (tarif 1‰/hari, plafon 5% yang lazim di kontrak konstruksi Indonesia), tidak ada konsep pemotongan denda pada invoice AR maupun pada alokasi pembayaran, tidak ada perlakuan GL.

**Bukti.** grep -i 'denda|penalty|liquidated' di seluruh Modules = nol hasil; kolom fin_ar_invoices hanya dpp/ppn/retention_withheld; PaymentService::post menolak alokasi yang tidak sama persis dengan jumlah pembayaran (Modules/Finance/Services/PaymentService.php:123-127).

**Dampak.** Saat pemilik membayar invoice dikurangi denda keterlambatan (praktik umum), finance tidak bisa mencatatnya: alokasi wajib pas dengan nilai invoice, sehingga invoice menggantung sebagai 'kurang bayar' selamanya atau dipaksa dikoreksi lewat jurnal manual di luar alur — piutang dan laporan umur piutang jadi menyesatkan. Denda ke subkon yang terlambat juga tak bisa dikenakan.

**Usaha.** 2-3 hari: parameter denda di kontrak + baris potongan denda di invoice/penerimaan + posting GL (beban denda / pendapatan denda). — **Rekomendasi.** Minimal: izinkan 'potongan lain-lain' beralasan pada alokasi pembayaran sehingga selisih pembayaran riil bisa ditutup dengan jejak audit.

## 16. 🟠 Dokumentasi proyek — galeri foto progres

**Temuan.** Foto lapangan sudah ter-geotag dan tervalidasi jarak dari lokasi, tetapi tersebar per dokumen (laporan harian/tiket) dan hanya tampil sebagai baris nama file dengan tombol unduh — tidak ada thumbnail, tidak ada galeri foto per proyek yang bisa difilter tanggal/pekan.

**Bukti.** public/app/js/views/lapangan.js:1-101 (capture + geotag + distance badge, tampilan nama file saja); public/app/js/views/attachments.js:64-80 (baris attachment tanpa preview gambar — grep 'thumb|img|gallery' nihil); tidak ada route galeri di custom.js:45-1198 maupun API (Modules/Core/Routes/api.php hanya attachments index/store/download).

**Dampak.** Foto progres adalah bukti pendukung tagihan termin, klaim CCO, dan lampiran BAST; untuk merakit lampiran satu termin, admin harus membuka dokumen satu per satu dan mengunduh file satu per satu — PM juga tak bisa review visual kemajuan mingguan.

**Usaha.** 2-3 hari (endpoint thumbnail + layar galeri per proyek: grid foto lintas laporan harian/BAST/tiket, filter tanggal, unduh zip) — **Rekomendasi.** Galeri per proyek yang membaca core_attachments ber-mime image lintas dokumen proyek, diurut tanggal, dengan badge geotag yang sudah ada.

## 17. 🟠 Estimasi — riwayat harga satuan

**Temuan.** Harga satuan AHSP adalah satu angka cache yang di-overwrite (recalcUnitPrice), item persediaan hanya menyimpan avg_cost dan last_price — tidak ada tabel riwayat harga, tidak ada layar tren harga material/upah, dan AHSP tidak berversi/bertanggal.

**Bukti.** Modules/Estimation/Database/Migrations/2026_07_25_000600_create_est_ahsp_table.php:18-20 (unit_price cache tunggal); Modules/Inventory/Database/Migrations/2026_07_25_000410_create_inv_items_table.php:20-21 (avg_cost, last_price saja). Grep 'price_history|riwayat harga' di seluruh Modules dan public/app/js: nihil.

**Dampak.** Estimator menyusun RAB tanpa melihat tren harga beli aktual (padahal harga tiap PO tersimpan di prc_purchase_order_items); penawaran lama tak bisa diaudit terhadap harga AHSP saat itu karena angkanya sudah tertimpa — margin bisa salah hitung di pasar material yang fluktuatif.

**Usaha.** 2-4 hari (layar tren harga per item dari data PO/GRN yang sudah ada + snapshot harga AHSP saat BOQ disetujui) — **Rekomendasi.** Query riwayat harga dari prc_purchase_order_items/GRN (tanpa tabel baru) untuk layar tren, plus simpan snapshot unit_price AHSP di BOQ item saat approve.

## 18. 🟠 Feedback / Alur rekonsiliasi bank

**Temuan.** Setiap aksi cocokkan/batalkan satu baris rekening koran memanggil onChanged=load yang membuang seluruh body, mengambil ulang detail statement + seluruh suggestions (2 request), dan merender ulang tabel dari nol — posisi scroll hilang.

**Bukti.** public/app/js/views/bankrecon.js:503-509 dan 484-489 (withBusy → onChanged); 831-895 (load() → clear(body) + refetch detail & suggestions di 869-874)

**Dampak.** Mencocokkan rekening koran 200 baris berarti 200 kali kembali ke puncak halaman dan menunggu dua round-trip — pekerjaan bulanan yang paling repetitif di modul keuangan menjadi berkali lipat lebih lambat.

**Usaha.** 1-2 hari (perbarui baris in-situ dari respons match; refetch ringkasan saja) — **Rekomendasi.** Setelah match sukses, ganti sel status+padanan baris itu saja; sediakan tombol 'muat ulang' manual untuk sinkronisasi penuh.

## 19. 🟠 Filter & state daftar

**Temuan.** Hanya 2 dari 51 resource yang punya filter rentang tanggal (jurnal dan laporan harian); Invoice AR, Tagihan AP, Pembayaran, dan GRN hanya berfilter status/relasi. Halaman, kata kunci, dan filter disimpan di Map in-memory — bukan di URL — sehingga hilang saat reload dan tidak bisa dibagikan/bookmark.

**Bukti.** grep date_from schema.js = baris 553-554 (daily-reports) dan 1519-1520 (journals) saja; filter finance/ar-invoices di schema.js:1568+ hanya status/customer_id/project_id; public/app/js/views/list.js:13-17 (state Map)

**Dampak.** "Semua invoice November" — pertanyaan harian finance — tidak bisa dijawab layar daftar; dan link "lihat daftar ini" yang dikirim antar-rekan selalu membuka daftar kosong dari halaman 1.

**Usaha.** 1 hari (tambah filter date_from/date_to pada resource keuangan/inventori di schema.js; serialisasi state ke query-string hash) — **Rekomendasi.** Backend list endpoint umumnya sudah menerima date_from/date_to (jurnal membuktikannya); tinggal deklarasikan di schema. State ke URL menyusul.

## 20. 🟠 Form / Input nilai rupiah

**Temuan.** Semua field currency adalah <input type=number> polos: nilai kontrak miliaran diketik tanpa pemisah ribuan, dan scroll-wheel di atas input dapat mengubah angka tanpa disadari.

**Bukti.** public/app/js/views/form.js:27-31 (case 'currency' → input type number step 0.01)

**Dampak.** 15000000000 vs 1500000000 tidak terbedakan secara visual saat entry — salah satu sumber salah ketik nilai jurnal/kontrak paling klasik di ERP, dengan konsekuensi finansial langsung.

**Usaha.** 1 hari (format ribuan id-ID saat blur / live-mask; blokir wheel saat fokus) — **Rekomendasi.** Ganti dengan input teks ber-mask ribuan (1.500.000.000) yang read() menjadi Number; tampilkan terbilang singkat ("1,5 M") sebagai hint di bawah field.

## 21. 🟠 Form / Validasi & pesan error

**Temuan.** openForm tidak memvalidasi apa pun sebelum submit (field required dikirim kosong, ditolak server). Error validasi baris line-item (mis. items.0.unit_price) gagal dipetakan ke inputnya karena pemetaan memakai controls[fieldKey.split('.')[0]] sementara baris hidup di lineControls terpisah — error hanya muncul sebagai toast berisi path field mentah berbahasa Inggris, dipotong 4 baris, dan hilang setelah 8 detik.

**Bukti.** public/app/js/views/form.js:349-382 (tanpa cek required; mapping error line 373-380 tidak menjangkau lines); public/app/js/api.js:17-21 (details = 'field: pesan' mentah); public/app/js/ui.js:137-144 (slice(0,4), timeout 8000)

**Dampak.** Pengguna yang salah mengisi baris ke-7 dari 15 pada PO harus menebak baris mana yang salah dari toast 'items.6.qty: ...' yang sudah lenyap — siklus submit-gagal berulang.

**Usaha.** 1-2 hari (cek required client-side + petakan error lines.N.kolom ke sel baris; tandai baris merah) — **Rekomendasi.** Validasi required sebelum kirim; parse index dari path error dan sorot sel yang salah; toast error jangan auto-dismiss selama form masih terbuka.

## 22. 🟠 HR — Cuti/izin & absensi harian

**Temuan.** Tidak ada modul pengajuan cuti/izin sama sekali, dan absensi hanya rekap bulanan agregat (work_days/present_days/sick_days/leave_days/alpha_days per bulan) — tidak ada pencatatan kehadiran harian, tidak ada saldo cuti, tidak ada alur persetujuan cuti.

**Bukti.** Modules/HrPayroll/Database/Migrations/2026_07_25_001010_create_hr_attendance_recaps_table.php:11-29 (hanya agregat bulanan, unique per employee+bulan); Modules/HrPayroll/Routes/api.php hanya punya employees, attendance-recaps, payroll-runs; nav 'SDM & Payroll' hanya 3 menu di public/app/js/schema.js:2425-2431. Grep 'cuti|izin|leave_request' di Modules/HrPayroll: nihil.

**Dampak.** Perusahaan 50-200 orang tetap mengelola cuti di Excel/WA: saldo cuti tahunan (hak 12 hari UU) tak terkontrol, rekap absensi diketik manual tiap bulan sebelum payroll sehingga rawan salah potong gaji harian/lembur, dan HR tak punya jejak persetujuan izin.

**Usaha.** 5-8 hari (pengajuan cuti + saldo + approval memakai trait Approvable yang sudah ada; capture absensi harian dari layar Lapangan +3-5 hari lagi) — **Rekomendasi.** Tambah hr_leave_requests (jenis, tanggal, approval, potong saldo) yang otomatis mengisi leave_days rekap; tahap 2: absensi harian via layar Lapangan yang sudah mobile-first.

## 23. 🟠 HR — Kontrak kerja (PKWT) tanpa tanggal berakhir

**Temuan.** hr_employees punya employment_type 'kontrak' tetapi tidak ada kolom tanggal berakhir kontrak, tidak ada riwayat perpanjangan, dan tidak ada reminder kontrak habis.

**Bukti.** Modules/HrPayroll/Database/Migrations/2026_07_25_001000_create_hr_employees_table.php:21 (employment_type kontrak) — kolom tanggal di tabel hanya birth_date/join_date/resign_date (baris 18,20,32); Modules/HrPayroll/Enums/EmploymentType.php:8. Grep 'contract_end|kontrak' di modul HrPayroll: tidak ada kolom/route terkait.

**Dampak.** Kontrak PKWT lewat tanggal tanpa disadari: secara hukum (UU Cipta Kerja) PKWT yang terus bekerja tanpa perpanjangan berubah demi hukum menjadi PKWTT — risiko legal dan kompensasi nyata bagi kontraktor yang banyak memakai tenaga kontrak per proyek.

**Usaha.** 1-2 hari (kolom contract_end_date + tabel riwayat kontrak sederhana + masuk ke deadline-watch) — **Rekomendasi.** Tambah contract_end_date (nullable, wajib bila employment_type=kontrak) dan reminder H-30/H-14 via NotificationService::system() ke pemegang hr.update.

## 24. 🟠 HR — SKK/sertifikat tenaga ahli

**Temuan.** Tidak ada register sertifikat karyawan (SKK Konstruksi, Ahli K3, sertifikat vendor principal untuk teknisi SI) berikut tanggal kadaluarsanya. Detail karyawan hanya menampilkan identitas + slip gaji.

**Bukti.** Tidak ada tabel/route sertifikat di Modules/HrPayroll (daftar migrasi lengkap: employees, attendance_recaps, payroll_runs, payslips); layar detail karyawan public/app/js/views/custom.js:788-892 hanya memuat hr/employees/{id} dan payslips.

**Dampak.** Untuk ikut tender pemerintah/BUMN wajib melampirkan SKK personel yang masih berlaku; sertifikat kadaluarsa yang tak terpantau bisa menggugurkan penawaran atau melanggar syarat SMK3 di proyek berjalan.

**Usaha.** 2-3 hari (tabel hr_certificates: jenis, nomor, penerbit, berlaku s/d + tab di detail karyawan + expiry masuk deadline-watch) — **Rekomendasi.** Register sertifikat per karyawan dengan lampiran scan (AttachableDocuments sudah mendukung hr/employees) dan reminder H-60.

## 25. 🟠 Kalender kewajiban pajak

**Temuan.** Ekspor e-Faktur dan e-Bupot per masa sudah ada, tetapi tidak ada pelacakan batas waktu setor/lapor (PPN masa akhir bulan berikutnya, PPh unifikasi tanggal 10/15), tidak ada status 'sudah setor/sudah lapor' per masa, dan tidak ada rekap SPT Masa PPN yang menetting PPN keluaran vs masukan.

**Bukti.** TaxExportService.php:287-293 overview() hanya mengembalikan baris efaktur+ebupot; grep 'deadline|jatuh tempo|batas waktu' di Modules/Finance hanya mengenai due_date dokumen AR/AP; tidak ada model/tabel status masa pajak; penyelesaian saldo 2-1210/2-1220/2-1230/2-1300 hanya mungkin lewat JV manual (PaymentService.php:88-106).

**Dampak.** Sanksi bunga keterlambatan setor/lapor bergantung sepenuhnya pada ingatan staf di luar sistem; saldo hutang pajak menumpuk di neraca tanpa penanda masa mana yang belum dibayar, sehingga rekonsiliasi hutang pajak vs SPT tiap masa dikerjakan manual di spreadsheet.

**Usaha.** 1-2 hari (tabel status masa pajak per jenis: dihitung → disetor (tanggal+NTPN) → dilapor, plus pengingat jatuh tempo di dasbor) — **Rekomendasi.** Tambahkan register masa pajak dengan deadline terhitung otomatis dan tautkan JV penyetoran ke masa terkait; tampilkan masa yang mendekati/melewati deadline sebagai kartu dasbor untuk role finance.

## 26. 🟠 Kas kecil / kasbon karyawan

**Temuan.** Tidak ada fitur kas kecil, kasbon, uang muka kerja karyawan, atau reimbursement sama sekali di seluruh sistem. Satu-satunya 'uang muka' adalah uang muka pembelian ke vendor atas PO.

**Bukti.** grep petty|kasbon|kas kecil|imprest|reimburs|expense claim|cash_advance di seluruh Modules/ dan public/app/js kosong; yang ada hanya uang muka pembelian vendor (SettingService.php:360-363, ApBillService.php:29-31, 99); PaymentService hanya mengenal alokasi ar_invoice/ap_bill (PaymentService.php:88-106).

**Dampak.** Operasional harian proyek konstruksi (bensin, tol, konsumsi tukang, retribusi, material mendadak) tidak punya jalur masuk sistem: dibukukan telat lewat JV manual oleh finance pusat, tanpa alur pertanggungjawaban bon, tanpa saldo kasbon per karyawan/site, tanpa aging uang muka. Biaya proyek bulanan understated sampai JV masuk — dan run PSAK 115 cost-to-cost ikut understated.

**Usaha.** 3-5 hari (modul kasbon/kas kecil minimal: pengajuan → persetujuan → pencairan → pertanggungjawaban → posting biaya per proyek) — **Rekomendasi.** Prioritaskan kasbon site dengan pertanggungjawaban per proyek — kaitkan ke fin_project_costs agar POC dan profitabilitas proyek langsung benar.

## 27. 🟠 Keuangan/Legal — register jaminan bank (bond)

**Temuan.** Tidak ada register jaminan penawaran/pelaksanaan/uang muka/pemeliharaan (bank garansi/surety bond) berikut nilai, bank penerbit, dan tanggal berakhir. Kata 'jaminan' hanya muncul sebagai teks bebas di catatan seeder.

**Bukti.** Grep 'jaminan|bank guarantee|bid bond' di Modules dan public/app/js: hanya string naratif di CrmDatabaseSeeder.php:255 dan FinanceDatabaseSeeder.php:138 — tidak ada tabel, route, atau layar.

**Dampak.** Kontraktor yang ikut tender selalu memegang beberapa jaminan aktif yang mengikat kas/fasilitas bank: jaminan pelaksanaan yang kadaluarsa saat proyek molor = wanprestasi kontrak; jaminan yang tidak ditarik kembali setelah selesai = kas dan limit bank tertahan tanpa ada yang sadar.

**Usaha.** 2-3 hari (register jaminan terhubung ke kontrak/penawaran: jenis, bank, nilai, berlaku s/d, status + expiry masuk deadline-watch) — **Rekomendasi.** Satu resource fin/guarantees dengan lampiran scan dan reminder H-30 sebelum berakhir.

## 28. 🟠 Koreksi dokumen ter-posting (void / nota kredit)

**Temuan.** Status Cancelled dideklarasikan dan difilter defensif di banyak query, tetapi TIDAK ADA satu pun jalur kode yang men-set-nya pada dokumen Finance: rute AR/AP hanya submit/approve/reject, delete dibatasi draft/rejected, dan billed_at termin diisi saat approve tanpa pernah bisa dilepas. Invoice atau tagihan vendor yang terlanjur disetujui (dan ber-jurnal) tidak bisa dibatalkan/dikoreksi dari sistem.

**Bukti.** Modules/Core/Enums/DocumentStatus.php:12 (case Cancelled ada); ArInvoiceService.php:70-76 dan ApBillService.php:742-749 (query whereNot Cancelled — bukti niat desain); Modules/Finance/Routes/api.php:43-61 (tidak ada rute cancel/void); ArInvoiceService.php:211-215 + :323-329 (delete hanya draft/rejected); ArInvoiceService.php:188 (approve men-stamp billed_at termin, tidak ada jalur unset); grep 'Cancelled' yang men-SET status = hanya ServiceDesk TicketStatus.

**Dampak.** Salah tagih adalah kejadian rutin (DPP keliru, invoice ditolak MK, termin salah pilih — makin mungkin karena input termin berupa ID mentah). Begitu approved: piutang fiktif menggantung permanen di aging, termin terkunci 'sudah ditagih' sehingga invoice pengganti DITOLAK guard, dan satu-satunya jalan adalah JV manual yang membuat subledger AR/AP tidak lagi sama dengan GL. Sisi AP sama: tagihan vendor keliru yang disetujui mengunci gate finalBillExists PO-nya.

**Usaha.** 1-2 hari: aksi 'Batalkan' pada dokumen approved yang belum dibayar — jurnal balik otomatis, set status cancelled, lepas billed_at & baris retensi terkait. — **Rekomendasi.** Guard: hanya bila amount_paid=0 dan wajib alasan; enum + filter query-nya sudah siap menampung, tinggal jalur transisinya.

## 29. 🟠 Masa pemeliharaan & BAST II

**Temuan.** Tidak ada register defect/punch-list untuk masa pemeliharaan proyek konstruksi. svc_tickets milik ServiceDesk hanya terikat ke service_contract/customer/site — tidak punya project_id — dan modul Projects tidak punya model defect sama sekali. BAST II juga tanpa prasyarat apa pun: bisa dibuat dan disetujui tanpa BAST I ada, tanpa cek progres 100%, tanpa cek defect selesai; persetujuannya langsung menutup proyek.

**Bukti.** Modules/ServiceDesk/Database/Migrations/2026_07_25_001220_create_svc_tickets_table.php:14-17 (tanpa project_id); daftar Modules/Projects/Models (tidak ada model defect); Modules/Projects/Services/ProjectService.php:207-226 (approveBast BAST II → langsung Closed tanpa pemeriksaan); Modules/Projects/Http/Requests/BastStoreRequest.php (tidak ada aturan keterkaitan BAST I).

**Dampak.** Klaim perbaikan selama 12 bulan pemeliharaan (kontrak 1 & 2 demo) dikelola di WA/Excel di luar sistem — padahal BAST II adalah dasar pencairan retensi Rp 2,425 miliar (kontrak 1). BAST II bisa disetujui tanpa bukti kewajiban pemeliharaan tuntas, membuka sengketa dengan pemilik dan risiko retensi dipotong.

**Usaha.** 2-3 hari: register defect per proyek berstatus warranty (lapor, perbaiki, verifikasi) + guard BAST II menampilkan/memblokir defect terbuka dan mensyaratkan BAST I approved. — **Rekomendasi.** Model sederhana prj_defects (project_id, uraian, dilaporkan, target, selesai, verifikasi) cukup; jangan pakai svc_tickets karena semantik SLA-nya berbeda.

## 30. 🟠 Orkestrasi tutup buku bulanan

**Temuan.** Langkah-langkah close (payroll→GL, run penyusutan, run PSAK 115, rekonsiliasi bank, ekspor pajak, tutup periode) ada sebagai pulau terpisah tanpa checklist, status, atau penjagaan urutan. Run PSAK 115 cost-to-cost tidak memeriksa bahwa payroll dan penyusutan bulan itu sudah diposting sebelum ia dihitung/diposting.

**Bukti.** RevenueRecognitionService.php:24 (% kemajuan = biaya kumulatif / EAC) dan :163-246 (post hanya menjaga monoton antar-run dan periode terbuka — tidak ada referensi ke payroll_run/depreciation); PayrollPostingService.php:212-217 memposting di tanggal akhir bulan yang sama; BankReconciliationController.php:14 menyatakan rekonsiliasi tidak menahan apa pun dan tanpa sign-off; daftar tab laporan reports.js:10-15 tidak memuat checklist close; dashboard.js:132-144 hanya inbox approval, bukan status close.

**Dampak.** Bila controller memposting POC sebelum payroll/penyusutan bulan itu masuk, % penyelesaian understated dan pendapatan bulan berjalan bergeser ke bulan berikutnya (self-correcting kumulatif tapi laba bulanan salah saji). Urutan close bergantung hafalan satu orang; pergantian staf = risiko bulan pertama kacau.

**Usaha.** 2-3 hari (layar checklist close per periode: status payroll/penyusutan/POC/rekonsiliasi/ekspor pajak + tombol tutup periode di ujungnya) — **Rekomendasi.** Satu layar 'Tutup Buku {periode}' yang membaca status tiap langkah dari data yang sudah ada (payroll_run posted? depreciation run posted? run POC posted? baris bank belum match?) dan hanya mengaktifkan tutup periode bila semuanya hijau — datanya sudah tersedia semua.

## 31. 🟠 Pembayaran non-AP (gaji, pajak, BPJS)

**Temuan.** Modul Pembayaran hanya bisa mengalokasikan pembayaran keluar ke tagihan AP; membayar gaji bersih (2-1110), menyetor PPh 21/PPN/BPJS, dan biaya bank harus lewat jurnal manual. Komentar di kode payroll bahkan menjanjikan alur 'ordinary PAY payment against 2-1110' yang tidak dapat dilakukan kode saat ini.

**Bukti.** PaymentService.php:88-106 ($expectedType untuk direction out = ap_bill saja, tipe lain ditolak); PayrollPostingService.php:37-39 (komentar yang menjanjikan pembayaran gaji via PAY); satu-satunya jalan alternatif adalah JV manual yang admissible di rekonsiliasi (BankStatementMatchService.php:243-258).

**Dampak.** Disbursement terbesar dan paling rutin tiap bulan (gaji + setoran pajak + BPJS) justru lewat jalur paling rawan: JV manual tanpa approval, salah pilih akun tidak tertahan sistem, dan register pembayaran tidak lagi mencerminkan seluruh uang keluar — laporan pembayaran ≠ mutasi kas.

**Usaha.** 1-2 hari (tipe alokasi 'lainnya' pada pembayaran keluar: pilih akun liabilitas/beban lawan, tetap lewat guard periode dan rekonsiliasi) — **Rekomendasi.** Izinkan PAY beralokasi ke akun GL terpilih (whitelist: 2-1110, 2-1120, 2-1210, 2-1220, 2-1230, 2-1300, biaya bank) sehingga semua uang keluar tercatat sebagai dokumen pembayaran yang bisa direkonsiliasi dan disetujui.

## 32. 🟠 Penagihan termin (validasi syarat)

**Temuan.** Invoice termin bisa dibuat untuk termin yang syarat penagihannya belum terpenuhi: ArInvoiceService::createFromTermin hanya memeriksa termin belum billed, tidak ada invoice ganda, dan kontrak approved — modul Finance sama sekali tidak pernah membaca prj_milestones (grep 'Milestone' di Modules/Finance = nol). Relasi milestone↔termin juga rapuh: termin_id di milestone hanya divalidasi 'integer min:1' tanpa Rule::exists dan tanpa cek termin milik kontrak proyek yang sama, dan di UI diisi sebagai angka mentah.

**Bukti.** Modules/Finance/Services/ArInvoiceService.php:58-119 (tidak ada cek milestone); Modules/Projects/Http/Requests/MilestoneStoreRequest.php:23 ('termin_id' => nullable|integer|min:1); public/app/js/schema.js:645 ({ key: 'termin_id', label: 'ID termin terkait', type: 'number' }); billing_condition hanya teks bebas (crm_contract_termins migration).

**Dampak.** Finance dapat menagih 'Progress 80%' saat progres aktual 55% (data demo proyek 1) — invoice ditolak MK/pemilik, BAP tidak keluar, hubungan pelanggan rusak. Sebaliknya milestone bisa menunjuk termin kontrak lain atau ID yang tidak ada tanpa error, sehingga rantai syarat-penagihan putus diam-diam.

**Usaha.** 1 hari: Rule::exists + cek kontrak sama pada termin_id; warning (bukan blok keras) di createFromTermin bila milestone terkait belum achieved. — **Rekomendasi.** Jadikan pelanggaran syarat sebagai konfirmasi eksplisit ('Milestone syarat termin ini belum tercapai — tetap tagih?') supaya kasus sah (mis. deviasi kontrak) tetap mungkin tapi tercatat.

## 33. 🟠 Pengadaan — kendali anggaran

**Temuan.** Tidak ada gate anggaran saat menyetujui PO/SPK. CommitmentService menghitung sisa anggaran (RAP − aktual − komitmen) tetapi hanya untuk laporan profitabilitas; Approvable::approve dan controller tidak pernah membandingkan nilai dokumen dengan sisa RAP proyek.

**Bukti.** Modules/Finance/Services/CommitmentService.php:36-55 (report-only, per docblock); Modules/Core/Traits/Approvable.php:33-41; Modules/Procurement/Http/Controllers/PurchaseOrderController.php:89-98.

**Dampak.** PM/direktur menyetujui komitmen yang menembus RAP tanpa satu pun peringatan pada saat keputusan diambil — angka sisa anggaran baru terlihat kalau seseorang membuka laporan profitabilitas.

**Usaha.** 1-2 hari (soft-block: tampilkan sisa anggaran pada layar approve dan minta konfirmasi/alasan bila akan negatif) — **Rekomendasi.** Panggil CommitmentService saat submit/approve PO & SPK ber-proyek; kebijakan hard/soft block dibuat parameter.

## 34. 🟠 Pengadaan — kendali harga

**Temuan.** Tidak ada tahap RFQ/perbandingan penawaran (tidak ada tabel, rute, atau layar; crm_quotations adalah penawaran jual) dan tidak ada kendali harga PR→PO: harga PO bebas diketik/diubah tanpa dibandingkan ke estimated_price PR, AHSP, atau RAP. Tautan anggaran malah putus di PO — pr_items punya boq_item_id tetapi prc_purchase_order_items tidak punya kolom itu, dan createFromPr tidak menyalinnya.

**Bukti.** Modules/Procurement/Services/PoService.php:96-109 (salin tanpa boq_item_id), 212-231 (syncItems tanpa cek harga); Modules/Procurement/Database/Migrations/2026_07_25_000840_create_prc_purchase_order_items_table.php (tanpa boq_item_id); grep rfq/penawaran vendor = nol.

**Dampak.** Pembelian di atas harga RAP/AHSP lolos diam-diam dan baru terlihat setelah jadi biaya; tidak ada bukti banding minimal beberapa penawaran untuk tata kelola pengadaan; komitmen per baris anggaran tidak bisa ditelusuri karena PO kehilangan referensi BOQ.

**Usaha.** 3-5 hari (bawa boq_item_id ke baris PO 0,5 hari; peringatan harga>estimasi 1 hari; RFQ sederhana multi-vendor 2-3 hari) — **Rekomendasi.** Minimal: turunkan boq_item_id ke baris PO dan tampilkan selisih harga PO vs estimasi PR/harga AHSP saat submit; tahap dua: dokumen RFQ dengan tabulasi penawaran dan alasan pemenang.

## 35. 🟠 Pengadaan — prakualifikasi vendor

**Temuan.** Tidak ada register dokumen vendor dengan masa berlaku: prc_vendors hanya menyimpan NPWP/SPPKP tanpa NIB/SIUP/SBU/sertifikat dan tanpa satu pun kolom tanggal kedaluwarsa (grep 'berlaku/expiry/sertifikat' di Procurement nol). Lampiran generik bisa ditempel ke vendor tetapi tanpa jenis dan tanpa tanggal habis. Status vendor (aktif/nonaktif) juga tidak pernah diperiksa: PoService::create/createFromPr dan SubcontractService hanya findOrFail.

**Bukti.** Modules/Procurement/Database/Migrations/2026_07_25_000800_create_prc_vendors_table.php; Modules/Procurement/Services/PoService.php:23,77 (tanpa cek status); Modules/Subcontract/Services/SubcontractService.php:152-163 (hanya cek is_subcontractor); Modules/Core/Support/AttachableDocuments.php:56.

**Dampak.** PO/SPK bisa terbit ke vendor nonaktif atau subkon yang SBU/sertifikat badan usahanya kedaluwarsa. Untuk subkon ini beririsan pajak: tarif PPh final PP 9/2022 bergantung status bersertifikat (2,65% vs 4%) yang dipilih manual di pph_scheme tanpa bukti sertifikat yang masih berlaku.

**Usaha.** 2-3 hari (tabel prc_vendor_documents: jenis, nomor, berlaku_sampai, lampiran + alert kedaluwarsa + blok vendor nonaktif) — **Rekomendasi.** Register dokumen per vendor dengan reminder jatuh tempo di dashboard pengadaan; tolak submit PO/SPK bila vendor nonaktif atau dokumen wajib kedaluwarsa (dengan override beralasan).

## 36. 🟠 Pengendalian material site

**Temuan.** Varian pemakaian material (qty teoretis vs aktual) tidak pernah dihitung padahal seluruh datanya ada: est_ahsp_components punya item_id+koefisien (10 baris demo terhubung item), est_boq_items punya ahsp_id+qty, inv_issues mencatat project_id+wbs_task_id, dan prj_daily_report_materials mencatat pemakaian harian. wbs_task_id hanya muncul di Request/Resource — tidak ada satu pun query yang mengonsumsinya, dan tidak ada laporan yang menggabungkan AHSP×BOQ dengan issue.

**Bukti.** Modules/Estimation/Database/Migrations/2026_07_25_000610_create_est_ahsp_components_table.php; 2026_07_25_000640_create_est_boq_items_table.php; Modules/Inventory/Database/Migrations/2026_07_25_000440_create_inv_issues_table.php; grep wbs_task_id/coefficient di Projects+Finance+Inventory = hanya Request/Resource; DB demo: inv_issues.wbs_task_id terisi = 0.

**Dampak.** Pemborosan, susut, dan kebocoran material di site (masalah #1 kontraktor) tidak terlihat sampai muncul sebagai selisih rupiah di P&L proyek — terlambat untuk dikoreksi. Semen yang terpakai 120% dari teoretis tidak pernah memicu pertanyaan.

**Usaha.** 3-5 hari (laporan per proyek/item: teoretis = Σ qty BOQ × koefisien AHSP vs Σ issue + pemakaian laporan harian, dengan %) — **Rekomendasi.** Bangun layar 'Varian Pemakaian Material' per proyek; jadikan wbs_task_id wajib untuk issue ke proyek agar varian bisa turun ke level WBS.

## 37. 🟠 Persediaan — retur dari proyek

**Temuan.** Tidak ada jalan mengembalikan sisa material dari proyek ke gudang: issue hanya qty positif (min:0.001), applyOut satu arah, dan dua jalan pintas yang tersedia sama-sama salah — GRN tanpa vendor mengkredit ekuitas 3-3100, adjustment mengkredit beban 6-4400 — dan tidak satu pun mengurangi fin_project_costs yang sudah tercatat saat issue.

**Bukti.** Modules/Inventory/Http/Requests/IssueStoreRequest.php:25; Modules/Inventory/Services/StockService.php:487-514 (applyOut), 743-760 (kredit GRN non-vendor ke 3-3100), 934-957 (adjustment ke 6-4400).

**Dampak.** Material yang di-issue berlebih lalu dikembalikan tetap tercatat sebagai biaya proyek — P&L proyek melebih-lebihkan biaya material dan stok fisik gudang tidak sama dengan sistem, mendorong opname 'surplus' yang membukukan pendapatan operasi palsu.

**Usaha.** 1-2 hari (dokumen issue-return: stok masuk di harga issue + reversal fin_project_costs dan jurnal Dr 1-1400 / Cr 5-xxxx) — **Rekomendasi.** Tambah dokumen 'Pengembalian Material' yang mereferensi issue asal dan membalik biayanya di harga saat dikeluarkan.

## 38. 🟠 Persediaan — retur pembelian

**Temuan.** Tidak ada dokumen retur pembelian ke vendor: Inventory hanya punya GRN/Issue/Transfer/Adjustment, AdjustmentReason terbatas opname/damage/loss, dan tidak ada dokumen yang sekaligus mengurangi stok dan hutang vendor.

**Bukti.** Modules/Inventory/Routes/api.php (empat dokumen saja); Modules/Inventory/Enums/AdjustmentReason.php:6-9; StockService::postAdjustmentJournal (6-4400, tanpa sentuh AP).

**Dampak.** Barang ditolak/rusak yang dikembalikan ke vendor harus di-adjust keluar sebagai beban selisih persediaan 6-4400 sementara tagihan vendor tetap penuh — biaya perusahaan melebihi kenyataan dan saldo hutang vendor salah sampai dikoreksi jurnal manual.

**Usaha.** 2-3 hari (dokumen retur: stok keluar di harga terima + debit note mengurangi hutang/menagih balik vendor) — **Rekomendasi.** Dokumen 'Retur Pembelian' terhubung GRN asal, memutar balik clearing/akrual yang tercatat di penerimaan itu.

## 39. 🟠 Procure-to-Pay — otorisasi

**Temuan.** needs_director_approval hanya label. PO/SPK di atas ambang (approvals.purchase_order.threshold_two_level, default Rp 100 juta) distempel flag saat submit, tetapi Approvable::approve tetap satu langkah submitted→approved oleh siapa pun pemegang prc.approve/scm.approve — tidak ada pemeriksaan peran direktur, tidak ada persetujuan kedua.

**Bukti.** Modules/Procurement/Models/PurchaseOrder.php:50-57; Modules/Core/Traits/Approvable.php:33-41; Modules/Procurement/Routes/api.php (approve hanya middleware permission:prc.approve); Modules/Subcontract/Models/Subcontract.php:53; Modules/Iam/Database/Seeders/RoleSeeder.php:23-27.

**Dampak.** Kontrol dua tingkat yang dijanjikan konfigurasi tidak pernah ditegakkan: begitu peran non-direktur diberi prc.approve (mis. manajer pengadaan agar PO kecil tidak menunggu direktur), orang yang sama sendirian bisa menyetujui PO Rp 5 miliar. Sebaliknya dengan peran bawaan, PO Rp 500 ribu pun antre ke direktur.

**Usaha.** 2-3 hari (status approved_level_1 + syarat approver kedua berperan direktur bila flag aktif) — **Rekomendasi.** Tegakkan dua tingkat: dokumen ber-flag butuh dua approval berbeda, yang kedua wajib peran direktur; audit trail Approval sudah siap menampungnya.

## 40. 🟠 Procure-to-Pay — tagihan parsial

**Temuan.** Satu PO hanya boleh punya SATU tagihan final (finalBillExists menolak yang kedua) dan tagihan itu baru bisa disetujui setelah seluruh baris stock diterima atau PO ditutup (assertStockCommitmentSettled). Vendor yang menagih per pengiriman — pola normal untuk besi/beton/blanket PO — tidak bisa dibukukan sebagai beberapa tagihan.

**Bukti.** Modules/Finance/Services/ApBillService.php:670-689 (finalBillAmounts), 742-749 (finalBillExists), 1075-1104 (gate penerimaan penuh).

**Dampak.** Invoice vendor per kiriman harus ditumpuk menunggu kiriman terakhir (umur hutang dan faktur pajak masuk tidak akurat), atau PO ditutup dini agar bisa ditagih — mengorbankan sisa pesanan; nomor invoice vendor per kiriman tidak terlacak satu-satu.

**Usaha.** 3-5 hari (izinkan beberapa tagihan per PO, masing-masing menyapu clearing GRN tertentu; mesin clearedAgainstReceipts sudah setengah jalan ke sana) — **Rekomendasi.** Ubah model menjadi tagihan per (PO, kumpulan GRN): pilih GRN yang ditagih pada form, kliring per pilihan — arsitektur pencatatan gl_cleared_amount yang ada sudah mendukung arah ini.

## 41. 🟠 Procure-to-Pay — uang muka vendor (UI)

**Temuan.** Mesin uang muka PO (is_advance) dan tagihan-atas-GRN (goods_receipt_id) sudah lengkap di backend (ApBillService + validasi ApBillStoreRequest) tetapi form Tagihan Vendor di SPA tidak memiliki kedua field itu — grep is_advance/goods_receipt di public/app/js nol untuk form.

**Bukti.** public/app/js/schema.js:1638-1658 (form ap-bills: hanya purchase_order_id, subcontract_claim_id, vendor, pajak, tanggal); Modules/Finance/Http/Requests/ApBillStoreRequest.php:22-35 menerima keduanya; ApBillService.php:160-199, 223-305.

**Dampak.** DP 20-30% ke pemasok material — alasan utama fitur ini dibangun — tetap tidak bisa dicatat oleh staf keuangan tanpa memanggil API manual; akrual penerimaan tanpa-PO (2-1600) tidak pernah bisa ditagihkan dari layar sehingga menggantung di neraca.

**Usaha.** 0,5-1 hari — **Rekomendasi.** Tambah toggle 'Uang muka' (aktif hanya bila PO dipilih) dan lookup 'Dari GRN tanpa PO' pada form tagihan; tampilkan advance yang diperhitungkan di detail tagihan final.

## 42. 🟠 Proyek — EVM (CPI/SPI)

**Temuan.** Tidak ada perhitungan earned value sama sekali (grep CPI/SPI/earned value: nihil) padahal seluruh bahan bakunya sudah ada di sistem: RAP (est_cost_budgets), realisasi biaya per proyek (fin_project_costs), progres fisik berbobot (WBS + weekly_progress), nilai kontrak, bahkan run POC bulanan.

**Bukti.** Laporan profitabilitas proyek hanya menyajikan pendapatan tertagih vs realisasi biaya vs anggaran (public/app/js/views/reports.js:210-231); dasbor proyek hanya deviasi progres % (project.js:170-203); tidak ada endpoint EVM di seluruh route dump.

**Dampak.** Deviasi % progres saja tidak memberi tahu proyek mana yang boros: proyek bisa on-schedule tapi membakar biaya (CPI<1) dan baru ketahuan rugi saat termin akhir. CPI/SPI per proyek adalah alarm dini standar yang membedakan ERP kontraktor dari akuntansi biasa.

**Usaha.** 2-3 hari (EV = progres berbobot × RAP; PV dari planned_pct; AC dari fin_project_costs — tampilkan CPI/SPI di kartu proyek dan daftar proyek) — **Rekomendasi.** Hitung di ProjectController::dashboard yang sudah ada dan beri warna ambang (CPI<0,95 merah) di daftar proyek.

## 43. 🟠 Proyek — baseline kurva-S & re-baseline

**Temuan.** Kurva-S hanya menyimpan satu pasang angka per minggu (planned_pct, actual_pct) dengan unique(project_id, week_no) — rencana adalah satu kurva yang bisa diedit/tertimpa, tanpa versi baseline. Padahal CCO (tambah-kurang) sudah ada dan mengubah nilai/jadwal kontrak.

**Bukti.** Modules/Projects/Database/Migrations/2026_07_25_000740_create_prj_weekly_progress_table.php:17-24; grep 'baseline|rebase' di Modules dan public/app/js: hanya komentar internal RevenueRecognitionService, tidak ada fitur; chart hanya 2 garis plan/actual (public/app/js/views/project.js:53-58).

**Dampak.** Setelah addendum/CCO, kurva rencana asli hilang — kontraktor tak bisa membuktikan deviasi terhadap baseline kontrak awal saat mengajukan perpanjangan waktu (EOT) atau membela diri dari denda keterlambatan; ini dokumen kunci di sengketa proyek.

**Usaha.** 2-3 hari (kolom baseline_no di weekly_progress atau tabel snapshot baseline + aksi 're-baseline' yang menyalin kurva lama + garis ketiga di chart) — **Rekomendasi.** Aksi eksplisit 'Re-baseline (addendum)' yang membekukan kurva rencana lama dan mencatat alasannya, terhubung ke CCO.

## 44. 🟠 Register jaminan bank & asuransi proyek

**Temuan.** Tidak ada register jaminan (bank garansi penawaran/pelaksanaan/uang muka/pemeliharaan, asuransi CAR/TPL) di seluruh sistem: tidak ada tabel, rute, atau layar — kata 'jaminan' hanya muncul sebagai teks bebas di data demo, yang justru membuktikan proses bisnisnya membutuhkan dokumen itu.

**Bukti.** grep -ri 'jaminan|garansi|guarantee|bond' di Modules dan public/app/js = hanya teks bebas seeder: Modules/Crm/Database/Seeders/CrmDatabaseSeeder.php:255 (syarat termin DP: 'penyerahan jaminan uang muka') dan Modules/Finance/Database/Seeders/FinanceDatabaseSeeder.php:138 (catatan approval: 'jaminan uang muka sudah diterima'); navigasi SPA lengkap (schema.js:2360-2460) tidak memuat menu terkait.

**Dampak.** Jaminan pelaksanaan/pemeliharaan (lazim 5% nilai kontrak — untuk kontrak 1 berarti ± Rp 2,4 M per jenis) punya masa berlaku; yang kedaluwarsa tanpa perpanjangan adalah wanprestasi yang memberi pemilik dasar menahan pembayaran atau mencairkan jaminan, dan jaminan yang lupa ditarik setelah BAST terus membebani fasilitas non-cash-loan serta biaya provisi bank. Sistem yang menagih DP berdasarkan syarat 'jaminan uang muka diserahkan' tidak tahu jaminan itu ada, apalagi kapan habis.

**Usaha.** 2-3 hari: tabel register jaminan (jenis, penerbit, nomor, nilai, berlaku s/d, kontrak/proyek terkait, status ditarik) + pengingat H-30 + kartu dasbor. — **Rekomendasi.** Satu register untuk jaminan bank dan polis asuransi proyek (CAR/TPL) sekaligus — pola datanya sama: dokumen bernilai dengan tanggal habis yang harus dijaga selama umur proyek.

## 45. 🟠 Reminder engine — puluhan kolom tanggal tanpa pengawas

**Temuan.** Hanya dua watcher terjadwal di seluruh sistem: erp:backup-watch (08:00) dan svc:generate-pm (06:00). Notifikasi lain hanya event approval. Kolom deadline yang ada datanya tapi tidak pernah mengingatkan siapa pun: crm_quotations.valid_until; crm_contracts.end_date; prj_projects.end_date + warranty_months (akhir masa pemeliharaan); prj_milestones.due_date (hanya tampil pasif di workspace proyek); prj_bast.retention_release_due (retensi 5% jatuh tempo tagih); prj_safety_incidents.due_date (tindakan korektif K3); prj_wbs_tasks.planned_end; prc_purchase_orders.expected_date (PO telat kirim); prc_purchase_requisitions.needed_date; fin_ar_invoices.due_date & fin_ap_bills.due_date (hanya laporan aging pull); ast_maintenances.next_due_date (kontras: PM servis punya cron, servis aset tidak); ast_deployments.planned_until (alat belum dikembalikan); svc_contracts.period_end (perpanjangan kontrak layanan); prj_manpower_assignments.assigned_until.

**Bukti.** Scheduler: Modules/Core/Providers/CoreServiceProvider.php:99-100 dan Modules/ServiceDesk/Providers/ServiceDeskServiceProvider.php:30-31 (hanya 2 command; grep Schedule di semua provider). NotificationService.php:50-163 hanya submitted/decided/system, dan satu-satunya pemanggil system() adalah BackupWatchCommand. Kolom-kolom tanggal: migrasi masing-masing (crm 000320:18, 000340:26; prj 000700:26,31, 000750:15, 000760:20, 000780:54, 000710:22, 000770:18; prc 000830:21, 000810:17; fin 001140:21, 001160:21; ast 000530:20, 000520:17; svc 001200:19).

**Dampak.** Kerugian paling konkret: retensi 5% (ratusan juta pada proyek menengah) yang lupa ditagih setelah masa pemeliharaan, kontrak layanan yang lewat tanpa perpanjangan (revenue berulang hilang), PO material telat tanpa eskalasi sehingga proyek berhenti, dan alat tak diservis. Sistem sudah tahu semua tanggalnya — tidak ada yang memberitahu manusia.

**Usaha.** 3-5 hari (satu command erp:deadline-watch harian dengan registry deklaratif kolom→permission→H-minus, memakai NotificationService::system() + dedup yang sudah ada) — **Rekomendasi.** Bangun satu watcher generik, bukan per modul — daftar (tabel, kolom, kondisi, penerima, hari-menjelang) sebagai konfigurasi, mulai dari 4 teratas: retensi, kontrak layanan, PO expected_date, due_date AR.

## 46. 🟠 ServiceDesk ↔ Persediaan — suku cadang berita acara tidak menyentuh stok

**Temuan.** Suku cadang yang dipakai teknisi dicatat sebagai baris data (item_id, qty) pada berita acara kunjungan, tetapi tidak pernah mengurangi stok gudang dan tidak pernah menjadi biaya: tidak ada satu pun referensi StockService/issue di seluruh modul ServiceDesk, dan dokumen pengeluaran barang Inventory tidak punya kolom referensi tiket/berita acara untuk mengaitkannya manual sekalipun.

**Bukti.** Modules/ServiceDesk/Services/FieldReportService.php:13-27 dan :40-44 (parts = create baris data saja, replace wholesale); grep 'StockService|Issue' di Modules/ServiceDesk = nol; Modules/Inventory/Http/Requests/IssueStoreRequest.php:17-26 (issue hanya kenal warehouse/project/wbs — tanpa ticket_id/field_report_id); acknowledge() hanya mengunci tanda tangan pelanggan (FieldReportService.php:70-80).

**Dampak.** Bisnis maintenance SI justru hidup dari penggantian part (sparepart ELV/ICT, DOA replacement 1x24 jam seperti di data demo). Setiap kunjungan yang memasang part membuat stok sistem > fisik; selisihnya baru muncul saat opname dan dibukukan buta sebagai kerugian 6-4400 tanpa tahu barang ke mana — celah kebocoran gudang klasik. Sekaligus profitabilitas kontrak layanan overstated karena biaya part tidak pernah dibebankan.

**Usaha.** 1-2 hari: saat berita acara di-acknowledge, generate inventory issue otomatis dari baris parts (pilih gudang teknisi) + simpan referensi tiket pada issue. — **Rekomendasi.** Jadikan acknowledge() pemicunya — di titik itu pemakaian part sudah ditandatangani pelanggan, sehingga stok dan biaya kontrak layanan mengikuti bukti terkuat.

## 47. 🟠 Siklus hidup proyek (siapa menutup, apa akibatnya)

**Temuan.** Status proyek adalah dropdown bebas tanpa state machine: siapa pun ber-permission prj.update bisa lompat langsung ke 'Ditutup' tanpa BAST. Penutupan (baik manual maupun via BAST II) tidak memeriksa apa pun — termin belum tertagih, piutang outstanding, retensi belum cair, PO terbuka — dan tidak mengunci apa pun: ProjectStatus::isOperational() tidak dipakai satu kali pun, sehingga laporan harian dan progres masih bisa dientri di proyek closed.

**Bukti.** Modules/Projects/Http/Requests/ProjectUpdateRequest.php:37 + public/app/js/schema.js:512 (status = select bebas); grep 'isOperational' di Modules = hanya deklarasinya di Enums/ProjectStatus.php; Modules/Projects/Services/DailyReportService.php:11-22 dan ProgressService.php:19-50,117-151 (tanpa guard status); ProjectService.php:207-226 (BAST II menutup tanpa checklist — data demo kontrak 1 masih punya termin 4 'BAST 15%' Rp 7,275 M dan termin 5 retensi belum tertagih).

**Dampak.** Proyek bisa 'selesai' di sistem sementara Rp 9,7 miliar hak tagih (termin 4+5 kontrak 1) belum diproses dan tidak ada yang menghalangi atau mengingatkan; data lapangan bisa terus mengalir ke proyek tutup sehingga laporan progres dan biaya periode tidak bisa dipercaya.

**Usaha.** 1-2 hari: validasi transisi status + guard isOperational di service entri lapangan + checklist penutupan (tagihan sisa, retensi, PO terbuka) saat approve BAST II atau set Closed. — **Rekomendasi.** Jadikan penutupan aksi eksplisit 'Tutup proyek' yang menampilkan ringkasan item terbuka, bukan sekadar pilihan dropdown.

## 48. 🟠 Subkontrak — addendum SPK

**Temuan.** Tidak ada mekanisme addendum/pekerjaan tambah-kurang untuk SPK: SPK yang sudah approved tidak bisa diedit (assertEditable), nilai klaim kumulatif dikunci pada nilai SPK asli (assertWithinContractValue), dan CCO hanya ada untuk kontrak pelanggan di modul Crm.

**Bukti.** Modules/Subcontract/Services/SubcontractService.php:165-172; Modules/Subcontract/Services/ClaimService.php:203-217; ContractChangeOrderService hanya di Modules/Crm.

**Dampak.** Perubahan lingkup subkon di lapangan (sangat lazim, apalagi bila CCO pelanggan menurunkan pekerjaan tambahan ke subkon) memaksa SPK baru terpisah — riwayat progres, retensi, dan evaluasi pecah antar dokumen untuk satu paket pekerjaan yang sama.

**Usaha.** 2-3 hari (addendum SPK ber-approval yang menambah/mengubah baris dan menaikkan plafon klaim) — **Rekomendasi.** Tiru pola CCO Crm untuk SPK: dokumen addendum yang setelah disetujui menyesuaikan value dan baris SPK.

## 49. 🟠 Subkontrak — uang muka

**Temuan.** Uang muka subkon tidak ada sama sekali: ApBillService menolak advance untuk klaim subkon ('Uang muka hanya dapat dibuat atas pesanan pembelian (PO)'), scm_progress_claims tidak punya kolom pemotongan uang muka, dan rumus ClaimService (net_payable = gross − retensi + PPN − PPh) tidak mengenal pengembalian DP.

**Bukti.** Modules/Finance/Services/ApBillService.php:321-323, 546-548; Modules/Subcontract/Database/Migrations/2026_07_25_000920_create_scm_progress_claims_table.php; Modules/Subcontract/Services/ClaimService.php:174-197.

**Dampak.** DP mobilisasi 10-20% ke subkon — praktik standar SPK konstruksi — tidak bisa dibukukan; bila dipaksakan lewat tagihan manual, DPP-nya didebit ke beban proyek (jalur klasik ApBillService) sehingga biaya subkon terhitung dua kali saat opname ditagih.

**Usaha.** 2-3 hari (advance per SPK + kolom pemotongan proporsional di opname, meniru pola advance PO) — **Rekomendasi.** Perluas is_advance ke SPK dan tambahkan advance_recovery_amount di klaim yang mengurangi net_payable serta mengkredit 1-1500 saat tagihan opname disetujui.

## 50. 🟠 Tabel / Ekspor CSV

**Temuan.** Tidak ada tombol ekspor CSV/Excel di layar daftar generik mana pun, dan tidak pula di laporan keuangan (neraca saldo, laba rugi, neraca, aging AR/AP, profitabilitas proyek) — satu-satunya jalan keluar adalah window.print(). Ekspor hanya ada untuk 4 tabel data master dan ekspor pajak.

**Bukti.** public/app/js/views/list.js (tanpa export); public/app/js/views/reports.js:287 (hanya tombol print); public/app/js/views/masterdata.js:151-159 dan taxexport.js:205-210 (satu-satunya ekspor)

**Dampak.** Aging piutang untuk rapat kolektibilitas, neraca saldo untuk KAP, atau daftar PO untuk vendor harus disalin manual ke Excel — pekerjaan berulang tiap tutup bulan.

**Usaha.** 1-2 hari (fungsi csvFromTable generik dari payload yang sudah ada di memori; pola unduh sudah ada di taxexport.js:45-55) — **Rekomendasi.** Tambah tombol 'Unduh CSV' di pager list.js dan di setiap tab reports.js, membangun CSV dari data yang sudah dirender.

## 51. 🟠 Tabel / Sorting

**Temuan.** Tidak ada sorting kolom sama sekali di layar daftar generik: header <th> tidak punya handler klik, tidak ada parameter sort/order dikirim ke API, dan tidak ada sorting client-side.

**Bukti.** public/app/js/views/list.js:237-254 (thead dibangun tanpa listener); grep 'sort' pada list.js = 0 hit

**Dampak.** Finance tidak bisa mengurutkan invoice per jatuh tempo atau nilai terbesar; PM tidak bisa mengurutkan proyek per deviasi. Semua bergantung urutan default server, memaksa scan visual halaman per halaman.

**Usaha.** 1-2 hari (sort client-side untuk halaman aktif; param sort ke server bila endpoint mendukung) — **Rekomendasi.** Minimal: klik th mengurutkan 20 baris halaman aktif; ideal: kirim sort ke API dan simpan di state per-resource yang sudah ada.

## 52. 🟠 Termin berbasis waktu (kontrak pemeliharaan)

**Temuan.** Termin tidak punya kolom tanggal rencana tagih — hanya billed_at — sehingga termin kalender (kuartalan kontrak PM) tidak bisa dijadwalkan atau diingatkan. Scheduler aplikasi hanya berisi dua job: svc:generate-pm dan erp:backup-watch; tidak ada job pengingat penagihan.

**Bukti.** Modules/Crm/Database/Migrations/2026_07_25_000350_create_crm_contract_termins_table.php (tanpa due date); grep 'Schedule' di Modules/*/Providers = CoreServiceProvider.php:100 dan ServiceDeskServiceProvider.php:31 saja. Bukti hidup: kontrak demo CTR/2026/III/0003 (maintenance) — termin 'Triwulan II 25%' (id 11) masih billed_at=NULL per 31-07-2026 padahal sudah masuk triwulan III; TW I ditagih 06-04-2026.

**Dampak.** Penagihan rutin kontrak pemeliharaan bergantung pada ingatan orang; di data demo satu kuartal penuh (Rp 120 juta) terlewat tanpa satu pun sinyal dari sistem — pendapatan berulang yang paling mudah ditagih justru yang paling mudah lolos.

**Usaha.** 1 hari: kolom due_date pada termin + job harian yang menotifikasi fin.create untuk termin jatuh tempo belum billed. — **Rekomendasi.** Isi due_date otomatis untuk pola kuartalan saat kontrak dibuat; tampilkan termin lewat-tempo di kartu dasbor 'Termin siap ditagih' yang sama dengan temuan milestone.

## 53. 🟠 UX penagihan termin

**Temuan.** Menagih termin dari layar Invoice Termin (AR) mengharuskan pengguna MENGETIK ID internal database termin ('Termin kontrak (ID)', input angka). Tidak ada lookup termin per kontrak yang belum ditagih, dan tabel jadwal termin di detail kontrak tidak punya aksi 'Tagih termin ini'.

**Bukti.** public/app/js/schema.js:1594 ({ key: 'termin_id', label: 'Termin kontrak (ID)', type: 'number', createOnly: true }); schema.js:289-300 (tabel termin detail kontrak tanpa aksi baris); satu-satunya aksi kontrak adalah 'Aktifkan Kontrak' (schema.js:302-306).

**Dampak.** Pengguna finance harus tahu ID numerik internal (mis. termin 2 kontrak 1 = id 2) — salah ketik menagih termin yang salah (guard hanya menangkap termin yang SUDAH billed). Praktisnya pengguna akan lari ke invoice manual, yang membuat billed_at termin tidak pernah terisi dan jadwal termin kehilangan fungsi pelacakannya.

**Usaha.** 0.5-1 hari: ganti input angka dengan lookup termin (filter: kontrak terpilih, belum billed) atau tombol 'Tagih' langsung di tabel termin detail kontrak. — **Rekomendasi.** Tombol 'Tagih termin ini' pada baris termin di detail kontrak adalah jalur paling alami — konteks (nilai, syarat, status milestone) sudah di layar.

## 54. 🟠 Visibilitas arus kas

**Temuan.** Tidak ada laporan arus kas (PSAK 2), tidak ada proyeksi kas, dan tidak ada saldo bank di dasbor. Tidak satu layar pun menjawab 'cukupkah kas bulan depan untuk gaji + subkon + pajak?' — padahal semua bahan bakunya sudah ada di database.

**Bukti.** grep 'arus kas|cash flow|forecast|proyeksi kas' di seluruh repo kosong; ReportService.php:33-311 hanya trialBalance/profitLoss/balanceSheet/projectProfitability/aging; reports.js:10-15 enam tab tanpa arus kas; dashboard.js:107-116 hanya total piutang/hutang terbuka tanpa saldo bank; data tersedia: due_date AR/AP (ReportService.php:326-343), jadwal termin berencana tanggal (crm_contract_termins.billed_at — migrasi 2026_07_25_000350:19), saldo bank per akun via rekonsiliasi.

**Dampak.** Keputusan paling menentukan hidup-mati kontraktor — kapan menarik termin, pembayaran vendor mana yang ditunda, kapan butuh kredit modal kerja — diambil di luar sistem berbasis feeling/spreadsheet. Laporan keuangan bulanan juga tidak lengkap secara SAK tanpa laporan arus kas.

**Usaha.** 2-4 hari (laporan arus kas metode langsung dari jurnal akun bank + layar proyeksi 90 hari dari due date AR/AP + jadwal termin + jadwal gaji/pajak) — **Rekomendasi.** Mulai dari proyeksi 13 minggu sederhana: saldo bank + AR jatuh tempo + termin berencana − AP jatuh tempo − gaji rata-rata − hutang pajak berjalan; datanya tinggal di-join.

## 55. 🟡 Aset — penghapusbukuan/penjualan tanpa jurnal

**Temuan.** Status aset bisa diubah menjadi 'disposed' lewat update biasa tanpa akuntansi apa pun: tidak ada jurnal pelepasan (keluarkan harga perolehan & akumulasi penyusutan, akui laba/rugi), kolom disposal_date/disposal_value ada di tabel tetapi tidak dikonsumsi kode mana pun, dan satu-satunya posting GL modul Assets adalah run penyusutan.

**Bukti.** Modules/Assets/Http/Requests/AssetUpdateRequest.php:31 (status disposed via Rule::in update biasa); Modules/Assets/Http/Controllers/AssetController.php:65-84 (update tanpa jurnal); Modules/Assets/Services hanya berisi DeploymentService + DepreciationService, dan grep autoPost/Journal di modul Assets hanya mengenai DepreciationService.php:160,181,227; migrasi 2026_07_25_000510_create_ast_assets_table.php:30-31 (disposal_date/disposal_value tak berpemakai); DepreciationService.php:79 hanya mengecualikan disposed dari run berikutnya.

**Dampak.** Alat yang dijual, hilang, atau di-scrap tetap tercantum di neraca pada harga perolehan berikut akumulasi penyusutannya selamanya — nilai aset tetap overstated, laba/rugi pelepasan tidak pernah diakui, hasil penjualan aset hanya bisa dibukukan JV manual yang tidak terhubung ke aset, dan daftar aset tidak akan pernah cocok dengan GL saat audit.

**Usaha.** 1 hari: aksi 'Hapus buku/Jual' yang mengisi disposal_date/value dan memposting jurnal pelepasan otomatis. — **Rekomendasi.** Ikuti pola DepreciationService::postJournal yang sudah ada; tolak perubahan status ke disposed lewat update biasa agar jalurnya tunggal.

## 56. 🟡 Asumsi entitas tunggal / intercompany

**Temuan.** Arsitektur mengunci satu entitas hukum: profil perusahaan adalah tabel satu baris, jurnal dan seluruh dokumen tidak punya dimensi entitas, dan tidak ada konsep KSO (kerja sama operasi) yang lazim pada tender konstruksi Indonesia.

**Bukti.** Migrasi Modules/Core/Database/Migrations/2026_07_25_000100_create_core_company_table.php (tabel core_company tunggal tanpa relasi ke dokumen); migrasi fin_journals (2026_07_25_001130) tanpa kolom company/entity; grep 'entity|entitas|KSO|intercompany' di Modules kosong kecuali label BPJS.

**Dampak.** Bila perusahaan memenangkan tender lewat KSO atau bertransaksi dengan entitas afiliasi, transaksinya terpaksa dicampur ke buku entitas tunggal (salah saji) atau dikelola sepenuhnya di luar sistem. Bukan cacat hari ini, tetapi batasan yang harus disadari sebelum menandatangani KSO pertama.

**Usaha.** Berminggu-minggu bila dibutuhkan (perubahan arsitektur); 1 jam untuk mendokumentasikannya sebagai batasan sistem — **Rekomendasi.** Dokumentasikan batasan entitas tunggal secara eksplisit; bila KSO muncul, tangani sebagai proyek dengan pencatatan porsi (proportionate share) sebelum memutuskan multi-entity penuh.

## 57. 🟡 Bahasa / Konsistensi ID-EN

**Temuan.** Label campur Inggris di layar berbahasa Indonesia: tombol 'Update' dan teks 'rollup' di tabel WBS; 'Generate WBS dari BOQ'; tab 'Kartu stok (ledger)' dan deskripsi 'moving-average'; header tabel Utilisasi Aset di-auto-titleize dari key API Inggris (mis. 'Total Days'); fallback titleize() di detail generik menghasilkan label Inggris untuk key di luar kamus LABELS (mis. depreciation_start_date → 'Depreciation Start Date'); toast error menampilkan nama field mentah Inggris ('items.0.unit_price: ...').

**Bukti.** public/app/js/views/project.js:94-95,155; custom.js:50,57,1182-1184; detail.js:114-120 (fallback titleize); api.js:19

**Dampak.** Kesan tidak selesai di dokumen yang dilihat klien/auditor (detail, cetak), dan istilah teknis Inggris membingungkan operator lapangan.

**Usaha.** 0,5-1 hari (ganti label eksplisit; lengkapi kamus LABELS; peta label kolom utilisasi) — **Rekomendasi.** Audit cepat: grep tombol/teks literal di views; jadikan fallback titleize men-log key yang tak dikenal saat dev.

## 58. 🟡 CRM — lead tidak mengikuti nasib penawarannya

**Temuan.** Status lead sepenuhnya manual: QuotationService::markWon/markLost tidak pernah menyentuh lead terkait (quotation.lead_id ada tapi tak dipakai untuk apa pun), tidak ada aksi konversi lead→customer (penawaran mensyaratkan customer_id yang sudah ada), dan tidak ada field follow-up/next-action pada lead.

**Bukti.** Modules/Crm/Services/QuotationService.php:81-125 (won/lost tanpa update lead); grep 'LeadStatus::' = hanya request/model; Modules/Crm/Http/Controllers/LeadController.php (CRUD polos tanpa convert); Modules/Crm/Http/Requests/QuotationStoreRequest.php:19 (customer_id wajib); migrasi crm_leads (tanpa kolom follow-up).

**Dampak.** Pipeline CRM (menang/kalah per sales, konversi lead) tidak akurat karena status lead membeku di 'Penawaran Dikirim' kecuali diedit manual; data prospek diketik dua kali (lead lalu customer).

**Usaha.** 0.5 hari: set lead→Won/Lost di markWon/markLost; aksi 'Jadikan pelanggan' yang menyalin data lead. — **Rekomendasi.** Sekalian tambahkan tanggal follow-up berikutnya pada lead agar funnel awal juga punya pengingat.

## 59. 🟡 Cetak / Print stylesheet

**Temuan.** @media print hanya menyembunyikan chrome (nav, header, filters, pager) dan memutihkan background body — token warna tidak dipaksa terang. Pengguna bertema gelap mencetak teks --text #e7ebf0 (abu sangat terang) di kertas putih; .toasts juga tidak disembunyikan sehingga toast aktif ikut tercetak.

**Bukti.** public/app/css… app.css:780-786 (blok print) vs :root[data-theme="dark"] app.css:85-96; .toasts tidak ada di daftar display:none baris 781

**Dampak.** Tombol cetak tersedia di hampir semua layar detail dan merupakan satu-satunya jalur keluar laporan keuangan — hasil cetak nyaris kosong bagi pengguna tema gelap.

**Usaha.** 2 jam (redeklarasi token terang di dalam @media print + sembunyikan .toasts/.overlay) — **Rekomendasi.** Salin blok token light ke dalam @media print { :root { … } } dan tambah .toasts ke daftar hidden.

## 60. 🟡 Customer portal

**Temuan.** Tidak ada portal pelanggan: seluruh endpoint di balik login sanctum + permission internal; 'portal' hanya nilai enum channel tiket. Pelanggan kontrak layanan tidak bisa membuat/melacak tiket, melihat SLA, atau mengunduh invoice/BAST sendiri.

**Bukti.** routes/web.php:9-27 (hanya SPA + /status + login JSON); Modules/Iam/Routes (auth/login tunggal); grep 'portal' hanya kena enum channel di Modules/ServiceDesk/Database/Migrations/2026_07_25_001220_create_svc_tickets_table.php:22 dan enums.js:110.

**Dampak.** Untuk SI dengan kontrak maintenance multi-site, semua permintaan servis masuk lewat telepon/WA dan diketik ulang admin — lambat, tak teraudit, dan kalah bersaing dengan vendor yang memberi portal + laporan SLA ke pelanggan korporat.

**Usaha.** 5-10 hari (aktor baru non-internal: scope per customer, buat/lihat tiket + status SLA; tahap lanjut: unduh invoice) — **Rekomendasi.** Mulai dari yang sempit: token per kontak pelanggan yang hanya bisa CRUD tiket milik kontraknya sendiri.

## 61. 🟡 Eskalasi harga kontrak multi-tahun

**Temuan.** Penyesuaian harga karena eskalasi (klausul standar kontrak pemerintah multi-tahun) tidak didukung: tidak ada fitur, formula, maupun istilahnya di kode. Satu-satunya jalan mengubah nilai kontrak adalah CCO, yang semantiknya pekerjaan tambah-kurang (judul, alasan, lingkup) — bukan penyesuaian indeks harga.

**Bukti.** grep -i 'eskalasi|escalation' di seluruh Modules = nol hasil; ContractChangeOrder hanya punya value_change + reason (Modules/Crm/Services/ContractChangeOrderService.php:44-47).

**Dampak.** Untuk kontrak konstruksi multi-tahun, perhitungan eskalasi (indeks BPS) dikerjakan di Excel lalu dicatat sebagai 'pekerjaan tambah' yang salah makna — jejak audit menyesatkan saat pemeriksaan. Workaround via CCO tetap ada, sehingga bukan pemblokir.

**Usaha.** 2-3 hari bila ingin formula indeks; alternatif murah 0.5 hari: tipe/kategori pada CCO ('tambah-kurang' vs 'eskalasi harga') agar minimal jejaknya benar. — **Rekomendasi.** Mulai dari kategori CCO — kebutuhan formula penuh baru relevan bila portofolio kontrak pemerintah multi-tahun bertambah.

## 62. 🟡 Feedback / Skeleton layar detail custom

**Temuan.** Delapan layar detail custom (payroll run, tiket, SPK subkon, pembayaran, peran, karyawan, aset, revenue run) mengosongkan host lalu await fetch tanpa skeleton/spinner — area konten blank total selama request berjalan; renderDetail generik dan renderProject justru sudah benar (skeleton).

**Bukti.** public/app/js/views/custom.js:177-186, 287-292, 390-399, 561-566, 699-706, 789-798, 893-898, 998-1002 (clear(host) → loadOrFail tanpa placeholder); bandingkan detail.js:236-237 dan project.js:115-116

**Dampak.** Di koneksi site yang lambat, klik payroll/tiket tampak 'mati' 1-3 detik; pengguna mengklik ulang.

**Usaha.** 0,5 hari (satu baris skeleton sebelum loadOrFail di 8 tempat) — **Rekomendasi.** Salin pola skeleton renderDetail ke loadOrFail sehingga otomatis untuk semua pemakainya.

## 63. 🟡 Form / Lookup kosong tanpa penjelasan (403)

**Temuan.** Sumber lookup yang ditolak 403 di-cache sebagai daftar kosong tanpa indikasi apa pun ke pengguna — field 'Sales penanggung jawab' (lookup users butuh iam.view) tampil sebagai select berisi '—' saja bagi user sales tanpa hak IAM.

**Bukti.** public/app/js/lookup.js:75-81 (catch 403 → cache []); schema.js:111 (lead.user_id lookup 'users'); SOURCES users → iam/users (lookup.js:24)

**Dampak.** Pengguna mengira data belum ada, bukan bahwa haknya kurang; dokumen dibuat tanpa penanggung jawab.

**Usaha.** 0,5 hari (tandai sumber 'forbidden' dan tampilkan hint 'butuh hak akses X' di field) — **Rekomendasi.** Simpan status 403 di cache dan render placeholder berbeda ('Tidak punya akses daftar pengguna').

## 64. 🟡 Hak akses posting penyusutan

**Temuan.** Posting run penyusutan membutuhkan ast.post, tetapi tidak ada satu role bawaan pun (selain admin) yang memegangnya — role finance bahkan tidak punya ast.view sehingga menu Assets tidak tampak baginya.

**Bukti.** Modules/Assets/Routes/api.php:48 (post butuh permission:ast.post); RoleSeeder.php:29-32 project-manager hanya view/create/update untuk ast; RoleSeeder.php:24-27 direktur hanya view+approve; RoleSeeder.php:56-59 finance tanpa prefix ast sama sekali.

**Dampak.** Langkah penyusutan pada tutup buku bulanan hanya bisa dieksekusi akun admin/IT, bukan orang finance yang bertanggung jawab atas close — dalam praktik langkah ini akan terlewat atau dikerjakan oleh orang yang salah secara kontrol.

**Usaha.** 1 jam (tambahkan ast.view + ast.post ke role finance di RoleSeeder, atau sesuaikan lewat layar Roles) — **Rekomendasi.** Beri role finance ast.view dan ast.post; biarkan ast.create di PM/admin agar pemisahan pembuat-vs-pemosting tetap ada.

## 65. 🟡 Mata uang tunggal IDR (pengadaan impor SI)

**Temuan.** Tidak ada dukungan valuta asing sama sekali: tidak ada kurs/exchange rate di mana pun, PO/tagihan vendor tidak punya kolom mata uang, dan 'currency' hanya muncul di parser rekening koran sebagai validasi. Padahal lini system-integrator lazim membeli perangkat dari principal dengan penawaran USD.

**Bukti.** grep -ri 'exchange_rate|kurs' di Modules = nol hasil; migrasi prc_purchase_orders (2026_07_25_000830) dan fin_ap_bills (2026_07_25_001160) tanpa kolom mata uang; 'currency' hanya di Mt940Parser.php:295 dan fin_bank_statements (2026_07_25_001198) untuk konsistensi berkas import.

**Dampak.** PO ke principal harus dikonversi manual di luar sistem: nilai komitmen tidak mengikat kurs, selisih kurs antara PO-tagihan-pembayaran tidak pernah terbukukan (salah saji biaya proyek), dan hutang vendor USD tidak bisa dinyatakan ulang akhir periode. Bukan pemblokir untuk pekerjaan konstruksi lokal — tetapi batasan yang harus disadari dan didokumentasikan sebelum kontrak pengadaan impor pertama.

**Usaha.** 1 jam untuk mendokumentasikan sebagai batasan; dukungan FX sungguhan berminggu-minggu (kolom mata uang + kurs dokumen + selisih kurs realisasi/translasi). — **Rekomendasi.** Jangka pendek cukup kolom catatan kurs indikatif pada PO/tagihan agar jejak konversinya tersimpan; putuskan FX penuh hanya bila volume impor material.

## 66. 🟡 Mobile / Responsif 390px

**Temuan.** Fondasi responsif ada (drawer nav <760px, form-grid 1 kolom <900px, layar Lapangan memang mobile-first), tetapi kosakata kolom hideOnNarrow yang didokumentasikan di schema.js tidak diimplementasikan di mana pun — di 390px semua tabel daftar (7-10 kolom) hanya mengandalkan scroll horizontal, dan form line-item di modal .wide menuntut scroll dua arah di dalam modal.

**Bukti.** schema.js:5 (dokumentasi hideOnNarrow); grep hideOnNarrow di seluruh public/app/js = 1 hit (komentar itu saja); app.css:749-778 (hanya 2 breakpoint, tanpa aturan kolom); app.css:680 (.modal.wide 960px)

**Dampak.** Menyetujui PO dari ponsel (kasus nyata: direktur di perjalanan) berarti menggeser-geser tabel; entry baris di ponsel praktis tidak mungkin.

**Usaha.** 1 hari (implementasi hideOnNarrow di list.js/detail.js + tandai kolom sekunder di schema) — **Rekomendasi.** Implementasikan flag yang sudah didesain: sembunyikan kolom hideOnNarrow di bawah 760px; layar approval cukup kode-nilai-status.

## 67. 🟡 Navigasi / Detail visual

**Temuan.** Ikon chevron grup navigasi tidak pernah berputar saat grup ditutup: CSS menganimasikan .nav-group button .chev tetapi icon() tidak pernah memberi class 'chev' pada svg yang dirender, sehingga selector mati.

**Bukti.** app.css:279-280 (selector .chev, transform rotate) vs public/app/js/ui.js:75-91 (icon() tanpa class) dan app.js:154 (icon('chevron',13) dipakai polos)

**Dampak.** Satu-satunya indikator buka/tutup grup adalah muncul-hilangnya item — afordans kecil tapi terlihat setiap hari.

**Usaha.** 5 menit (svg.classList.add('chev') atau param class di icon()) — **Rekomendasi.** Tambahkan class saat membangun tombol grup di app.js buildShell.

## 68. 🟡 Pengadaan — evaluasi vendor

**Temuan.** Loop evaluasi kinerja tidak menutup: skor (termasuk ketepatan kirim) diisi manual padahal data GRN vs expected_date ada; rating tidak tampil di lookup pemilihan vendor (lookup.js:16 hanya name+code) dan tidak ada peringatan/blokir vendor ber-rating rendah saat membuat PO/SPK; tidak ada pemicu evaluasi saat PO close/SPK selesai (demo: 1 evaluasi untuk 5 vendor).

**Bukti.** Modules/Procurement/Services/VendorEvaluationService.php:11-47 (manual murni); public/app/js/lookup.js:16; PoService.php:23 (tanpa cek rating); DB demo prc_vendor_evaluations = 1.

**Dampak.** Evaluasi vendor menjadi formalitas terpisah dari keputusan pembelian — vendor yang sering terlambat atau bermasalah tetap terpilih tanpa hambatan.

**Usaha.** 2-3 hari (auto-suggest skor kirim dari GRN vs expected_date, rating di lookup, prompt evaluasi saat PO close) — **Rekomendasi.** Tampilkan rating pada picker vendor, hitung delivery score otomatis dari riwayat GRN, dan minta evaluasi saat menutup PO/SPK bernilai besar.

## 69. 🟡 Pengadaan — legalitas vendor/subkon tanpa masa berlaku

**Temuan.** Master vendor hanya menyimpan NPWP/SPPKP sebagai teks; tidak ada dokumen legal ber-masa-berlaku (NIB, SBU Konstruksi, SKK penanggung jawab, sertifikat principal untuk distributor ICT) sehingga kelayakan subkon tak bisa dipantau.

**Bukti.** Modules/Procurement/Database/Migrations/2026_07_25_000800_create_prc_vendors_table.php:11-38 (tidak ada satu pun kolom/tabel dokumen ber-expiry); evaluasi vendor hanya rating transaksional (000850).

**Dampak.** Memakai subkon dengan SBU kadaluarsa di proyek pemerintah bisa menggugurkan pembayaran atau kena temuan audit; procurement baru sadar saat dokumen diminta owner.

**Usaha.** 1-2 hari (tabel dokumen vendor: jenis, nomor, berlaku s/d + lampiran — AttachableDocuments sudah mendukung procurement/vendors — + expiry ke deadline-watch) — **Rekomendasi.** Tambah sub-tabel dokumen legal vendor dan tandai vendor 'dokumen kadaluarsa' di layar pemilihan vendor PO/SPK.

## 70. 🟡 Pengadaan — pemantauan pengiriman

**Temuan.** Tidak ada laporan backorder/keterlambatan: expected_date PO tidak dipakai di mana pun selain tampilan, qty vs qty_received hanya terlihat per dokumen PO, dan menu Pengadaan (schema.js:2381-2384) tidak punya layar outstanding lines.

**Bukti.** grep expected_date = hanya PoService.php:85 (copy dari PR) dan label detail.js:67; public/app/js/schema.js:2381-2384.

**Dampak.** Kiriman terlambat baru ketahuan saat site kehabisan material; ekspeditor harus membuka PO satu per satu untuk tahu apa yang belum datang.

**Usaha.** 1 hari (layar 'PO Outstanding': baris belum terkirim penuh, umur vs expected_date) — **Rekomendasi.** Laporan baris PO terbuka dengan sorot lewat-jadwal; sekaligus jadi cek disiplin penutupan PO.

## 71. 🟡 Peralatan kecil — peminjaman alat

**Temuan.** Item bertipe 'tool' ada di persediaan, tetapi satu-satunya jalan keluar gudang adalah inv_issues (konsumsi ke biaya proyek) atau transfer antar gudang — tidak ada mekanisme pinjam-kembali alat ke orang/proyek. Kustodian hanya ada untuk aset besar.

**Bukti.** public/app/js/enums.js:53-56 (itemType 'tool'); Modules/Inventory/Routes (issues/transfers saja, tidak ada checkout/return); ast_assets.custodian_employee_id hanya di modul Aset (migrasi 000510:27) untuk aset terdaftar, bukan alat bantu stok.

**Dampak.** Bor, mesin las, scaffolding, alat ukur — barang bernilai jutaan yang berpindah antar proyek — hilang tanpa penanggung jawab; opname hanya menemukan selisih, bukan siapa yang terakhir memegang.

**Usaha.** 3-4 hari (transaksi pinjam/kembali alat: ke karyawan+proyek, laporan 'alat di tangan siapa', integrasi opname) — **Rekomendasi.** Tambah tipe transaksi checkout/checkin untuk item_type=tool dengan pemegang wajib (employee_id) dan tanggal janji kembali.

## 72. 🟡 Persediaan — valuasi GRN

**Temuan.** Harga satuan GRN diketik manual tanpa default dan tanpa validasi terhadap harga baris PO: GoodsReceiptService::syncItems memakai unit_cost input (default 0) dan StockService memposting nilai berapa pun — termasuk 0, yang diperlakukan diam-diam sebagai 'free-issue' tanpa jurnal.

**Bukti.** Modules/Inventory/Services/GoodsReceiptService.php:60-71; Modules/Inventory/Services/StockService.php:766-770 (nilai 0 dilewati tanpa peringatan); form schema.js:1130 (unit_cost diketik bebas).

**Dampak.** Salah ketik harga di GRN merusak HPP rata-rata gudang permanen (selisihnya di tagihan lari ke 6-4500, bukan mengoreksi persediaan) — biaya material proyek yang dihitung dari average palsu ikut salah; GRN berharga 0 membuat stok bernilai nol lalu issue membebani proyek Rp 0.

**Usaha.** 0,5-1 hari (prefill harga dari baris PO + peringatan bila menyimpang > toleransi atau 0) — **Rekomendasi.** Isi otomatis unit_cost dari harga PO saat baris tertaut PO (satu paket dengan perbaikan po_item_id) dan konfirmasi eksplisit untuk harga 0.

## 73. 🟡 Retensi — dua pola tumpang tindih tanpa pagar

**Temuan.** Sistem mendukung dua pola retensi sekaligus tanpa guard atau petunjuk: (a) retensi dipotong per-invoice via checkbox 'Tahan retensi sesuai kontrak' (dpp × retention_pct), dan (b) retensi sebagai termin terakhir 5% dalam jadwal 100% (pola kontrak demo 1 dan 2: termin 'Retensi 5%'). Tidak ada yang mencegah finance mencentang potongan retensi pada termin 1-4 DAN kemudian menagih termin 'Retensi 5%' — retensi tercatat dobel.

**Bukti.** Modules/Finance/Services/ArInvoiceService.php:94-100 (opsi withhold_retention dari retention_pct kontrak); public/app/js/schema.js:1595 (checkbox tanpa help-text kondisi pakai); data demo crm_contract_termins id=5 dan id=9 ('Retensi 5%' sebagai termin) pada kontrak yang retention_pct-nya juga 5%.

**Dampak.** Jika kedua pola dipakai bersamaan pada satu kontrak, saldo 1-1350 Piutang Retensi dobel (~5% nilai kontrak = Rp 2,4 M pada kontrak 1), penagihan ke pelanggan efektif 105%, dan rekonsiliasi retensi vs kontrak tidak akan pernah ketemu.

**Usaha.** 0.5 hari: bila jadwal kontrak memuat termin bertanda retensi, sembunyikan/blokir opsi withhold_retention untuk kontrak itu (atau sebaliknya), plus help-text menjelaskan dua pola. — **Rekomendasi.** Tambahkan flag is_retention pada termin agar sistem tahu pola mana yang dipakai kontrak tersebut.

## 74. 🟡 Sinkronisasi nilai kontrak → proyek setelah CCO

**Temuan.** prj_projects.contract_value adalah salinan sekali-jalan saat proyek dibuat dari kontrak dan tidak pernah diperbarui ketika CCO disetujui — CCO approve hanya menyentuh crm_contracts. Layar workspace proyek (tile 'Nilai kontrak' dan 'Retensi ditahan' = contract_value × retention_pct) membaca salinan basi itu.

**Bukti.** Modules/Crm/Services/ContractChangeOrderService.php:124-129 (hanya crm_contracts); Modules/Projects/Services/ProjectService.php:57 (salin sekali di createFromContract); public/app/js/views/project.js:175,200 dan Modules/Projects/Models/Project.php:99-102 (retentionAmount dari salinan).

**Dampak.** Setelah pekerjaan tambah-kurang, angka nilai kontrak dan retensi yang dilihat tim proyek berbeda dari angka kontrak yang dipakai finance dan mesin pengakuan pendapatan (yang benar membaca crm_contracts.value) — dua versi kebenaran untuk angka paling penting proyek.

**Usaha.** 0.5 hari: perbarui prj_projects.contract_value di dalam transaksi approve CCO, atau tampilkan nilai dari relasi kontrak. — **Rekomendasi.** Sinkron di CCO approve paling aman karena laporan lain juga membaca kolom salinan ini.

## 75. 🟡 Subkontrak — gate retensi

**Temuan.** Pelepasan retensi subkon tidak bersyarat apa pun selain saldo: tidak ada field masa pemeliharaan/BAST di scm_subcontracts, dan RetentionService::release tidak memeriksa progres 100% ataupun tanggal — kontras dengan sisi pelanggan yang punya prj_bast.retention_release_due.

**Bukti.** Modules/Subcontract/Services/RetentionService.php:37-66; Modules/Subcontract/Database/Migrations/2026_07_25_000900_create_scm_subcontracts_table.php (hanya start/end date); Modules/Finance/Services/ArRetentionService.php:42-59 (pembanding sisi AR).

**Dampak.** Retensi 5% bisa dilepas sehari setelah opname pertama — fungsi jaminan cacat mutu hilang; perusahaan kehilangan leverage terhadap subkon selama masa pemeliharaan.

**Usaha.** 1 hari (field masa pemeliharaan/BAST subkon + guard tanggal & progres di release) — **Rekomendasi.** Tambah defect_liability_until (atau referensi BAST subkon) di SPK dan tolak release sebelum tanggal itu kecuali override beralasan.

## 76. 🟡 Tabel / Header sticky tidak berfungsi & kepadatan

**Temuan.** th position:sticky berada di dalam .table-wrap{overflow-x:auto}; overflow non-visible menjadikan wrapper itu konteks sticky, dan karena wrapper tidak pernah scroll vertikal, header ikut tergulung di daftar panjang (COA perPage 100, stok 200 baris, neraca saldo). Tidak ada pula pemilih baris-per-halaman atau opsi kepadatan baris.

**Bukti.** app.css:461 (.table-wrap overflow-x:auto) + 469-471 (th sticky); schema.js:1451 (perPage:100); list.js:16,22 (perPage tetap), pager list.js:260-286 tanpa pemilih

**Dampak.** Membaca kolom ke-7 pada baris ke-80 neraca saldo tanpa header terlihat = salah baca kolom.

**Usaha.** 0,5 hari (beri .table-wrap max-height + overflow-y:auto agar sticky hidup; tambah select per-page di pager) — **Rekomendasi.** Aktifkan sticky dengan menjadikan .table-wrap scroll container dua arah pada daftar panjang.

## 77. 🟡 Tabel / Total kolom uang

**Temuan.** Layar daftar generik tidak menampilkan total untuk kolom currency (tanpa tfoot), padahal infrastruktur totalnya sudah ada dan dipakai tabel detail (totalKey), saldo stok, dan payroll.

**Bukti.** public/app/js/views/list.js:237-258 (tabel tanpa tfoot); bandingkan detail.js:170-185 (totalKeys) dan custom.js:120-123 (tfoot stok)

**Dampak.** Total outstanding AR terfilter, total nilai PO ke satu vendor, dsb. harus dihitung di luar sistem.

**Usaha.** 0,5 hari total halaman (kolom bertanda totalKey di schema); total keseluruhan butuh dukungan API — **Rekomendasi.** Tambahkan tfoot 'Total halaman ini' dari payload yang sudah ada — jujur menyebut cakupannya.

## 78. 🟡 Tender/Estimasi — analitik win-rate

**Temuan.** Data menang/kalah penawaran sudah dicatat (won_at, lost_at, lost_reason) tetapi tidak ada satu pun layar/endpoint yang mengagregasinya: tidak ada win-rate per periode/sales/kategori, tidak ada analisis alasan kalah, tidak ada nilai pipeline tertimbang.

**Bukti.** Modules/Crm/Services/QuotationService.php:92,119 (stamp won_at/lost_at); schema.js:178-192 (aksi Tandai Menang/Kalah + lost_reason wajib). Laporan yang ada hanya 6 laporan keuangan (public/app/js/views/reports.js:10-15); satu-satunya endpoint statistik di seluruh API adalah safety-incidents/statistics (Modules/Projects/Routes/api.php).

**Dampak.** Manajemen tak bisa menjawab 'berapa persen tender kita menang, di segmen mana kita kalah, dan kenapa' — keputusan pricing dan pemilihan tender berjalan tanpa data padahal datanya sudah tersimpan.

**Usaha.** 1-2 hari (endpoint agregasi + kartu di dasbor/laporan; datanya sudah lengkap) — **Rekomendasi.** Endpoint crm/reports/pipeline: win-rate per kuartal, nilai menang vs kalah, top alasan kalah.

## 79. 🟡 UI/UX — agregat dasbor terpotong 100 baris

**Temuan.** Dasbor menjumlah nilai kontrak, piutang, dan hutang dari fetch per_page:100 halaman pertama saja — begitu invoice AR/AP approved melebihi 100 baris, angka tile diam-diam terlalu kecil tanpa peringatan.

**Bukti.** public/app/js/views/dashboard.js:72-79 (per_page:100), 89-96 (reduce di sisi klien); public/app/js/api.js:127 (unwrap payload.data — meta pagination dibuang).

**Dampak.** Perusahaan yang menagih puluhan termin per tahun akan melewati 100 invoice terbuka+riwayat dalam 1-2 tahun; direktur mengambil keputusan kas dari angka piutang yang salah tanpa ada tanda apa pun bahwa itu terpotong.

**Usaha.** 0,5-1 hari (endpoint ringkasan agregat di server, atau minimal jumlahkan dari meta.total dan tandai bila terpotong) — **Rekomendasi.** Endpoint dashboard/summary yang menghitung SUM di SQL — sekalian memangkas 14 request paralel saat dasbor dibuka.

## 80. 🟡 UI/UX — dasbor tidak berbasis peran

**Temuan.** Satu dasbor untuk semua: tile disaring per permission modul, bukan per peran/kepemilikan. PM melihat semua proyek (bukan proyeknya — padahal prj_projects.project_manager_id dan users.employee_id ada), direktur tak punya ringkasan eksekutif (tren bulan-ke-bulan, posisi kas), finance tak punya widget arus kas.

**Bukti.** public/app/js/views/dashboard.js:47-126 (satu renderDashboard, gating hanya session.can per modul); peran direktur/project-manager/site-manager/finance memang di-seed (Modules/Iam/Database/Seeders/UserSeeder.php:20-30); project_manager_id ada di migrasi prj_projects baris 34 tetapi tak pernah dipakai untuk memfilter layar mana pun (grep di public/app/js: nihil).

**Dampak.** Semakin banyak proyek, dasbor PM makin bising dan direktur makin tak terlayani — masing-masing peran akhirnya kembali minta rekap manual mingguan, padahal datanya ada.

**Usaha.** 3-5 hari (filter 'proyek saya' dari users.employee_id→project_manager_id + varian kartu untuk direktur/finance) — **Rekomendasi.** Mulai dari yang murah: toggle 'Proyek saya' di dasbor & daftar proyek, lalu kartu kas/bank untuk fin.view.

## 81. 🟡 WIP schedule untuk auditor

**Temuan.** Semua kolom WIP schedule auditor (harga kontrak, EAC, biaya s.d. kini, % penyelesaian, pendapatan kumulatif, tertagih, posisi aset/liabilitas kontrak) sudah dihitung dan tampil di detail run PSAK 115 — tetapi layar itu tidak punya tombol cetak/ekspor, berbeda dari laporan keuangan lain yang bisa dicetak.

**Bukti.** public/app/js/views/custom.js:1099-1110 (kolom tabel per kontrak lengkap); custom.js:1025-1046 aksi pageHead hanya 'Hitung Ulang' dan 'Posting Jurnal' tanpa print; bandingkan reports.js:287 yang memberi tombol cetak pada enam laporan lainnya.

**Dampak.** Saat audit tahunan, WIP schedule — permintaan standar auditor kontraktor — harus disalin manual dari layar ke Excel, rawan salah ketik pada angka yang justru paling ditelaah auditor.

**Usaha.** 2-4 jam (tombol cetak dengan layout print yang sudah ada + ekspor CSV pada detail run) — **Rekomendasi.** Tambahkan tombol cetak/CSV pada layar run PSAK 115 dan beri judul 'WIP Schedule' pada versi cetaknya agar langsung bisa dilampirkan sebagai working paper.
