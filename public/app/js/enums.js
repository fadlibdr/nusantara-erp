/* Option lists mirrored from the PHP enums under each module's Enums
   directory and the Rule::in() lists in the FormRequests.
   Values must match the API exactly. */

const opts = (pairs) => pairs.map(([value, label]) => ({ value, label }));

export const ENUMS = {
  documentStatus: opts([
    ['draft', 'Draf'], ['submitted', 'Diajukan'], ['approved', 'Disetujui'],
    ['rejected', 'Ditolak'], ['closed', 'Selesai'], ['cancelled', 'Dibatalkan'],
  ]),
  postingStatus: opts([['draft', 'Draf'], ['posted', 'Terposting']]),
  /* Pembayaran keluar (PAY) melewati persetujuan; penerimaan (RCV) langsung
     diposting dan hanya memakai draft/posted dari daftar yang sama. */
  paymentStatus: opts([
    ['draft', 'Draf'], ['submitted', 'Diajukan'], ['approved', 'Disetujui'],
    ['rejected', 'Ditolak'], ['posted', 'Terposting'],
    // Terminal, bukan kembali ke draf: uangnya benar-benar bergerak dan
    // rekening korannya akan selalu mengatakan begitu.
    ['reversed', 'Dibalik'],
  ]),
  /* "Dibatalkan" adalah keadaan KETIGA, bukan jalan pulang ke draf: bon yang
     sudah diposting tetap berdiri di kartu stok selamanya, dan pembatalannya
     adalah pergerakan cermin plus jurnal pembalik (StockService::cancelIssue) —
     persis seperti 'Dibalik' pada paymentStatus di atas. Tanpa baris ini bon
     yang dibatalkan tidak bisa disaring sama sekali di daftar Pengeluaran
     Barang. Hari ini hanya bon yang bisa sampai ke sana; GRN memakai daftar
     yang sama tetapi belum punya jalan kembali, jadi saringannya di layar
     Penerimaan Barang memang selalu kosong — lihat StockDocumentStatus.php. */
  stockDocStatus: opts([['draft', 'Draf'], ['posted', 'Diposting'], ['cancelled', 'Dibatalkan']]),
  transferStatus: opts([['draft', 'Draf'], ['in_transit', 'Dalam Perjalanan'], ['received', 'Diterima']]),

  scopeType: opts([
    ['construction', 'Konstruksi Gedung'],
    ['system_integration', 'Integrasi Sistem (ELV/ICT)'],
    ['maintenance', 'Pemeliharaan'],
  ]),
  leadStatus: opts([
    ['new', 'Baru'], ['contacted', 'Sudah Dihubungi'], ['qualified', 'Terkualifikasi'],
    ['proposal', 'Penawaran Dikirim'], ['won', 'Menang'], ['lost', 'Kalah'],
  ]),
  /* Jenis CCO (temuan #61) — eskalasi harga menggerakkan nilai lewat jalur CCO
     yang sama dengan pekerjaan tambah-kurang; yang dibedakan makna jejak
     auditnya, bukan hitungannya (tidak ada mesin formula indeks). */
  ccoChangeType: opts([['tambah_kurang', 'Tambah-Kurang'], ['eskalasi_harga', 'Eskalasi Harga']]),
  activeStatus: opts([['active', 'Aktif'], ['inactive', 'Nonaktif']]),
  certificateType: opts([['skk', 'SKK Konstruksi'], ['k3', 'Sertifikat K3/AK3'], ['principal', 'Sertifikasi Principal'], ['lainnya', 'Lainnya']]),
  guaranteeType: opts([
    ['bid_bond', 'Jaminan Penawaran'], ['performance_bond', 'Jaminan Pelaksanaan'],
    ['advance_payment_bond', 'Jaminan Uang Muka'], ['maintenance_bond', 'Jaminan Pemeliharaan'],
    ['car', 'Asuransi CAR'], ['tpl', 'Asuransi TPL'], ['lainnya', 'Lainnya'],
  ]),
  guaranteeStatus: opts([['active', 'Berlaku'], ['released', 'Dikembalikan'], ['claimed', 'Dicairkan']]),

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
    ['preparation', 'Persiapan'], ['active', 'Berjalan'], ['on_hold', 'Ditangguhkan'],
    ['finishing', 'Finishing'], ['warranty', 'Masa Pemeliharaan'], ['closed', 'Ditutup'],
  ]),
  /* Status yang bisa dipilih dari FORM proyek — pola assetStatusEditable.
     'Ditutup' sengaja tidak ada: menutup proyek adalah aksi ber-checklist
     (prj.approve) di halaman proyek, dan server menolak status closed dari PUT
     biasa. Select kosong pada proyek yang SUDAH tutup dikirim sebagai null dan
     server membacanya "biarkan apa adanya". Daftar penuh projectStatus di atas
     tetap dipakai kolom & filter, supaya proyek tutup tetap terbaca. */
  projectStatusEditable: opts([
    ['preparation', 'Persiapan'], ['active', 'Berjalan'], ['on_hold', 'Ditangguhkan'],
    ['finishing', 'Finishing'], ['warranty', 'Masa Pemeliharaan'],
  ]),
  weather: opts([['cerah', 'Cerah'], ['mendung', 'Mendung'], ['hujan', 'Hujan']]),
  bastType: opts([['bast1', 'BAST I — Serah Terima Pertama'], ['bast2', 'BAST II — Serah Terima Kedua']]),

  vendorClassification: opts([
    ['material', 'Material'], ['jasa', 'Jasa'], ['ict', 'ICT'],
    ['sipil', 'Sipil'], ['me', 'Mekanikal & Elektrikal'],
  ]),
  /* Cermin Modules\Procurement\Enums\VendorDocumentType. */
  vendorDocumentType: opts([
    ['nib', 'NIB'], ['siup', 'SIUP'], ['npwp', 'NPWP'], ['sppkp', 'SPPKP (PKP)'],
    ['sbu_konstruksi', 'SBU Konstruksi'], ['skk', 'SKK Penanggung Jawab'],
    ['principal', 'Sertifikat Principal'], ['akta', 'Akta Perusahaan'],
    ['lainnya', 'Lainnya'],
  ]),
  /* Cermin Modules\Procurement\Enums\VendorDocumentType. */
  vendorDocumentType: opts([
    ['nib', 'NIB'], ['siup', 'SIUP'], ['npwp', 'NPWP'], ['sppkp', 'SPPKP (PKP)'],
    ['sbu_konstruksi', 'SBU Konstruksi'], ['skk', 'SKK Penanggung Jawab'],
    ['principal', 'Sertifikat Principal'], ['akta', 'Akta Perusahaan'],
    ['lainnya', 'Lainnya'],
  ]),

  itemType: opts([
    ['material', 'Material'], ['sparepart', 'Sparepart'],
    ['tool', 'Alat Bantu'], ['merchandise', 'Barang Dagangan'],
  ]),
  adjustmentReason: opts([['opname', 'Stock Opname'], ['damage', 'Barang Rusak'], ['loss', 'Barang Hilang']]),

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
  employeeStatus: opts([['active', 'Aktif'], ['resigned', 'Resign']]),
  payrollRunType: opts([['regular', 'Gaji Bulanan'], ['thr', 'THR Keagamaan']]),
  leaveType: opts([['tahunan', 'Cuti Tahunan'], ['sakit', 'Sakit'], ['izin', 'Izin'], ['khusus', 'Cuti Khusus']]),
  attendanceStatus: opts([['hadir', 'Hadir'], ['setengah_hari', 'Setengah Hari'], ['absen', 'Absen']]),

  billingCycle: opts([['monthly', 'Bulanan'], ['quarterly', 'Triwulanan'], ['yearly', 'Tahunan']]),
  svcContractStatus: opts([['active', 'Aktif'], ['expired', 'Berakhir'], ['terminated', 'Diputus']]),
  ticketCategory: opts([
    ['incident', 'Gangguan'], ['request', 'Permintaan'], ['preventive', 'Pemeliharaan Preventif'],
  ]),
  ticketPriority: opts([['low', 'Rendah'], ['medium', 'Sedang'], ['high', 'Tinggi'], ['critical', 'Kritis']]),
  ticketStatus: opts([
    ['open', 'Terbuka'], ['assigned', 'Ditugaskan'], ['in_progress', 'Dikerjakan'],
    ['pending_customer', 'Menunggu Pelanggan'], ['resolved', 'Terselesaikan'],
    ['closed', 'Ditutup'], ['cancelled', 'Dibatalkan'],
  ]),
  ticketChannel: opts([
    ['phone', 'Telepon'], ['email', 'Email'], ['wa', 'WhatsApp'], ['portal', 'Portal'], ['system', 'Sistem'],
  ]),
  ticketActivityType: opts([
    ['comment', 'Komentar'], ['status_change', 'Perubahan Status'],
    ['assignment', 'Penugasan'], ['work_log', 'Catatan Pekerjaan'],
  ]),
  fieldReportStatus: opts([['draft', 'Draf'], ['submitted', 'Diajukan'], ['acknowledged', 'Disahkan Pelanggan']]),
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
  incidentStatus: opts([
    ['open', 'Terbuka'], ['investigating', 'Investigasi'], ['closed', 'Selesai'],
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
  defectStatus: opts([
    ['open', 'Terbuka'], ['in_progress', 'Perbaikan berjalan'],
    ['ready_for_review', 'Menunggu verifikasi'], ['closed', 'Selesai (terverifikasi)'],
    ['waived', 'Dispensasi pelanggan'],
  ]),

  assetStatus: opts([
    ['available', 'Tersedia'], ['deployed', 'Termobilisasi'],
    ['maintenance', 'Dalam Perawatan'], ['disposed', 'Dihapusbukukan'],
  ]),
  // Tanpa 'disposed': status itu hanya lahir dari aksi "Hapus Buku / Jual"
  // (POST assets/{id}/dispose) yang memposting jurnal pelepasannya — lewat
  // update biasa server kini menolaknya (Temuan 55).
  assetStatusEditable: opts([
    ['available', 'Tersedia'], ['maintenance', 'Dalam Perawatan'],
  ]),
  maintenanceType: opts([
    ['service_rutin', 'Service Rutin'], ['perbaikan', 'Perbaikan'], ['kalibrasi', 'Kalibrasi'],
  ]),
  deploymentStatus: opts([['active', 'Aktif'], ['returned', 'Dikembalikan']]),
};

/** Look up a display label for an enum value. */
export function enumLabel(name, value) {
  const list = ENUMS[name] || [];
  const hit = list.find((option) => option.value === value);
  return hit ? hit.label : value;
}
