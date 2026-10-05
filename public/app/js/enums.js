/* Option lists mirrored from the PHP enums under each module's Enums
   directory and the Rule::in() lists in the FormRequests.
   Values must match the API exactly. */

/* Pasangan [value, label] — dan, bila enum itu punya makna warna sendiri,
   [value, label, tone]; sejak UX-C.4 juga [value, label, tone, icon]
   (ERRATA E-3 butir 1). `tone` hanya dipasang bila disebut: '' adalah netral
   yang SAH, sedangkan `null`/kosong berarti "ikuti peta kata bersama di
   format.js" — begitulah statusTone() membedakan keduanya, dan begitulah enum
   status dokumen menulis ikonnya tanpa mengambil alih warnanya (`submitted`
   tetap amber dari peta kata). `icon` = nama simbol sprite Lucide; setiap nilai
   dari setiap enum yang dipakai kolom status wajib punya satu
   (StatusVocabularyTest), supaya status tidak pernah hanya warna. */
const opts = (pairs) => pairs.map(([value, label, tone, icon]) => ({
  value,
  label,
  ...(tone === undefined || tone === null ? {} : { tone }),
  ...(icon === undefined ? {} : { icon }),
}));

export const ENUMS = {
  /* P-0b — kotak keluar pengiriman notifikasi (core_notification_deliveries).
     Warna dipasang di sini: `queued` bukan keadaan normal yang layak hijau,
     dan `skipped` adalah keputusan yang jujur, bukan galat. */
  deliveryChannel: opts([['email', 'E-mail'], ['whatsapp', 'WhatsApp'], ['webpush', 'Web push']]),
  /* P-3a — status balik dari webhook Meta (kosakata Meta apa adanya), terpisah
     dari status baris: `sent` = diterima penyedia; ini = apa yang terjadi
     sesudahnya di jalan. */
  waProviderStatus: opts([
    ['sent', 'Diterima Meta', '', 'send'], ['delivered', 'Sampai', 'green', 'check'],
    ['read', 'Dibaca', 'green', 'eye'], ['failed', 'Gagal di jalan', 'red', 'circle-x'],
  ]),
  deliveryStatus: opts([
    ['queued', 'Antre', 'amber', 'clock'], ['sent', 'Terkirim', 'green', 'send'],
    ['failed', 'Gagal', 'red', 'circle-x'], ['skipped', 'Dilewati', '', 'x'],
  ]),
  documentStatus: opts([
    ['draft', 'Draf', null, 'pencil'], ['submitted', 'Diajukan', null, 'clock'], ['approved', 'Disetujui', null, 'circle-check'],
    ['rejected', 'Ditolak', null, 'circle-x'], ['closed', 'Selesai', null, 'check'], ['cancelled', 'Dibatalkan', null, 'x'],
  ]),
  postingStatus: opts([['draft', 'Draf', null, 'pencil'], ['posted', 'Terposting', null, 'check']]),
  /* Pembayaran keluar (PAY) melewati persetujuan; penerimaan (RCV) langsung
     diposting dan hanya memakai draft/posted dari daftar yang sama. */
  paymentStatus: opts([
    ['draft', 'Draf', null, 'pencil'], ['submitted', 'Diajukan', null, 'clock'], ['approved', 'Disetujui', null, 'circle-check'],
    ['rejected', 'Ditolak', null, 'circle-x'], ['posted', 'Terposting', null, 'check'],
    // Terminal, bukan kembali ke draf: uangnya benar-benar bergerak dan
    // rekening korannya akan selalu mengatakan begitu.
    ['reversed', 'Dibalik', null, 'history'],
  ]),
  /* "Dibatalkan" adalah keadaan KETIGA, bukan jalan pulang ke draf: bon yang
     sudah diposting tetap berdiri di kartu stok selamanya, dan pembatalannya
     adalah pergerakan cermin plus jurnal pembalik (StockService::cancelIssue) —
     persis seperti 'Dibalik' pada paymentStatus di atas. Tanpa baris ini bon
     yang dibatalkan tidak bisa disaring sama sekali di daftar Pengeluaran
     Barang. Hari ini hanya bon yang bisa sampai ke sana; GRN memakai daftar
     yang sama tetapi belum punya jalan kembali, jadi saringannya di layar
     Penerimaan Barang memang selalu kosong — lihat StockDocumentStatus.php. */
  stockDocStatus: opts([['draft', 'Draf', null, 'pencil'], ['posted', 'Diposting', null, 'check'], ['cancelled', 'Dibatalkan', null, 'x']]),
  transferStatus: opts([['draft', 'Draf', null, 'pencil'], ['in_transit', 'Dalam Perjalanan', null, 'truck'], ['received', 'Diterima', null, 'circle-check']]),

  /* Cermin Modules\Procurement\Enums\ProcurementMethod — metode paket belanja
     pada Rencana Pengadaan (PBL, P2). Nilai wajib sama persis dengan API. */
  procurementMethod: opts([
    ['pembelian_langsung', 'Pembelian langsung'],
    ['penunjukan_langsung', 'Penunjukan langsung'],
    ['rfq', 'Seleksi / banding penawaran (RFQ)'],
    ['tender', 'Tender'],
  ]),
  /* Status baris paket PBL — perencanaan, bukan dokumen berjenjang. */
  procurementPlanItemStatus: opts([
    ['planned', 'Direncanakan', null, 'pencil'], ['in_progress', 'Berjalan', null, 'arrow-right'],
    ['contracted', 'Terkontrak', null, 'check'], ['cancelled', 'Dibatalkan', null, 'x'],
  ]),
  procurementPlanStatus: opts([
    ['draft', 'Draf', null, 'pencil'], ['active', 'Aktif', null, 'circle-check'], ['closed', 'Selesai', null, 'check'],
  ]),

  /* P7 — cermin Modules\Crm\Enums\Tkdn*. Nilainya adalah tabel Permenperin
     35/2025 Lampiran IV huruf B, bukan pilihan gaya rumah: tenaga kerja
     dinilai dari KEWARGANEGARAAN, alat kerja dari NEGARA PEMBUAT × KEPEMILIKAN,
     jasa umum dari ASAL PENYEDIA. Tidak ada pilihan "persen dalam negeri" yang
     bisa diketik, di sini maupun di server. */
  tkdnCostGroup: opts([
    ['tenaga_kerja', 'Tenaga kerja'],
    ['alat_kerja', 'Alat kerja / fasilitas kerja'],
    ['jasa_umum', 'Jasa umum'],
  ]),
  tkdnNationality: opts([
    ['wni', 'Warga Negara Indonesia'], ['wna', 'Warga Negara Asing'],
  ]),
  tkdnOrigin: opts([['dn', 'Dalam negeri'], ['ln', 'Luar negeri']]),
  tkdnOwnership: opts([
    ['dn', 'Dimiliki dalam negeri'], ['ln', 'Dimiliki luar negeri'],
    ['campuran', 'Dimiliki dalam negeri + luar negeri'],
  ]),

  scopeType: opts([
    ['construction', 'Konstruksi Gedung'],
    ['system_integration', 'Integrasi Sistem (ELV/ICT)'],
    ['maintenance', 'Pemeliharaan'],
  ]),
  leadStatus: opts([
    ['new', 'Baru', null, 'inbox'], ['contacted', 'Sudah Dihubungi', null, 'send'], ['qualified', 'Terkualifikasi', null, 'star'],
    ['proposal', 'Penawaran Dikirim', null, 'file-text'], ['won', 'Menang', null, 'circle-check'], ['lost', 'Kalah', null, 'circle-x'],
  ]),
  /* Aktivitas CRM (F-3) — cermin Modules\Crm\Enums\ActivityType, dijaga
     ActivityRegistryTest. Tanpa warna: jenis pekerjaan bukan keadaan, dan
     sebuah "Telepon" hijau tidak berarti apa-apa. */
  activityType: opts([
    ['call', 'Telepon'], ['meeting', 'Rapat'], ['email', 'Email'],
    ['visit', 'Kunjungan'], ['note', 'Catatan'],
  ]),
  /* Keadaan sebuah aktivitas — cermin tiga cabang `state` di
     ActivityController::index. Tiga, bukan satu bendera: "lewat tanggal"
     adalah bagian dari "terbuka", dan menggabungkannya membuat antrean kerja
     harian mustahil ditanyakan. Urutannya urutan yang ditanyakan orang. */
  activityState: opts([
    ['open', 'Terbuka', null, 'inbox'], ['overdue', 'Lewat tanggal', null, 'circle-alert'], ['done', 'Selesai', null, 'check'],
  ]),
  /* Jenis dokumen tempat sebuah aktivitas menggantung — cermin
     ActivityDocuments::DOCUMENTS. */
  activityDocument: opts([
    ['lead', 'Prospek'], ['quotation', 'Penawaran'], ['customer', 'Pelanggan'],
  ]),
  /* Jenis CCO (temuan #61) — eskalasi harga menggerakkan nilai lewat jalur CCO
     yang sama dengan pekerjaan tambah-kurang; yang dibedakan makna jejak
     auditnya, bukan hitungannya (tidak ada mesin formula indeks). 'waktu'
     (P0-B) menggeser TANGGAL SELESAI kontrak dan bukan nilainya: value_change
     wajib 0, harinya lewat days_change (bertanda), dan tanggal barunya
     dihitung server saat disetujui — tidak pernah diinput. */
  ccoChangeType: opts([
    ['tambah_kurang', 'Tambah-Kurang'], ['eskalasi_harga', 'Eskalasi Harga'],
    ['waktu', 'Addendum Waktu'],
  ]),
  /* Cermin Modules\Subcontract\Enums\AddendumChangeType — sengaja enum PHP
     sendiri dan TANPA 'waktu': addendum SPK hanya menggerakkan nilai, dan
     servernya menolak 'waktu' dengan 422. Pola projectStatusEditable: daftar
     tersaring untuk layar yang pilihannya lebih sempit daripada padanannya,
     supaya form tidak menawarkan jenis yang pasti ditolak. */
  spkAddendumType: opts([['tambah_kurang', 'Tambah-Kurang'], ['eskalasi_harga', 'Eskalasi Harga']]),
  activeStatus: opts([['active', 'Aktif', null, 'circle-check'], ['inactive', 'Nonaktif', null, 'x']]),
  certificateType: opts([['skk', 'SKK Konstruksi'], ['k3', 'Sertifikat K3/AK3'], ['principal', 'Sertifikasi Principal'], ['lainnya', 'Lainnya']]),
  guaranteeType: opts([
    ['bid_bond', 'Jaminan Penawaran'], ['performance_bond', 'Jaminan Pelaksanaan'],
    ['advance_payment_bond', 'Jaminan Uang Muka'], ['maintenance_bond', 'Jaminan Pemeliharaan'],
    ['car', 'Asuransi CAR'], ['tpl', 'Asuransi TPL'], ['lainnya', 'Lainnya'],
  ]),
  guaranteeStatus: opts([['active', 'Berlaku', null, 'circle-check'], ['released', 'Dikembalikan', null, 'check'], ['claimed', 'Dicairkan', null, 'circle-x']]),

  ahspCategory: opts([
    ['sipil', 'Sipil'], ['arsitektur', 'Arsitektur'], ['mep', 'MEP'], ['elv', 'ELV'], ['ict', 'ICT'],
  ]),
  componentType: opts([['labor', 'Upah'], ['material', 'Bahan'], ['equipment', 'Alat']]),
  costCategory: opts([
    ['material', 'Material'], ['labor', 'Upah'], ['subcon', 'Subkon'],
    ['equipment', 'Alat'], ['overhead', 'Overhead'],
  ]),

  projectType: opts([
    ['construction', 'Konstruksi Gedung'],
    ['system_integration', 'Integrasi Sistem (ELV/ICT)'],
    ['maintenance', 'Pemeliharaan'],
  ]),
  projectStatus: opts([
    ['preparation', 'Persiapan', null, 'pencil'], ['active', 'Berjalan', null, 'hard-hat'], ['on_hold', 'Ditangguhkan', null, 'clock'],
    ['finishing', 'Finishing', null, 'arrow-right'], ['warranty', 'Masa Pemeliharaan', null, 'clipboard-check'], ['closed', 'Ditutup', null, 'check'],
  ]),
  /* Status yang bisa dipilih dari FORM proyek — pola assetStatusEditable.
     'Ditutup' sengaja tidak ada: menutup proyek adalah aksi ber-checklist
     (prj.approve) di halaman proyek, dan server menolak status closed dari PUT
     biasa. Select kosong pada proyek yang SUDAH tutup dikirim sebagai null dan
     server membacanya "biarkan apa adanya". Daftar penuh projectStatus di atas
     tetap dipakai kolom & filter, supaya proyek tutup tetap terbaca. */
  projectStatusEditable: opts([
    ['preparation', 'Persiapan', null, 'pencil'], ['active', 'Berjalan', null, 'hard-hat'], ['on_hold', 'Ditangguhkan', null, 'clock'],
    ['finishing', 'Finishing', null, 'arrow-right'], ['warranty', 'Masa Pemeliharaan', null, 'clipboard-check'],
  ]),
  weather: opts([['cerah', 'Cerah'], ['mendung', 'Mendung'], ['hujan', 'Hujan']]),
  /* P0-C — cermin Modules\Projects\Enums\WorkShift & GatePassDirection. */
  workShift: opts([['pagi', 'Pagi'], ['siang', 'Siang'], ['malam', 'Malam']]),
  gatePassDirection: opts([['in', 'Masuk'], ['out', 'Keluar']]),
  /* Cermin Modules\Projects\Enums\DailyReportRole — 12 jabatan tabel JUMLAH
     ORANG pada pad FM-10-12, persis dan urut. value adalah role_key yang
     tersimpan; label mengikuti label() PHP huruf demi huruf, karena teks
     inilah yang juga tercetak di pad (LaporanFormService::harian). */
  dailyReportRole: opts([
    ['project_manager', 'Project Manager'],
    ['deputy_project_manager', 'Deputy Project Manager'],
    ['engineering', 'Engineering'],
    ['komersial', 'Komersial'],
    ['keuangan', 'Keuangan'],
    ['danlat', 'Danlat'],
    ['produksi', 'Produksi'],
    ['safety_officer', 'Safety Officer'],
    ['mandor_sipil', 'Mandor Sipil + Tukang'],
    ['mandor_arsitek', 'Mandor Arsitek + Tukang'],
    ['mandor_mep', 'Mandor MEP + Tukang'],
    ['subkont', 'Subkont'],
  ]),
  bastType: opts([['bast1', 'BAST I — Serah Terima Pertama'], ['bast2', 'BAST II — Serah Terima Kedua']]),

  vendorClassification: opts([
    ['material', 'Material'], ['jasa', 'Jasa'], ['ict', 'ICT'],
    ['sipil', 'Sipil'], ['me', 'Mekanikal & Elektrikal'],
  ]),
  /* P4 — cermin Modules\Procurement\Enums\VendorType (label dari label()-nya).
     Menggantikan boolean is_subcontractor pada layar Vendor; boolean lama
     tetap dibaca berkas SPA lain dan disinkronkan model Vendor. */
  vendorType: opts([
    ['supplier', 'Pemasok'], ['subcontractor', 'Subkontraktor'],
    ['mandor', 'Mandor'], ['rental', 'Rental / sewa alat'],
  ]),
  /* Cermin Modules\Procurement\Enums\VendorDocumentType. */
  vendorDocumentType: opts([
    ['nib', 'NIB'], ['siup', 'SIUP'], ['npwp', 'NPWP'], ['sppkp', 'SPPKP (PKP)'],
    ['sbu_konstruksi', 'SBU Konstruksi'], ['skk', 'SKK Penanggung Jawab'],
    ['principal', 'Sertifikat Principal'], ['akta', 'Akta Perusahaan'],
    ['k3l_commitment', 'Komitmen K3L'], ['pakta_integritas', 'Pakta Integritas'],
    // P4 — daftar riwayat hidup mandor; sumber lembar F/CVM.
    ['cv_mandor', 'CV Mandor'],
    ['lainnya', 'Lainnya'],
  ]),

  itemType: opts([
    ['material', 'Material'], ['sparepart', 'Sparepart'],
    ['tool', 'Alat Bantu'], ['merchandise', 'Barang Dagangan'],
  ]),
  adjustmentReason: opts([['opname', 'Stock Opname'], ['damage', 'Barang Rusak'], ['loss', 'Barang Hilang']]),

  // P4 — skema PPh upah mandor (SP3). pph21_ter tampil apa adanya sebagai
  // pintu yang belum diaktifkan: memilihnya dijawab server dengan 422 yang
  // menjelaskan dirinya (LaborContractService::assertSchemeActive).
  // P5 — basis tarif sewa alat/jasa (PPK): per_jam ditagih dari delta
  // hour-meter register, per_bulan/per_hari_8jam dari kalender.
  rateBasis: opts([
    ['per_bulan', 'Per bulan'],
    ['per_hari_8jam', 'Per hari (8 jam)'],
    ['per_jam', 'Per jam'],
  ]),

  laborPphScheme: opts([
    ['final_umkm', 'PPh Final UMKM 0,5% (PP 55/2022)'],
    ['pph21_ter', 'PPh 21 TER (mesin payroll — belum diaktifkan)'],
  ]),

  pphScheme: opts([
    ['pelaksanaan_kecil_bersertifikat', 'Pelaksanaan — kualifikasi kecil, bersertifikat (1,75%)'],
    ['pelaksanaan_bersertifikat', 'Pelaksanaan — bersertifikat menengah/besar (2,65%)'],
    ['pelaksanaan_tanpa_sertifikat', 'Pelaksanaan — tanpa sertifikat (4%)'],
    ['perancangan_bersertifikat', 'Perancangan/pengawasan — bersertifikat (3,5%)'],
    ['perancangan_tanpa_sertifikat', 'Perancangan/pengawasan — tanpa sertifikat (6%)'],
    ['terintegrasi_bersertifikat', 'Terintegrasi — bersertifikat (2,65%)'],
    ['terintegrasi_tanpa_sertifikat', 'Terintegrasi — tanpa sertifikat (4%)'],
  ]),

  accountType: opts([
    ['asset', 'Aset'], ['liability', 'Kewajiban'], ['equity', 'Ekuitas'], ['revenue', 'Pendapatan'],
    ['cogs', 'Beban Proyek (HPP)'], ['expense', 'Beban Operasional'], ['other', 'Pendapatan/Beban Lain'],
  ]),
  normalBalance: opts([['debit', 'Debit'], ['credit', 'Kredit']]),
  taxType: opts([['ppn', 'PPN'], ['pph_withholding', 'PPh (dipotong/dipungut)']]),
  paymentDirection: opts([['in', 'Penerimaan (RCV)'], ['out', 'Pengeluaran (PAY)']]),

  ptkpStatus: opts([
    ['TK/0', 'TK/0 — Tidak kawin, tanpa tanggungan'],
    ['TK/1', 'TK/1 — Tidak kawin, 1 tanggungan'],
    ['TK/2', 'TK/2 — Tidak kawin, 2 tanggungan'],
    ['TK/3', 'TK/3 — Tidak kawin, 3 tanggungan'],
    ['K/0', 'K/0 — Kawin, tanpa tanggungan'],
    ['K/1', 'K/1 — Kawin, 1 tanggungan'],
    ['K/2', 'K/2 — Kawin, 2 tanggungan'],
    ['K/3', 'K/3 — Kawin, 3 tanggungan'],
  ]),
  employmentType: opts([
    ['tetap', 'Karyawan Tetap (PKWTT)'], ['kontrak', 'Karyawan Kontrak (PKWT)'], ['harian', 'Tenaga Harian Lepas'],
  ]),
  // PP 35/2021: PKWT jangka waktu punya tanggal akhir; PKWT selesainya
  // pekerjaan tertentu sah TANPA tanggal — pengawas tenggat tidak menagihnya.
  pkwtBasis: opts([
    ['jangka_waktu', 'Jangka waktu tertentu'], ['selesainya_pekerjaan', 'Selesainya pekerjaan tertentu'],
  ]),
  gender: opts([['male', 'Laki-laki'], ['female', 'Perempuan']]),
  department: opts([
    ['proyek', 'Proyek'], ['engineering', 'Engineering'], ['keuangan', 'Keuangan'],
    ['hrga', 'HR & GA'], ['procurement', 'Procurement'], ['servis', 'Servis'],
  ]),
  employeeStatus: opts([['active', 'Aktif', null, 'circle-check'], ['resigned', 'Resign', null, 'log-out']]),
  payrollRunType: opts([['regular', 'Gaji Bulanan'], ['thr', 'THR Keagamaan']]),
  leaveType: opts([['tahunan', 'Cuti Tahunan'], ['sakit', 'Sakit'], ['izin', 'Izin'], ['khusus', 'Cuti Khusus']]),
  attendanceStatus: opts([['hadir', 'Hadir', null, 'circle-check'], ['setengah_hari', 'Setengah Hari', null, 'clock'], ['absen', 'Absen', null, 'circle-x']]),

  billingCycle: opts([['monthly', 'Bulanan'], ['quarterly', 'Triwulanan'], ['yearly', 'Tahunan']]),
  svcContractStatus: opts([['active', 'Aktif', null, 'circle-check'], ['expired', 'Berakhir', null, 'circle-alert'], ['terminated', 'Diputus', null, 'x']]),
  ticketCategory: opts([
    ['incident', 'Gangguan'], ['request', 'Permintaan'], ['preventive', 'Pemeliharaan Preventif'],
  ]),
  ticketPriority: opts([['low', 'Rendah'], ['medium', 'Sedang'], ['high', 'Tinggi'], ['critical', 'Kritis']]),
  /* Tanpa tone sendiri: tiket yang baru dibuka adalah keadaan normal meja
     layanan, jadi 'open' → hijau dari peta bersama statusTone memang benar di
     sini — berbeda dengan NCR/insiden/defect di bawah. */
  ticketStatus: opts([
    ['open', 'Terbuka', null, 'inbox'], ['assigned', 'Ditugaskan', null, 'user'], ['in_progress', 'Dikerjakan', null, 'arrow-right'],
    ['pending_customer', 'Menunggu Pelanggan', null, 'clock'], ['resolved', 'Terselesaikan', null, 'circle-check'],
    ['closed', 'Ditutup', null, 'check'], ['cancelled', 'Dibatalkan', null, 'x'],
  ]),
  ticketChannel: opts([
    ['phone', 'Telepon'], ['email', 'Email'], ['wa', 'WhatsApp'], ['portal', 'Portal'], ['system', 'Sistem'],
  ]),
  ticketActivityType: opts([
    ['comment', 'Komentar'], ['status_change', 'Perubahan Status'],
    ['assignment', 'Penugasan'], ['work_log', 'Catatan Pekerjaan'],
  ]),
  fieldReportStatus: opts([['draft', 'Draf', null, 'pencil'], ['submitted', 'Diajukan', null, 'clock'], ['acknowledged', 'Disahkan Pelanggan', null, 'circle-check']]),
  pmFrequency: opts([['monthly', 'Bulanan'], ['quarterly', 'Triwulanan'], ['semiannual', 'Semesteran']]),

  incidentSeverity: opts([
    ['near_miss', 'Nyaris celaka (near miss)'], ['first_aid', 'P3K'],
    ['medical_treatment', 'Perawatan medis'], ['lost_time', 'Kehilangan hari kerja'],
    ['fatality', 'Fatal'],
  ]),
  incidentCategory: opts([
    ['fall_from_height', 'Jatuh dari ketinggian'], ['struck_by_object', 'Tertimpa material'],
    ['caught_between', 'Terjepit / terlindas'], ['electrical', 'Listrik / tersengat'],
    ['fire', 'Kebakaran / ledakan'], ['heavy_equipment', 'Alat berat'],
    ['excavation', 'Galian / longsor'], ['chemical', 'Bahan kimia'],
    ['traffic', 'Lalu lintas / kendaraan'], ['environmental', 'Lingkungan (tumpahan, limbah)'],
    ['property_damage', 'Kerusakan properti'], ['other', 'Lainnya'],
  ]),
  /* Tone per nilai: insiden K3 yang masih terbuka adalah kecelakaan yang
     tindakan koreksinya belum selesai — merah, bukan hijau 'open' tiket
     layanan (diukur 4 Sep 2026: lencana detail K3/2026/IX/003 "Terbuka → green"). */
  incidentStatus: opts([
    ['open', 'Terbuka', 'red', 'triangle-alert'], ['investigating', 'Investigasi', 'amber', 'search'], ['closed', 'Selesai', 'green', 'check'],
  ]),

  /* Register defect (punch list). "Menunggu verifikasi" masih dihitung TERBUKA:
     BAST II adalah penerimaan pelanggan, jadi item yang baru diklaim selesai
     belum diterima siapa pun — lihat Modules/Projects/Enums/DefectStatus.php. */
  defectSeverity: opts([
    ['critical', 'Kritis (menghentikan fungsi)'], ['major', 'Mayor'], ['minor', 'Minor (snagging)'],
  ]),
  defectSource: opts([
    ['handover', 'Serah terima (BAST I)'], ['warranty', 'Masa pemeliharaan'], ['internal', 'QC internal'],
  ]),
  /* Tone per nilai (dulu peta pribadi views/defect.js): temuan yang terbuka
     adalah pekerjaan yang belum selesai pada proyek yang retensinya belum
     cair — merah. `waived` sengaja netral dan bukan hijau: pelanggan
     menerimanya apa adanya, tidak ada yang diperbaiki. Diukur 4 Sep 2026:
     register memakai peta pribadinya (Terbuka → red) sementara halaman detail
     temuan yang sama memakai statusTone (Terbuka → green). */
  defectStatus: opts([
    ['open', 'Terbuka', 'red', 'triangle-alert'], ['in_progress', 'Perbaikan berjalan', 'amber', 'arrow-right'],
    ['ready_for_review', 'Menunggu verifikasi', 'blue', 'eye'], ['closed', 'Selesai (terverifikasi)', 'green', 'check'],
    ['waived', 'Dispensasi pelanggan', '', 'info'],
  ]),

  /* P1-ENG — cermin Modules\Engineering\Enums, satu peta per enum, SEKALI.
     Nilai persis milik server; label mengikuti label() PHP huruf demi huruf,
     karena kata yang sama juga tercetak di F/SD–F/IPP (PrintableDocuments). */
  discipline: opts([
    ['struktur', 'Struktur'], ['arsitektur', 'Arsitektur'], ['mep', 'MEP'],
    ['elv', 'ELV'], ['ict', 'ICT'],
  ]),
  /* Empat stempel keputusan FM-10, urut seperti pada stempelnya. INGAT:
     'approved_as_noted' membuka gerbang IPP untuk GAMBAR tetapi TIDAK untuk
     MATERIAL (IppService — baris material menuntut Disetujui penuh). */
  submittalDecision: opts([
    ['approved', 'Disetujui'], ['approved_as_noted', 'Disetujui dengan catatan'],
    ['revise_resubmit', 'Revisi & ajukan ulang'], ['rejected', 'Ditolak'],
  ]),
  reviewerParty: opts([['mk', 'Konsultan MK'], ['owner', 'Pemilik']]),
  /* Status register gambar — CERMIN keputusan submittal terkini, digerakkan
     DrawingSubmittalService, tidak pernah diketik dari form mana pun. */
  drawingStatus: opts([
    ['belum_diajukan', 'Belum diajukan', null, 'pencil'], ['diajukan', 'Diajukan', null, 'clock'],
    ['approved', 'Disetujui', null, 'circle-check'], ['approved_as_noted', 'Disetujui dengan catatan', null, 'clipboard-check'],
    ['revise_resubmit', 'Revisi & ajukan ulang', null, 'refresh-cw'], ['rejected', 'Ditolak', null, 'circle-x'],
  ]),
  transmittalDirection: opts([['keluar', 'Keluar'], ['masuk', 'Masuk']]),
  /* Kosakata baris transmittal — TransmittalService::LINE_KINDS, bukan enum
     PHP: kelas tidak pernah melintasi kabel, hanya tiga kata ini. */
  transmittalLineKind: opts([
    ['drawing_submittal', 'Shop drawing (SDS)'],
    ['material_submittal', 'Material (SMS)'],
    ['lainnya', 'Lainnya (teks bebas)'],
  ]),
  ippScope: opts([['struktur', 'Struktur'], ['arsitek', 'Arsitektur'], ['mep', 'MEP']]),
  /* Cermin Modules\Core\Enums\LocationKind — jenjang lokasi tapak. */
  locationKind: opts([
    ['tower', 'Tower'], ['floor', 'Lantai'], ['zone', 'Zona'],
    ['axis', 'As'], ['room', 'Ruang'],
  ]),

  /* P1-QC — cermin Modules\Quality\Enums. Titik henti mutu (before<during<after);
     urutan ini adalah perbandingan gerbang NCR (InspectionService). */
  inspectionStage: opts([
    ['before', 'Sebelum pelaksanaan'], ['during', 'Saat pelaksanaan'], ['after', 'Setelah pelaksanaan'],
  ]),
  /* P6 — cermin Modules\Quality\Enums\TemplateKind. Checklist 5R = inspeksi
     biasa atas template ber-jenis '5r'; tanpa mesin checklist paralel. */
  templateKind: opts([['quality', 'Checklist mutu'], ['5r', 'Checklist 5R']]),
  /* P6 — cermin Modules\Projects\Enums\RiskLevel: banding matriks 5×5
     Permen PUPR 10/2021 (1–4 kecil, 5–12 sedang, 15–25 besar). */
  riskLevel: opts([['kecil', 'Risiko kecil'], ['sedang', 'Risiko sedang'], ['besar', 'Risiko besar']]),
  /* Verdikt satu butir; 'nok' menggagalkan lembar (overall pass), 'na' tidak. */
  itemResult: opts([['ok', 'Sesuai'], ['nok', 'Tidak sesuai'], ['na', 'Tidak berlaku']]),
  witnessParty: opts([['mk', 'Konsultan MK'], ['owner', 'Pemilik']]),
  /* Daur hidup NCR — enum sendiri, BUKAN DocumentStatus. 'open'/'under_correction'
     memblokir (isOpen); 'verified'/'closed' tidak. Tone per nilai: NCR terbuka
     menahan inspeksi tahap berikutnya dan BAST I, jadi merah adalah warnanya
     yang jujur — statusTone bersama melukis 'open' hijau (diukur 4 Sep 2026:
     lencana detail NCR/2026/IX/0002 "Terbuka → green"). */
  ncrStatus: opts([
    ['open', 'Terbuka', 'red', 'triangle-alert'], ['under_correction', 'Perbaikan berjalan', 'amber', 'arrow-right'],
    ['verified', 'Terverifikasi', 'blue', 'eye'], ['closed', 'Ditutup', 'green', 'check'],
  ]),

  /* P3 — cermin Modules\Projects\Enums\ZoneCertificateStatus. 'done' digerbangi
     NCR terbuka di zona itu; 'waiting_repair' memblokir klaim owner (kriteria #6). */
  zoneCertificateStatus: opts([
    ['done', 'Selesai', null, 'check'], ['check', 'Diperiksa', null, 'eye'], ['waiting_repair', 'Nunggu perbaikan', null, 'clock'],
  ]),
  /* Pihak yang menandatangani BAPP — fakta tercatat, tidak pernah diisi dari
     master proyek (roadmap §7). */
  certifyingParty: opts([
    ['mk', 'Konsultan MK'], ['owner', 'Pemilik'], ['kontraktor', 'Kontraktor'],
  ]),
  /* P3 — cermin Modules\Subcontract\Enums\HandoverType. */
  handoverType: opts([
    ['bast1', 'BAST I (serah terima pertama)'], ['bast2', 'BAST II (akhir masa pemeliharaan)'],
  ]),
  /* P3 — cermin Modules\Projects\Models\WeeklyProgress::SOURCE_*. Dua hal yang
     sangat berbeda pada satu kolom: taksiran yang diketik pengawas, dan volume
     terukur berbobot nilai dari opname yang disetujui. Baris progres mingguan
     yang dicakup opname MENGGANTIKAN angka ketikannya, jadi kolom ini adalah
     satu-satunya yang menjelaskan mengapa angkanya berubah sendiri. */
  actualPctSource: opts([
    ['weekly_report', 'Diketik (laporan mingguan)'], ['progress_measurement', 'Opname disetujui'],
  ]),

  assetStatus: opts([
    ['available', 'Tersedia', null, 'circle-check'], ['deployed', 'Termobilisasi', null, 'truck'],
    ['maintenance', 'Dalam Perawatan', null, 'settings'], ['disposed', 'Dihapusbukukan', null, 'x'],
  ]),
  // P5 — kepemilikan aset. Sewa: kolom perolehan/penyusutan NULL (bergaris,
  // bukan Rp 0 — alat itu tidak ada di neraca kita) dan tidak pernah
  // disusutkan; kepemilikan TIDAK bisa diubah lewat sunting biasa (beli-putus
  // alat sewa adalah peristiwa akuntansi, bukan suntingan register).
  assetOwnership: opts([
    ['owned', 'Milik sendiri'], ['rented', 'Sewa'],
  ]),
  // Tanpa 'disposed': status itu hanya lahir dari aksi "Hapus Buku / Jual"
  // (POST assets/{id}/dispose) yang memposting jurnal pelepasannya — lewat
  // update biasa server kini menolaknya (Temuan 55).
  assetStatusEditable: opts([
    ['available', 'Tersedia', null, 'circle-check'], ['maintenance', 'Dalam Perawatan', null, 'settings'],
  ]),
  maintenanceType: opts([
    ['service_rutin', 'Service Rutin'], ['perbaikan', 'Perbaikan'], ['kalibrasi', 'Kalibrasi'],
  ]),
  deploymentStatus: opts([['active', 'Aktif', null, 'circle-check'], ['returned', 'Dikembalikan', null, 'check']]),
};

/** Look up a display label for an enum value. */
export function enumLabel(name, value) {
  const list = ENUMS[name] || [];
  const hit = list.find((option) => option.value === value);
  return hit ? hit.label : value;
}

/**
 * Warna lencana yang ditetapkan enum itu sendiri untuk satu nilai, atau
 * `undefined` bila enum tidak menetapkannya (statusTone lalu memakai peta
 * kata bersamanya). '' adalah jawaban sah: netral, bukan "tidak tahu".
 */
export function enumTone(name, value) {
  const list = ENUMS[name] || [];
  const hit = list.find((option) => option.value === value);
  return hit && 'tone' in hit ? hit.tone : undefined;
}

/**
 * Ikon sprite yang ditetapkan enum itu sendiri untuk satu nilai (kolom keempat
 * tuple opts, UX-C.3), atau `undefined` bila enum tidak menetapkannya —
 * statusIcon() di format.js lalu memakai peta kata bersamanya.
 */
export function enumIcon(name, value) {
  const list = ENUMS[name] || [];
  const hit = list.find((option) => option.value === value);
  return hit && 'icon' in hit ? hit.icon : undefined;
}

/* Petunjuk statis per (enum, nilai) untuk baris Pekerjaan Saya (ERRATA E-3
   butir 5): satu klausa tentang APA YANG TERJADI BERIKUTNYA, tanpa nama orang
   atau tingkat — yang menyebut orang datang dari data server, bukan dari sini.
   Kalimat keadaan lengkap tetap hanya satu sumbernya: statusStrip() di
   views/detail.js. Peta ini bukan generator kalimat. */
const HINTS = {
  documentStatus: { rejected: 'Perbaiki lalu ajukan lagi.', submitted: 'Menunggu keputusan penyetuju.' },
  paymentStatus: { rejected: 'Perbaiki lalu ajukan lagi.', submitted: 'Menunggu keputusan penyetuju.' },
};

/** Petunjuk satu klausa untuk satu nilai status, atau `null` bila enum itu tidak menetapkannya. */
export function enumHint(name, value) {
  return (HINTS[name || 'documentStatus'] || {})[value] || null;
}
