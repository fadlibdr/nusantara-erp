/* Generic document detail: header, key/value panel, line tables, approvals. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, errorState, emptyState, pluck, toast, menuButton } from '../ui.js';
import { renderCell, sumColumn } from '../cells.js';
import * as fmt from '../format.js';
import { attachmentsCard } from './attachments.js';
import { externalApprovalsCard } from './external.js';
import { preload, labelFor } from '../lookup.js';
import { openForm } from './form.js';
import { RESOURCES } from '../schema.js';
import { actionButtons } from './actions.js';
import { downloadPdf, openPrintable, pdfName, xlsxName } from '../print.js';
import { loadPrintForms, printButtonsFor, printablePath, xlsxPath } from '../printcatalog.js';
import { navigate, back } from '../router.js';

const HIDDEN_KEYS = new Set([
  'id', 'code', 'status', 'status_label', 'created_at', 'updated_at', 'deleted_at',
  'items', 'lines', 'sections', 'termins', 'sites', 'components', 'materials', 'parts',
  'entries', 'checklist', 'payslips', 'approvals', 'permissions', 'activities', 'allocations',
  // Empat tabel baris FM-10-12 (P0-A) — dirender sebagai detail.tables, dan
  // 'locked' bool hanyalah bayangan locked_at yang sudah tampil sendiri.
  'manpower', 'equipment', 'receipts', 'activity_lines', 'locked',
  // Baris pekerja izin lembur (P0-C) — detail.tables; tanpa ini panel
  // Informasi memajang array objeknya sebagai badge JSON di atas tabelnya.
  'workers',
  // Empat tabel baris IPP (P1-ENG) — 'materials'/'equipment' sudah di atas;
  // dua sisanya dirender detail.tables juga, bukan badge JSON.
  'drawings', 'material_approvals',
  // T3.6: bendera untuk tombol "Lengkapi kontrak" pada penawaran (cangkang
  // Tandai Menang tanpa jadwal) — keadaan tombol, bukan data dokumen.
  'contract_needs_schedule',
]);

/** Ditampilkan hanya bila sudah terisi — lihat pemakaiannya di renderDetail(). */
const WHEN_SET_KEYS = new Set([
  'cancelled_at', 'cancellation_reason', 'cancelled_by', 'locked_at',
  // Kisah waktu P0-B, hanya bila ada: days_change/new_end_date null pada CCO
  // nilai (dan new_end_date null sampai disetujui), original_end_date null
  // berarti kontrak tidak pernah diperpanjang — "Tanggal selesai awal: —"
  // pada kontrak biasa hanyalah kebisingan.
  'days_change', 'new_end_date', 'original_end_date',
  // Cap gerbang IMK (P0-C): kosong sampai security menekan 'periksa'.
  'checked_by', 'checked_at',
  // P1-ENG: revisi hidup belum digantikan siapa pun — "Digantikan pada: —"
  // pada SDS yang justru sedang berlaku adalah kebalikan dari informasi.
  'superseded_at', 'superseded_by_code',
  // P8: cap asal berkas warisan — hanya dokumen hasil impor yang memilikinya;
  // "Sumber impor: —" pada dokumen yang diketik tangan adalah kebisingan.
  'import_source',
  // P7: kode metode pelaksanaan berdampingan dengan judulnya, dan penawaran
  // yang memang tidak mengutip metode tidak perlu dua baris "—" untuk satu
  // ketiadaan yang sama.
  'method_library_code',
  // T3.6: alasan selisih nilai kontrak terhadap DPP penawarannya. Berbeda
  // dari pr_bypass_reason (T3.8) yang sengaja tampil "—" untuk membuka
  // alasan yang HILANG, di sini server menolak alasan yang hilang, jadi
  // null selalu berarti "nilainya sama dengan penawaran" — barisnya diam.
  'value_change_reason',
]);

/*
 * P0-C: Resource izin lapangan meratakan nama relasinya (requested_by_name,
 * …) karena requested_by/safety_officer menunjuk hr_employees sementara
 * ID_LOOKUPS global memetakan requested_by ke 'users' — id yang sama, orang
 * yang berbeda. Bila nama rata itu ada, baris id mentahnya digantikan; kunci
 * _name yang kosong juga tidak menyisakan baris "—" kembar di samping baris
 * id-nya.
 */
const NAME_SHADOWED = {
  requested_by: 'requested_by_name',
  safety_officer_id: 'safety_officer_name',
  checked_by: 'checked_by_name',
  vendor_id: 'vendor_name',
  // P1-ENG: Resource Engineering meratakan rujukannya sendiri — nomor gambar
  // untuk SDS, kode SDS pengganti, jalur lokasi untuk IPP, nomor IPP untuk
  // bon gudang, dan pembuat dokumen.
  drawing_id: 'drawing_number',
  superseded_by_id: 'superseded_by_code',
  location_id: 'location_path',
  ipp_id: 'ipp_code',
  created_by: 'created_by_name',
  // P7: QuotationResource meratakan metode pelaksanaan yang dikutip penawaran.
  // Tanpa baris ini kartu Informasi menuliskan "Method Library Id: 3".
  method_library_id: 'method_library_title',
};
const NAME_KEYS = new Set(Object.values(NAME_SHADOWED));

const MONEY_KEY = /(amount|total|value|price|cost|salary|dpp|ppn|pph|budget|payable|paid|outstanding|retention|gross|net|subtotal|discount|rate_internal)/;
const PERCENT_KEY = /(_pct|_rate)$/;
const DATE_KEY = /(_date|_at|_until|_from|_due|date)$/;

/** Foreign-key column -> reference source, so ids render as names. */
const ID_LOOKUPS = {
  customer_id: ['customers'], contract_id: ['contracts'], quotation_id: ['quotations'], lead_id: ['leads'],
  project_id: ['projects'], boq_id: ['boqs'], ahsp_id: ['ahsp'], vendor_id: ['vendors'],
  purchase_requisition_id: ['purchaseRequisitions'], purchase_order_id: ['purchaseOrders'],
  item_id: ['items'], warehouse_id: ['warehouses'], from_warehouse_id: ['warehouses'],
  to_warehouse_id: ['warehouses'], category_id: ['itemCategories', 'assetCategories'],
  employee_id: ['employees'], technician_employee_id: ['employees'], custodian_employee_id: ['employees'],
  keeper_employee_id: ['employees'], assigned_to: ['employees'], project_manager_id: ['employees'],
  site_manager_id: ['employees'], user_id: ['users'], requested_by: ['users'], evaluated_by: ['users'],
  created_by: ['users'], issued_by: ['users'], received_by: ['users'], posted_by: ['users'],
  // IssueResource memulangkan cancelled_by; tanpa baris ini panel dokumen
  // menuliskan "#3" untuk pembatal bon ISS/2026/VII/0001, bukan namanya.
  cancelled_by: ['users'],
  account_id: ['accounts'], coa_account_id: ['accounts'], pph_tax_id: ['taxes'],
  bank_account_id: ['bankAccounts'], subcontract_id: ['subcontracts'],
  subcontract_claim_id: ['progressClaims'], service_contract_id: ['serviceContracts'],
  ticket_id: ['tickets'], asset_id: ['assets'],
  // Izin lapangan P0-C — petugas K3 adalah karyawan, dan rujukan gudang IMK
  // (GR/transfer) tampil sebagai nomor dokumennya, bukan id basis data.
  safety_officer_id: ['employees'], wbs_task_id: ['wbsTasks'],
  goods_receipt_id: ['goodsReceipts'], transfer_id: ['transfers'],
  // P1-ENG. drawing_id/location_id/ipp_id biasanya sudah dibayangi kunci
  // rata Resource-nya (NAME_SHADOWED); baris ini melayani baris DAFTAR dan
  // dokumen yang datang tanpa relasi termuat. parent_id sengaja TIDAK
  // dipetakan: akun COA dan kategori juga memakai nama kolom itu.
  drawing_id: ['drawings'], location_id: ['locations'],
  // P7: sama alasannya — QuotationResource meratakan judul metodenya hanya bila
  // relasinya termuat, dan respons POST/PUT penawaran datang tanpa relasi itu.
  method_library_id: ['methodLibrary'],
};

/** Indonesian labels for the generic key/value panel. */
const LABELS = {
  dpp: 'DPP', ppn_amount: 'PPN', ppn_rate: 'Tarif PPN', pph_amount: 'PPh dipotong',
  pph_rate: 'Tarif PPh', pph_scheme: 'Skema PPh', subtotal: 'Subtotal', total: 'Total',
  discount_amount: 'Diskon', total_payable: 'Total dibayar', amount_paid: 'Sudah dibayar',
  outstanding: 'Sisa', retention_pct: 'Retensi', retention_amount: 'Nilai retensi',
  retention_withheld: 'Retensi ditahan', gross_amount: 'Nilai bruto', net_payable: 'Netto dibayar',
  net_before_tax: 'Netto sebelum pajak', total_with_ppn: 'Total termasuk PPN',
  value: 'Nilai', contract_value: 'Nilai kontrak', total_budget: 'Total anggaran',
  target_margin_pct: 'Target margin', unit_price: 'Harga satuan', avg_cost: 'HPP rata-rata',
  last_price: 'Harga beli terakhir', min_stock: 'Stok minimum', base_salary: 'Gaji pokok',
  customer_id: 'Pelanggan', vendor_id: 'Vendor', project_id: 'Proyek', contract_id: 'Kontrak',
  quotation_id: 'Penawaran', lead_id: 'Prospek', boq_id: 'BOQ', item_id: 'Item',
  warehouse_id: 'Gudang', from_warehouse_id: 'Gudang asal', to_warehouse_id: 'Gudang tujuan',
  employee_id: 'Karyawan', user_id: 'Pengguna', account_id: 'Akun', coa_account_id: 'Akun COA',
  bank_account_id: 'Rekening bank', asset_id: 'Aset', ticket_id: 'Tiket',
  purchase_order_id: 'PO', purchase_requisition_id: 'PR', subcontract_id: 'SPK',
  subcontract_claim_id: 'Opname subkon', service_contract_id: 'Kontrak layanan',
  pph_tax_id: 'Jenis PPh', category_id: 'Kategori', termin_id: 'Termin',
  assigned_to: 'Ditugaskan ke', requested_by: 'Diminta oleh', evaluated_by: 'Dievaluasi oleh',
  created_by: 'Dibuat oleh', issued_by: 'Dikeluarkan oleh', received_by: 'Diterima oleh',
  posted_by: 'Diposting oleh', project_manager_id: 'Project manager', site_manager_id: 'Site manager',
  custodian_employee_id: 'Penanggung jawab', keeper_employee_id: 'Penjaga gudang',
  technician_employee_id: 'Teknisi',
  title: 'Judul', name: 'Nama', legal_name: 'Nama badan hukum', description: 'Keterangan',
  import_source: 'Sumber impor',
  notes: 'Catatan', scope: 'Lingkup', scope_type: 'Lingkup', purpose: 'Keperluan',
  needed_date: 'Dibutuhkan', order_date: 'Tanggal PO', expected_date: 'Perkiraan kirim',
  invoice_date: 'Tanggal invoice', bill_date: 'Tanggal tagihan', due_date: 'Jatuh tempo',
  payment_date: 'Tanggal bayar', receipt_date: 'Tanggal terima', issue_date: 'Tanggal keluar',
  transfer_date: 'Tanggal transfer', adjustment_date: 'Tanggal opname', report_date: 'Tanggal laporan',
  journal_date: 'Tanggal jurnal', sign_date: 'Tanggal TTD', start_date: 'Mulai', end_date: 'Selesai',
  actual_start_date: 'Aktual mulai', actual_end_date: 'Aktual selesai', handover_date: 'Serah terima',
  acquisition_date: 'Tanggal perolehan', join_date: 'Tanggal masuk', birth_date: 'Tanggal lahir',
  period_start: 'Periode mulai', period_end: 'Periode selesai', valid_until: 'Berlaku sampai',
  paid_at: 'Lunas pada', cancelled_at: 'Dibatalkan pada', cancellation_reason: 'Alasan pembatalan',
  // Tanpa entri ini titleize() jatuh ke "Cancelled By" — label berbahasa
  // Inggris di layar berbahasa Indonesia, tepat pada dokumen yang baru saja
  // dibatalkan seseorang dan paling sering dibaca auditor.
  cancelled_by: 'Dibatalkan oleh',
  retentions: 'Retensi', withholdings: 'Potongan pajak',
  closed_at: 'Ditutup', posted_at: 'Diposting', won_at: 'Menang pada',
  lost_at: 'Kalah pada', lost_reason: 'Alasan kalah', reported_at: 'Dilaporkan',
  payment_term_days: 'Termin bayar (hari)', warranty_months: 'Masa pemeliharaan (bulan)',
  useful_life_months: 'Umur manfaat (bulan)', is_pkp: 'PKP', is_active: 'Aktif',
  is_subcontractor: 'Subkontraktor', is_postable: 'Dapat diposting', is_site_warehouse: 'Gudang site',
  needs_director_approval: 'Perlu persetujuan direktur', npwp: 'NPWP', nib: 'NIB',
  // P2 — jenjang persetujuan berbobot nilai (award decision): berapa penyetuju
  // berbeda yang dibutuhkan, dan berapa yang sudah masuk. Selisihnya = sisa.
  required_levels: 'Tingkat persetujuan diperlukan', approvals_given: 'Persetujuan masuk',
  awarded_amount: 'Nilai diputuskan', rab_amount: 'Nilai RAB (HPS)',
  deviation_amount: 'Deviasi thd RAB', deviation_reason: 'Alasan deviasi',
  meeting_date: 'Tanggal negosiasi', harga_awal: 'Harga awal', harga_nego: 'Harga nego',
  nik_ktp: 'NIK KTP', sppkp_number: 'No. SPPKP', faktur_pajak_no: 'No. faktur pajak',
  occurred_at: 'Waktu kejadian', severity: 'Keparahan', category: 'Jenis kejadian',
  is_recordable: 'Masuk hitungan frequency rate', people_involved: 'Orang terlibat',
  lost_days: 'Hari kerja hilang', immediate_action: 'Tindakan segera',
  root_cause: 'Penyebab dasar', corrective_action: 'Tindakan korektif',
  responsible_employee_id: 'Penanggung jawab', is_overdue: 'Lewat target selesai',
  is_reportable: 'Wajib dilaporkan', reported_to_authority_at: 'Dilaporkan ke instansi',
  // Embedded relation objects land in the "Terkait" panel under these names.
  project: 'Proyek', responsible_employee: 'Penanggung jawab',
  vendor_invoice_no: 'No. invoice vendor', delivery_note_no: 'No. surat jalan',
  delivery_address: 'Alamat pengiriman', billing_address: 'Alamat penagihan',
  contract_number_customer: 'No. kontrak pelanggan', reference_type: 'Tipe referensi',
  reference_id: 'ID referensi', reference: 'Referensi', revision: 'Revisi', version: 'Versi',
  claim_no: 'Opname ke-', line_no: 'Baris', week_no: 'Minggu ke-', manpower_count: 'Jumlah tenaga kerja',
  activities: 'Kegiatan', obstacles: 'Kendala', safety_notes: 'Catatan K3',
  // Laporan harian penuh (P0-A) — kop jam kerja dan kunci BAST I.
  work_start: 'Jam mulai kerja', work_end: 'Jam selesai kerja',
  lost_hours_reason: 'Alasan jam kerja hilang', locked_at: 'Terkunci pada',
  findings: 'Temuan', actions_taken: 'Tindakan', recommendations: 'Rekomendasi',
  resolution_notes: 'Catatan penyelesaian', coverage: 'Cakupan layanan',
  address: 'Alamat', city: 'Kota', province: 'Provinsi', postal_code: 'Kode pos',
  phone: 'Telepon', email: 'Email', website: 'Website', pic_name: 'Nama PIC', pic_phone: 'Telepon PIC',
  bank_name: 'Bank', bank_account_no: 'No. rekening', bank_account_name: 'Atas nama',
  position: 'Jabatan', department: 'Departemen', gender: 'Jenis kelamin',
  ptkp_status: 'Status PTKP', ter_category: 'Kategori TER', employment_type: 'Status kerja',
  location: 'Lokasi', latitude: 'Lintang', longitude: 'Bujur', unit: 'Satuan', barcode: 'Barcode',
  brand: 'Merek', model: 'Model', serial_no: 'No. seri', salvage_value: 'Nilai residu',
  accumulated_depreciation: 'Akumulasi penyusutan', book_value: 'Nilai buku',
  monthly_depreciation: 'Penyusutan per bulan', acquisition_cost: 'Harga perolehan',
  period_year: 'Tahun', period_month: 'Bulan', run_type: 'Jenis run', payslips_count: 'Jumlah slip',
  total_gross: 'Total bruto', total_deductions: 'Total potongan', total_net: 'Total netto',
  sla_response_hours: 'SLA respons (jam)', sla_resolution_hours: 'SLA penyelesaian (jam)',
  billing_cycle: 'Siklus penagihan', priority: 'Prioritas', category: 'Kategori', channel: 'Kanal',
  frequency: 'Frekuensi', next_due_date: 'Jatuh tempo berikutnya',
  guarantee_type: 'Jenis jaminan', issuer: 'Penerbit', number: 'Nomor',
  document_location: 'Lokasi dokumen fisik', is_expired: 'Sudah lewat masa berlaku',
  days_left: 'Sisa hari berlaku',

  /*
   * Pelengkapan #57: kunci di bawah diambil dari diff seluruh Resource API
   * terhadap kamus ini — tanpa entri, titleize() memajang label auto-Inggris
   * ("Is Billed", "Customer") tepat di panel Informasi dan Terkait.
   */
  // Objek relasi tertanam (panel "Terkait") dan nama/kode yang diratakan Resource.
  customer: 'Pelanggan', vendor: 'Vendor', contract: 'Kontrak', quotation: 'Penawaran',
  employee: 'Karyawan', user: 'Pengguna', item: 'Item', warehouse: 'Gudang',
  from_warehouse: 'Gudang asal', to_warehouse: 'Gudang tujuan', asset: 'Aset',
  subcontract: 'SPK', subcontract_claim: 'Opname subkon', subcontract_item: 'Item SPK',
  purchase_order: 'PO', purchase_requisition: 'PR', goods_receipt: 'Penerimaan barang',
  issue: 'Pengeluaran barang', termin: 'Termin', site: 'Site', payroll_run: 'Run payroll',
  petty_cash_fund: 'Dana kas kecil', bank_account: 'Rekening bank', pph_tax: 'Jenis PPh',
  coa_account: 'Akun COA', account: 'Akun', wbs_task: 'Tugas WBS', parent: 'Induk',
  active_deployment: 'Penempatan aktif',
  account_code: 'Kode akun', account_name: 'Atas nama', account_no: 'No. rekening',
  account_type: 'Jenis akun', normal_balance: 'Saldo normal',
  // quotation_code "Dari penawaran", bukan "No. penawaran": label yang sama
  // dengan pemilihnya di formulir kontrak, dan kalimat yang dibaca pada
  // kontrak — CTR/2026/VIII/0004 di produksi (4 Sep 2026) tidak menyebut
  // penawaran Rp 2,04 M asalnya sama sekali (T3.6).
  project_code: 'Kode proyek', contract_code: 'No. kontrak', quotation_code: 'Dari penawaran',
  value_change_reason: 'Alasan perubahan nilai',
  boq_code: 'Kode BOQ', cost_budget_code: 'Kode RAP', cost_budget_status: 'Status RAP',
  cost_budget_id: 'RAP', boq_total: 'Total BOQ', boq_wbs_code: 'Kode WBS BOQ',
  issue_code: 'No. pengeluaran barang', ticket_code: 'No. tiket', invoice_code: 'No. invoice',
  ap_bill_code: 'No. tagihan vendor', ap_bill_id: 'Tagihan vendor', ar_invoice_id: 'Invoice AR',
  source_invoice_id: 'Invoice sumber', service_contract_code: 'No. kontrak layanan',
  bupot_no: 'No. bukti potong', wbs_code: 'Kode WBS', section_no: 'No. bagian',
  customer_name: 'Pelanggan', company_name: 'Perusahaan', assignee_name: 'Ditugaskan ke',
  technician_name: 'Teknisi', evaluator_name: 'Dievaluasi oleh', requester_name: 'Diminta oleh',
  reported_by_name: 'Dilaporkan oleh', user_name: 'Pengguna', owner_name: 'Pemilik prospek',
  warehouse_name: 'Gudang', item_name: 'Item', item_code: 'Kode item', site_name: 'Nama site',
  customer_representative: 'Wakil pelanggan', customer_sign_name: 'TTD pelanggan',
  customer_signed_at: 'Ditandatangani pelanggan',
  // Tanggal, stempel waktu, dan pelakunya.
  achieved_date: 'Tanggal tercapai', is_achieved: 'Tercapai', addendum_date: 'Tanggal addendum',
  approved_at: 'Disetujui pada', approved_by: 'Disetujui oleh',
  assigned_from: 'Ditugaskan sejak', assigned_until: 'Ditugaskan sampai',
  billed_at: 'Ditagih pada', cost_date: 'Tanggal biaya', date: 'Tanggal',
  depreciation_start_date: 'Mulai penyusutan', disposal_date: 'Tanggal pelepasan',
  disposal_reason: 'Alasan pelepasan', disposal_value: 'Nilai pelepasan',
  effective_date: 'Tanggal efektif', expiry_date: 'Tanggal kedaluwarsa',
  days_to_expiry: 'Sisa hari berlaku', fixed_at: 'Selesai diperbaiki pada',
  verified_at: 'Diverifikasi pada', verified_by: 'Diverifikasi oleh',
  reported_on: 'Dilaporkan pada', reported_by: 'Dilaporkan oleh', days_open: 'Hari terbuka',
  first_response_at: 'Respons pertama', resolved_at: 'Selesai pada',
  response_due_at: 'Batas respons', resolution_due_at: 'Batas penyelesaian',
  response_breached: 'SLA respons terlewati', resolution_breached: 'SLA penyelesaian terlewati',
  issued_date: 'Tanggal terbit', received_date: 'Tanggal terima',
  release_date: 'Tanggal pencairan', released: 'Sudah dicairkan', released_at: 'Dicairkan pada',
  return_date: 'Tanggal retur', returned_at: 'Dikembalikan pada', returned_by: 'Dikembalikan oleh',
  reversal_reason: 'Alasan pembalikan', reversed_at: 'Dibalik pada',
  settlement_date: 'Tanggal pertanggungjawaban', voucher_date: 'Tanggal bon',
  next_follow_up_at: 'Tindak lanjut berikutnya', maintenance_date: 'Tanggal perawatan',
  deployed_from: 'Ditempatkan sejak', planned_until: 'Rencana sampai', rfq_date: 'Tanggal RFQ',
  trx_date: 'Tanggal transaksi', defect_liability_until: 'Masa pemeliharaan sampai',
  retention_release_due: 'Jatuh tempo pencairan retensi', superseded_at: 'Digantikan pada',
  superseded_by_id: 'Digantikan oleh', resign_date: 'Tanggal resign',
  pkwt_end_date: 'Akhir PKWT', pkwt_basis: 'Dasar PKWT',
  // SDM & payroll.
  basic_salary: 'Gaji pokok', gross_income: 'Penghasilan bruto', net_pay: 'Gaji bersih',
  allowances: 'Tunjangan', allowances_total: 'Total tunjangan',
  fixed_allowances: 'Tunjangan tetap', fixed_allowances_total: 'Total tunjangan tetap',
  overtime_hours: 'Jam lembur', overtime_pay: 'Upah lembur', thr_amount: 'THR',
  pph21_amount: 'PPh 21', ter_rate: 'Tarif TER', bpjs: 'BPJS',
  bpjs_company_total: 'BPJS (perusahaan)', bpjs_employee_total: 'BPJS (karyawan)',
  bpjs_kesehatan_no: 'No. BPJS Kesehatan', bpjs_tk_no: 'No. BPJS TK',
  work_days: 'Hari kerja', present_days: 'Hari hadir', sick_days: 'Hari sakit',
  alpha_days: 'Hari alpa', leave_days: 'Hari cuti', leave_type: 'Jenis cuti',
  day_count: 'Jumlah hari', counts_against_balance: 'Memotong saldo cuti',
  certificate_type: 'Jenis sertifikat', recorded_by: 'Dicatat oleh', payroll_run_id: 'Run payroll',
  // Vendor & pengadaan.
  classification: 'Klasifikasi', rating: 'Rating', doc_type: 'Jenis dokumen', is_mandatory: 'Wajib',
  delivery_score: 'Skor pengiriman', price_score: 'Skor harga', quality_score: 'Skor kualitas',
  service_score: 'Skor layanan', total_score: 'Skor total', period: 'Periode',
  rfq_id: 'RFQ', is_winner: 'Pemenang', vendor_ids: 'Vendor diundang',
  qty_received: 'Qty diterima', qty_returned: 'Qty diretur', qty_used: 'Qty terpakai',
  qualification_override_reason: 'Alasan override kualifikasi',
  // T3.8: jejak PO tanpa PR, ditampilkan seperti alasan override di atasnya.
  pr_bypass_reason: 'Alasan tanpa PR',
  goods_receipt_id: 'Penerimaan barang', field_report_id: 'Laporan lapangan',
  issue_id: 'Pengeluaran barang', wbs_task_id: 'Tugas WBS', boq_item_id: 'Item BOQ',
  // P4 — SP3 mandor & opname mandor; tanpa entri ini titleize() jatuh ke
  // "Labor Contract" / "Kasbon Id" pada layar berbahasa Indonesia.
  vendor_type: 'Jenis vendor', labor_contract_id: 'SP3', labor_contract: 'SP3',
  labor_claim_id: 'Opname mandor', labor_claim: 'Opname mandor',
  kasbon_id: 'Kasbon', kasbon: 'Kasbon dipotong',
  kasbon_deduction_amount: 'Potongan kasbon', unit_rate: 'Tarif upah',
  // Uang, termin, progres.
  amount: 'Jumlah', percent: 'Persentase', termin_no: 'Termin ke-', is_billed: 'Sudah ditagih',
  is_due: 'Sudah jatuh tempo', is_retention: 'Termin retensi',
  billing_condition: 'Syarat penagihan', billing_amount_per_period: 'Tagihan per periode',
  original_value: 'Nilai awal', value_change: 'Perubahan nilai', change_type: 'Jenis perubahan',
  // Addendum waktu (P0-B): hari bertanda, tanggal hasil persetujuan, dan
  // tanggal selesai sebagaimana ditandatangani (sekali tulis di kontrak).
  days_change: 'Perubahan waktu (hari)', new_end_date: 'Tanggal selesai baru',
  original_end_date: 'Tanggal selesai awal',
  advance_recovery_amount: 'Pemulihan uang muka', is_advance: 'Klaim uang muka',
  float_amount: 'Float dana', spend: 'Belanja', cash_returned: 'Kas dikembalikan',
  total_debit: 'Total debit', total_credit: 'Total kredit', total_amount: 'Total',
  entries_count: 'Jumlah entri', users_count: 'Jumlah pengguna', stock_value: 'Nilai persediaan',
  estimated_value: 'Nilai estimasi', estimated_price: 'Harga estimasi', cost: 'Biaya',
  cost_category: 'Kategori biaya', direction: 'Arah', unit_cost: 'HPP satuan',
  overhead_pct: 'Overhead', weight_pct: 'Bobot', progress_pct: 'Progres',
  planned_pct: 'Rencana', actual_pct: 'Aktual', deviation_pct: 'Deviasi',
  actual_progress_pct: 'Progres aktual', planned_progress_pct: 'Progres rencana',
  actual_start: 'Aktual mulai', actual_end: 'Aktual selesai',
  planned_start: 'Rencana mulai', planned_end: 'Rencana selesai',
  planned_finish: 'Rencana selesai', contract_finish: 'Selesai kontrak',
  planned_duration_days: 'Durasi rencana (hari)',
  // Baseline & EVM.
  bac: 'BAC', bac_source: 'Sumber BAC', curve_source: 'Sumber kurva',
  // 'Revisi berlaku', bukan 'Baseline berlaku': kunci yang sama kini dibawa
  // izin kerja, IPP, inspeksi (P8) dan pustaka metode — dan untuk baseline pun
  // kalimatnya tetap benar.
  revision_no: 'Revisi ke-', reference_no: 'No. referensi', is_current: 'Revisi berlaku',
  leaf_task_count: 'Jumlah paket kerja', leaf_weight_total: 'Total bobot paket',
  // Lain-lain yang muncul di panel Informasi.
  source: 'Sumber', need_summary: 'Ringkasan kebutuhan', note: 'Catatan', remark: 'Keterangan',
  reason: 'Alasan', resolution_note: 'Catatan penyelesaian', blocks_handover: 'Menahan BAST',
  is_open: 'Masih terbuka', bast_type: 'Jenis BAST',
  prerequisite_override_reason: 'Alasan override prasyarat',
  prerequisite_override_at: 'Override prasyarat pada',
  prerequisite_override_by: 'Override prasyarat oleh',
  closure_override_reason: 'Alasan override penutupan', closed_by: 'Ditutup oleh',
  override_reason: 'Alasan override', weather_am: 'Cuaca pagi', weather_pm: 'Cuaca siang',
  role_on_project: 'Peran di proyek', is_current_today: 'Aktif hari ini',
  maintenance_type: 'Jenis perawatan', useful_life_months_default: 'Umur manfaat bawaan (bulan)',
  is_fully_depreciated: 'Habis disusutkan', current_project_id: 'Proyek saat ini',
  item_type: 'Jenis item', tax_type: 'Jenis pajak', object_code: 'Kode objek pajak',
  rate: 'Tarif', daily_rate_internal: 'Tarif harian internal', minutes_spent: 'Menit dikerjakan',
  terbilang: 'Terbilang', roles: 'Peran', type: 'Jenis', depreciation_run_id: 'Run penyusutan',
  asset_account_hint: 'Akun aset (petunjuk)', accum_account_hint: 'Akun akumulasi (petunjuk)',
  depreciation_account_hint: 'Akun penyusutan (petunjuk)',
  // Tiga izin lapangan (P0-C): IKL, ILB, IMK.
  shift: 'Shift', permit_date: 'Tanggal izin', work_description: 'Pekerjaan yang dimohonkan',
  hazard_notes: 'Potensi bahaya', ppe_required: 'APD wajib', valid_from: 'Berlaku mulai',
  requested_by_name: 'Pemohon', safety_officer_id: 'Petugas K3', safety_officer_name: 'Petugas K3',
  overtime_date: 'Tanggal lembur', start_time: 'Jam mulai', end_time: 'Jam selesai',
  crosses_midnight: 'Melewati tengah malam', total_hours: 'Total jam lembur',
  pass_date: 'Tanggal', vehicle_no: 'No. polisi kendaraan', driver_name: 'Pengemudi',
  counterparty: 'Asal/tujuan barang', transfer_id: 'Transfer antar gudang',
  checked_by: 'Diperiksa oleh', checked_by_name: 'Diperiksa oleh', checked_at: 'Diperiksa pada',
  vendor_name: 'Vendor',
  // P1-ENG — Engineering: register gambar, submittal, transmittal, IPP,
  // lokasi tapak. Tanpa entri ini panel Informasi memajang "Reviewer Party".
  discipline: 'Disiplin', planned_submit_date: 'Rencana tanggal ajuan',
  current_submittal_code: 'SDS berlaku', current_revision: 'Revisi berlaku',
  drawing_id: 'Gambar', drawing_number: 'No. gambar', drawing_title: 'Judul gambar',
  submitted_at: 'Tanggal diajukan', reviewer_party: 'Pemeriksa',
  decision: 'Keputusan', decided_at: 'Tanggal keputusan',
  superseded_by_code: 'Digantikan oleh', created_by_name: 'Dibuat oleh',
  material_name: 'Nama material', spec_reference: 'Rujukan spesifikasi',
  sample_attached: 'Sampel disertakan',
  to_party: 'Kepada', transmittal_date: 'Tanggal transmittal',
  received_at: 'Diterima pada',
  ipp_id: 'IPP', ipp_code: 'No. IPP',
  location_id: 'Lokasi tapak', location_name: 'Lokasi tapak', location_path: 'Lokasi tapak',
  duration_days: 'Durasi (hari)', wbs_task_code: 'Kode paket WBS', wbs_task_name: 'Paket pekerjaan',
  kind: 'Jenjang', parent_id: 'Induk', parent_code: 'Kode induk', parent_name: 'Induk',
  path: 'Jalur lokasi', sort_order: 'Urutan', children_count: 'Jumlah sub-lokasi',
  // P7 — metode pelaksanaan yang dikutip penawaran.
  method_library_id: 'Metode pelaksanaan', method_library_title: 'Metode pelaksanaan',
  method_library_code: 'Kode metode',
};

/*
 * Sekali per kunci, selalu aktif: aplikasi ini tidak punya penanda dev/prod
 * (tidak ada cek hostname di mana pun), dan biaya satu console.warn per kunci
 * per sesi tidak berarti — sementara diamnya-lah yang membuat label
 * auto-Inggris #57 lolos berbulan-bulan tanpa ada yang tahu harus menambah
 * entri LABELS mana.
 */
const TITLEIZE_WARNED = new Set();

function titleize(key) {
  if (LABELS[key]) return LABELS[key];
  if (!TITLEIZE_WARNED.has(key)) {
    TITLEIZE_WARNED.add(key);
    console.warn(`titleize: kunci '${key}' belum ada di LABELS (detail.js) — label tampil auto-Inggris.`);
  }
  return key
    .replace(/_id$/, '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

/** Best-effort rendering of a raw record field when the schema doesn't name it. */
function autoValue(record, key) {
  const value = record[key];

  if (value === null || value === undefined || value === '') return el('span.muted', { text: '—' });
  if (typeof value === 'boolean') return el('span', { text: value ? 'Ya' : 'Tidak' });

  if (ID_LOOKUPS[key]) {
    for (const source of ID_LOOKUPS[key]) {
      const label = labelFor(source, value);
      if (label) return el('span', { text: label });
    }
    return el('span.mono.muted', { text: `#${value}` });
  }

  if (Array.isArray(value)) {
    return el('span', { style: { display: 'inline-flex', gap: '4px', flexWrap: 'wrap' } },
      value.map((entry) => badge(typeof entry === 'object' ? JSON.stringify(entry) : String(entry), 'primary')));
  }
  if (typeof value === 'object') {
    return el('div', Object.entries(value).map(([name, amount]) =>
      el('div', { text: `${titleize(name)}: ${typeof amount === 'number' ? fmt.rupiah(amount) : amount}` })));
  }

  const labelKey = `${key}_label`;
  if (record[labelKey]) return el('span', { text: record[labelKey] });

  if (PERCENT_KEY.test(key)) return el('span.num', { text: fmt.percent(value) });
  // Sebelum MONEY_KEY: total_hours (ILB, P0-C) memuat kata 'total' dan akan
  // tampil "Rp 8" — jam bukan uang, berapa pun kemiripan nama kolomnya.
  if (/_hours$/.test(key) && !Number.isNaN(Number(value))) {
    const hours = Number(value);
    return el('span.num', { text: fmt.num(hours, Number.isInteger(hours) ? 0 : 2) });
  }
  if (MONEY_KEY.test(key) && !Number.isNaN(Number(value))) return el('span.num', { text: fmt.rupiah(value) });
  if (DATE_KEY.test(key)) return el('span', { text: String(value).length > 10 ? fmt.dateTime(value) : fmt.date(value) });
  if (key.endsWith('_terbilang')) return el('em', { text: String(value) });

  return el('span', { text: String(value) });
}

/** Money summary strip (dpp / ppn / total …) driven by detail.summary. */
function summaryStrip(record, keys) {
  return el('.stat-row', keys.map((key) => el('.stat', [
    el('.label', { text: titleize(key) }),
    el('.value.sm', { text: fmt.rupiah(record[key]) }),
  ])));
}

function linesTable(rows, table, record) {
  if (!rows || !rows.length) {
    return el('.card-body', el('p.muted', { text: 'Belum ada baris.', style: { margin: 0 } }));
  }

  const totalKeys = table.totals || (table.totalKey ? [table.totalKey] : []);

  /*
   * Per-row action. Billing a termin is the reason this exists: the termin
   * schedule already knows the contract, the amount and the billing condition,
   * and the alternative was typing a raw database id into the invoice form —
   * where a typo bills the wrong termin and nothing catches it.
   */
  const action = table.rowAction && session.can(table.rowAction.perm) ? table.rowAction : null;

  /* Kolom bertanda hideOnNarrow disembunyikan per sel — th, td, DAN sel tfoot,
     supaya jumlah sel tiap baris tetap segaris di bawah 760px. Aturan CSS-nya
     satu tempat di app.css, breakpoint yang sama dengan drawer nav. */
  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', [
      ...table.columns.map((column) =>
        el(`th${column.align ? `.${column.align}` : ''}${column.hideOnNarrow ? '.hide-narrow' : ''}`, { text: column.label })),
      action ? el('th', { text: '' }) : null,
    ])),
    el('tbody', rows.map((row) => el('tr', [
      ...table.columns.map((column) =>
        el(`td${column.align ? `.${column.align}` : ''}${column.hideOnNarrow ? '.hide-narrow' : ''}`, renderCell(row, column))),
      action
        ? el('td.right', action.when && !action.when(row)
          ? null
          : button(action.label, {
            size: 'sm',
            variant: action.variant || 'ghost',
            onClick: () => openForm({
              def: RESOURCES[action.opens],
              key: action.opens,
              prefill: action.prefill(row, record),
              onSaved: () => (action.navigateTo ? navigate(action.navigateTo) : null),
            }),
          }))
        : null,
    ]))),
    totalKeys.length
      ? el('tfoot', el('tr', [
        ...table.columns.map((column, index) => {
          const narrow = column.hideOnNarrow ? '.hide-narrow' : '';
          if (totalKeys.includes(column.key)) {
            return el(`td.right${narrow}`, { text: fmt.rupiah(sumColumn(rows, column.key)) });
          }
          return el(`td${column.align ? `.${column.align}` : ''}${narrow}`, { text: index === 0 ? 'Total' : '' });
        }),
        action ? el('td') : null,
      ]))
      : null,
  ]));
}

/** BOQ-style section > items nesting flattened into one table. */
function nestedTable(sections, table) {
  const body = el('tbody');
  let grandTotal = 0;

  for (const section of sections || []) {
    body.appendChild(el('tr.section-row', [
      el('td.code', { text: section.section_no || '' }),
      el('td', { text: section.name, colspan: table.columns.length - 1 }),
    ]));
    for (const row of section.items || []) {
      grandTotal += Number(row[table.totalKey]) || 0;
      body.appendChild(el('tr', table.columns.map((column) =>
        el(`td${column.align ? `.${column.align}` : ''}${column.hideOnNarrow ? '.hide-narrow' : ''}`, renderCell(row, column)))));
    }
  }

  if (!body.childElementCount) {
    return el('.card-body', el('p.muted', { text: 'Belum ada bagian atau item.', style: { margin: 0 } }));
  }

  // Baris section dan tfoot memakai colspan penuh — dan justru karena itu
  // kolom nested-table TIDAK BOLEH diberi flag hideOnNarrow: td[colspan=N-1]
  // menahan grid di N kolom sementara baris item menyusut ke N-h, sehingga
  // angka grand total mendarat satu kolom di KANAN kolom uang yang ia
  // jumlahkan, tanpa header. linesTable aman (tfoot per-kolom); yang ini
  // tidak. Flag hanya untuk kolom daftar tingkat atas.
  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', table.columns.map((column) =>
      el(`th${column.align ? `.${column.align}` : ''}${column.hideOnNarrow ? '.hide-narrow' : ''}`, { text: column.label })))),
    body,
    el('tfoot', el('tr', [
      el('td', { text: 'Total', colspan: table.columns.length - 1 }),
      el('td.right', { text: fmt.rupiah(grandTotal) }),
    ])),
  ]));
}

/** Kalimat keadaan dokumen di bawah judul, atau null bila statusnya tidak dikenal. */
function statusStrip(def, record, canEdit) {
  const status = String(record.status || '');
  const approvals = Array.isArray(record.approvals) ? record.approvals : [];
  const last = (action) => [...approvals].reverse().find((a) => a.action === action);
  const who = (entry) => (entry && entry.user ? entry.user.name : 'Sistem');
  const when = (entry) => (entry ? fmt.date(entry.created_at) : '');
  const label = (def.labelOne || 'Dokumen');

  let tone = 'info';
  let text = null;
  let sub = null;

  if (status === 'draft') {
    text = `${label} ini masih draf — belum masuk antrean siapa pun.`;
    sub = canEdit ? 'Ubah dan Hapus tersedia sampai diajukan. Tekan Ajukan bila sudah lengkap.' : null;
  } else if (status === 'submitted') {
    const s = last('submitted');
    const given = approvals.filter((a) => a.action === 'approved').length;
    const need = Number(record.required_levels || 0);
    text = `${s ? `Diajukan ${when(s)} oleh ${who(s)}` : 'Diajukan'} · menunggu persetujuan${need > 1 ? ` (${given} dari ${need} tingkat)` : ''}.`;
    sub = 'Ubah dan Hapus tidak tersedia selama menunggu. Untuk memperbaiki isinya, minta penyetuju menolaknya — dokumen kembali ke draf.';
    tone = 'warn';
  } else if (status === 'rejected') {
    const r = last('rejected');
    text = `Ditolak ${when(r)} oleh ${who(r)}${r && r.note ? `: "${r.note}"` : '.'}`;
    sub = 'Perbaiki lalu ajukan lagi; riwayat penolakan tetap tersimpan.';
    tone = 'error';
  } else if (['approved', 'posted', 'active', 'closed'].includes(status)) {
    const a = last('approved');
    text = a ? `Disetujui ${when(a)} oleh ${who(a)} · dokumen terkunci.` : `${label} ini terkunci (${record.status_label || status}).`;
    sub = 'Isi tidak dapat diubah lagi; perubahan hanya lewat revisi, pembalikan, atau dokumen lanjutan.';
  }

  if (!text) return el('span', { hidden: true });
  return el(`.alert.${tone}`, { style: { marginBottom: '14px' } }, [
    icon(tone === 'info' ? 'inbox' : 'warn', 15),
    el('div', { style: { flex: '1' } }, [el('div', { text }), sub ? el('.cell-sub', { text: sub }) : null]),
  ]);
}

export function approvalTimeline(approvals) {
  if (!approvals || !approvals.length) {
    return el('p.muted', { text: 'Belum ada riwayat persetujuan.', style: { margin: 0, fontSize: '13px' } });
  }

  const tone = { approved: 'ok', rejected: 'bad', submitted: 'pending' };
  const label = { submitted: 'Diajukan', approved: 'Disetujui', rejected: 'Ditolak' };

  return el('.timeline', approvals.map((entry) => el(`.timeline-item${tone[entry.action] ? `.${tone[entry.action]}` : ''}`, [
    el('b', { text: label[entry.action] || entry.action }),
    el('.meta', { text: `${entry.user ? entry.user.name : 'Sistem'} · ${fmt.dateTime(entry.created_at)}` }),
    entry.note ? el('.note', { text: entry.note }) : null,
  ])));
}

/*
 * "Cetak <formulir>" — the company's own construction forms.
 *
 * Two sources, merged by printcatalog.js printButtonsFor():
 *
 *   1. def.printForms on the schema entry — for the forms that need a query
 *      parameter only the row can supply (?tanggal= off a daily report,
 *      ?minggu= off a progress row).
 *   2. GET core/print/forms — every document registered in
 *      Modules\Core\Support\PrintableDocuments, already filtered to the ones
 *      THIS caller may print, each naming the RESOURCES key it belongs to.
 *
 * The second is why a module lane adds one registry entry and gets its button
 * with no edit to this file and none to schema.js. Both sources arrive in the
 * same shape:
 *
 *   form    slug registered on the server — also the URL segment
 *   label   button reads "Cetak <label>"
 *   idField which field of the record is the {id} (default 'id'; a form that
 *           hangs off the project prints from a line record with 'project_id')
 *   params  query string, param name => record field. A field the record does
 *           not carry is left out rather than sent empty — the endpoint's
 *           defaults are better than a blank.
 *
 * Satu daftar entri untuk dua bentuk: item menu "Cetak ▾" pada layar detail
 * generik (printMenu, T2.6) dan tombol lepas pada layar custom yang merakit
 * bilahnya sendiri (formButtons — rfq.js, tender.js, custom.js). `trigger`
 * pada onClick adalah simpul yang memutar spinner withBusy: tombolnya sendiri
 * untuk tombol lepas, tombol menunya untuk item menu (itemnya sudah dibuang
 * begitu dipilih — lihat ui.js menuButton).
 */
export function formMenuItems(forms, record) {
  return (forms || [])
    .filter((form) => record[form.idField || 'id'])
    .flatMap((form) => [
      {
        label: `Cetak ${form.label}`,
        iconName: 'print',
        title: `Cetak ${form.label} dalam format formulir perusahaan`,
        onClick: (event, trigger) => openPrintable(printablePath(form, record), trigger),
      },
      /* P8 — the same composition as the print sheet, as a spreadsheet. Drawn
         ONLY when the catalogue flags the slug (form.xlsx — satu pemilik di
         PHP), and downloaded like a PDF: fetched as a blob so the session
         token rides along. Di menu, kata kerjanya ikut ("Unduh … (XLSX)");
         sebagai tombol lepas tetap "XLSX" seperti sebelumnya (short). */
      form.xlsx
        ? {
          label: `Unduh ${form.label} (XLSX)`,
          short: 'XLSX',
          iconName: 'download',
          title: `Unduh ${form.label} sebagai XLSX — sel yang di kertas bergaris adalah sel kosong, bukan 0`,
          onClick: (event, trigger) => downloadPdf(
            xlsxPath(form, record),
            xlsxName(form.form, record.code || record[form.idField || 'id']),
            trigger,
          ),
        }
        : null,
    ])
    .filter(Boolean);
}

export function formButtons(forms, record) {
  return formMenuItems(forms, record).map((item) => button(item.short || item.label, {
    iconName: item.iconName,
    title: item.title,
    onClick: (event) => item.onClick(event, event.currentTarget),
  }));
}

/*
 * "Cetak ▾" — every output of the document behind ONE button (T2.6).
 *
 * Diukur 4 Sep 2026 (harness S2 › po_bar, katalog cetak sudah diperbaiki —
 * lihat printcatalog.js loadPrintForms): PO draf memajang Kembali · Cetak
 * halaman · PDF · Cetak Pesanan Pembelian · XLSX · Ubah · Ajukan — 7 tombol
 * setara di satu baris, keputusan bernilai ratusan juta di sebelah "Cetak
 * halaman" (ASESMEN-UX §1.2). Tiga keluaran plus XLSX-nya kini satu menu:
 * Cetak halaman, Unduh PDF, Cetak <formulir>, Unduh <formulir> (XLSX).
 *
 * Menu berisi SATU perintah adalah satu klik ekstra tanpa pilihan, jadi layar
 * tanpa PDF dan tanpa formulir rumah yang boleh dicetak pemanggil (pengajuan
 * cuti bagi direktur, tiket) tetap memakai tombol Cetak halaman langsung —
 * bilahnya tidak berubah dari 2 Sep 2026.
 */
export function printMenu(def, key, record) {
  const printPage = {
    label: 'Cetak halaman',
    iconName: 'print',
    title: 'Cetak tampilan layar ini lewat peramban',
    onClick: () => window.print(),
  };
  const items = [
    printPage,
    // A proper document with a letterhead and somewhere to sign, as opposed
    // to the browser printing this screen.
    def.printable
      ? {
        label: 'Unduh PDF',
        iconName: 'download',
        title: `Unduh ${def.labelOne} sebagai PDF`,
        onClick: (event, trigger) => downloadPdf(
          def.printable.path.replace('{id}', record.id),
          pdfName(def.printable.prefix, record.code || record.id),
          trigger,
        ),
      }
      : null,
    // Formulir rumah — the company's own construction forms, printed by the
    // browser rather than saved as a PDF. One entry per form this caller may
    // print: def.printForms plus the server catalogue for this resource,
    // merged by printButtonsFor(); see formMenuItems() above for the shape.
    ...formMenuItems(printButtonsFor(def, key), record),
  ].filter(Boolean);

  if (items.length === 1) return button('', { iconName: 'print', title: 'Cetak halaman', onClick: printPage.onClick });
  return menuButton('Cetak', items, { iconName: 'print', title: 'Cetak atau unduh dokumen ini' });
}

export async function renderDetail(host, { key, def, id }) {
  clear(host);
  host.appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '18px', width: '40%' } }))));

  let record;
  try {
    record = await api.get(`${def.api}/${id}`);
  } catch (error) {
    clear(host);
    host.append(
      el('.page-head', [button('Kembali', { iconName: 'back', onClick: () => back() })]),
      errorState(error, () => renderDetail(host, { key, def, id })),
    );
    return;
  }

  const detail = def.detail || {};

  await Promise.all([
    preload([
      ...def.columns.filter((column) => column.type === 'rel').map((column) => column.lookup),
      ...(detail.tables || []).flatMap((table) => table.columns.filter((c) => c.type === 'rel').map((c) => c.lookup)),
      // Every foreign key present on the record, so ids show as names.
      ...Object.keys(record).flatMap((fieldKey) => ID_LOOKUPS[fieldKey] || []),
    ]),
    // Which house forms this caller may print. Awaited here rather than inside
    // the render so the action row is drawn once, complete — a button that
    // appears a moment after the screen settles reads as a glitch. Never
    // rejects; a screen without its print button is still a working screen.
    loadPrintForms(),
  ]);

  const reload = () => renderDetail(host, { key, def, id });

  const canEdit = def.canEdit !== false && Boolean(def.form) && session.can(`${def.module}.update`) &&
    (!def.editableWhen || def.editableWhen(record));

  const title = record.code || record.name || record.title || `${def.labelOne} #${record.id}`;
  const subtitle = record.code ? (record.title || record.name || '') : '';
  document.title = `${title} · Nusantara ERP`;
  // Enum kolom status di schema.js (ncrStatus, incidentStatus, defectStatus)
  // menentukan warna lencananya; tanpa enum, peta kata bersama statusTone.
  // Diukur 4 Sep 2026: NCR/2026/IX/0002 "Terbuka → green" di sini.
  const statusEnum = (def.columns.find((column) => column.key === 'status') || {}).enum;

  // The breadcrumb was drawn with a placeholder id before the record loaded.
  const crumb = document.querySelector('#crumbs b');
  if (crumb) crumb.textContent = title;

  clear(host);

  /*
   * Bilah aksi tiga zona (T2.6): kiri navigasi (Kembali), tengah keluaran
   * (satu "Cetak ▾" — printMenu), kanan keputusan (Ubah, lalu aksi siklus
   * hidup). Diukur 4 Sep 2026 (harness S2 › po_bar): PO draf 7 tombol setara
   * di satu baris, kini 4. Hanya keputusan utama yang .primary: schema.js
   * memberi variant 'primary' pada lebih dari satu aksi yang bisa tampil
   * bersamaan (dua aksi aset tanpa `when`, misalnya); yang pertama dalam
   * urutan skema — urutan siklus hidupnya — yang memegangnya.
   *
   * Panel catatan inline (details.action-note, T2.3) tetap anak langsung
   * .actions dan paling akhir, BUKAN di zona keputusan: app.css mengukurnya
   * di sana (flex-basis 100%, :has(.action-note)).
   */
  const lifecycle = actionButtons(def, record, reload);
  const notePanels = lifecycle.filter((node) => node.matches('details.action-note'));
  const decisions = [
    canEdit ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key, row: record, onSaved: reload }) }) : null,
    ...lifecycle.filter((node) => !notePanels.includes(node)),
  ].filter(Boolean);
  decisions.filter((node) => node.classList.contains('primary')).slice(1).forEach((node) => node.classList.remove('primary'));

  host.appendChild(el('.page-head', [
    el('div', [
      el('div', { style: { display: 'flex', alignItems: 'center', gap: '9px', flexWrap: 'wrap' } }, [
        el('h1', { text: title }),
        record.status ? badge(record.status_label || record.status, fmt.statusTone(record.status, statusEnum)) : null,
      ]),
      subtitle ? el('.desc', { text: subtitle }) : null,
    ]),
    el('.actions', [
      el('.zone.navigasi', [button('', { iconName: 'back', title: 'Kembali', onClick: () => back() })]),
      el('.zone.keluaran', [printMenu(def, key, record)]),
      decisions.length ? el('.zone.keputusan', decisions) : null,
      ...notePanels,
    ]),
  ]));

  /*
   * Strip status: satu kalimat tentang di mana dokumen ini berada dan apa yang
   * bisa dilakukan sekarang. Ini jawaban di layar untuk tiga dari "enam kalimat
   * untuk semua orang" di panduan pengguna — Ubah yang menghilang tanpa pesan,
   * maker-checker, dan tidak ada batal setelah posting — yang dulu hanya bisa
   * dibaca di dokumen, bukan di tempat orang bertanya (asesmen 2 Sep 2026).
   * Datanya sudah ada di record.approvals; tidak ada permintaan tambahan.
   */
  host.appendChild(statusStrip(def, record, canEdit));

  /* P8 — revisi generik (D9): the superseded banner, speaking the 422's own
     words (Revisable::assertRevisiBerlaku) BEFORE anybody presses a button.
     Gated on def.revisable, not on the data alone: baselines and the method
     library expose is_current too, and their screens already tell this story
     their own way. The old row stays printable — that is the point of it. */
  if (def.revisable && record.is_current === false && record.superseded_by_id) {
    host.appendChild(el('.alert.warn', [
      icon('warn', 15),
      el('div', { style: { flex: '1' } }, [
        el('div', {
          text: `${def.labelOne} ini telah digantikan revisi ${record.superseded_by_code || `#${record.superseded_by_id}`} `
            + 'dan tidak dapat diubah, diajukan, atau diputus lagi.',
        }),
        el('.cell-sub', {
          text: 'Nomor, status, dan riwayat persetujuannya tetap tersimpan; lembarnya tetap bisa dicetak sebagai arsip.',
        }),
      ]),
      button('Buka revisi terbarunya', {
        size: 'sm',
        onClick: () => navigate(`d/${key}/${record.superseded_by_id}`),
      }),
    ]));
  }

  if (detail.summary) host.appendChild(summaryStrip(record, detail.summary));

  const main = el('div');
  const side = el('div');

  const relatedObjects = Object.keys(record).filter((fieldKey) =>
    typeof record[fieldKey] === 'object' && record[fieldKey] !== null &&
    !Array.isArray(record[fieldKey]) && record[fieldKey].id);

  // Foreign keys already covered by an embedded object go in the "Terkait"
  // panel instead of being repeated as a bare id.
  const coveredIds = new Set(relatedObjects.map((name) => `${name}_id`));

  const fieldKeys = Object.keys(record).filter((fieldKey) =>
    !HIDDEN_KEYS.has(fieldKey) &&
    !fieldKey.endsWith('_label') &&
    !coveredIds.has(fieldKey) &&
    !(detail.summary || []).includes(fieldKey) &&
    // Sebuah koleksi kosong tidak punya apa pun untuk ditampilkan; barisnya
    // hanya menyisakan label menggantung ("Retensi" tanpa nilai).
    !(Array.isArray(record[fieldKey]) && record[fieldKey].length === 0) &&
    // Bidang pembatalan baru berarti setelah terisi. Tanpa ini setiap invoice
    // sehat memasang "Dibatalkan pada —" di kartu Informasi.
    !(WHEN_SET_KEYS.has(fieldKey) && (record[fieldKey] === null || record[fieldKey] === '')) &&
    // Nama relasi yang diratakan Resource menggantikan baris id mentahnya
    // (P0-C — lihat NAME_SHADOWED), dan tidak meninggalkan baris "—" saat kosong.
    !(NAME_SHADOWED[fieldKey] && record[NAME_SHADOWED[fieldKey]]) &&
    !(NAME_KEYS.has(fieldKey) && (record[fieldKey] === null || record[fieldKey] === '')) &&
    !(typeof record[fieldKey] === 'object' && record[fieldKey] !== null && !Array.isArray(record[fieldKey]) && record[fieldKey].id));

  main.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Informasi' })),
    el('.card-body', el('dl.kv', fieldKeys.flatMap((fieldKey) => [
      el('dt', { text: titleize(fieldKey) }),
      el('dd', autoValue(record, fieldKey)),
    ]))),
  ]));

  for (const table of detail.tables || []) {
    let rows = record[table.key];

    if (table.endpoint) {
      try {
        rows = await api.get(`${def.api}/${String(table.endpoint).replace('{id}', id)}`);
      } catch {
        rows = [];
      }
    }

    main.appendChild(el('.card', [
      el('.card-head', el('h2', { text: table.label })),
      table.nested ? nestedTable(rows, table) : linesTable(rows, table, record),
    ]));
  }

  for (const list of detail.lists || []) {
    const values = record[list.key] || [];
    main.appendChild(el('.card', [
      el('.card-head', el('h2', { text: list.label })),
      el('.card-body', values.length
        ? el('ul', { style: { margin: 0, paddingLeft: '20px', lineHeight: '1.9' } },
          values.map((value) => el('li', { text: String(value) })))
        : el('p.muted', { text: 'Kosong.', style: { margin: 0 } })),
    ]));
  }

  if (relatedObjects.length) {
    side.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Terkait' })),
      el('.card-body', el('dl.kv', relatedObjects.flatMap((fieldKey) => {
        const related = record[fieldKey];
        return [
          el('dt', { text: titleize(fieldKey) }),
          el('dd', [
            el('div', { text: related.name || related.title || related.site_name || `#${related.id}` }),
            related.code ? el('.cell-sub.mono', { text: related.code }) : null,
          ]),
        ];
      }))),
    ]));
  }

  if (Array.isArray(record.approvals)) {
    side.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Riwayat Persetujuan' })),
      el('.card-body', approvalTimeline(record.approvals)),
    ]));
  }

  const attachments = attachmentsCard(key, record.id, def.module);
  if (attachments) side.appendChild(attachments);

  // Persetujuan Eksternal MK/Owner (P0-F) — same one-line wiring as the
  // attachments card: the registry mirror inside the card decides membership.
  const externalApprovals = externalApprovalsCard(key, record.id, def.module);
  if (externalApprovals) side.appendChild(externalApprovals);

  side.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Metadata' })),
    el('.card-body', el('dl.kv', [
      el('dt', { text: 'ID' }), el('dd.mono', { text: String(record.id) }),
      el('dt', { text: 'Dibuat' }), el('dd', { text: fmt.dateTime(record.created_at) }),
      el('dt', { text: 'Diperbarui' }), el('dd', { text: fmt.dateTime(record.updated_at) }),
    ])),
  ]));

  host.appendChild(el('.detail-grid', [main, side]));
}
