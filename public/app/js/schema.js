/* Resource catalogue — the single description of every screen in the ERP.
   The generic list / form / detail views read this; adding a screen means
   adding an entry here, not writing a view.

   column  { key, label, type, align, width, sub, lookup, enum, hideOnNarrow }
   field   { key, label, type, required, span, options, enum, lookup, help,
             default, hideWhen(record) }
   line    { key, label, columns[], min, prefill, importPick }
           prefill MENGGANTI seluruh baris dari dokumen sumber tanpa bertanya;
           importPick membuka dialog centang lalu MENAMBAHKAN baris terpilih —
           lihat buildLines di views/form.js untuk kedua bentuknya.
   action  { key, label, path, method, variant, perm, when, confirm, fields[],
             inlineNote }
           fields membuka modal sebelum aksi; inlineNote { key, label } adalah
           satu textarea opsional yang dilipat di bilah aksi (actions.js
           inlineNote) dan ikut terkirim tanpa dialog.

   types — text | code | textarea | number | currency | qty | percent | date |
           datetime | time | bool | select | lookup | status | enum | rel |
           json | tags | progress | password | multiselect
*/

import { ENUMS } from './enums.js';

const DRAFT_OR_REJECTED = (row) => ['draft', 'rejected'].includes(row.status);
const IS_DRAFT = (row) => row.status === 'draft';
const IS_SUBMITTED = (row) => row.status === 'submitted';

/** Standard draft → submitted → approved/rejected buttons. */
function approvalActions(module, { submitPerm, approvePerm } = {}) {
  return [
    {
      key: 'submit', label: 'Ajukan', path: '{id}/submit', method: 'POST',
      perm: submitPerm || `${module}.update`, when: DRAFT_OR_REJECTED, variant: 'primary',
    },
    {
      key: 'approve', label: 'Setujui', path: '{id}/approve', method: 'POST',
      perm: approvePerm || `${module}.approve`, when: IS_SUBMITTED, variant: 'success',
      /* Catatan persetujuan dilipat di bilah aksi, bukan `fields` yang membuka
         modal pada SETIAP persetujuan: diukur 2 Sep 2026 (HASIL-UJI §1, S2)
         3 klik per dokumen — Setujui, Setujui lagi di modal catatan, Buka —
         untuk isian yang boleh kosong. Kini 2 tanpa catatan. Tolak tetap
         lewat modal: alasannya wajib. Payload ke server tetap { note }. */
      inlineNote: { key: 'note', label: 'Catatan persetujuan' },
    },
    {
      key: 'reject', label: 'Tolak', path: '{id}/reject', method: 'POST',
      perm: approvePerm || `${module}.approve`, when: IS_SUBMITTED, variant: 'danger',
      fields: [{ key: 'note', label: 'Alasan penolakan', type: 'textarea', required: true }],
    },
  ];
}

const statusColumn = { key: 'status', label: 'Status', type: 'status', width: '1%' };
const codeColumn = { key: 'code', label: 'Kode', type: 'code', width: '1%' };

/* P8 — revisi generik (D9) untuk izin kerja, IPP, dan inspeksi mutu: dokumen
   yang belum punya pola revisinya sendiri. Semantiknya semantik SDS — revisi
   adalah BARIS BARU bernomor baru; pendahulu tercap "Digantikan", nomor,
   status dan riwayat persetujuannya utuh, tetap tercetak sebagai arsip — dan
   hanya baris hidup yang punya tombol.

   is_current !== false, bukan === true: baris dari respons lama tanpa kunci
   ini (undefined) tetap diperlakukan hidup — menyembunyikan tombol karena
   kuncinya belum termuat adalah kegagalan yang tak terlihat. Server tetap
   penjaganya (assertRevisiBerlaku menjawab 422 yang menyebut penggantinya). */
const IS_LIVE_REVISION = (row) => row.is_current !== false;

/* Kolom penanda pada daftar ketiganya: dua baris dengan uraian pekerjaan yang
   sama dan tanpa lencana di antaranya adalah cara revisi yang salah ikut
   disetujui. */
const revisionColumn = {
  key: 'is_current', label: 'Revisi', type: 'flag', width: '1%', hideOnNarrow: true,
  trueLabel: 'Berlaku', trueTone: '', falseLabel: 'Digantikan', falseTone: 'amber',
};

/* Aksi siklus hidup yang sadar-revisi: submit/approve/reject disembunyikan
   pada baris yang sudah digantikan (server toh menjawab 422 — tombol yang
   satu-satunya jawaban mungkinnya penolakan bukan tombol), plus aksi Buat
   Revisi itu sendiri. Rute {id}/revise bergerbang {module}.create karena
   hasilnya memang dokumen baru. */
function revisableActions(module, actions) {
  return [
    ...actions.map((action) => ({
      ...action,
      when: (row) => IS_LIVE_REVISION(row) && (!action.when || action.when(row)),
    })),
    {
      key: 'revise', label: 'Buat Revisi', path: '{id}/revise', method: 'POST',
      perm: `${module}.create`,
      // Trio yang sama dengan revisi penawaran: draf cukup diubah langsung.
      when: (row) => IS_LIVE_REVISION(row) && ['submitted', 'approved', 'rejected'].includes(row.status),
      confirm: 'Buat revisi dari dokumen ini? Baris lama tetap tersimpan dan tetap tercetak, tercap '
        + '"Digantikan"; revisi barunya lahir sebagai Draf bernomor baru.',
      navigateToResult: true,
    },
  ];
}

/* P5 — kepemilikan aset pada form aset. Saat MEMBUAT, select ownership yang
   menentukan; saat MENYUNTING field itu createOnly (kepemilikan tidak bisa
   diubah — beli-putus alat sewa adalah peristiwa akuntansi), jadi dibaca dari
   record. Server menolak kolom pihak lain dengan prohibited_if dua arah,
   sehingga field yang tersembunyi memang TIDAK boleh ikut terkirim
   (form.js visibleWhen menahannya dari payload). */
const ASSET_OWNED = (values, record) => (values.ownership ?? (record && record.ownership) ?? 'owned') === 'owned';
const ASSET_RENTED = (values, record) => (values.ownership ?? (record && record.ownership) ?? 'owned') === 'rented';

/* T3.8 — PO tanpa PR harus beralasan. Field alasannya ikut nilai HIDUP lookup
   "Dari PR" (combobox mengembalikan null saat kosong): tampil dan wajib begitu
   PR dikosongkan, hilang dari layar DAN dari payload begitu PR dipilih —
   server (PurchaseOrderStoreRequest required_without) hanya menuntutnya bila
   purchase_requisition_id kosong, jadi tidak ada 422 untuk field yang tidak
   tampil. Diukur 4 Sep 2026 di produksi (ANALISIS-PROSES E3): PO/2026/III/0002
   Rp 128 jt tanpa PR dan tanpa alasan tercatat. */
const PO_WITHOUT_PR = (values) => values.purchase_requisition_id === null || values.purchase_requisition_id === undefined || values.purchase_requisition_id === '';

/*
 * T3.6 — isian formulir kontrak dari penawaran yang menang. Yang disalin
 * adalah yang penawaran tahu: pelanggan, judul, lingkup, tarif PPN, dan
 * nilai = DPP (sebelum PPN — crm_contracts.value memang DPP). Jadwal termin
 * TIDAK diusulkan: penawaran tidak membawa termin dan kontrak tidak membawa
 * baris rincian, jadi mengarang "DP 20% / BAST 80%" di sini berarti
 * menyatakan kesepakatan yang tidak ada. Diukur 4 Sep 2026 di produksi:
 * QTN/2026/VIII/0008 Rp 2,04 M diketik ulang menjadi CTR/2026/VIII/0004
 * Rp 1,84 M tanpa tautan dan tanpa alasan (ANALISIS-PROSES A1). Server
 * (create-contract) menyalin ulang pelanggan dan quotation_id apa pun yang
 * dikirim — isian di sini supaya orangnya MELIHAT apa yang disalin sebelum
 * Simpan, dan nilai yang ia ubah ditanyai alasannya oleh server.
 */
const CONTRACT_FROM_QUOTATION = (row) => ({
  customer_id: row.customer_id,
  quotation_id: row.id,
  title: row.title,
  scope_type: row.scope_type,
  value: Number(row.dpp || 0),
  ppn_rate: Number(row.ppn_rate || 0),
});

export const RESOURCES = {
  /* ============================================================== CRM === */
  'crm/customers': {
    module: 'crm', api: 'crm/customers', label: 'Pelanggan', labelOne: 'Pelanggan',
    lookupSource: 'customers',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama', type: 'text', sub: 'legal_name' },
      { key: 'city', label: 'Kota', type: 'text' },
      { key: 'pic_name', label: 'PIC', type: 'text', sub: 'pic_phone' },
      { key: 'is_pkp', label: 'PKP', type: 'bool', align: 'center' },
      { key: 'payment_term_days', label: 'TOP', type: 'number', align: 'right', suffix: ' hari' },
      statusColumn,
    ],
    filters: [{ key: 'status', label: 'Status', enum: 'activeStatus' }],
    form: {
      sections: [
        {
          title: 'Identitas',
          fields: [
            { key: 'name', label: 'Nama pelanggan', type: 'text', required: true, span: 2 },
            { key: 'legal_name', label: 'Nama badan hukum', type: 'text', span: 2 },
            { key: 'code', label: 'Kode', type: 'text', help: 'Kosongkan untuk penomoran otomatis (CUST-xxxx).' },
            { key: 'npwp', label: 'NPWP', type: 'text' },
            { key: 'is_pkp', label: 'Pengusaha Kena Pajak (PKP)', type: 'bool' },
            { key: 'status', label: 'Status', type: 'select', enum: 'activeStatus', default: 'active' },
          ],
        },
        {
          title: 'Alamat & kontak',
          fields: [
            { key: 'billing_address', label: 'Alamat penagihan', type: 'textarea', span: 2 },
            { key: 'city', label: 'Kota', type: 'text' },
            { key: 'province', label: 'Provinsi', type: 'text' },
            { key: 'phone', label: 'Telepon', type: 'text' },
            { key: 'email', label: 'Email', type: 'text' },
            { key: 'pic_name', label: 'Nama PIC', type: 'text' },
            { key: 'pic_phone', label: 'Telepon PIC', type: 'text' },
            { key: 'payment_term_days', label: 'Termin pembayaran (hari)', type: 'number', default: 30 },
          ],
        },
      ],
    },
  },

  'crm/leads': {
    module: 'crm', api: 'crm/leads', label: 'Prospek (Lead)', labelOne: 'Prospek',
    columns: [
      codeColumn,
      { key: 'name', label: 'Kontak', type: 'text', sub: 'company_name' },
      { key: 'source', label: 'Sumber', type: 'text' },
      { key: 'estimated_value', label: 'Estimasi nilai', type: 'currency', align: 'right' },
      { key: 'owner_name', label: 'Sales', type: 'text' },
      // Pengingat funnel awal (temuan #58) — tanggal relatifnya ("3 hari lagi")
      // yang membuat kolom ini terpakai sebagai antrean kerja sales.
      { key: 'next_follow_up_at', label: 'Follow-up', type: 'date', withRelative: true },
      { key: 'status', label: 'Status', type: 'status', width: '1%' },
    ],
    filters: [{ key: 'status', label: 'Status', enum: 'leadStatus' }],
    form: {
      sections: [{
        title: 'Prospek',
        fields: [
          { key: 'name', label: 'Nama kontak', type: 'text', required: true },
          { key: 'company_name', label: 'Perusahaan', type: 'text' },
          { key: 'source', label: 'Sumber', type: 'text', help: 'mis. referral, tender, pameran' },
          { key: 'status', label: 'Status', type: 'select', enum: 'leadStatus', default: 'new' },
          { key: 'phone', label: 'Telepon', type: 'text' },
          { key: 'email', label: 'Email', type: 'text' },
          { key: 'estimated_value', label: 'Estimasi nilai', type: 'currency' },
          { key: 'user_id', label: 'Sales penanggung jawab', type: 'lookup', lookup: 'users' },
          // Pengingat funnel awal (temuan #58): sebelum ada penawaran, tidak
          // ada dokumen lain yang bisa membawa tanggal tindak lanjut.
          { key: 'next_follow_up_at', label: 'Follow-up berikutnya', type: 'date' },
          { key: 'need_summary', label: 'Ringkasan kebutuhan', type: 'textarea', span: 2 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
    actions: [{
      // "Jadikan pelanggan" (temuan #58): penawaran mensyaratkan customer_id,
      // jadi data prospek selama ini diketik dua kali. Server idempoten —
      // klik kedua memulangkan pelanggan yang sama — tapi tombolnya memang
      // tidak perlu tampil lagi setelah lead punya customer_id.
      key: 'convert-to-customer', label: 'Jadikan Pelanggan', path: '{id}/convert-to-customer', method: 'POST',
      perm: 'crm.create', variant: 'success', navigateTo: 'crm/customers',
      when: (row) => row.status === 'won' && !row.customer_id,
      confirm: 'Buat pelanggan baru dari data lead ini?',
    }],
  },

  'crm/quotations': {
    module: 'crm', api: 'crm/quotations', label: 'Penawaran', labelOne: 'Penawaran',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text', sub: 'customer.name' },
      { key: 'scope_type', label: 'Lingkup', type: 'enum', enum: 'scopeType' },
      { key: 'valid_until', label: 'Berlaku s/d', type: 'date' },
      { key: 'total', label: 'Total', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'scope_type', label: 'Lingkup', enum: 'scopeType' },
      { key: 'customer_id', label: 'Pelanggan', lookup: 'customers' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Penawaran',
        fields: [
          { key: 'customer_id', label: 'Pelanggan', type: 'lookup', lookup: 'customers', required: true },
          { key: 'lead_id', label: 'Dari prospek', type: 'lookup', lookup: 'leads' },
          { key: 'title', label: 'Judul penawaran', type: 'text', required: true, span: 2 },
          { key: 'scope_type', label: 'Lingkup pekerjaan', type: 'select', enum: 'scopeType', required: true },
          {
            // P7 — "Metode Pelaksanaan". Pemilihnya hanya menawarkan versi yang
            // BERLAKU; versi yang sudah digantikan ditolak server dengan 422
            // yang menyebut versi penggantinya.
            key: 'method_library_id', label: 'Metode pelaksanaan', type: 'lookup', lookup: 'methodLibrary',
            help: 'Dirujuk pada dokumen penawaran. Hanya versi yang berlaku boleh dikutip.',
          },
          { key: 'valid_until', label: 'Berlaku sampai', type: 'date' },
          { key: 'discount_amount', label: 'Diskon', type: 'currency', default: 0 },
          { key: 'ppn_rate', label: 'Tarif PPN (%)', type: 'percent', default: 11 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Rincian penawaran', min: 1,
        columns: [
          { key: 'description', label: 'Uraian', type: 'text', required: true, width: '46%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '12%', default: 1 },
          { key: 'unit', label: 'Satuan', type: 'text', width: '12%' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', required: true, width: '20%' },
        ],
        total: (row) => Number(row.qty || 0) * Number(row.unit_price || 0),
      }],
    },
    detail: {
      summary: ['subtotal', 'discount_amount', 'dpp', 'ppn_amount', 'total'],
      tables: [{
        key: 'items', label: 'Rincian penawaran',
        columns: [
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [
      ...approvalActions('crm'),
      {
        key: 'mark-won', label: 'Tandai Menang', path: '{id}/mark-won', method: 'POST',
        perm: 'crm.update', variant: 'success',
        when: (row) => row.status === 'approved' && !row.won_at && !row.lost_at,
        confirm: 'Tandai penawaran ini sebagai dimenangkan?',
      },
      /*
       * T3.6 — dari penawaran menang ke kontraknya: formulir kontrak yang
       * sudah terisi (CONTRACT_FROM_QUOTATION), disimpan ke
       * {id}/create-contract (submitTo, actions.js). Dua tombol untuk dua
       * keadaan yang SERVER bedakan (contract_code / contract_needs_schedule
       * dari QuotationResource): belum ada kontrak → "Buat kontrak"; yang ada
       * masih cangkang Tandai Menang (draf tanpa jadwal — CTR/2026/VIII/0005
       * di produksi, 13 hari draf setelah QTN/2026/VII/0004 menang, 4 Sep
       * 2026) → "Lengkapi kontrak", nomornya tetap. Kontrak yang sudah
       * berjadwal: tanpa tombol — baris "No. kontrak" di Informasi menunjuknya.
       * `=== null` / `=== true`: kedua kunci hanya ada bila show() memuat
       * relasinya; baris daftar tidak membawanya dan tidak boleh menebak.
       */
      {
        key: 'create-contract', label: 'Buat kontrak', perm: 'crm.create', variant: 'primary',
        opens: 'crm/contracts', submitTo: '{id}/create-contract', prefill: CONTRACT_FROM_QUOTATION,
        when: (row) => Boolean(row.won_at) && row.contract_code === null,
      },
      {
        key: 'complete-contract', label: 'Lengkapi kontrak', perm: 'crm.create', variant: 'primary',
        opens: 'crm/contracts', submitTo: '{id}/create-contract', prefill: CONTRACT_FROM_QUOTATION,
        when: (row) => Boolean(row.won_at) && row.contract_needs_schedule === true,
      },
      {
        key: 'mark-lost', label: 'Tandai Kalah', path: '{id}/mark-lost', method: 'POST',
        perm: 'crm.update', when: (row) => !row.won_at && !row.lost_at,
        fields: [{ key: 'lost_reason', label: 'Alasan kalah', type: 'textarea', required: true }],
      },
      {
        key: 'revise', label: 'Buat Revisi', path: '{id}/revise', method: 'POST',
        perm: 'crm.update', when: (row) => ['approved', 'rejected', 'submitted'].includes(row.status),
        confirm: 'Buat revisi baru dari penawaran ini?', navigateToResult: true,
      },
    ],
  },

  'crm/contract-change-orders': {
    module: 'crm', api: 'crm/contract-change-orders',
    label: 'Pekerjaan Tambah-Kurang', labelOne: 'Pekerjaan Tambah-Kurang',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text', sub: 'contract.code' },
      { key: 'change_date', label: 'Tanggal', type: 'date' },
      // Eskalasi harga vs tambah-kurang (temuan #61) — pembeda jejak audit,
      // bukan mesin formula.
      { key: 'change_type', label: 'Jenis', type: 'enum', enum: 'ccoChangeType' },
      // Signed: negative is pekerjaan kurang, and it reads as a negative amount
      // rather than needing a separate direction column.
      { key: 'value_change', label: 'Perubahan nilai', type: 'currency' },
      // P0-B: kolom waktu dari addendum waktu — hari bertanda (negatif
      // memendekkan) dan tanggal selesai yang DISTEMPEL saat persetujuan;
      // '—' pada CCO nilai, dan Selesai baru '—' selama belum disetujui.
      { key: 'days_change', label: 'Perubahan waktu', type: 'number', align: 'right', suffix: ' hari' },
      { key: 'new_end_date', label: 'Selesai baru', type: 'date' },
      { key: 'status', label: 'Status', type: 'enum', enum: 'documentStatus' },
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'change_type', label: 'Jenis', enum: 'ccoChangeType' },
    ],
    form: {
      sections: [{
        title: 'Perubahan pekerjaan',
        fields: [
          /* createOnly: validated() di ContractChangeOrderController menandai
             contract_id 'prohibited' saat update, jadi mengirim ulang field ini
             membuat setiap Ubah gagal 422 (pola sama dengan subcontract_id di
             subcontract/addenda). */
          { key: 'contract_id', label: 'Kontrak', type: 'lookup', lookup: 'contracts', required: true, createOnly: true },
          { key: 'change_date', label: 'Tanggal', type: 'date', required: true },
          { key: 'title', label: 'Judul', type: 'text', required: true, span: 2 },
          {
            // Temuan #61: tanpa jenis, eskalasi harga (klausul indeks kontrak
            // multi-tahun) tercatat sebagai "pekerjaan tambah" yang salah makna
            // di pemeriksaan. Nilainya tetap dihitung di luar dan masuk lewat
            // value_change — tidak ada mesin formula indeks.
            key: 'change_type', label: 'Jenis perubahan', type: 'select', enum: 'ccoChangeType', default: 'tambah_kurang',
            help: 'Pilih "Eskalasi Harga" untuk penyesuaian indeks kontrak multi-tahun; "Addendum Waktu" (P0-B) menggeser tanggal selesai, bukan nilai.',
          },
          {
            key: 'value_change', label: 'Perubahan nilai', type: 'currency', required: true,
            help: 'Positif untuk pekerjaan tambah, negatif untuk pekerjaan kurang. Untuk Addendum Waktu isi 0 — waktu dan nilai tidak pernah bergerak di satu lembar.',
          },
          {
            /* P0-B. Form generik tidak punya field kondisional yang mengikuti
               nilai select (hideWhen membaca KEADAAN record, bukan isian) —
               jadi kejujurannya lewat bantuan + penolakan server: days_change
               pada jenis lain ditolak 422 menyebut nama, dan tanggal selesai
               baru TIDAK pernah diinput — dihitung server dari tanggal selesai
               berjalan saat disetujui, lalu tampil di kolom Selesai baru. */
            key: 'days_change', label: 'Perubahan waktu (hari)', type: 'number',
            help: 'Hanya untuk Addendum Waktu: bertanda dan tidak boleh 0 — negatif memendekkan. Kosongkan pada jenis lain.',
          },
          {
            key: 'reason', label: 'Sebab', type: 'select',
            options: [
              { value: 'permintaan_pelanggan', label: 'Permintaan pelanggan' },
              { value: 'kondisi_lapangan', label: 'Kondisi lapangan' },
              { value: 'desain', label: 'Perubahan desain' },
              { value: 'lainnya', label: 'Lainnya' },
            ],
          },
          { key: 'customer_ref', label: 'No. CCO pelanggan', type: 'text' },
          { key: 'description', label: 'Uraian', type: 'textarea', span: 2 },
        ],
      }],
    },
    detail: { summary: ['value_change', 'ppn_change'] },
    actions: [
      /* runAction membangun URL dari `path` apa adanya — tanpa {id} tombol ini
         mem-POST ke koleksi (crm/contract-change-orders/submit), jadi seluruh
         siklus CCO macet dari SPA. Bentuknya kini sama dengan
         approvalActions(); `perm`, bukan `permission`, karena actions.js
         membaca action.perm. */
      {
        key: 'submit', label: 'Ajukan', path: '{id}/submit', method: 'POST',
        perm: 'crm.update', variant: 'primary', when: (r) => r.status === 'draft' || r.status === 'rejected',
      },
      {
        key: 'approve', label: 'Setujui', path: '{id}/approve', method: 'POST',
        perm: 'crm.approve', variant: 'success', when: (r) => r.status === 'submitted',
        // Sama dengan approvalActions(): catatan inline, bukan modal (T2.3).
        inlineNote: { key: 'note', label: 'Catatan persetujuan' },
      },
      {
        key: 'reject', label: 'Tolak', path: '{id}/reject', method: 'POST',
        perm: 'crm.approve', variant: 'danger', when: (r) => r.status === 'submitted',
        fields: [{ key: 'note', label: 'Alasan penolakan', type: 'textarea', required: true }],
      },
      {
        /* Temuan #14: wizard pasca-persetujuan — nilai tambah menjadi SATU
           termin baru ber-due_date sehingga antrean siap tagih ikut
           mengejarnya. Hanya CCO approved bernilai positif yang belum
           dijadwalkan (termin_id kosong); sisanya ditolak server lewat
           re-read di dalam transaksinya. */
        key: 'schedule-termin', label: 'Jadwalkan Termin Nilai Tambah', path: '{id}/schedule-termin', method: 'POST',
        perm: 'crm.update', variant: 'primary',
        /* Addendum waktu tidak membawa nilai — servernya menolak dengan
           "tidak ada yang dijadwalkan untuk ditagih" (P0-B). value_change 0
           sudah menyembunyikan tombolnya; syarat jenis ditulis eksplisit
           supaya wizard-nya tidak muncul kembali bila aturan nilainya
           berubah. */
        when: (r) => r.status === 'approved' && r.change_type !== 'waktu'
          && Number(r.value_change) > 0 && !r.termin_id,
        fields: [
          {
            key: 'due_date', label: 'Rencana tagih', type: 'date', required: true,
            help: 'Termin masuk antrean siap tagih begitu tanggal ini lewat.',
          },
          { key: 'name', label: 'Nama termin', type: 'text', help: 'Kosongkan untuk "Pekerjaan tambah <kode CCO>".' },
        ],
      },
    ],
  },

  'crm/contracts': {
    module: 'crm', api: 'crm/contracts', label: 'Kontrak', labelOne: 'Kontrak',
    lookupSource: 'contracts',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text', sub: 'customer.name' },
      { key: 'scope_type', label: 'Lingkup', type: 'enum', enum: 'scopeType' },
      { key: 'sign_date', label: 'Tgl TTD', type: 'date' },
      { key: 'value', label: 'Nilai (DPP)', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'customer_id', label: 'Pelanggan', lookup: 'customers' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Kontrak',
        fields: [
          { key: 'customer_id', label: 'Pelanggan', type: 'lookup', lookup: 'customers', required: true },
          { key: 'quotation_id', label: 'Dari penawaran', type: 'lookup', lookup: 'quotations' },
          { key: 'title', label: 'Judul kontrak', type: 'text', required: true, span: 2 },
          { key: 'contract_number_customer', label: 'No. kontrak pelanggan', type: 'text' },
          { key: 'scope_type', label: 'Lingkup', type: 'select', enum: 'scopeType', required: true },
          { key: 'value', label: 'Nilai kontrak (DPP)', type: 'currency', required: true },
          {
            // T3.6: hanya tampil bila kontrak merujuk penawaran. Server yang
            // mewajibkannya — bila nilai berbeda dari DPP penawaran, 422-nya
            // menyebut kedua angka dan dilukis di field ini; nilai yang sama
            // menyimpan null walau diisi. Di bawah "Nilai kontrak" karena
            // itulah angka yang dijelaskannya.
            key: 'value_change_reason', label: 'Alasan perubahan nilai', type: 'textarea', span: 2,
            visibleWhen: (values) => Boolean(values.quotation_id),
            help: 'Wajib bila nilai kontrak berbeda dari nilai penawaran (DPP) yang dirujuk.',
          },
          { key: 'ppn_rate', label: 'Tarif PPN (%)', type: 'percent', default: 11 },
          { key: 'sign_date', label: 'Tanggal tanda tangan', type: 'date' },
          { key: 'start_date', label: 'Mulai', type: 'date' },
          { key: 'end_date', label: 'Selesai', type: 'date' },
          { key: 'retention_pct', label: 'Retensi (%)', type: 'percent', default: 5 },
          { key: 'warranty_months', label: 'Masa pemeliharaan (bulan)', type: 'number', default: 12 },
        ],
      }],
      lines: [{
        key: 'termins', label: 'Jadwal termin', min: 1,
        help: 'Total persentase termin harus tepat 100%. Centang "Retensi" pada termin retensi (mis. "Retensi 5%") — kontrak yang memuatnya menagih retensi lewat termin itu, dan potongan retensi per invoice akan ditolak agar tidak tercatat dobel.',
        columns: [
          { key: 'name', label: 'Nama termin', type: 'text', required: true, width: '28%' },
          { key: 'percent', label: 'Persen (%)', type: 'percent', required: true, width: '12%' },
          { key: 'billing_condition', label: 'Syarat penagihan', type: 'text', width: '30%' },
          // Penanda pola "retensi sebagai termin" (temuan #73). checkboxLabel
          // spasi: judul kolom sudah bicara, label ganda hanya menyempitkan sel.
          { key: 'is_retention', label: 'Retensi', type: 'bool', checkboxLabel: ' ', width: '8%' },
          // Termin kalender (kontrak pemeliharaan triwulanan) tidak punya milestone
          // yang memicunya — tanggal inilah yang membuatnya bisa diingatkan.
          { key: 'due_date', label: 'Rencana tagih', type: 'date', width: '18%' },
        ],
      }],
    },
    detail: {
      summary: ['value', 'ppn_amount', 'total_with_ppn'],
      tables: [{
        key: 'termins', label: 'Jadwal termin', endpoint: '{id}/termins',
        // Menagih termin langsung dari jadwalnya: kontrak, pelanggan dan termin
        // sudah diketahui di sini. Alternatifnya adalah mengetik ID termin dari
        // ingatan ke formulir invoice — dan salah ketik menagih termin lain
        // tanpa ada yang menahan.
        rowAction: {
          label: 'Tagih termin ini',
          perm: 'fin.create',
          variant: 'primary',
          when: (row) => !row.billed_at,
          opens: 'finance/ar-invoices',
          navigateTo: 'r/finance/ar-invoices',
          prefill: (row, contract) => ({
            termin_id: row.id,
            contract_id: contract?.id ?? row.contract_id ?? null,
            customer_id: contract?.customer_id ?? contract?.customer?.id ?? null,
            description: `${contract?.code ? `${contract.code} — ` : ''}${row.name || ''}`.trim(),
            // Dua pola retensi (temuan #73): kontrak yang jadwalnya memuat
            // termin ber-flag retensi menagih retensinya LEWAT termin itu —
            // menyalakan potongan per-invoice di sini mencatat retensi dua
            // kali, dan server memang menolaknya.
            withhold_retention: !(contract?.termins || []).some((t) => t.is_retention),
          }),
        },
        columns: [
          { key: 'termin_no', label: '#', align: 'right' },
          { key: 'name', label: 'Termin' },
          { key: 'percent', label: 'Persen', type: 'percent', align: 'right' },
          { key: 'amount', label: 'Nilai', type: 'currency', align: 'right' },
          { key: 'billing_condition', label: 'Syarat' },
          { key: 'is_retention', label: 'Retensi', type: 'bool', align: 'center' },
          { key: 'due_date', label: 'Rencana tagih', type: 'date' },
          { key: 'billed_at', label: 'Ditagih', type: 'date' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [{
      key: 'activate', label: 'Aktifkan Kontrak', path: '{id}/activate', method: 'POST',
      perm: 'crm.approve', variant: 'success', when: (row) => row.status !== 'approved',
      confirm: 'Aktifkan kontrak ini? Termin akan siap ditagih.',
    }],
  },

  /* ---------------------------------------------------------------- P7
     Paket tender: berkas satu lelang. Bukan dokumen ber-persetujuan (lihat
     migrasi 000386), jadi tidak ada tombol Ajukan/Setujui di sini — yang
     ber-maker-checker adalah PENAWARAN-nya.

     Register dokumen lelang adalah `lines`, karena register memang daftar:
     judul, bab, tanggal terbit, dan addendum ke-n per baris. Urutan addendum
     TIDAK diperiksa di sini — server menolak register yang melompat dengan
     422 yang menyebut nomor yang bolong, dan sebuah tiruan aturannya di
     browser hanya akan punya kesempatan untuk berbeda. */
  'crm/tender-packages': {
    module: 'crm', api: 'crm/tender-packages',
    label: 'Paket Tender', labelOne: 'Paket Tender',
    columns: [
      codeColumn,
      { key: 'title', label: 'Paket pekerjaan', type: 'text', sub: 'owner_name' },
      { key: 'tender_number', label: 'No. lelang', type: 'text' },
      { key: 'aanwijzing_date', label: 'Aanwijzing', type: 'date' },
      // API mengurutkan batas pemasukan menaik: yang paling cepat jatuh tempo
      // di baris teratas, karena "mana yang harus dikejar" adalah pertanyaan
      // yang dijawab daftar ini.
      { key: 'submission_deadline', label: 'Batas pemasukan', type: 'date', withRelative: true },
      { key: 'last_addendum_no', label: 'Addendum ke', type: 'text', align: 'right', width: '1%' },
    ],
    filters: [
      { key: 'lead_id', label: 'Prospek', lookup: 'leads' },
    ],
    form: {
      sections: [{
        title: 'Paket tender',
        fields: [
          { key: 'lead_id', label: 'Prospek', type: 'lookup', lookup: 'leads', required: true },
          { key: 'title', label: 'Paket pekerjaan', type: 'text', required: true, span: 2 },
          { key: 'owner_name', label: 'Pemberi tugas / instansi', type: 'text' },
          { key: 'tender_number', label: 'Nomor pengumuman lelang', type: 'text' },
          { key: 'registered_at', label: 'Tanggal pendaftaran', type: 'date' },
          { key: 'submission_deadline', label: 'Batas pemasukan penawaran', type: 'date' },
          { key: 'aanwijzing_date', label: 'Tanggal aanwijzing', type: 'date' },
          {
            key: 'aanwijzing_notes', label: 'Catatan berita acara aanwijzing', type: 'textarea', span: 2,
            help: 'Aanwijzing lanjutan yang punya berita acara sendiri dicatat sebagai baris register di bawah.',
          },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'documents', label: 'Register dokumen lelang',
        columns: [
          { key: 'title', label: 'Judul dokumen', type: 'text', required: true, width: '38%' },
          { key: 'chapter', label: 'Bab / bagian', type: 'text', width: '20%' },
          { key: 'issued_date', label: 'Tanggal terbit', type: 'date', required: true, width: '18%' },
          {
            key: 'addendum_no', label: 'Addendum ke', type: 'number', width: '12%',
            help: 'Kosongkan untuk terbitan asli. Nomor harus berurutan dari 1.',
          },
          { key: 'notes', label: 'Catatan', type: 'text', width: '12%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'documents', label: 'Register dokumen lelang',
        columns: [
          { key: 'title', label: 'Judul dokumen' },
          { key: 'chapter', label: 'Bab / bagian' },
          { key: 'issued_date', label: 'Terbit', type: 'date' },
          { key: 'addendum_no', label: 'Addendum ke', align: 'right' },
          { key: 'notes', label: 'Catatan' },
        ],
      }],
    },
  },

  /* P7 — lembar hitung TKDN Jasa (Permenperin 35/2025 Pasal 14 & Lampiran IV
     huruf B). Tiap baris adalah satu BIAYA, ditandai baris penawaran yang
     ditanggungnya; kolom penentunya adalah tabel peraturannya, bukan sebuah
     kolom persen yang bisa diketik.

     Persentase paket TIDAK ditampilkan sendirian di mana pun: ia datang dari
     `summary` bersama cakupan penilaiannya, karena baris penawaran yang belum
     diuraikan biayanya BELUM DINILAI — bukan 0%, bukan 100%. */
  'crm/tkdn-worksheets': {
    module: 'crm', api: 'crm/tkdn-worksheets',
    label: 'Lembar TKDN', labelOne: 'Lembar TKDN',
    // Layar detail sendiri (views/tender.js): rincian biaya menguraikan BARIS
    // PENAWARAN LEMBAR INI, dan satu-satunya cara memilihnya dengan jujur
    // adalah daftar baris penawaran itu sendiri.
    customDetail: 'tkdn',
    columns: [
      codeColumn,
      { key: 'quotation.title', label: 'Penawaran', type: 'text', sub: 'quotation.code' },
      { key: 'summary.tkdn_pct', label: 'TKDN jasa (%)', type: 'text', align: 'right' },
      { key: 'summary.coverage_pct', label: 'Cakupan dinilai (%)', type: 'text', align: 'right' },
      // TIGA EMBER, bukan dua. Cakupan hanya menghitung baris yang uraian
      // biayanya benar-benar sepadan dengan nilai barisnya; tanpa kolom ini
      // daftar akan berbunyi "cakupan 0% · belum dinilai 5,6 M" atas lembar
      // yang sudah menguraikan 4,2 M — dan ketiganya berhenti menjumlah nilai
      // penawaran. Ember yang tidak punya kolom adalah ember yang hilang.
      { key: 'summary.partially_assessed_value', label: 'Baru sebagian', type: 'currency', align: 'right' },
      { key: 'summary.unassessed_value', label: 'Belum dinilai', type: 'currency', align: 'right' },
    ],
    filters: [
      { key: 'quotation_id', label: 'Penawaran', lookup: 'quotations' },
      { key: 'tender_package_id', label: 'Paket tender', lookup: 'tenderPackages' },
    ],
    form: {
      sections: [{
        title: 'Lembar TKDN',
        fields: [
          {
            key: 'quotation_id', label: 'Penawaran', type: 'lookup', lookup: 'quotations', required: true,
            help: 'Satu penawaran satu lembar. Rincian biaya menguraikan baris penawaran ini.',
          },
          { key: 'tender_package_id', label: 'Paket tender', type: 'lookup', lookup: 'tenderPackages' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      /* TANPA kisi `lines`, disengaja — dan inilah alasan layar detailnya
         khusus. Endpoint-nya memang menerima `items` sekaligus, tetapi kisi
         generik hanya bisa menawarkan KOTAK ANGKA untuk `quotation_item_id`:
         angka yang tidak dilihat siapa pun, pada lembar yang persennya dikutip
         di dokumen penawaran, berjarak satu salah ketik dari menguraikan biaya
         ke baris penawaran yang salah — dan hasilnya tetap terlihat wajar,
         karena persennya tetap terhitung. Pemilihnya ada di layar detail,
         menampilkan nomor dan uraian baris penawaran yang sebenarnya. */
      note: 'Rincian komponen biaya diisi di halaman detail lembar ini, lewat pemilih yang menampilkan baris penawarannya sendiri.',
    },
  },

  /* P7 — RKK penawaran (Permen PUPR 10/2021). Baris IBPRP dan baris biaya SMKK
     TIDAK disunting di sini: keduanya menautkan baris milik modul lain
     (prj_risk_register dan est_boq_items) lewat endpoint tersendiri, dan
     nilainya dibaca hidup dari sana — sebuah editor baris di layar ini akan
     mengundang salinan yang bisa membeku. Yang ditampilkan adalah hasil
     bacanya. */
  'crm/rkk-documents': {
    module: 'crm', api: 'crm/rkk-documents',
    label: 'RKK Penawaran', labelOne: 'RKK',
    // Layar detail sendiri (views/tender.js): dua pemilih yang menampilkan
    // baris register risiko dan baris RAB yang sebenarnya, lalu menuliskannya
    // lewat /ibprp-links dan /smkk-costs — bukan lewat rekaman RKK-nya.
    customDetail: 'rkk',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text', sub: 'tender_package.code' },
      { key: 'smkk_total', label: 'Biaya SMKK', type: 'currency', align: 'right' },
    ],
    filters: [
      { key: 'tender_package_id', label: 'Paket tender', lookup: 'tenderPackages' },
      { key: 'project_id', label: 'Proyek (sumber IBPRP)', lookup: 'projects' },
    ],
    form: {
      sections: [{
        title: 'RKK penawaran',
        fields: [
          { key: 'tender_package_id', label: 'Paket tender', type: 'lookup', lookup: 'tenderPackages', required: true },
          { key: 'title', label: 'Judul RKK', type: 'text', required: true, span: 2 },
          {
            key: 'project_id', label: 'Proyek sumber IBPRP', type: 'lookup', lookup: 'projects',
            help: 'Register risiko proyek inilah yang boleh ditaut sebagai baris IBPRP.',
          },
          {
            key: 'boq_id', label: 'BoQ / RAB', type: 'lookup', lookup: 'boqs',
            help: 'Biaya penerapan SMKK diambil dari baris RAB ini — tidak diketik ulang.',
          },
          { key: 'policy', label: 'Kebijakan keselamatan konstruksi', type: 'textarea', span: 2 },
          { key: 'program', label: 'Program & sasaran keselamatan konstruksi', type: 'textarea', span: 2 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      note: 'Baris IBPRP dan baris biaya SMKK dipilih di halaman detail RKK ini — keduanya menunjuk baris milik register risiko proyek dan RAB, dan nilainya dibaca dari sana.',
    },
  },

  'crm/guarantees': {
    module: 'crm', api: 'crm/guarantees',
    label: 'Jaminan & Asuransi', labelOne: 'Jaminan',
    columns: [
      { key: 'number', label: 'Nomor', type: 'text', sub: 'issuer' },
      { key: 'guarantee_type', label: 'Jenis', type: 'enum', enum: 'guaranteeType' },
      { key: 'value', label: 'Nilai', type: 'currency', align: 'right' },
      // API mengurutkan end_date ASC — jaminan yang paling cepat habis selalu di
      // baris teratas, jadi register menjawab "mana yang mati duluan" tanpa
      // tampilan khusus; pengingat 30-harinya ada di layar Tenggat.
      { key: 'end_date', label: 'Berakhir', type: 'date', withRelative: true },
      { key: 'status', label: 'Status', type: 'status', width: '1%' },
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'guaranteeStatus' },
      { key: 'guarantee_type', label: 'Jenis', enum: 'guaranteeType' },
      { key: 'contract_id', label: 'Kontrak', lookup: 'contracts' },
    ],
    // Nomor jaminan unik per penerbit TERMASUK baris terhapus (index DB ikut
    // menghitungnya) — hapus hanya untuk salah input, bukan untuk jaminan usai.
    deleteConfirm: 'Hapus jaminan ini dari register? Nomornya tetap terkunci per penerbit sampai baris dipulihkan — untuk jaminan yang sudah kembali, ubah status ke "Dikembalikan", jangan dihapus.',
    form: {
      sections: [{
        title: 'Jaminan / polis',
        fields: [
          { key: 'guarantee_type', label: 'Jenis', type: 'select', enum: 'guaranteeType', required: true },
          { key: 'number', label: 'Nomor (dari penerbit)', type: 'text', required: true },
          { key: 'issuer', label: 'Penerbit', type: 'text', required: true },
          { key: 'value', label: 'Nilai', type: 'currency', required: true },
          {
            key: 'contract_id', label: 'Kontrak', type: 'lookup', lookup: 'contracts',
            help: 'Isi kontrak ATAU penawaran — jaminan penawaran terbit sebelum kontrak ada.',
          },
          { key: 'quotation_id', label: 'Penawaran', type: 'lookup', lookup: 'quotations' },
          { key: 'start_date', label: 'Mulai berlaku', type: 'date', required: true },
          { key: 'end_date', label: 'Berakhir', type: 'date', required: true },
          { key: 'status', label: 'Status', type: 'select', enum: 'guaranteeStatus', default: 'active' },
          {
            key: 'document_location', label: 'Lokasi dokumen fisik', type: 'text',
            help: 'Klaim pencairan butuh dokumen asli — catat di mana fisiknya disimpan.',
          },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  /* ======================================================= ESTIMATION === */
  'estimation/ahsp': {
    module: 'est', api: 'estimation/ahsp', label: 'AHSP', labelOne: 'Analisa Harga Satuan',
    lookupSource: 'ahsp',
    columns: [
      codeColumn,
      { key: 'name', label: 'Uraian analisa', type: 'text' },
      { key: 'category', label: 'Kategori', type: 'enum', enum: 'ahspCategory' },
      { key: 'unit', label: 'Satuan', type: 'text', align: 'center' },
      { key: 'overhead_pct', label: 'Overhead', type: 'percent', align: 'right' },
      { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
    ],
    filters: [{ key: 'category', label: 'Kategori', enum: 'ahspCategory' }],
    form: {
      sections: [{
        title: 'Analisa',
        fields: [
          { key: 'code', label: 'Kode AHSP', type: 'text', required: true, help: 'mis. A.2.3.1.1' },
          { key: 'unit', label: 'Satuan', type: 'text', required: true },
          { key: 'name', label: 'Uraian pekerjaan', type: 'text', required: true, span: 2 },
          { key: 'category', label: 'Kategori', type: 'select', enum: 'ahspCategory', required: true },
          { key: 'overhead_pct', label: 'Overhead & profit (%)', type: 'percent', default: 10 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'components', label: 'Komponen (upah / bahan / alat)',
        columns: [
          { key: 'component_type', label: 'Jenis', type: 'select', enum: 'componentType', required: true, width: '14%' },
          { key: 'name', label: 'Uraian', type: 'text', required: true, width: '30%' },
          { key: 'item_id', label: 'Item stok', type: 'lookup', lookup: 'items', width: '20%' },
          { key: 'unit', label: 'Satuan', type: 'text', required: true, width: '10%' },
          { key: 'coefficient', label: 'Koefisien', type: 'qty', required: true, width: '12%' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', required: true, width: '14%' },
        ],
        total: (row) => Number(row.coefficient || 0) * Number(row.unit_price || 0),
      }],
    },
    detail: {
      tables: [{
        key: 'components', label: 'Komponen',
        columns: [
          { key: 'component_type', label: 'Jenis', type: 'enum', enum: 'componentType' },
          { key: 'name', label: 'Uraian' },
          { key: 'unit', label: 'Satuan' },
          { key: 'coefficient', label: 'Koefisien', type: 'qty', align: 'right' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
  },

  'estimation/boqs': {
    module: 'est', api: 'estimation/boqs', label: 'BOQ / RAB', labelOne: 'BOQ',
    lookupSource: 'boqs',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text' },
      { key: 'version', label: 'Versi', type: 'number', align: 'center', prefix: 'v' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'total', label: 'Total', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'BOQ / RAB',
        fields: [
          { key: 'title', label: 'Judul', type: 'text', required: true, span: 2 },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'contract_id', label: 'Kontrak', type: 'lookup', lookup: 'contracts' },
          { key: 'quotation_id', label: 'Penawaran', type: 'lookup', lookup: 'quotations' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      note: 'Bagian (section) dan item BOQ dikelola dari halaman detail setelah BOQ dibuat.',
    },
    detail: {
      tables: [{
        // The endpoint behind this has existed since the module was written and
        // nothing ever called it. Flat, in BOQ order — the shape somebody copies
        // into a spreadsheet, which the grouped view below cannot give them.
        key: 'flat_items', label: 'Seluruh item (datar)', endpoint: '{id}/items',
        columns: [
          { key: 'wbs_code', label: 'Kode', type: 'code' },
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Volume', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
      }, {
        key: 'sections', label: 'Bagian & item pekerjaan', nested: 'items',
        columns: [
          { key: 'wbs_code', label: 'Kode', type: 'code' },
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Volume', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [
      ...approvalActions('est'),
      {
        key: 'new-version', label: 'Versi Baru', path: '{id}/new-version', method: 'POST',
        perm: 'est.create', when: (row) => ['approved', 'rejected'].includes(row.status),
        confirm: 'Buat versi baru dari BOQ ini? Versi baru dimulai sebagai draf.',
        navigateToResult: true,
      },
    ],
  },

  'estimation/cost-budgets': {
    module: 'est', api: 'estimation/cost-budgets', label: 'RAP (Anggaran Biaya)', labelOne: 'RAP',
    columns: [
      codeColumn,
      { key: 'boq_code', label: 'Dari BOQ', type: 'code' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'target_margin_pct', label: 'Target margin', type: 'percent', align: 'right' },
      { key: 'total_budget', label: 'Total anggaran', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'RAP',
        fields: [
          { key: 'boq_id', label: 'BOQ sumber', type: 'lookup', lookup: 'boqs', required: true },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'target_margin_pct', label: 'Target margin (%)', type: 'percent', required: true, default: 15 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Rincian anggaran',
        columns: [
          { key: 'cost_category', label: 'Kategori', type: 'enum', enum: 'costCategory' },
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Volume', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'unit_cost', label: 'Biaya satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [
      {
        key: 'generate-from-boq', label: 'Buat dari BOQ', path: '{id}/generate-from-boq', method: 'POST',
        perm: 'est.update', when: DRAFT_OR_REJECTED, variant: 'primary',
        fields: [{ key: 'target_margin_pct', label: 'Target margin (%)', type: 'percent', help: 'Kosongkan untuk memakai margin yang tersimpan.' }],
      },
      ...approvalActions('est'),
    ],
  },

  /* ========================================================= PROJECTS === */
  'projects': {
    module: 'prj', api: 'projects', label: 'Proyek', labelOne: 'Proyek',
    lookupSource: 'projects', customDetail: 'project',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama proyek', type: 'text', sub: 'city' },
      { key: 'type', label: 'Jenis', type: 'enum', enum: 'projectType', hideOnNarrow: true },
      { key: 'contract_value', label: 'Nilai kontrak', type: 'currency', align: 'right' },
      { key: 'actual_progress_pct', label: 'Progres', type: 'progress', width: '150px', hideOnNarrow: true },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'projectStatus' },
      { key: 'type', label: 'Jenis', enum: 'projectType' },
      { key: 'customer_id', label: 'Pelanggan', lookup: 'customers' },
      // Temuan #80: ProjectController sudah menghormati mine (tri-state —
      // kosong: semua; Ya: proyek saya via users.employee_id →
      // project_manager_id; Tidak: milik orang lain). Tanpa filter
      // terdeklarasi ini list.js tidak pernah mengirimkannya, dan separuh
      // temuan itu hanya hidup di dasbor.
      { key: 'mine', label: 'Proyek saya', type: 'boolFilter' },
    ],
    form: {
      sections: [
        {
          title: 'Proyek',
          fields: [
            { key: 'contract_id', label: 'Dari kontrak', type: 'lookup', lookup: 'contracts', help: 'Mengisi kontrak akan menyalin nilai, tanggal, retensi & garansi.' },
            { key: 'customer_id', label: 'Pelanggan', type: 'lookup', lookup: 'customers' },
            { key: 'name', label: 'Nama proyek', type: 'text', span: 2 },
            { key: 'type', label: 'Jenis proyek', type: 'select', enum: 'projectType' },
            { key: 'boq_id', label: 'BOQ', type: 'lookup', lookup: 'boqs' },
            { key: 'contract_value', label: 'Nilai kontrak', type: 'currency' },
            { key: 'retention_pct', label: 'Retensi (%)', type: 'percent', default: 5 },
            { key: 'warranty_months', label: 'Masa pemeliharaan (bulan)', type: 'number', default: 12 },
            { key: 'status', label: 'Status', type: 'select', enum: 'projectStatusEditable', editOnly: true, help: 'Menutup proyek lewat tombol "Tutup proyek" di halaman proyek — bukan dari sini.' },
          ],
        },
        {
          title: 'Lokasi & jadwal',
          fields: [
            { key: 'location', label: 'Lokasi', type: 'text', span: 2 },
            { key: 'city', label: 'Kota', type: 'text' },
            { key: 'province', label: 'Provinsi', type: 'text' },
            { key: 'latitude', label: 'Lintang', type: 'number', step: 'any' },
            { key: 'longitude', label: 'Bujur', type: 'number', step: 'any' },
            { key: 'start_date', label: 'Rencana mulai', type: 'date' },
            { key: 'end_date', label: 'Rencana selesai', type: 'date' },
            { key: 'actual_start_date', label: 'Aktual mulai', type: 'date' },
            { key: 'actual_end_date', label: 'Aktual selesai', type: 'date', editOnly: true },
          ],
        },
        {
          title: 'Tim',
          fields: [
            { key: 'project_manager_id', label: 'Project manager', type: 'lookup', lookup: 'employees' },
            { key: 'site_manager_id', label: 'Site manager', type: 'lookup', lookup: 'employees' },
            // Kotak KONSULTAN MK pada kop formulir rumah: tanpa field ini
            // kotaknya tidak pernah bisa terisi dari aplikasi. Kosong = kotak
            // kosong di kertas, sama seperti formulir aslinya.
            { key: 'consultant_name', label: 'Konsultan MK / pengawas', type: 'text' },
            { key: 'consultant_role', label: 'Sebutan konsultan', type: 'text', help: 'Judul kotak pada kop — mis. Konsultan MK, Konsultan Pengawas. Kosong = "KONSULTAN MK".' },
          ],
        },
      ],
    },
  },

  'projects/daily-reports': {
    module: 'prj', api: 'projects/daily-reports', label: 'Laporan Harian', labelOne: 'Laporan Harian',
    // Formulir rumah (Form F/LH) — lembar HTML siap cetak, bukan PDF dompdf:
    // kop empat pihak, blok identitas, dan kolom-kolom yang diisi tangan di
    // lapangan tetap bergaris seperti aslinya.
    printForms: [
      { form: 'laporan-harian', label: 'Laporan Harian', params: { tanggal: 'report_date' } },
    ],
    columns: [
      codeColumn,
      { key: 'report_date', label: 'Tanggal', type: 'date' },
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'manpower_count', label: 'Tenaga kerja', type: 'number', align: 'right', suffix: ' org' },
      { key: 'weather_am', label: 'Cuaca pagi', type: 'enum', enum: 'weather' },
      { key: 'weather_pm', label: 'Cuaca siang', type: 'enum', enum: 'weather' },
      { key: 'activities', label: 'Kegiatan', type: 'text', truncate: 70 },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'date_from', label: 'Dari', type: 'date' },
      { key: 'date_to', label: 'Sampai', type: 'date' },
    ],
    /*
     * P0-A: laporan yang locked_at-nya terisi (BAST I proyek disetujui)
     * MEMBEKU — tombol Ubah/Hapus disembunyikan di daftar maupun detail.
     * Server tetap menolak dengan 422 yang menyebut BAST pengunci dan tanggal
     * serah terimanya; sembunyi di sini hanya supaya penolakan itu tidak
     * menjadi kalimat pertama yang dibaca pengawas.
     */
    editableWhen: (row) => !row.locked,
    deletableWhen: (row) => !row.locked,
    form: {
      sections: [{
        title: 'Laporan harian',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'report_date', label: 'Tanggal laporan', type: 'date', required: true, defaultToday: true },
          { key: 'weather_am', label: 'Cuaca pagi', type: 'select', enum: 'weather' },
          { key: 'weather_pm', label: 'Cuaca siang', type: 'select', enum: 'weather' },
          // Kolom WAKTU pada kop FM-10-12 — 'HH:MM'; server menolak
          // work_end ≤ work_start dengan menyebut kedua jamnya.
          { key: 'work_start', label: 'Jam mulai kerja', type: 'time' },
          { key: 'work_end', label: 'Jam selesai kerja', type: 'time' },
          {
            key: 'lost_hours_reason', label: 'Alasan jam kerja hilang', type: 'text', span: 2,
            help: 'Hujan, tunggu material, listrik padam — alasan jam efektif lebih pendek dari jam kerja.',
          },
          {
            key: 'manpower_count', label: 'Jumlah tenaga kerja', type: 'number',
            // P0-A: begitu tabel per jabatan diisi, angka ini TURUNAN dari
            // jumlah headcount — klaim manual yang berbeda ditolak server
            // dengan 422 yang menyebut kedua angkanya. Kotak ini tinggal
            // untuk laporan lama tanpa rincian (kompat maju): hanya di sana
            // angka manual masih sah. hideWhen menahannya ikut terkirim basi
            // setiap kali rincian jabatan diedit.
            help: 'Terhitung otomatis begitu tabel "Tenaga kerja per jabatan" diisi — kosongkan saja. '
              + 'Isi manual hanya untuk laporan tanpa rincian jabatan.',
            hideWhen: (record) => Array.isArray(record && record.manpower) && record.manpower.length > 0,
          },
          { key: 'activities', label: 'Kegiatan hari ini', type: 'textarea', required: true, span: 2 },
          { key: 'obstacles', label: 'Kendala', type: 'textarea', span: 2 },
          {
            key: 'safety_notes', label: 'Catatan K3', type: 'textarea', span: 2,
            // The seeded near-miss lived here, in prose, with no severity, no
            // cause and nobody accountable. Kejadian belongs in the register.
            help: 'Catatan pengamatan harian. Kejadian kecelakaan atau near miss dicatat di Register K3 (SMK3), bukan di sini.',
          },
        ],
      }],
      /*
       * Empat tabel baris FM-10-12 (P0-A) + pemakaian material yang sudah ada.
       * Sel formulir rumah yang dulu bergaris kosong kini punya sumber datanya
       * di sini; baris kosong = tetap bergaris kosong di kertas.
       */
      lines: [
        {
          key: 'manpower', label: 'Tenaga kerja per jabatan',
          help: 'Tabel JUMLAH ORANG pada FM-10-12. Total tenaga kerja dihitung otomatis dari headcount — '
            + 'jabatan yang kosong tetap bergaris kosong di cetakan.',
          columns: [
            { key: 'role_key', label: 'Jabatan', type: 'select', enum: 'dailyReportRole', required: true, width: '34%' },
            { key: 'headcount', label: 'Jumlah orang', type: 'number', min: 0, required: true, width: '18%' },
            { key: 'notes', label: 'Keterangan', type: 'text', width: '44%' },
          ],
        },
        {
          key: 'activity_lines', label: 'Uraian pekerjaan',
          help: 'Kolom URAIAN PEKERJAAN / PROGRESS / TARGET / HAMBATAN pada FM-10-12 — satu baris per pekerjaan.',
          columns: [
            { key: 'wbs_task_id', label: 'Paket WBS', type: 'lookup', lookup: 'wbsTasks', width: '22%' },
            { key: 'description', label: 'Uraian pekerjaan', type: 'text', required: true, width: '30%' },
            { key: 'progress_note', label: 'Progress', type: 'text', width: '14%' },
            { key: 'target_note', label: 'Target', type: 'text', width: '14%' },
            { key: 'obstacle', label: 'Hambatan', type: 'text', width: '16%' },
          ],
        },
        {
          key: 'receipts', label: 'Material masuk',
          help: 'Tabel MATERIAL MASUK (diterima/ditolak) pada FM-10-12 — kedatangan di lapangan hari ini, '
            + 'BUKAN pemakaian. Pakai "Impor dari GRN" supaya baris tertaut ke penerimaan gudang site; '
            + 'baris ketik tangan sah untuk kedatangan tanpa GRN.',
          columns: [
            // Tertaut lewat "Impor dari GRN", tidak pernah diketik.
            { key: 'goods_receipt_id', type: 'hidden' },
            { key: 'item_id', type: 'hidden' },
            { key: 'description', label: 'Material', type: 'text', required: true, width: '30%' },
            { key: 'qty_received', label: 'Diterima', type: 'qty', required: true, width: '13%' },
            { key: 'qty_rejected', label: 'Ditolak', type: 'qty', width: '13%' },
            { key: 'unit', label: 'Satuan', type: 'text', required: true, width: '12%' },
            { key: 'rejection_reason', label: 'Alasan ditolak', type: 'text', width: '28%' },
          ],
          importPick: {
            label: 'Impor dari GRN',
            title: 'Impor dari penerimaan gudang site (GRN)',
            requiresRecord: 'Simpan laporan ini dulu, lalu buka Ubah — kandidat GRN dibaca dari proyek dan tanggal laporan yang tersimpan.',
            empty: 'Tidak ada GRN terposting di gudang site proyek ini pada tanggal laporan.',
            hint: 'GRN terposting di gudang site proyek pada tanggal laporan tersimpan. Centang baris yang '
              + 'benar-benar tiba di lapangan; baris bertanda "Sudah diimpor" akan menjadi baris ganda bila dicentang lagi.',
            load: (record, api) => api.get(`projects/daily-reports/${record.id}/receipts-candidates`),
            columns: [
              { key: 'grn_code', label: 'GRN', sub: 'delivery_note_no' },
              { key: 'description', label: 'Material', sub: 'item_code' },
              { key: 'qty_received', label: 'Qty', type: 'qty', align: 'right' },
              { key: 'unit', label: 'Satuan' },
              { key: 'vendor_name', label: 'Vendor' },
            ],
            note: (row) => (row.already_imported ? 'Sudah diimpor' : null),
            // Bentuk persis baris receipts[] — konteks tampilan tidak ikut.
            map: (row) => ({
              goods_receipt_id: row.goods_receipt_id,
              item_id: row.item_id,
              description: row.description,
              qty_received: row.qty_received,
              qty_rejected: row.qty_rejected ?? 0,
              unit: row.unit,
            }),
          },
        },
        {
          key: 'materials', label: 'Pemakaian material',
          help: 'Material yang DIPAKAI hari ini — berbeda dari Material masuk di atas.',
          columns: [
            { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '52%' },
            { key: 'qty_used', label: 'Qty dipakai', type: 'qty', required: true, width: '24%' },
            { key: 'unit', label: 'Satuan', type: 'text', required: true, width: '24%' },
          ],
        },
        {
          key: 'equipment', label: 'Alat-alat',
          help: 'Tabel ALAT-ALAT pada FM-10-12. Pilih aset untuk alat milik perusahaan; alat sewa cukup uraiannya.',
          columns: [
            { key: 'asset_id', label: 'Aset', type: 'lookup', lookup: 'assets', width: '28%' },
            { key: 'description', label: 'Uraian alat', type: 'text', required: true, width: '34%' },
            { key: 'qty', label: 'Jumlah', type: 'number', min: 1, required: true, width: '14%' },
            { key: 'hours', label: 'Jam operasi', type: 'number', step: '0.5', min: 0, max: 24, width: '18%' },
          ],
        },
      ],
    },
    detail: {
      tables: [
        {
          key: 'manpower', label: 'Tenaga kerja per jabatan',
          columns: [
            { key: 'role_label', label: 'Jabatan' },
            { key: 'headcount', label: 'Jumlah', type: 'number', align: 'right', suffix: ' org' },
            { key: 'notes', label: 'Keterangan' },
          ],
        },
        {
          key: 'activity_lines', label: 'Uraian pekerjaan',
          columns: [
            { key: 'wbs_task_id', label: 'Paket WBS', type: 'rel', lookup: 'wbsTasks' },
            { key: 'description', label: 'Uraian pekerjaan' },
            { key: 'progress_note', label: 'Progress' },
            { key: 'target_note', label: 'Target' },
            { key: 'obstacle', label: 'Hambatan' },
          ],
        },
        {
          key: 'receipts', label: 'Material masuk',
          columns: [
            { key: 'goods_receipt_id', label: 'GRN', type: 'rel', lookup: 'goodsReceipts' },
            { key: 'description', label: 'Material' },
            { key: 'qty_received', label: 'Diterima', type: 'qty', align: 'right' },
            { key: 'qty_rejected', label: 'Ditolak', type: 'qty', align: 'right' },
            { key: 'unit', label: 'Satuan' },
            { key: 'rejection_reason', label: 'Alasan ditolak' },
          ],
        },
        {
          key: 'materials', label: 'Pemakaian material',
          columns: [
            { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
            { key: 'qty_used', label: 'Qty', type: 'qty', align: 'right' },
            { key: 'unit', label: 'Satuan' },
          ],
        },
        {
          key: 'equipment', label: 'Alat-alat',
          columns: [
            { key: 'asset_id', label: 'Aset', type: 'rel', lookup: 'assets' },
            { key: 'description', label: 'Uraian alat' },
            { key: 'qty', label: 'Jumlah', type: 'number', align: 'right' },
            { key: 'hours', label: 'Jam operasi', type: 'number', decimals: 2, align: 'right' },
          ],
        },
      ],
    },
  },

  /*
   * P0-C — tiga izin lapangan menjadi transaksi. Form F/IK, F/IL, F/IM
   * berhenti dicetak sebagai pad kosong: satu baris = satu izin, dan tombol
   * cetak di sini berjangkar pada id IZINNYA (printForms tanpa idField =
   * id baris), bukan lagi id proyek seperti kartu blank-pad project.js lama.
   */
  'projects/work-permits': {
    module: 'prj', api: 'projects/work-permits', label: 'Izin Kerja (IKL)', labelOne: 'Izin Kerja Lapangan',
    revisable: true, // P8 (D9): banner "digantikan" pada detail generik
    printForms: [
      { form: 'izin-kerja', label: 'Izin Kerja Lapangan' },
    ],
    columns: [
      codeColumn,
      { key: 'permit_date', label: 'Tanggal', type: 'date' },
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'shift', label: 'Shift', type: 'enum', enum: 'workShift' },
      { key: 'work_description', label: 'Pekerjaan', type: 'text', truncate: 60 },
      { key: 'requested_by_name', label: 'Pemohon', type: 'text', hideOnNarrow: true },
      revisionColumn,
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'shift', label: 'Shift', enum: 'workShift' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: (row) => DRAFT_OR_REJECTED(row) && IS_LIVE_REVISION(row),
    deletableWhen: (row) => DRAFT_OR_REJECTED(row) && IS_LIVE_REVISION(row),
    form: {
      sections: [{
        title: 'Izin Kerja Lapangan',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'permit_date', label: 'Tanggal izin', type: 'date', required: true, help: 'Harus di dalam waktu pelaksanaan proyek.' },
          { key: 'shift', label: 'Shift', type: 'select', enum: 'workShift', required: true },
          { key: 'wbs_task_id', label: 'Paket WBS', type: 'lookup', lookup: 'wbsTasks' },
          { key: 'valid_from', label: 'Berlaku mulai', type: 'datetime', required: true },
          { key: 'valid_until', label: 'Berlaku sampai', type: 'datetime', required: true },
          { key: 'work_description', label: 'Pekerjaan yang dimohonkan', type: 'textarea', required: true, span: 2 },
          { key: 'hazard_notes', label: 'Potensi bahaya', type: 'textarea', span: 2, help: 'Satu potensi bahaya per baris — tercetak per baris pada tabel APD.' },
          { key: 'ppe_required', label: 'APD wajib', type: 'tags', span: 2, help: 'Satu APD per baris (helm, harness, sepatu safety, …).' },
          { key: 'requested_by', label: 'Pemohon (pelaksana/mandor)', type: 'lookup', lookup: 'employees', required: true },
          { key: 'safety_officer_id', label: 'Petugas K3', type: 'lookup', lookup: 'employees' },
        ],
      }],
    },
    actions: revisableActions('prj', approvalActions('prj')),
  },

  'projects/overtime-permits': {
    module: 'prj', api: 'projects/overtime-permits', label: 'Izin Lembur (ILB)', labelOne: 'Izin Kerja Lembur',
    printForms: [
      { form: 'izin-lembur', label: 'Izin Kerja Lembur' },
    ],
    columns: [
      codeColumn,
      { key: 'overtime_date', label: 'Tanggal', type: 'date' },
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'start_time', label: 'Mulai', type: 'text', align: 'center' },
      { key: 'end_time', label: 'Selesai', type: 'text', align: 'center' },
      { key: 'total_hours', label: 'Total jam', type: 'number', decimals: 2, align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Izin Kerja Lembur',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'overtime_date', label: 'Tanggal lembur', type: 'date', required: true },
          {
            key: 'start_time', label: 'Jam mulai', type: 'time', required: true,
          },
          {
            key: 'end_time', label: 'Jam selesai', type: 'time', required: true,
            help: 'Lembur melewati tengah malam ditulis dengan jam selesai lebih kecil (mis. 22:00 s/d 02:00).',
          },
          { key: 'reason', label: 'Alasan lembur', type: 'textarea', required: true, span: 2 },
        ],
      }],
      /* Satu baris per orang, karena lembarnya ditandatangani per orang dan
         jam per KARYAWAN inilah yang diumpankan ke rekap payroll saat izin
         disetujui. Kru mandor non-karyawan diketik namanya — tetap tercetak,
         tidak pernah menyentuh rekap. */
      lines: [{
        key: 'workers', label: 'Daftar pekerja lembur',
        help: 'Pilih karyawan ATAU ketik nama (kru non-karyawan) — tepat satu per baris. Jam per baris > 0.',
        columns: [
          { key: 'employee_id', label: 'Karyawan', type: 'lookup', lookup: 'employees', width: '34%' },
          { key: 'worker_name', label: 'Nama non-karyawan', type: 'text', width: '34%' },
          { key: 'hours', label: 'Jam', type: 'number', step: '0.5', min: 0, max: 24, required: true, width: '16%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'workers', label: 'Daftar pekerja lembur',
        columns: [
          { key: 'display_name', label: 'Nama' },
          { key: 'hours', label: 'Jam', type: 'number', decimals: 2, align: 'right' },
        ],
      }],
    },
    actions: [...approvalActions('prj')],
  },

  'projects/gate-passes': {
    module: 'prj', api: 'projects/gate-passes', label: 'Izin Material (IMK)', labelOne: 'Izin Masuk/Keluar Material',
    printForms: [
      { form: 'izin-material', label: 'Izin Masuk / Keluar Material & Peralatan' },
    ],
    columns: [
      codeColumn,
      { key: 'pass_date', label: 'Tanggal', type: 'date' },
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'direction', label: 'Arah', type: 'enum', enum: 'gatePassDirection' },
      { key: 'vehicle_no', label: 'No. polisi', type: 'text', hideOnNarrow: true },
      { key: 'checked_at', label: 'Diperiksa', type: 'date', hideOnNarrow: true },
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'direction', label: 'Arah', enum: 'gatePassDirection' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Izin Masuk / Keluar Material & Peralatan',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'direction', label: 'Arah barang', type: 'select', enum: 'gatePassDirection', required: true },
          { key: 'pass_date', label: 'Tanggal', type: 'date', required: true },
          { key: 'vehicle_no', label: 'No. polisi kendaraan', type: 'text' },
          { key: 'driver_name', label: 'Nama pengemudi', type: 'text' },
          { key: 'vendor_id', label: 'Vendor (bila terdaftar)', type: 'lookup', lookup: 'vendors' },
          { key: 'counterparty', label: 'Asal/tujuan (teks bebas)', type: 'text', help: 'Untuk pihak yang bukan vendor terdaftar.' },
        ],
      }],
      lines: [{
        key: 'items', label: 'Rincian material / peralatan',
        columns: [
          { key: 'item_id', label: 'Item stok', type: 'lookup', lookup: 'items', width: '24%' },
          { key: 'description', label: 'Jenis barang', type: 'text', required: true, width: '30%' },
          { key: 'qty', label: 'Jumlah', type: 'qty', required: true, width: '12%' },
          { key: 'unit', label: 'Satuan', type: 'text', required: true, width: '12%' },
          { key: 'notes', label: 'Keterangan', type: 'text', width: '20%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Rincian material / peralatan',
        columns: [
          { key: 'item_id', label: 'Item stok', type: 'rel', lookup: 'items' },
          { key: 'description', label: 'Jenis barang' },
          { key: 'qty', label: 'Jumlah', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'notes', label: 'Keterangan' },
        ],
      }],
    },
    /* Urutan yang ditegakkan server: manajemen menyetujui dulu (prj.approve),
       baru satpam memeriksa muatan — 'periksa' hanya muncul pada izin
       approved yang belum dicap, dan capnya sekali saja. */
    actions: [
      ...approvalActions('prj'),
      {
        key: 'periksa', label: 'Periksa di gerbang', path: '{id}/periksa', method: 'POST',
        perm: 'prj.update', variant: 'primary',
        when: (row) => row.status === 'approved' && !row.checked_at,
      },
    ],
  },

  /* P3 — OPNAME KE PEMILIK (OPN). Volume terukur per item BOQ per periode, cermin
     opname subkon di sisi pendapatan. Plafonnya (volume kontrak + CCO disetujui)
     ditegakkan MeasurementService, bukan di sini: layar ini mengirim qty_this dan
     server yang menghitung qty_prev/qty_cum dari riwayat yang sudah disetujui. */
  'projects/progress-measurements': {
    module: 'prj', api: 'projects/progress-measurements', label: 'Opname Owner (OPN)', labelOne: 'Opname ke Pemilik',
    columns: [
      codeColumn,
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'measurement_no', label: 'Opname ke-', type: 'number', align: 'center' },
      { key: 'period_end', label: 'Periode s/d', type: 'date' },
      { key: 'period_amount', label: 'Nilai periode', type: 'currency', align: 'right' },
      { key: 'cumulative_amount', label: 'Nilai kumulatif', type: 'currency', align: 'right', hideOnNarrow: true },
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Opname ke Pemilik',
        help: 'Isi volume yang diukur PERIODE INI per item BOQ. Volume lalu dan kumulatif dihitung server '
          + 'dari opname yang sudah disetujui, dan ditolak bila kumulatifnya melampaui volume kontrak + CCO.',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'period_start', label: 'Periode mulai', type: 'date', required: true },
          { key: 'period_end', label: 'Periode selesai', type: 'date', required: true },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Volume terukur per item BOQ', min: 1,
        // ID item BOQ mentah, pola yang sama dengan baris opname subkon
        // (subcontract_item_id): tidak ada endpoint daftar item BOQ yang datar
        // untuk dijadikan sumber pilih, dan menebak satu di sini akan mengukur
        // volume ke item yang salah. Server menolak id dari BOQ kontrak lain
        // dengan 422 berbahasa Indonesia.
        help: 'ID item BOQ dibaca dari halaman BOQ/RAB kontrak ini. Satu baris per item — '
          + 'server menolak item yang tercantum dua kali dan item dari BOQ kontrak lain. '
          + 'Lokasi/zona boleh dikosongkan, tetapi bila diisi harus lokasi proyek ini: '
          + 'zona proyek lain ditolak (baris seperti itu luput selamanya dari gerbang BAPP).',
        columns: [
          { key: 'boq_item_id', label: 'ID item BOQ', type: 'number', required: true, width: '20%' },
          { key: 'location_id', label: 'Lokasi/zona', type: 'lookup', lookup: 'locations', width: '30%' },
          { key: 'qty_this', label: 'Volume periode ini', type: 'qty', required: true, width: '20%' },
          { key: 'notes', label: 'Keterangan', type: 'text', width: '30%' },
        ],
      }],
    },
    detail: {
      summary: ['period_amount', 'cumulative_amount'],
      tables: [{
        key: 'items', label: 'Backsheet opname',
        columns: [
          { key: 'description', label: 'Uraian pekerjaan' },
          { key: 'location_path', label: 'Lokasi' },
          { key: 'unit', label: 'Satuan' },
          { key: 'qty_prev', label: 'Volume lalu', type: 'qty', align: 'right' },
          { key: 'qty_this', label: 'Periode ini', type: 'qty', align: 'right' },
          { key: 'qty_cum', label: 'Kumulatif', type: 'qty', align: 'right' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Nilai periode', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: approvalActions('prj'),
  },

  /* P3 — BAPP per zona. Bukan Approvable: statusnya catatan pemeriksa, bukan
     tahapan persetujuan. Menandai "Selesai" digerbangi NCR terbuka di zona itu
     (422 menyebut nomor NCR-nya), dan "Nunggu perbaikan" memblokir klaim owner. */
  'projects/zone-certificates': {
    module: 'prj', api: 'projects/zone-certificates', label: 'BAPP per Zona', labelOne: 'Berita Acara Pemeriksaan Pekerjaan',
    columns: [
      codeColumn,
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'location_path', label: 'Zona', type: 'text' },
      { key: 'status', label: 'Status', type: 'enum', enum: 'zoneCertificateStatus' },
      { key: 'certified_at', label: 'Tanggal', type: 'date' },
      { key: 'certified_by_party_label', label: 'Diperiksa oleh', type: 'text', hideOnNarrow: true },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'location_id', label: 'Lokasi', lookup: 'locations' },
      { key: 'status', label: 'Status', enum: 'zoneCertificateStatus' },
    ],
    form: {
      sections: [{
        title: 'Berita Acara Pemeriksaan Pekerjaan (BAPP)',
        help: 'Satu lembar per pemeriksaan. Zona yang diperiksa ulang mendapat lembar baru — '
          + 'lembar terakhir yang menentukan status zona.',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'location_id', label: 'Zona/lokasi', type: 'lookup', lookup: 'locations', required: true },
          { key: 'status', label: 'Hasil pemeriksaan', type: 'select', enum: 'zoneCertificateStatus', required: true },
          { key: 'certified_at', label: 'Tanggal pemeriksaan', type: 'date' },
          { key: 'certified_by_party', label: 'Pihak pemeriksa', type: 'select', enum: 'certifyingParty' },
          { key: 'certified_by_name', label: 'Nama pemeriksa', type: 'text', help: 'Diisi dari lembar yang benar-benar ditandatangani — jangan disalin dari master proyek.' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  /* P3 — REGISTER VARIASI KONTRAK. Volume tambah-kurang per item BOQ: separuh
     plafon opname yang tidak bisa diungkapkan CCO, karena CCO mencatat NILAI
     yang ditandatangani dan tidak membawa baris sama sekali.

     Register kecil, bukan dokumen: siklus hidup yang menentukan adalah siklus
     CCO-nya, dan MeasurementService hanya menghitung baris yang CCO-nya sudah
     DISETUJUI. Kolom status CCO tampil di daftar supaya QS melihat mengapa
     sebuah volume belum menaikkan plafon.

     Layar ini adalah tujuan kalimat kedua pesan 422 opname ("…atau catat dahulu
     volume CCO-nya pada register variasi kontrak"); tanpa entri di sini pesan
     itu menunjuk ke tempat yang tidak ada. */
  'projects/contract-variations': {
    module: 'prj', api: 'projects/contract-variations', label: 'Variasi Kontrak (Plafon Opname)', labelOne: 'Volume Tambah-Kurang',
    noDetail: true,
    columns: [
      { key: 'change_order_code', label: 'CCO', type: 'code' },
      { key: 'change_order_status', label: 'Status CCO', type: 'enum', enum: 'documentStatus' },
      { key: 'boq_wbs_code', label: 'WBS', type: 'text' },
      { key: 'boq_description', label: 'Item BOQ', type: 'text', truncate: 60 },
      // Bertanda: pekerjaan kurang MENURUNKAN plafon, dan tanda minusnya
      // adalah satu-satunya cara jujur menampilkannya.
      { key: 'qty_change', label: 'Perubahan volume', type: 'qty', align: 'right' },
      { key: 'unit', label: 'Satuan', type: 'text', align: 'center' },
    ],
    filters: [
      { key: 'contract_id', label: 'Kontrak', lookup: 'contracts' },
      { key: 'change_order_id', label: 'CCO', lookup: 'contractChangeOrders' },
    ],
    form: {
      sections: [{
        title: 'Volume tambah-kurang per item BOQ',
        help: 'Satu baris per pasangan CCO x item BOQ. Plafon opname baru naik setelah CCO-nya DISETUJUI — '
          + 'baris atas CCO yang masih diajukan tercatat tetapi belum dihitung.',
        fields: [
          { key: 'contract_id', label: 'Kontrak', type: 'lookup', lookup: 'contracts', required: true, createOnly: true },
          { key: 'change_order_id', label: 'Pekerjaan tambah-kurang (CCO)', type: 'lookup', lookup: 'contractChangeOrders', required: true, createOnly: true },
          // ID item BOQ mentah, pola yang sama dengan baris opname subkon
          // (subcontract_item_id): belum ada endpoint daftar item BOQ yang
          // datar untuk dijadikan sumber pilih.
          { key: 'boq_item_id', label: 'ID item BOQ', type: 'number', required: true, createOnly: true },
          { key: 'qty_change', label: 'Perubahan volume', type: 'qty', required: true, help: 'Negatif untuk pekerjaan kurang.' },
          { key: 'unit', label: 'Satuan', type: 'text' },
          { key: 'notes', label: 'Keterangan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  'projects/weekly-progress': {
    module: 'prj', api: 'projects/weekly-progress', label: 'Progres Mingguan', labelOne: 'Progres Mingguan',
    noDetail: true, canDelete: false, canEdit: false,
    // Detail Schedule / Program Kerja (Form F/DS) — lanskap, grid harian
    // empat minggu. Dicetak dari baris minggu; idField menunjuk proyeknya.
    printForms: [
      { form: 'laporan-mingguan', label: 'Detail Schedule', idField: 'project_id', params: { minggu: 'week_no' } },
    ],
    columns: [
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'week_no', label: 'Minggu', type: 'number', align: 'center', prefix: 'M-' },
      { key: 'period_start', label: 'Mulai', type: 'date' },
      { key: 'period_end', label: 'Selesai', type: 'date' },
      { key: 'planned_pct', label: 'Rencana', type: 'percent', align: 'right' },
      { key: 'actual_pct', label: 'Aktual', type: 'percent', align: 'right' },
      // P3 — dua hal yang sangat berbeda hidup di kolom Aktual: taksiran yang
      // diketik pengawas, dan volume terukur dari opname yang disetujui.
      // Tanpa kolom ini, angka yang berubah sendiri tidak punya penjelasan
      // apa pun di layar.
      { key: 'actual_pct_source', label: 'Sumber aktual', type: 'enum', enum: 'actualPctSource', hideOnNarrow: true },
      { key: 'deviation_pct', label: 'Deviasi', type: 'percent', align: 'right', signed: true },
    ],
    filters: [{ key: 'project_id', label: 'Proyek', lookup: 'projects' }],
    form: {
      sections: [{
        title: 'Progres mingguan',
        help: 'Menyimpan ulang minggu yang sama akan memperbarui data (upsert). Bila ada OPNAME KE PEMILIK '
          + 'yang sudah disetujui mencakup periode ini, kolom Aktual DIGANTI dengan volume terukur berbobot '
          + 'nilai — angka yang Anda ketik di situ tidak disimpan, dan kolom "Sumber aktual" pada daftar '
          + 'mengatakan yang mana yang dipakai.',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true },
          { key: 'week_no', label: 'Minggu ke-', type: 'number', required: true, min: 1 },
          { key: 'period_start', label: 'Periode mulai', type: 'date', required: true },
          { key: 'period_end', label: 'Periode selesai', type: 'date', required: true },
          { key: 'planned_pct', label: 'Rencana kumulatif (%)', type: 'percent', required: true },
          {
            key: 'actual_pct', label: 'Aktual kumulatif (%)', type: 'percent', required: true,
            help: 'Diabaikan pada minggu yang dicakup opname ke pemilik yang sudah disetujui — '
              + 'server memakai volume terukur dan mencatat sumbernya.',
          },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  'projects/milestones': {
    module: 'prj', api: 'projects/milestones', label: 'Milestone', labelOne: 'Milestone',
    columns: [
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'name', label: 'Milestone', type: 'text' },
      { key: 'due_date', label: 'Target', type: 'date' },
      { key: 'achieved_date', label: 'Tercapai', type: 'date' },
      { key: 'is_achieved', label: 'Status', type: 'flag', trueLabel: 'Tercapai', falseLabel: 'Belum' },
      { key: 'is_overdue', label: 'Terlambat', type: 'flag', trueLabel: 'Terlambat', falseLabel: '—', trueTone: 'red', falseTone: 'muted' },
    ],
    filters: [{ key: 'project_id', label: 'Proyek', lookup: 'projects' }],
    form: {
      sections: [{
        title: 'Milestone',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'name', label: 'Nama milestone', type: 'text', required: true, span: 2 },
          { key: 'due_date', label: 'Target tanggal', type: 'date', required: true },
          { key: 'achieved_date', label: 'Tanggal tercapai', type: 'date' },
          { key: 'termin_id', label: 'ID termin terkait', type: 'number' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  'projects/bast': {
    module: 'prj', api: 'projects/bast', label: 'BAST', labelOne: 'BAST',
    // The handover the customer's representative signs; prj_bast has recorded
    // their name since the first migration.
    printable: { path: 'core/print/bast/{id}', prefix: 'bast' },
    columns: [
      codeColumn,
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'bast_type', label: 'Jenis', type: 'enum', enum: 'bastType' },
      { key: 'handover_date', label: 'Tgl serah terima', type: 'date' },
      { key: 'retention_release_due', label: 'Retensi jatuh tempo', type: 'date' },
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Berita Acara Serah Terima',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'bast_type', label: 'Jenis BAST', type: 'select', enum: 'bastType', required: true },
          { key: 'handover_date', label: 'Tanggal serah terima', type: 'date', required: true },
          { key: 'retention_release_due', label: 'Retensi dibayar setelah', type: 'date', help: 'Otomatis dari masa pemeliharaan bila dikosongkan (BAST I).' },
          { key: 'customer_representative', label: 'Wakil pelanggan', type: 'text' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
    // Bukan approvalActions('prj') polos: persetujuan BAST II membaca prasyarat
    // (BAST I disetujui, punch list kritis/mayor bersih, tanggal & progres WBS
    // wajar) dan PERINGATAN hanya boleh dilewati dengan alasan tertulis minimal
    // 20 karakter. Tanpa isian override_reason di sini SPA tidak pernah bisa
    // mengirimkannya, sehingga BAST II dengan satu temuan minor terbuka tertolak
    // dari layar padahal API menerimanya — sementara retensi Rp 2.425.000.000
    // menunggu persis di belakang persetujuan itu.
    actions: [
      {
        key: 'submit', label: 'Ajukan', path: '{id}/submit', method: 'POST',
        perm: 'prj.update', when: DRAFT_OR_REJECTED, variant: 'primary',
      },
      {
        key: 'approve', label: 'Setujui', path: '{id}/approve', method: 'POST',
        perm: 'prj.approve', when: IS_SUBMITTED, variant: 'success',
        fields: [
          { key: 'note', label: 'Catatan persetujuan', type: 'textarea' },
          {
            key: 'override_reason', label: 'Alasan melewati prasyarat (bila ada peringatan)', type: 'textarea',
            help: 'Minimal 20 karakter. Hanya melewati PERINGATAN — prasyarat wajib tidak dapat dilewati.',
          },
        ],
      },
      {
        key: 'reject', label: 'Tolak', path: '{id}/reject', method: 'POST',
        perm: 'prj.approve', when: IS_SUBMITTED, variant: 'danger',
        fields: [{ key: 'note', label: 'Alasan penolakan', type: 'textarea', required: true }],
      },
    ],
  },

  /* Terdaftar supaya ProjectBaseline bisa masuk ApprovableDocuments dan
     pemberitahuan "baseline menunggu persetujuan" punya halaman untuk mendarat
     (#/d/projects/baselines/{id}). SENGAJA tanpa entri menu dan tanpa form:
     ruang kerjanya adalah layar `evm`, dan baseline dibuat lewat snapshot yang
     menghitung kurva beku — bukan lewat formulir generik. */
  'projects/baselines': {
    module: 'prj', api: 'projects/baselines', label: 'Baseline Proyek', labelOne: 'Baseline',
    canDelete: false,
    columns: [
      codeColumn,
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'revision_no', label: 'Revisi', type: 'number', align: 'center' },
      { key: 'effective_date', label: 'Berlaku', type: 'date' },
      { key: 'bac', label: 'BAC', type: 'currency', align: 'right' },
      { key: 'planned_finish', label: 'Rencana selesai', type: 'date' },
      { key: 'is_current', label: 'Berlaku kini', type: 'flag', trueLabel: 'Ya', trueTone: 'green', falseLabel: '—' },
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    actions: approvalActions('prj'),
  },

  /* Terdaftar supaya temuan punya halaman sendiri: tautan pemberitahuan
     (#/d/projects/defects/{id}), pencarian global dan lampiran foto semuanya
     berhenti di sini. Sengaja TIDAK punya entri menu — pintu masuk register
     adalah layar `defects`, yang membawa ringkasan prasyarat BAST II. */
  'projects/defects': {
    module: 'prj', api: 'projects/defects', label: 'Register Defect (Punch List)', labelOne: 'Temuan',
    // Daftar Temuan (Form F/QC) — register QC dalam format rumah, lanskap.
    // Dicetak per proyek dari baris temuan mana pun.
    printForms: [
      { form: 'daftar-temuan', label: 'Daftar Temuan', idField: 'project_id' },
    ],
    columns: [
      codeColumn,
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'title', label: 'Temuan', type: 'text', truncate: 70, sub: 'location' },
      { key: 'severity', label: 'Keparahan', type: 'enum', enum: 'defectSeverity' },
      { key: 'due_date', label: 'Target perbaikan', type: 'date' },
      // Merah, bukan hijau bawaan: temuan yang lewat target adalah hal pertama
      // yang ditanyakan pelanggan di meja serah terima.
      { key: 'is_overdue', label: 'Lewat target', type: 'flag', trueLabel: 'Ya', trueTone: 'red', falseLabel: '—' },
      // Lencana, bukan teks polos: warnanya kini milik enumnya (open merah),
      // sama seperti halaman detail dan register punch list.
      { key: 'status', label: 'Status', type: 'status', enum: 'defectStatus', width: '1%' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'severity', label: 'Keparahan', enum: 'defectSeverity' },
      { key: 'status', label: 'Status', enum: 'defectStatus' },
      { key: 'source', label: 'Sumber', enum: 'defectSource' },
    ],
    // Temuan yang sudah diverifikasi atau didispensasi adalah catatan
    // penerimaan pelanggan. Mengoreksinya berarti membukanya kembali lebih
    // dulu — servicenya sudah menolak, ini menghentikan formulir menawarkannya.
    editableWhen: (row) => row.status !== 'closed' && row.status !== 'waived',
    deletableWhen: (row) => row.status !== 'closed' && row.status !== 'waived',
    form: {
      sections: [
        {
          title: 'Temuan',
          fields: [
            { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
            { key: 'title', label: 'Temuan', type: 'text', required: true, span: 2 },
            { key: 'severity', label: 'Keparahan', type: 'select', enum: 'defectSeverity', required: true, help: 'Kritis dan Mayor menahan BAST II sampai diverifikasi selesai atau diberi dispensasi.' },
            { key: 'source', label: 'Sumber temuan', type: 'select', enum: 'defectSource', required: true },
            { key: 'location', label: 'Lokasi', type: 'text', help: 'Mis. "Lantai 5, zona B".' },
            { key: 'subcontract_id', label: 'SPK subkon', type: 'lookup', lookup: 'subcontracts', help: 'Diisi bila perbaikannya menjadi tanggungan subkontraktor.' },
            { key: 'description', label: 'Uraian', type: 'textarea', span: 2 },
          ],
        },
        {
          title: 'Perbaikan',
          fields: [
            { key: 'responsible_employee_id', label: 'Penanggung jawab perbaikan', type: 'lookup', lookup: 'employees' },
            { key: 'due_date', label: 'Target perbaikan', type: 'date' },
            { key: 'reported_on', label: 'Tanggal temuan', type: 'date', help: 'Umur temuan dihitung dari tanggal ini.' },
          ],
        },
      ],
    },
    actions: [
      {
        key: 'fixed', label: 'Selesai Diperbaiki', path: '{id}/fixed', method: 'POST',
        perm: 'prj.update', when: (row) => row.status !== 'closed' && row.status !== 'waived',
        fields: [{ key: 'fixed_at', label: 'Tanggal perbaikan selesai', type: 'date', help: 'Temuan pindah ke "Menunggu verifikasi" dan masih dihitung terbuka.' }],
      },
      {
        key: 'verify', label: 'Verifikasi Selesai', path: '{id}/verify', method: 'POST',
        perm: 'prj.approve', when: (row) => row.status !== 'closed' && row.status !== 'waived', variant: 'success',
        fields: [{ key: 'verified_at', label: 'Tanggal diterima', type: 'date', help: 'Penerimaan atas perbaikannya — baris inilah yang dihitung prasyarat BAST II.' }],
      },
      {
        key: 'waive', label: 'Dispensasi Pelanggan', path: '{id}/waive', method: 'POST',
        perm: 'prj.approve', when: (row) => row.status !== 'closed' && row.status !== 'waived',
        fields: [
          { key: 'reason', label: 'Alasan dispensasi', type: 'textarea', required: true, help: 'Minimal 10 karakter. Ini satu-satunya jalan melewati blokir BAST II untuk temuan kritis/mayor — tulis siapa yang menerima dan atas dasar apa.' },
          { key: 'waived_at', label: 'Tanggal dispensasi', type: 'date' },
        ],
      },
      {
        key: 'reopen', label: 'Buka Kembali', path: '{id}/reopen', method: 'POST',
        perm: 'prj.approve', when: (row) => row.status === 'closed' || row.status === 'waived',
        fields: [{ key: 'reason', label: 'Alasan dibuka kembali', type: 'textarea', required: true, help: 'Minimal 10 karakter. Ditulis di ATAS catatan lama, bukan menggantinya.' }],
      },
    ],
  },

  'projects/safety-incidents': {
    module: 'prj', api: 'projects/safety-incidents', label: 'Register K3 (SMK3)', labelOne: 'Insiden K3',
    columns: [
      codeColumn,
      { key: 'occurred_at', label: 'Waktu kejadian', type: 'datetime' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'severity', label: 'Keparahan', type: 'enum', enum: 'incidentSeverity' },
      { key: 'category', label: 'Jenis', type: 'enum', enum: 'incidentCategory' },
      { key: 'lost_days', label: 'Hari hilang', type: 'number', align: 'right' },
      // Red, not the default green: an overdue corrective action is the one
      // thing a site manager gets asked about on a safety walk.
      { key: 'is_overdue', label: 'Tindakan telat', type: 'flag', trueLabel: 'Ya', trueTone: 'red', falseLabel: '—' },
      // Lencana, bukan teks polos: warnanya kini milik enumnya (open merah).
      // Diukur 4 Sep 2026: daftar K3 tanpa lencana status, detailnya hijau.
      { key: 'status', label: 'Status', type: 'status', enum: 'incidentStatus', width: '1%' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'severity', label: 'Keparahan', enum: 'incidentSeverity' },
      { key: 'category', label: 'Jenis kejadian', enum: 'incidentCategory' },
      { key: 'status', label: 'Status', enum: 'incidentStatus' },
    ],
    // A closed incident is a record. Correcting one means reopening it first,
    // which the service enforces; this stops the form offering the edit at all.
    editableWhen: (row) => row.status !== 'closed',
    deletableWhen: (row) => row.status !== 'closed',
    form: {
      sections: [
        {
          title: 'Kejadian',
          fields: [
            { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
            // Time of day, not just the date: the shift is half of what a safety
            // review looks for.
            { key: 'occurred_at', label: 'Waktu kejadian', type: 'datetime', required: true },
            { key: 'location', label: 'Lokasi di site', type: 'text', help: 'Mis. "Lantai 5, zona B".' },
            { key: 'severity', label: 'Keparahan', type: 'select', enum: 'incidentSeverity', required: true },
            { key: 'category', label: 'Jenis kejadian', type: 'select', enum: 'incidentCategory', required: true },
            { key: 'description', label: 'Uraian kejadian', type: 'textarea', required: true, span: 2 },
            { key: 'people_involved', label: 'Jumlah orang terlibat', type: 'number' },
            { key: 'lost_days', label: 'Hari kerja hilang', type: 'number', help: 'Pembilang severity rate pada laporan K3 bulanan.' },
            { key: 'immediate_action', label: 'Tindakan segera di lokasi', type: 'textarea', span: 2 },
          ],
        },
        {
          title: 'Investigasi & tindak lanjut',
          fields: [
            { key: 'root_cause', label: 'Penyebab dasar', type: 'textarea', span: 2, help: 'Wajib diisi sebelum insiden dapat ditutup.' },
            { key: 'corrective_action', label: 'Tindakan korektif', type: 'textarea', span: 2, help: 'Wajib diisi sebelum insiden dapat ditutup.' },
            { key: 'responsible_employee_id', label: 'Penanggung jawab', type: 'lookup', lookup: 'employees' },
            { key: 'due_date', label: 'Target selesai', type: 'date' },
          ],
        },
        {
          title: 'Pelaporan',
          fields: [
            { key: 'is_reportable', label: 'Wajib dilaporkan ke Disnaker/pemberi kerja', type: 'bool' },
            { key: 'reported_to_authority_at', label: 'Tanggal dilaporkan', type: 'date' },
          ],
        },
      ],
    },
    actions: [
      {
        key: 'close', label: 'Tutup Insiden', path: '{id}/close', method: 'POST',
        perm: 'prj.approve', when: (row) => row.status !== 'closed', variant: 'success',
        fields: [{ key: 'closed_at', label: 'Tanggal penutupan', type: 'date' }],
      },
      {
        key: 'reopen', label: 'Buka Kembali', path: '{id}/reopen', method: 'POST',
        perm: 'prj.approve', when: (row) => row.status === 'closed',
        confirm: 'Buka kembali insiden ini? Lakukan bila tindakan korektif ternyata belum efektif.',
      },
    ],
  },

  /* P6 — formulir K3 harian (FM-10-13, cetak F/K3H). Tautan ke laporan harian
     adalah FAKTA TURUNAN (proyek, tanggal) yang diselesaikan server — tidak
     ada isian untuk memilihnya; laporan harian yang lahir belakangan
     menaut-balik sendiri. */
  'projects/hse-daily': {
    module: 'prj', api: 'projects/hse-daily', label: 'Formulir K3 Harian', labelOne: 'Formulir K3 Harian',
    columns: [
      codeColumn,
      { key: 'report_date', label: 'Tanggal', type: 'date' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'toolbox_topic', label: 'Topik toolbox', type: 'text' },
      { key: 'daily_report_code', label: 'Laporan harian', type: 'code' },
      { key: 'findings_count', label: 'Temuan', type: 'number', align: 'right', width: '1%' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    form: {
      sections: [{
        title: 'Toolbox meeting',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'report_date', label: 'Tanggal', type: 'date', required: true, help: 'Satu formulir per proyek per hari. Laporan harian tanggal yang sama tertaut otomatis.' },
          { key: 'toolbox_topic', label: 'Topik toolbox meeting', type: 'text', span: 2 },
          { key: 'toolbox_attendees', label: 'Peserta toolbox', type: 'tags', span: 2, help: 'Satu nama per baris.' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [
        {
          key: 'apd',
          label: 'Pemakaian APD per kategori',
          columns: [
            { key: 'category', label: 'Kategori (helm, rompi, sepatu, harness, …)', type: 'text', required: true },
            { key: 'qty', label: 'Jumlah terpakai', type: 'number', required: true },
          ],
        },
        {
          key: 'findings',
          label: 'Temuan & tindak lanjut',
          columns: [
            { key: 'finding', label: 'Temuan', type: 'text', required: true },
            { key: 'follow_up', label: 'Tindak lanjut', type: 'text' },
          ],
        },
      ],
      note: 'Kategori APD yang tidak dihitung hari itu TIDAK diisi baris — pada lembar cetak selnya bergaris, bukan 0.',
    },
    detail: {
      tables: [
        {
          key: 'apd',
          label: 'Pemakaian APD',
          columns: [
            { key: 'category', label: 'Kategori', type: 'text' },
            { key: 'qty', label: 'Jumlah', type: 'number', align: 'right' },
          ],
        },
        {
          key: 'findings',
          label: 'Temuan & tindak lanjut',
          columns: [
            { key: 'finding', label: 'Temuan', type: 'text' },
            { key: 'follow_up', label: 'Tindak lanjut', type: 'text' },
          ],
        },
      ],
    },
  },

  /* P6 — register IBPRP per proyek (cetak F/IBPRP, satu lembar per proyek).
     Nilai risiko DIHITUNG server (F×A); tidak ada isian skor, dan tingkatnya
     turun dari banding satu tempat (Permen PUPR 10/2021). */
  'projects/risk-register': {
    module: 'prj', api: 'projects/risk-register', label: 'Register IBPRP', labelOne: 'Baris IBPRP',
    columns: [
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'activity', label: 'Aktivitas', type: 'text' },
      { key: 'hazard', label: 'Bahaya', type: 'text' },
      { key: 'initial_score', label: 'F×A', type: 'number', align: 'right', width: '1%' },
      { key: 'initial_level', label: 'Tingkat', type: 'enum', enum: 'riskLevel', width: '1%' },
      { key: 'residual_score', label: 'Sisa', type: 'number', align: 'right', width: '1%' },
      { key: 'residual_level', label: 'Tingkat sisa', type: 'enum', enum: 'riskLevel', width: '1%' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    form: {
      sections: [
        {
          title: 'Bahaya & risiko awal',
          fields: [
            { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
            { key: 'activity', label: 'Uraian pekerjaan / aktivitas', type: 'text', required: true },
            { key: 'hazard', label: 'Identifikasi bahaya', type: 'text', required: true, span: 2 },
            { key: 'impact', label: 'Dampak / tipe kecelakaan', type: 'text', span: 2 },
            { key: 'likelihood', label: 'Kemungkinan (F, 1–5)', type: 'number', required: true },
            { key: 'severity', label: 'Keparahan (A, 1–5)', type: 'number', required: true, help: 'Nilai risiko F×A dihitung otomatis — tidak pernah diketik.' },
          ],
        },
        {
          title: 'Pengendalian & risiko sisa',
          fields: [
            { key: 'controls', label: 'Pengendalian', type: 'textarea', span: 2 },
            { key: 'residual_likelihood', label: 'Kemungkinan sisa (F′, 1–5)', type: 'number' },
            { key: 'residual_severity', label: 'Keparahan sisa (A′, 1–5)', type: 'number', help: 'Isi keduanya atau kosongkan keduanya; sisa yang belum dinilai tercetak bergaris, bukan 0.' },
          ],
        },
      ],
    },
  },

  'projects/manpower-assignments': {
    module: 'prj', api: 'projects/manpower-assignments', label: 'Penugasan Personel', labelOne: 'Penugasan',
    columns: [
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'employee_id', label: 'Karyawan', type: 'rel', lookup: 'employees' },
      { key: 'role_on_project', label: 'Peran', type: 'text' },
      { key: 'assigned_from', label: 'Dari', type: 'date' },
      { key: 'assigned_until', label: 'Sampai', type: 'date' },
      { key: 'is_current_today', label: 'Di lokasi hari ini', type: 'flag', trueLabel: 'Ya', falseLabel: 'Tidak' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'employee_id', label: 'Karyawan', lookup: 'employees' },
    ],
    form: {
      sections: [{
        title: 'Penugasan personel',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'employee_id', label: 'Karyawan', type: 'lookup', lookup: 'employees', required: true },
          { key: 'role_on_project', label: 'Peran di proyek', type: 'text', required: true },
          { key: 'assigned_from', label: 'Ditugaskan dari', type: 'date', required: true },
          { key: 'assigned_until', label: 'Sampai', type: 'date' },
          { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
        ],
      }],
    },
  },

  /* ====================================================== PROCUREMENT === */
  'procurement/vendors': {
    module: 'prc', api: 'procurement/vendors', label: 'Vendor & Subkon', labelOne: 'Vendor',
    lookupSource: 'vendors',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama vendor', type: 'text', sub: 'city' },
      { key: 'classification', label: 'Klasifikasi', type: 'enum', enum: 'vendorClassification' },
      /* P4 — jenis vendor menggantikan kolom boolean "Subkon" di layar ini:
         empat nilai (pemasok/subkon/mandor/rental), bukan dua. Pembaca
         is_subcontractor lain (combobox/lookup/detail/form.js) tidak diubah —
         model Vendor menyinkronkan kedua kolom pada setiap simpan. */
      { key: 'vendor_type', label: 'Jenis', type: 'enum', enum: 'vendorType' },
      { key: 'is_pkp', label: 'PKP', type: 'bool', align: 'center' },
      { key: 'rating', label: 'Rating', type: 'number', align: 'right', decimals: 2 },
      statusColumn,
    ],
    filters: [
      { key: 'classification', label: 'Klasifikasi', enum: 'vendorClassification' },
      { key: 'status', label: 'Status', enum: 'activeStatus' },
      { key: 'vendor_type', label: 'Jenis vendor', enum: 'vendorType' },
    ],
    form: {
      sections: [
        {
          title: 'Identitas vendor',
          fields: [
            { key: 'name', label: 'Nama vendor', type: 'text', required: true, span: 2 },
            { key: 'legal_name', label: 'Nama badan hukum', type: 'text', span: 2 },
            { key: 'code', label: 'Kode', type: 'text', help: 'Kosongkan untuk penomoran otomatis.' },
            { key: 'classification', label: 'Klasifikasi', type: 'select', enum: 'vendorClassification', required: true },
            { key: 'npwp', label: 'NPWP', type: 'text' },
            { key: 'sppkp_number', label: 'No. SPPKP', type: 'text', help: 'Wajib bila vendor berstatus PKP.' },
            { key: 'is_pkp', label: 'PKP', type: 'bool' },
            /* P4 — menggantikan centang "Subkontraktor" lama: vendor_type
               menang di server bila keduanya terkirim, dan centang boolean
               diturunkan otomatis dari jenis (Vendor::booted). */
            {
              key: 'vendor_type', label: 'Jenis vendor', type: 'select', enum: 'vendorType',
              default: 'supplier',
              help: 'Subkontraktor & mandor terkena gerbang prakualifikasi K3L/pakta integritas; mandor untuk SP3 upah borongan, rental untuk sewa alat.',
            },
            { key: 'status', label: 'Status', type: 'select', enum: 'activeStatus', default: 'active' },
            { key: 'payment_term_days', label: 'Termin bayar (hari)', type: 'number', default: 30 },
          ],
        },
        {
          title: 'Kontak & bank',
          fields: [
            { key: 'address', label: 'Alamat', type: 'textarea', span: 2 },
            { key: 'city', label: 'Kota', type: 'text' },
            { key: 'phone', label: 'Telepon', type: 'text' },
            { key: 'email', label: 'Email', type: 'text' },
            { key: 'pic_name', label: 'Nama PIC', type: 'text' },
            { key: 'bank_name', label: 'Bank', type: 'text' },
            { key: 'bank_account_no', label: 'No. rekening', type: 'text' },
            { key: 'bank_account_name', label: 'Atas nama', type: 'text', span: 2 },
          ],
        },
      ],
    },
  },

  'procurement/vendor-documents': {
    module: 'prc', api: 'procurement/vendor-documents', label: 'Dokumen Vendor', labelOne: 'Dokumen Vendor',
    columns: [
      { key: 'vendor.name', label: 'Vendor', type: 'text', sub: 'vendor.code' },
      { key: 'doc_type', label: 'Jenis', type: 'enum', enum: 'vendorDocumentType' },
      { key: 'name', label: 'Dokumen', type: 'text', sub: 'number' },
      { key: 'valid_until', label: 'Berlaku s/d', type: 'date' },
      { key: 'is_mandatory', label: 'Wajib', type: 'bool', align: 'center' },
      { key: 'is_expired', label: 'Kedaluwarsa', type: 'bool', align: 'center' },
    ],
    filters: [
      { key: 'vendor_id', label: 'Vendor', lookup: 'vendors' },
      { key: 'doc_type', label: 'Jenis', enum: 'vendorDocumentType' },
      { key: 'expired', label: 'Kedaluwarsa', type: 'boolFilter' },
    ],
    form: {
      sections: [{
        title: 'Dokumen prakualifikasi vendor',
        help: 'Berlaku s/d: masih sah PADA hari itu; kosongkan bila tidak kedaluwarsa (mis. NPWP). Dokumen WAJIB yang lewat masa berlakunya memblokir pengajuan PO/SPK vendor ini (bisa di-override beralasan).',
        fields: [
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors', required: true },
          { key: 'doc_type', label: 'Jenis', type: 'select', enum: 'vendorDocumentType', required: true },
          { key: 'name', label: 'Nama dokumen', type: 'text', required: true, span: 2 },
          { key: 'number', label: 'Nomor', type: 'text' },
          { key: 'issuer', label: 'Penerbit', type: 'text' },
          { key: 'issued_date', label: 'Terbit', type: 'date' },
          { key: 'valid_until', label: 'Berlaku s/d', type: 'date' },
          { key: 'is_mandatory', label: 'Wajib untuk PO/SPK', type: 'bool' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  
  'procurement/purchase-requisitions': {
    module: 'prc', api: 'procurement/purchase-requisitions', label: 'Permintaan Pembelian (PR)', labelOne: 'PR',
    lookupSource: 'purchaseRequisitions',
    columns: [
      codeColumn,
      { key: 'purpose', label: 'Keperluan', type: 'text', truncate: 64 },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'warehouse_id', label: 'Gudang', type: 'rel', lookup: 'warehouses' },
      { key: 'needed_date', label: 'Dibutuhkan', type: 'date' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Permintaan pembelian',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'warehouse_id', label: 'Gudang tujuan', type: 'lookup', lookup: 'warehouses' },
          { key: 'needed_date', label: 'Dibutuhkan tanggal', type: 'date', required: true },
          { key: 'requested_by', label: 'Diminta oleh', type: 'lookup', lookup: 'users' },
          { key: 'purpose', label: 'Keperluan', type: 'textarea', span: 2 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Item yang diminta', min: 1,
        columns: [
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', width: '28%' },
          { key: 'description', label: 'Uraian', type: 'text', width: '28%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '12%', default: 1 },
          { key: 'unit', label: 'Satuan', type: 'text', width: '12%' },
          { key: 'estimated_price', label: 'Estimasi harga', type: 'currency', width: '20%' },
        ],
        total: (row) => Number(row.qty || 0) * Number(row.estimated_price || 0),
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Item yang diminta',
        columns: [
          { key: 'line_no', label: '#', align: 'right' },
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'estimated_price', label: 'Estimasi', type: 'currency', align: 'right' },
        ],
      }],
    },
    actions: [
      ...approvalActions('prc'),
      {
        key: 'create-po', label: 'Buat PO', path: '{id}/create-po', method: 'POST',
        perm: 'prc.create', variant: 'primary', when: (row) => row.status === 'approved',
        navigateTo: 'procurement/purchase-orders',
        fields: [
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors', required: true },
          { key: 'order_date', label: 'Tanggal PO', type: 'date', defaultToday: true },
          { key: 'expected_date', label: 'Perkiraan kirim', type: 'date' },
          { key: 'delivery_address', label: 'Alamat pengiriman', type: 'textarea' },
          { key: 'notes', label: 'Catatan', type: 'textarea' },
          { key: 'qualification_override_reason', label: 'Alasan override prakualifikasi', type: 'textarea', help: 'Isi hanya bila vendor terblokir prakualifikasi dan PO tetap harus dibuat.' },
        ],
      },
    ],
  },

  'procurement/rfqs': {
    module: 'prc', api: 'procurement/rfqs', label: 'RFQ (Banding Penawaran)', labelOne: 'RFQ',
    customDetail: 'rfq',
    columns: [
      codeColumn,
      { key: 'rfq_date', label: 'Tanggal', type: 'date' },
      { key: 'due_date', label: 'Batas penawaran', type: 'date', hideOnNarrow: true },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects', hideOnNarrow: true },
      { key: 'purchase_requisition_id', label: 'Dari PR', type: 'rel', lookup: 'purchaseRequisitions', hideOnNarrow: true },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Permintaan penawaran (RFQ)',
        help: 'Isi "Dari PR" untuk menyalin baris PR yang disetujui — daftar barang di bawah diabaikan. RFQ mandiri mengetik barisnya sendiri. Matriks harga diisi di halaman detail.',
        fields: [
          { key: 'purchase_requisition_id', label: 'Dari PR (disetujui)', type: 'lookup', lookup: 'purchaseRequisitions', createOnly: true },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'rfq_date', label: 'Tanggal RFQ', type: 'date', required: true, defaultToday: true },
          { key: 'due_date', label: 'Batas masuk penawaran', type: 'date' },
          { key: 'vendor_ids', label: 'Vendor diundang', type: 'multiselect', lookup: 'vendors', required: true, span: 2, help: 'Harga hanya bisa diketik untuk vendor yang diundang di sini.' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Barang yang dimintakan penawaran',
        columns: [
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', width: '24%' },
          { key: 'description', label: 'Uraian', type: 'text', required: true, width: '40%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '14%', default: 1 },
          { key: 'unit', label: 'Satuan', type: 'text', width: '12%' },
        ],
      }],
    },
  },

  'procurement/purchase-orders': {
    module: 'prc', api: 'procurement/purchase-orders', label: 'Pesanan Pembelian (PO)', labelOne: 'PO',
    lookupSource: 'purchaseOrders',
    printable: { path: 'core/print/purchase-orders/{id}', prefix: 'po' },
    columns: [
      codeColumn,
      { key: 'vendor.name', label: 'Vendor', type: 'text', sub: 'vendor.code' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects', hideOnNarrow: true },
      { key: 'order_date', label: 'Tgl PO', type: 'date', hideOnNarrow: true },
      { key: 'total', label: 'Total', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'vendor_id', label: 'Vendor', lookup: 'vendors' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Pesanan pembelian',
        fields: [
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors', required: true },
          { key: 'purchase_requisition_id', label: 'Dari PR', type: 'lookup', lookup: 'purchaseRequisitions' },
          // Tersimpan di PO sebagai jejak audit, tampil di detail dan di
          // formulir cetak — sama seperti alasan override prakualifikasi (T3.8).
          { key: 'pr_bypass_reason', label: 'Alasan tanpa PR', type: 'textarea', span: 2, required: true, visibleWhen: PO_WITHOUT_PR,
            help: 'PO ini dibuat tanpa permintaan pembelian (PR). Sebutkan mengapa pembelian langsung dilakukan (mis. kebutuhan darurat di lapangan).' },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'warehouse_id', label: 'Gudang tujuan', type: 'lookup', lookup: 'warehouses' },
          { key: 'order_date', label: 'Tanggal PO', type: 'date', required: true, defaultToday: true },
          // Wajib sejak T3.5: kolom inilah yang dibaca pengawas tenggat
          // `po_expected`. Diukur 4 Sep 2026 di produksi (ANALISIS-PROSES D1):
          // PO/2026/III/0002 Rp 128 jt disetujui 40 hari tanpa GRN dan tak
          // pernah disebut pengawas — tanggalnya kosong karena formulir tidak
          // memintanya. Server menolak 422 "Perkiraan kirim wajib diisi.".
          { key: 'expected_date', label: 'Perkiraan kirim', type: 'date', required: true },
          { key: 'payment_term_days', label: 'Termin bayar (hari)', type: 'number', help: 'Kosongkan untuk memakai termin vendor.' },
          { key: 'discount_amount', label: 'Diskon', type: 'currency', default: 0 },
          { key: 'delivery_address', label: 'Alamat pengiriman', type: 'textarea', span: 2 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
          { key: 'qualification_override_reason', label: 'Alasan override prakualifikasi', type: 'textarea', span: 2, help: 'Isi hanya bila vendor terblokir prakualifikasi (nonaktif / dokumen wajib kedaluwarsa) dan PO tetap harus dibuat.' },
        ],
      }],
      lines: [{
        key: 'items', label: 'Item pesanan', min: 1,
        columns: [
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', width: '24%' },
          { key: 'description', label: 'Uraian', type: 'text', required: true, width: '30%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '12%', default: 1 },
          { key: 'unit', label: 'Satuan', type: 'text', width: '10%' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', required: true, width: '20%' },
        ],
        total: (row) => Number(row.qty || 0) * Number(row.unit_price || 0),
      }],
    },
    detail: {
      summary: ['subtotal', 'discount_amount', 'dpp', 'ppn_amount', 'total'],
      tables: [{
        key: 'items', label: 'Item pesanan',
        columns: [
          { key: 'line_no', label: '#', align: 'right' },
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'qty_received', label: 'Diterima', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [
      // Ajukan milik PO tidak membawa field: alasan override prakualifikasi
      // diminta SESUDAH server menolak (aturan pertama di bawah), bukan di
      // modal pada setiap pengajuan. Dulu Ajukan selalu membuka modal
      // "Alasan override prakualifikasi" yang opsional — diukur 2 Sep 2026
      // (HASIL-UJI §1, S3): 12 klik buat→ajukan PO 2 baris, dua di antaranya
      // Ajukan + Ajukan di modal yang dikosongkan karena vendornya sehat.
      // Alasan yang terpakai tetap tersimpan di qualification_override_reason
      // PO sebagai jejak audit (PoQualificationOverrideAuditTest).
      ...approvalActions('prc').filter((action) => action.key !== 'submit'),
      {
        key: 'submit', label: 'Ajukan', path: '{id}/submit', method: 'POST',
        perm: 'prc.update', when: DRAFT_OR_REJECTED, variant: 'primary',
        /*
         * Tiga penolakan pola confirm-resubmit (temuan #72) bisa muncul saat
         * mengajukan, BERURUTAN dalam urutan server: gate prakualifikasi #35
         * (qualification_override_reason — vendor nonaktif / dokumen wajib
         * kedaluwarsa DI ANTARA draf dan pengajuan; PO ke vendor terblokir
         * tidak pernah lahir sebagai draf), lalu kendali harga #34
         * (items.N.unit_price), lalu gate anggaran #33 (budget). actions.js
         * menjawab satu jenis per putaran dan mengulang panggilannya dengan
         * jawabannya — flag untuk dua yang terakhir, isian wajib
         * (promptField) untuk yang pertama; pesan dialog adalah pesan server
         * apa adanya — pesan itulah yang menyebut vendor dan penyebab
         * blokirnya, atau angka harga/anggaran yang dikonfirmasi.
         */
        confirmResubmit: [
          {
            promptField: {
              key: 'qualification_override_reason', label: 'Alasan override prakualifikasi',
              type: 'textarea', required: true,
              help: 'Tersimpan di PO sebagai jejak audit. Sebutkan mengapa PO tetap harus jalan (mis. pembelian darurat ke pemegang lisensi tunggal).',
            },
            test: /^qualification_override_reason$/,
            title: 'Vendor belum lolos prakualifikasi — tetap ajukan?',
            confirmLabel: 'Ajukan dengan alasan ini',
          },
          {
            flag: 'confirm_price_deviation',
            test: /^items\.\d+\.unit_price$/,
            title: 'Harga di atas harga BOQ — tetap ajukan?',
            confirmLabel: 'Ya, harga sudah dinegosiasi',
          },
          {
            flag: 'confirm_over_budget',
            test: /^budget$/,
            title: 'Melampaui sisa anggaran RAP — tetap ajukan?',
            confirmLabel: 'Ya, tetap ajukan',
          },
        ],
      },
      {
        key: 'close', label: 'Tutup PO', path: '{id}/close', method: 'POST',
        perm: 'prc.update', when: (row) => row.status === 'approved',
        confirm: 'Tutup PO ini? Sisa kuantitas yang belum diterima dibatalkan.',
      },
    ],
  },

  'procurement/vendor-evaluations': {
    module: 'prc', api: 'procurement/vendor-evaluations', label: 'Evaluasi Vendor', labelOne: 'Evaluasi Vendor',
    columns: [
      { key: 'vendor.name', label: 'Vendor', type: 'text', sub: 'vendor.code' },
      { key: 'period', label: 'Periode', type: 'text' },
      { key: 'quality_score', label: 'Kualitas', type: 'number', align: 'center' },
      { key: 'delivery_score', label: 'Pengiriman', type: 'number', align: 'center' },
      { key: 'price_score', label: 'Harga', type: 'number', align: 'center' },
      { key: 'service_score', label: 'Layanan', type: 'number', align: 'center' },
      { key: 'total_score', label: 'Skor', type: 'number', align: 'right', decimals: 2, strong: true },
    ],
    filters: [{ key: 'vendor_id', label: 'Vendor', lookup: 'vendors' }],
    form: {
      sections: [{
        title: 'Evaluasi vendor',
        help: 'Skor 1 (buruk) sampai 5 (sangat baik). Skor total adalah rata-rata keempatnya.',
        fields: [
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors', required: true },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'period', label: 'Periode', type: 'text', required: true, help: 'mis. 2026-S1' },
          { key: 'evaluated_by', label: 'Dievaluasi oleh', type: 'lookup', lookup: 'users' },
          { key: 'quality_score', label: 'Kualitas (1-5)', type: 'number', required: true, min: 1, max: 5 },
          { key: 'delivery_score', label: 'Ketepatan kirim (1-5)', type: 'number', required: true, min: 1, max: 5 },
          { key: 'price_score', label: 'Harga (1-5)', type: 'number', required: true, min: 1, max: 5 },
          { key: 'service_score', label: 'Layanan (1-5)', type: 'number', required: true, min: 1, max: 5 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  /* P2 — tata kelola pengadaan: BA negosiasi, keputusan pemenang, rencana. */
  'procurement/negotiation-minutes': {
    module: 'prc', api: 'procurement/negotiation-minutes', label: 'BA Negosiasi', labelOne: 'BA Negosiasi',
    columns: [
      codeColumn,
      { key: 'rfq_id', label: 'RFQ', type: 'rel', lookup: 'rfqs', hideOnNarrow: true },
      { key: 'vendor.name', label: 'Vendor', type: 'text' },
      { key: 'meeting_date', label: 'Tanggal', type: 'date' },
      { key: 'location', label: 'Tempat', type: 'text', hideOnNarrow: true },
    ],
    filters: [
      { key: 'rfq_id', label: 'RFQ', lookup: 'rfqs' },
      { key: 'vendor_id', label: 'Vendor', lookup: 'vendors' },
    ],
    form: {
      sections: [{
        title: 'Berita Acara Negosiasi (BAN)',
        help: 'Risalah pertemuan negosiasi harga. Lampirkan daftar hadir lewat kartu Lampiran di bawah.',
        fields: [
          { key: 'rfq_id', label: 'RFQ', type: 'lookup', lookup: 'rfqs', required: true, createOnly: true },
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors', required: true, createOnly: true },
          { key: 'meeting_date', label: 'Tanggal pertemuan', type: 'date', required: true, defaultToday: true },
          { key: 'location', label: 'Tempat', type: 'text' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Harga awal → harga nego',
        columns: [
          { key: 'description', label: 'Uraian', type: 'text', required: true, width: '40%' },
          { key: 'qty', label: 'Qty', type: 'qty', width: '12%' },
          { key: 'unit', label: 'Satuan', type: 'text', width: '12%' },
          { key: 'harga_awal', label: 'Harga awal', type: 'currency', width: '18%' },
          { key: 'harga_nego', label: 'Harga nego', type: 'currency', width: '18%' },
        ],
      }],
    },
  },

  'procurement/award-decisions': {
    module: 'prc', api: 'procurement/award-decisions', label: 'Keputusan Pemenang', labelOne: 'Keputusan Pemenang',
    columns: [
      codeColumn,
      { key: 'rfq_id', label: 'RFQ', type: 'rel', lookup: 'rfqs', hideOnNarrow: true },
      { key: 'vendor.name', label: 'Pemenang', type: 'text' },
      { key: 'awarded_amount', label: 'Nilai', type: 'currency', align: 'right' },
      { key: 'deviation_amount', label: 'Deviasi vs RAB', type: 'currency', align: 'right', hideOnNarrow: true },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'rfq_id', label: 'RFQ', lookup: 'rfqs' },
      { key: 'vendor_id', label: 'Vendor', lookup: 'vendors' },
    ],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Keputusan Pemenang (Award)',
        help: 'Nilai keputusan di atas RAB wajib mengisi alasan deviasi. Bila nilai berbeda dari penawaran '
          + 'terakhir vendor, keputusan hanya bisa diajukan setelah ada BA Negosiasi untuk vendor ini. '
          + 'Persetujuan berjenjang menurut nilai: ≥ Rp 100 juta butuh direktur, ≥ Rp 1 miliar butuh tiga penyetuju.',
        fields: [
          { key: 'rfq_id', label: 'RFQ', type: 'lookup', lookup: 'rfqs', required: true, createOnly: true },
          { key: 'vendor_id', label: 'Vendor pemenang', type: 'lookup', lookup: 'vendors', required: true, createOnly: true },
          { key: 'rab_amount', label: 'Nilai RAB (HPS)', type: 'currency', required: true },
          { key: 'awarded_amount', label: 'Nilai diputuskan', type: 'currency', required: true },
          { key: 'deviation_reason', label: 'Alasan deviasi (bila di atas RAB)', type: 'textarea', span: 2 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
    detail: {
      summary: ['rab_amount', 'awarded_amount', 'deviation_amount'],
    },
    // Persetujuan berjenjang menurut nilai award: submit/reject seperti biasa,
    // tetapi 'approve' hanya menjadi Disetujui pada tingkat terakhir. Tombol
    // Setujui tetap muncul selama status masih 'submitted' (butuh tingkat
    // berikutnya), dan help-nya menunjuk panel detail untuk sisa tingkat.
    actions: [
      ...approvalActions('prc').filter((action) => action.key !== 'approve'),
      {
        key: 'approve', label: 'Setujui', path: '{id}/approve', method: 'POST',
        perm: 'prc.approve', when: IS_SUBMITTED, variant: 'success',
        fields: [{
          key: 'note', label: 'Catatan persetujuan', type: 'textarea',
          help: 'Persetujuan berjenjang: award baru menjadi Disetujui setelah tingkat '
            + 'terakhir, dari penyetuju yang BERBEDA tiap tingkat. Lihat "Persetujuan '
            + 'masuk" vs "Tingkat persetujuan diperlukan" di panel detail untuk sisa '
            + 'tingkat yang masih dibutuhkan (mis. ≥ Rp 100 juta butuh direktur, '
            + '≥ Rp 1 miliar butuh tiga penyetuju).',
        }],
      },
    ],
  },

  'procurement/procurement-plans': {
    module: 'prc', api: 'procurement/procurement-plans', label: 'Rencana Pengadaan', labelOne: 'Rencana Pengadaan',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects', hideOnNarrow: true },
      { key: 'status', label: 'Status', type: 'text', align: 'center' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    form: {
      sections: [{
        title: 'Rencana Pengadaan / Pola Belanja (PBL)',
        help: 'Disusun dari RAP: paket belanja, metode, target tanggal kontrak, dan PIC — sebelum PR terbit.',
        fields: [
          { key: 'title', label: 'Judul', type: 'text', required: true, span: 2 },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'status', label: 'Status', type: 'select', enum: 'procurementPlanStatus', default: 'draft' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Paket belanja',
        columns: [
          { key: 'package', label: 'Paket', type: 'text', required: true, width: '30%' },
          { key: 'method', label: 'Metode', type: 'select', enum: 'procurementMethod', width: '18%' },
          { key: 'estimated_amount', label: 'Perkiraan nilai', type: 'currency', width: '18%' },
          { key: 'target_contract_date', label: 'Target kontrak', type: 'date', width: '16%' },
          { key: 'pic', label: 'PIC', type: 'text', width: '18%' },
        ],
      }],
    },
  },

  /* P5 — PPK: perintah kerja alat sewa & jasa berbasis periode. Baris =
     alat/uraian x tarif x basis (per_bulan/per_hari_8jam/per_jam) x plafon
     qty_periods; baris per_jam wajib menunjuk alat (jam dibaca dari register
     hour-meter, bukan diketik). */
  'procurement/work-orders': {
    module: 'prc', api: 'procurement/work-orders', label: 'PPK Alat & Jasa', labelOne: 'PPK',
    lookupSource: 'workOrders',
    columns: [
      codeColumn,
      { key: 'title', label: 'Pekerjaan', type: 'text', sub: 'vendor.name' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'value', label: 'Nilai PPK', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'vendor_id', label: 'Vendor', lookup: 'vendors' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'PPK alat & jasa (per periode)',
        help: 'Vendor harus bertipe rental atau pemasok jasa (mandor memakai SP3, subkontraktor memakai SPK). qty_periods adalah plafon kuantitas dalam satuan basisnya: jam untuk per_jam, hari untuk per_hari_8jam, bulan untuk per_bulan.',
        fields: [
          { key: 'vendor_id', label: 'Vendor rental/jasa', type: 'lookup', lookup: 'vendors', required: true },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true },
          { key: 'title', label: 'Judul pekerjaan', type: 'text', required: true, span: 2 },
          { key: 'start_date', label: 'Mulai', type: 'date' },
          { key: 'end_date', label: 'Selesai', type: 'date' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
          { key: 'qualification_override_reason', label: 'Alasan override prakualifikasi', type: 'textarea', span: 2, help: 'Isi hanya bila vendor terblokir prakualifikasi (nonaktif / dokumen wajib kedaluwarsa) dan PPK tetap harus dibuat.' },
        ],
      }],
      lines: [{
        key: 'items', label: 'Baris alat / jasa', min: 1,
        columns: [
          { key: 'asset_id', label: 'ID aset (wajib utk per_jam)', type: 'number', width: '16%' },
          { key: 'description', label: 'Uraian', type: 'text', required: true, width: '34%' },
          { key: 'rate_basis', label: 'Basis tarif', type: 'select', enum: 'rateBasis', required: true, width: '18%' },
          { key: 'rate', label: 'Tarif', type: 'currency', required: true, width: '16%' },
          { key: 'qty_periods', label: 'Plafon kuantitas', type: 'number', required: true, width: '16%' },
        ],
      }],
    },
    detail: {
      summary: ['value', 'ppn_rate'],
      tables: [{
        key: 'items', label: 'Baris alat / jasa',
        columns: [
          { key: 'line_no', label: 'No', align: 'center' },
          { key: 'description', label: 'Uraian' },
          { key: 'rate_basis_label', label: 'Basis' },
          { key: 'rate', label: 'Tarif', type: 'currency', align: 'right' },
          { key: 'qty_periods', label: 'Plafon', type: 'qty', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }, {
        /* P5 — monitoring sewa (deviasi 3.6): periode mana saja yang sudah
           ditagih atas PPK ini, langsung di dokumennya. Tagihan periodenya
           DIBUAT dari layar Tagihan Periode PPK (NAV Pengadaan) — kuantitas
           diturunkan server, tidak ada angka yang diketik. */
        key: 'billings', label: 'Tagihan periode yang sudah dibuat',
        columns: [
          { key: 'code', label: 'Kode', type: 'code' },
          { key: 'period_start', label: 'Periode mulai', type: 'date' },
          { key: 'period_end', label: 'Periode selesai', type: 'date' },
          { key: 'total_amount', label: 'Nilai (DPP)', type: 'currency', align: 'right' },
        ],
        totalKey: 'total_amount',
      }],
    },
    /*
     * Cermin SPK/SP3 (celah kelas P4, ditemukan lane dokumentasi): gate
     * prakualifikasi berjalan ulang saat AJUKAN (WorkOrderController::submit,
     * atas data hidup), dan tanpa field ini PPK yang vendornya menjadi
     * nonaktif (atau dokumen wajibnya kedaluwarsa) di antara draf dan
     * pengajuan TIDAK PERNAH bisa diajukan dari SPA — alasan override hanya
     * dibaca server dari payload submit. Kosongkan bila vendornya sehat.
     */
    actions: approvalActions('prc').map((action) => (action.key !== 'submit' ? action : {
      ...action,
      fields: [{
        key: 'qualification_override_reason', label: 'Alasan override prakualifikasi',
        type: 'textarea',
        help: 'Kosongkan bila vendor sehat. Isi hanya bila pengajuan ditolak gate prakualifikasi dan tetap harus jalan.',
      }],
    })),
  },

  /* P5 — tagihan per periode atas PPK. Kuantitasnya DITURUNKAN server dari
     register hour-meter (per_jam: delta pembacaan DI DALAM periode) dan
     kalender (per_bulan wajib bulan kalender utuh; per_hari_8jam hari
     inklusif) — form ini hanya memilih PPK dan rentang tanggal. Periode
     tumpang-tindih dan plafon terlampaui ditolak server. */
  'procurement/work-order-billings': {
    module: 'prc', api: 'procurement/work-order-billings', label: 'Tagihan Periode PPK', labelOne: 'Tagihan periode',
    columns: [
      codeColumn,
      { key: 'work_order_code', label: 'PPK', type: 'code' },
      { key: 'period_start', label: 'Periode mulai', type: 'date' },
      { key: 'period_end', label: 'Periode selesai', type: 'date' },
      { key: 'total_amount', label: 'Nilai tagihan', type: 'currency', align: 'right' },
    ],
    filters: [
      { key: 'work_order_id', label: 'PPK', lookup: 'workOrders' },
    ],
    deletableWhen: () => true, // server menolak bila sudah ada tagihan AP hidup
    form: {
      sections: [{
        title: 'Tagihan periode PPK',
        help: 'Kuantitas dan rupiah dihitung server dari register hour-meter dan kalender — tidak ada angka yang diketik di sini. Satu periode hanya ditagih sekali; buat tagihan AP-nya dari layar Tagihan Vendor (Finance).',
        fields: [
          { key: 'work_order_id', label: 'PPK', type: 'lookup', lookup: 'workOrders', required: true, createOnly: true },
          { key: 'period_start', label: 'Periode mulai', type: 'date', required: true },
          { key: 'period_end', label: 'Periode selesai', type: 'date', required: true },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
    detail: {
      summary: ['total_amount'],
      tables: [{
        key: 'lines', label: 'Rincian kuantitas',
        columns: [
          { key: 'description', label: 'Uraian' },
          { key: 'rate_basis', label: 'Basis', type: 'enum', enum: 'rateBasis' },
          { key: 'meter_start', label: 'Meter awal', type: 'qty', align: 'right' },
          { key: 'meter_end', label: 'Meter akhir', type: 'qty', align: 'right' },
          { key: 'qty', label: 'Kuantitas', type: 'qty', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
  },

  /* ======================================================== INVENTORY === */
  'inventory/items': {
    module: 'inv', api: 'inventory/items', label: 'Item', labelOne: 'Item',
    lookupSource: 'items',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama item', type: 'text', sub: 'category.name' },
      { key: 'item_type', label: 'Jenis', type: 'enum', enum: 'itemType' },
      { key: 'unit', label: 'Satuan', type: 'text', align: 'center', hideOnNarrow: true },
      { key: 'min_stock', label: 'Stok min.', type: 'qty', align: 'right', hideOnNarrow: true },
      { key: 'avg_cost', label: 'HPP rata-rata', type: 'currency', align: 'right' },
      { key: 'is_active', label: 'Aktif', type: 'bool', align: 'center', hideOnNarrow: true },
    ],
    filters: [
      { key: 'item_type', label: 'Jenis', enum: 'itemType' },
      { key: 'category_id', label: 'Kategori', lookup: 'itemCategories' },
    ],
    form: {
      sections: [{
        title: 'Item',
        fields: [
          { key: 'name', label: 'Nama item', type: 'text', required: true, span: 2 },
          { key: 'code', label: 'Kode', type: 'text', help: 'Kosongkan untuk penomoran otomatis (ITM-xxxx).' },
          { key: 'category_id', label: 'Kategori', type: 'lookup', lookup: 'itemCategories', required: true },
          { key: 'item_type', label: 'Jenis item', type: 'select', enum: 'itemType', required: true, default: 'material' },
          { key: 'unit', label: 'Satuan', type: 'text', required: true },
          { key: 'barcode', label: 'Barcode', type: 'text' },
          { key: 'min_stock', label: 'Stok minimum', type: 'qty', default: 0 },
          { key: 'last_price', label: 'Harga beli terakhir', type: 'currency' },
          { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
        ],
      }],
    },
  },

  'inventory/item-categories': {
    module: 'inv', api: 'inventory/item-categories', label: 'Kategori Item', labelOne: 'Kategori Item',
    lookupSource: 'itemCategories', noDetail: true,
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama kategori', type: 'text' },
      { key: 'parent.name', label: 'Induk', type: 'text' },
    ],
    form: {
      sections: [{
        title: 'Kategori item',
        fields: [
          { key: 'code', label: 'Kode', type: 'text', required: true },
          { key: 'name', label: 'Nama kategori', type: 'text', required: true },
          { key: 'parent_id', label: 'Kategori induk', type: 'lookup', lookup: 'itemCategories', span: 2 },
        ],
      }],
    },
  },

  'inventory/warehouses': {
    module: 'inv', api: 'inventory/warehouses', label: 'Gudang', labelOne: 'Gudang',
    lookupSource: 'warehouses',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama gudang', type: 'text' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'is_site_warehouse', label: 'Gudang site', type: 'bool', align: 'center' },
      { key: 'keeper_employee_id', label: 'Penjaga', type: 'rel', lookup: 'employees' },
      { key: 'is_active', label: 'Aktif', type: 'bool', align: 'center' },
    ],
    form: {
      sections: [{
        title: 'Gudang',
        fields: [
          { key: 'code', label: 'Kode', type: 'text', required: true },
          { key: 'name', label: 'Nama gudang', type: 'text', required: true },
          { key: 'project_id', label: 'Proyek (gudang site)', type: 'lookup', lookup: 'projects', help: 'Diisi bila gudang berada di lokasi proyek.' },
          { key: 'keeper_employee_id', label: 'Penjaga gudang', type: 'lookup', lookup: 'employees' },
          { key: 'address', label: 'Alamat', type: 'textarea', span: 2 },
          { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
        ],
      }],
    },
  },

  'inventory/goods-receipts': {
    module: 'inv', api: 'inventory/goods-receipts', label: 'Penerimaan Barang (GRN)', labelOne: 'GRN',
    columns: [
      codeColumn,
      { key: 'receipt_date', label: 'Tanggal', type: 'date' },
      { key: 'warehouse.name', label: 'Gudang', type: 'text', sub: 'warehouse.code' },
      { key: 'purchase_order_id', label: 'PO', type: 'rel', lookup: 'purchaseOrders' },
      { key: 'delivery_note_no', label: 'No. surat jalan', type: 'text' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'stockDocStatus' },
      { key: 'warehouse_id', label: 'Gudang', lookup: 'warehouses' },
    ],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      /*
       * Temuan 72: server menolak (422 items.N.unit_cost) baris tertaut PO
       * berharga 0 sampai payload membawa confirm_zero_cost — free-issue sah,
       * salah ketik tidak. openForm menampilkan confirmDialog berisi pesan
       * server apa adanya (pesan itulah yang menyebut nama barangnya) lalu
       * mengirim ulang dengan flag ini.
       */
      confirmResubmit: {
        flag: 'confirm_zero_cost',
        test: /^items\.\d+\.unit_cost$/,
        title: 'Harga satuan Rp 0 — lanjutkan?',
        confirmLabel: 'Ya, barang gratis',
      },
      sections: [{
        title: 'Penerimaan barang',
        fields: [
          { key: 'warehouse_id', label: 'Gudang penerima', type: 'lookup', lookup: 'warehouses', required: true },
          { key: 'receipt_date', label: 'Tanggal terima', type: 'date', required: true, defaultToday: true },
          { key: 'purchase_order_id', label: 'PO terkait', type: 'lookup', lookup: 'purchaseOrders' },
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors' },
          { key: 'delivery_note_no', label: 'No. surat jalan', type: 'text' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Item diterima', min: 1,
        help: 'Untuk penerimaan atas PO, pakai "Salin baris dari PO" — hanya lewat jalur itu baris '
          + 'terhubung ke baris PO-nya, sehingga kolom "diterima" pada PO ikut terisi, penerimaan '
          + 'melebihi pesanan tertahan, dan tagihan final PO bisa disetujui.',
        columns: [
          // Never typed, always copied: the id that makes the three-way match real.
          { key: 'po_item_id', type: 'hidden' },
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '46%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '18%' },
          { key: 'unit_cost', label: 'Harga satuan', type: 'currency', required: true, width: '26%' },
        ],
        total: (row) => Number(row.qty || 0) * Number(row.unit_cost || 0),
        prefill: {
          label: 'Salin baris dari PO',
          sourceField: 'purchase_order_id',
          missingSource: 'Pilih PO terkait dulu di bagian atas formulir.',
          emptyMessage: 'Seluruh baris PO ini sudah diterima penuh.',
          load: async (purchaseOrderId, api) => {
            const order = await api.get(`procurement/purchase-orders/${purchaseOrderId}`);

            // Sisa pesanan, bukan qty pesanan: penerimaan kedua atas PO yang sama
            // tidak boleh menawarkan lagi barang yang sudah datang.
            return (order.items || [])
              .map((line) => ({
                po_item_id: line.id,
                item_id: line.item_id,
                qty: Math.max(0, Number(line.qty || 0) - Number(line.qty_received || 0)),
                unit_cost: Number(line.unit_price || 0),
              }))
              .filter((line) => line.item_id && line.qty > 0);
          },
        },
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Item diterima',
        columns: [
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'unit_cost', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
          // Sudah kembali ke vendor lewat retur terposting.
          { key: 'qty_returned', label: 'Diretur', type: 'qty', align: 'right' },
          // Baris tanpa tautan tidak mengurangi sisa PO — layak terlihat.
          { key: 'po_item_id', label: 'Baris PO', type: 'flag', trueLabel: 'Tertaut', falseLabel: 'Lepas', falseTone: 'amber' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [
      {
        key: 'post', label: 'Posting ke Stok', path: '{id}/post', method: 'POST',
        perm: 'inv.post', variant: 'primary', when: IS_DRAFT,
        confirm: 'Posting GRN ini? Stok dan HPP rata-rata bergerak akan diperbarui dan dokumen tidak bisa diubah lagi.',
      },
      {
        // Jalan kembali UTUH untuk GRN yang salah diposting (audit T37) —
        // pasangan dari "Buat Retur" di bawah yang membalik sebagian, dan
        // bentuknya disamakan dengan "Batalkan Bon" pada pengeluaran barang:
        // membatalkan memposting pergerakan stok CERMIN dan jurnal PEMBALIK,
        // mengosongkan kliring GRN (tagihan vendor tidak bisa lagi menyapunya),
        // dan mengembalikan kuantitas PO lewat PoService::unregisterReceipt —
        // termasuk membuka kembali PO yang tertutup otomatis. GRN aslinya
        // tidak pernah disentuh.
        //
        // when() menyaring status dan retur terposting; penolakan yang tidak
        // terlihat dari baris (kliring sudah disapu tagihan, PO ditagih
        // pembebanan langsung, stok sebagian sudah keluar) dijawab server
        // dengan kalimat yang menyebut jalan keluarnya — retur pembelian
        // untuk sebagian, opname untuk penyusutan.
        key: 'cancel', label: 'Batalkan Penerimaan', path: '{id}/cancel', method: 'POST',
        perm: 'inv.post', variant: 'danger',
        when: (row) => row.status === 'posted'
          && !(row.items || []).some((line) => Number(line.qty_returned || 0) > 0),
        fields: [{
          key: 'reason', label: 'Alasan pembatalan', type: 'textarea', required: true,
          help: 'Tercatat permanen di dokumen dan jejak audit. Minimal 5 karakter.',
        }],
      },
      {
        // Retur pembelian (temuan 38): barang ditolak/berlebih kembali ke
        // vendor. Membuat DRAF berisi sisa yang bisa diretur dari GRN ini
        // (POST inventory/goods-receipts/{id}/returns, inv.create); memposting
        // draf itulah yang menggerakkan stok, membalik irisan kliring GRN
        // (tagihan vendor tidak bisa lagi menagih bagian yang diretur), dan
        // mengembalikan kuantitas ke PO — termasuk membuka kembali PO yang
        // tertutup otomatis. GRN stok awal (tanpa PO dan tanpa vendor) tidak
        // ditawarkan: tidak ada pihak yang bisa menerima retur, keluarkan
        // lewat opname.
        key: 'return', label: 'Buat Retur', path: '{id}/returns', method: 'POST',
        perm: 'inv.create', navigateTo: 'inventory/purchase-returns',
        when: (row) => row.status === 'posted' && !!(row.vendor_id || row.purchase_order_id),
        fields: [{
          key: 'reason', label: 'Alasan retur', type: 'textarea', required: true,
          help: 'Tercatat permanen di dokumen retur. Minimal 5 karakter.',
        }],
      },
    ],
  },

  'inventory/issues': {
    module: 'inv', api: 'inventory/issues', label: 'Pengeluaran Barang', labelOne: 'Pengeluaran Barang',
    columns: [
      codeColumn,
      { key: 'issue_date', label: 'Tanggal', type: 'date' },
      { key: 'warehouse.name', label: 'Gudang', type: 'text', sub: 'warehouse.code' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'purpose', label: 'Keperluan', type: 'text', truncate: 56 },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'stockDocStatus' },
      { key: 'warehouse_id', label: 'Gudang', lookup: 'warehouses' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Pengeluaran barang',
        help: 'Biaya per baris dinilai otomatis pada HPP rata-rata gudang saat posting.',
        fields: [
          { key: 'warehouse_id', label: 'Gudang asal', type: 'lookup', lookup: 'warehouses', required: true },
          { key: 'issue_date', label: 'Tanggal keluar', type: 'date', required: true, defaultToday: true },
          { key: 'project_id', label: 'Proyek tujuan', type: 'lookup', lookup: 'projects' },
          {
            // P1-ENG: bon yang menunjuk IPP mewarisi paket pekerjaannya
            // (IssueService) — kolom WBS di bawah boleh dikosongkan.
            key: 'ipp_id', label: 'IPP (ijin pelaksanaan)', type: 'lookup', lookup: 'approvedIpps',
            help: 'Hanya IPP disetujui pada proyek yang sama. Kosongkan bila pengeluaran memang di luar cakupan IPP — pada proyek yang punya IPP aktif, server meminta konfirmasi.',
          },
          { key: 'wbs_task_id', label: 'Paket pekerjaan (WBS)', type: 'lookup', lookup: 'wbsTasks', help: 'Kosongkan bila IPP dipilih — diwarisi dari IPP-nya.' },
          { key: 'purpose', label: 'Keperluan', type: 'textarea', required: true, span: 2 },
        ],
      }],
      /*
       * Peringatan pola confirm-resubmit (preseden GRN harga 0, temuan #72;
       * pola PriceDeviationService): bon TANPA IPP pada proyek yang MEMILIKI
       * IPP aktif ditolak 422 pada 'ipp_id' sampai payload membawa flag —
       * pesan dialognya pesan server apa adanya, yang menyebut nomor IPP
       * aktifnya. Peringatan, bukan blokir: material di luar ijin (konsumsi
       * site, pembersihan) sah, yang dituntut hanyalah pengakuan sadar.
       */
      confirmResubmit: {
        flag: 'confirm_without_ipp',
        test: /^ipp_id$/,
        title: 'Proyek ini punya IPP aktif — tetap keluarkan tanpa IPP?',
        confirmLabel: 'Ya, bon ini di luar cakupan IPP',
      },
      lines: [{
        key: 'items', label: 'Item dikeluarkan', min: 1,
        columns: [
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '45%' },
          // Per baris, bukan hanya di kepala: satu bon bisa melayani dua paket
          // sekaligus — ISS/2026/VII/0001 membawa 150 zak semen (C.1) DAN
          // 80 btg besi (B.3), dan tanpa kolom ini keduanya tertimpa satu nilai.
          { key: 'wbs_task_id', label: 'Paket WBS', type: 'lookup', lookup: 'wbsTasks', width: '35%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '20%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Item dikeluarkan',
        columns: [
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          // Bon terposting harus memperlihatkan paket yang dibebani — laporan
          // varian material membaca kolom inilah, baris demi baris.
          { key: 'wbs_task_id', label: 'Paket WBS', type: 'rel', lookup: 'wbsTasks' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          // Sudah kembali lewat retur terposting — pembaca bon perlu tahu
          // berapa yang masih di proyek sebelum menekan "Buat Retur".
          { key: 'qty_returned', label: 'Dikembalikan', type: 'qty', align: 'right' },
          { key: 'unit_cost', label: 'HPP satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Nilai', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [
      {
        key: 'post', label: 'Posting ke Stok', path: '{id}/post', method: 'POST',
        perm: 'inv.post', variant: 'primary', when: IS_DRAFT,
        confirm: 'Posting pengeluaran ini? Stok akan berkurang dan dokumen dikunci.',
      },
      {
        // Bon adalah satu-satunya dokumen stok yang mendarat di BIAYA PROYEK,
        // jadi salah proyek di sini bukan salah ketik: ISS/2026/VII/0001
        // mengeluarkan semen dan besi senilai Rp 18.740.000, dan bila
        // dibebankan ke proyek yang keliru, realisasi, CPI dan basis biaya
        // PSAK 115 kedua proyek salah selamanya. Endpoint pembatalannya sudah
        // hidup (POST inventory/issues/{id}/cancel, inv.post) — tanpa tombol
        // ini tidak ada satu pun jalan memanggilnya dari layar, dan layar tanpa
        // jalan panggil pernah ikut rilis di sini sekali. Bentuknya disamakan
        // persis dengan pembatalan AR/AP di bawah supaya keduanya tidak
        // berpencar: membatalkan memposting pergerakan stok CERMIN dan jurnal
        // PEMBALIK; bon aslinya tidak pernah disentuh.
        //
        // Bon dari pengesahan laporan lapangan (field_report_id terisi) sengaja
        // tidak ditawarkan — StockService::cancelIssue menolaknya: laporannya
        // yang harus dikoreksi, karena pengesahan dan keluarnya suku cadang
        // adalah satu peristiwa yang sama.
        key: 'cancel', label: 'Batalkan Bon', path: '{id}/cancel', method: 'POST',
        perm: 'inv.post', variant: 'danger',
        when: (row) => row.status === 'posted' && !row.field_report_id,
        fields: [{
          key: 'reason', label: 'Alasan pembatalan', type: 'textarea', required: true,
          help: 'Tercatat permanen di dokumen dan jejak audit. Minimal 5 karakter.',
        }],
      },
      {
        // Jalan kembali SEBAGIAN — pasangan dari "Batalkan Bon" yang membalik
        // utuh. Kasus audit temuan 37: 150 zak keluar, pekerjaan selesai, 30
        // kembali. Tanpa dokumen ini sisa itu dipaksa lewat GRN tanpa vendor
        // (kredit EKUITAS 3-3100) atau opname (kredit BEBAN 6-4400), dan biaya
        // proyeknya tidak pernah berkurang. Tombol ini membuat DRAF berisi
        // sisa yang bisa kembali (POST inventory/issues/{id}/returns) —
        // operator merapikan barisnya lalu memposting dari layar retur, maka
        // haknya inv.create; posting-nya sendiri tetap di inv.post.
        //
        // Bon laporan lapangan tidak ditawarkan — StockService menolaknya:
        // koreksi laporannya, karena pengesahan dan keluarnya suku cadang
        // adalah satu peristiwa yang sama (alasan yang sama dengan Batalkan).
        key: 'return', label: 'Buat Retur', path: '{id}/returns', method: 'POST',
        perm: 'inv.create', navigateTo: 'inventory/issue-returns',
        when: (row) => row.status === 'posted' && !row.field_report_id,
        fields: [{
          key: 'reason', label: 'Alasan retur', type: 'textarea', required: true,
          help: 'Tercatat permanen di dokumen retur. Minimal 5 karakter.',
        }],
      },
    ],
  },

  'inventory/issue-returns': {
    module: 'inv', api: 'inventory/issue-returns', label: 'Retur Material Proyek', labelOne: 'Retur Material',
    columns: [
      codeColumn,
      { key: 'return_date', label: 'Tanggal', type: 'date' },
      { key: 'warehouse.name', label: 'Gudang', type: 'text', sub: 'warehouse.code' },
      { key: 'issue.code', label: 'Bon asal', type: 'text' },
      { key: 'reason', label: 'Alasan', type: 'text', truncate: 56 },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'stockDocStatus' },
      { key: 'warehouse_id', label: 'Gudang', lookup: 'warehouses' },
    ],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Retur material dari proyek',
        help: 'Barang kembali pada HARGA KELUARNYA (harga beku baris bon), bukan rata-rata hari ini, '
          + 'dan biaya proyek berkurang sebesar irisan yang sama saat diposting.',
        fields: [
          { key: 'issue_id', label: 'Bon pengeluaran asal', type: 'lookup', lookup: 'issues', required: true },
          { key: 'return_date', label: 'Tanggal kembali', type: 'date', required: true, defaultToday: true },
          {
            key: 'reason', label: 'Alasan retur', type: 'textarea', required: true, span: 2,
            help: 'Tercatat permanen di dokumen. Minimal 5 karakter.',
          },
        ],
      }],
      lines: [{
        key: 'items', label: 'Item dikembalikan', min: 1,
        help: 'Gunakan "Salin baris dari bon" — baris retur harus menunjuk baris bon asalnya, '
          + 'karena baris itulah yang membawa harga keluar barangnya.',
        columns: [
          // Never typed, always copied: the reference that carries the issue price.
          { key: 'issue_item_id', type: 'hidden' },
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '70%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '30%' },
        ],
        prefill: {
          label: 'Salin baris dari bon',
          sourceField: 'issue_id',
          missingSource: 'Pilih bon pengeluaran asal dulu di bagian atas formulir.',
          emptyMessage: 'Seluruh baris bon ini sudah kembali lewat retur sebelumnya.',
          load: async (issueId, api) => {
            const bon = await api.get(`inventory/issues/${issueId}`);

            // Sisa yang bisa kembali, bukan qty bon: retur kedua tidak boleh
            // menawarkan lagi barang yang sudah kembali. Server tetap menolak
            // kumulatifnya — ini supaya operator tidak digiring ke 422.
            return (bon.items || [])
              .map((line) => ({
                issue_item_id: line.id,
                item_id: line.item_id,
                qty: Math.max(0, Number(line.qty || 0) - Number(line.qty_returned || 0)),
              }))
              .filter((line) => line.qty > 0);
          },
        },
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Item dikembalikan',
        columns: [
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'unit_cost', label: 'Harga keluar', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Nilai', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [{
      key: 'post', label: 'Posting Retur', path: '{id}/post', method: 'POST',
      perm: 'inv.post', variant: 'primary', when: IS_DRAFT,
      confirm: 'Posting retur ini? Stok kembali pada harga keluarnya, jurnal Dr Persediaan / Cr HPP '
        + 'diposting, biaya proyek berkurang, dan dokumen tidak bisa diubah lagi.',
    }],
  },

  'inventory/purchase-returns': {
    module: 'inv', api: 'inventory/purchase-returns', label: 'Retur Pembelian', labelOne: 'Retur Pembelian',
    columns: [
      codeColumn,
      { key: 'return_date', label: 'Tanggal', type: 'date' },
      { key: 'warehouse.name', label: 'Gudang', type: 'text', sub: 'warehouse.code' },
      { key: 'goods_receipt.code', label: 'GRN asal', type: 'text' },
      { key: 'vendor_id', label: 'Vendor', type: 'rel', lookup: 'vendors' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'stockDocStatus' },
      { key: 'vendor_id', label: 'Vendor', lookup: 'vendors' },
      { key: 'warehouse_id', label: 'Gudang', lookup: 'warehouses' },
    ],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Retur pembelian ke vendor',
        help: 'Saat diposting: stok keluar, irisan kewajiban vendor yang dicatat GRN dibalik (tagihan '
          + 'vendor tidak bisa lagi menagih bagian yang diretur), dan kolom "diterima" PO berkurang. '
          + 'Bagian yang sudah disapu tagihan vendor tidak bisa diretur — selesaikan lewat nota kredit di Keuangan.',
        fields: [
          { key: 'goods_receipt_id', label: 'Penerimaan (GRN) asal', type: 'lookup', lookup: 'goodsReceipts', required: true },
          { key: 'return_date', label: 'Tanggal retur', type: 'date', required: true, defaultToday: true },
          {
            key: 'reason', label: 'Alasan retur', type: 'textarea', required: true, span: 2,
            help: 'Tercatat permanen di dokumen. Minimal 5 karakter.',
          },
        ],
      }],
      lines: [{
        key: 'items', label: 'Item dikembalikan', min: 1,
        help: 'Gunakan "Salin baris dari GRN" — baris retur harus menunjuk baris penerimaan asalnya, '
          + 'karena baris itulah yang membawa harga terima barangnya.',
        columns: [
          // Never typed, always copied: the reference that carries the receipt price.
          { key: 'grn_item_id', type: 'hidden' },
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '70%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '30%' },
        ],
        prefill: {
          label: 'Salin baris dari GRN',
          sourceField: 'goods_receipt_id',
          missingSource: 'Pilih penerimaan (GRN) asal dulu di bagian atas formulir.',
          emptyMessage: 'Seluruh baris GRN ini sudah kembali ke vendor lewat retur sebelumnya.',
          load: async (grnId, api) => {
            const grn = await api.get(`inventory/goods-receipts/${grnId}`);

            // Sisa yang bisa diretur, bukan qty GRN: server menolak kumulatif
            // melebihi penerimaan — ini supaya operator tidak digiring ke 422.
            return (grn.items || [])
              .map((line) => ({
                grn_item_id: line.id,
                item_id: line.item_id,
                qty: Math.max(0, Number(line.qty || 0) - Number(line.qty_returned || 0)),
              }))
              .filter((line) => line.qty > 0);
          },
        },
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Item dikembalikan',
        columns: [
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'unit_cost', label: 'Harga terima', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Nilai', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [{
      key: 'post', label: 'Posting Retur', path: '{id}/post', method: 'POST',
      perm: 'inv.post', variant: 'primary', when: IS_DRAFT,
      confirm: 'Posting retur ini? Stok keluar, sisa tagihan vendor berkurang sebesar irisan retur, '
        + 'kolom "diterima" PO berkurang, dan dokumen tidak bisa diubah lagi.',
    }],
  },

  'inventory/transfers': {
    module: 'inv', api: 'inventory/transfers', label: 'Transfer Antar Gudang', labelOne: 'Transfer',
    columns: [
      codeColumn,
      { key: 'transfer_date', label: 'Tanggal', type: 'date' },
      { key: 'from_warehouse.name', label: 'Dari', type: 'text', sub: 'from_warehouse.code' },
      { key: 'to_warehouse.name', label: 'Ke', type: 'text', sub: 'to_warehouse.code' },
      statusColumn,
    ],
    filters: [{ key: 'status', label: 'Status', enum: 'transferStatus' }],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Transfer stok',
        fields: [
          { key: 'from_warehouse_id', label: 'Gudang asal', type: 'lookup', lookup: 'warehouses', required: true },
          { key: 'to_warehouse_id', label: 'Gudang tujuan', type: 'lookup', lookup: 'warehouses', required: true },
          { key: 'transfer_date', label: 'Tanggal transfer', type: 'date', required: true, defaultToday: true },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Item ditransfer', min: 1,
        columns: [
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '70%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '30%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Item ditransfer',
        columns: [
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'unit_cost', label: 'HPP satuan', type: 'currency', align: 'right' },
        ],
      }],
    },
    actions: [
      {
        key: 'send', label: 'Kirim', path: '{id}/send', method: 'POST',
        perm: 'inv.post', variant: 'primary', when: IS_DRAFT,
        confirm: 'Kirim transfer ini? Stok keluar dari gudang asal pada HPP saat ini.',
      },
      {
        key: 'receive', label: 'Terima', path: '{id}/receive', method: 'POST',
        perm: 'inv.post', variant: 'success', when: (row) => row.status === 'in_transit',
        confirm: 'Terima transfer ini di gudang tujuan?',
      },
    ],
  },

  'inventory/stock-adjustments': {
    module: 'inv', api: 'inventory/stock-adjustments', label: 'Penyesuaian Stok (Opname)', labelOne: 'Penyesuaian Stok',
    columns: [
      codeColumn,
      { key: 'adjustment_date', label: 'Tanggal', type: 'date' },
      { key: 'warehouse.name', label: 'Gudang', type: 'text', sub: 'warehouse.code' },
      { key: 'reason', label: 'Alasan', type: 'enum', enum: 'adjustmentReason' },
      { key: 'posted_at', label: 'Diposting', type: 'datetime' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'warehouse_id', label: 'Gudang', lookup: 'warehouses' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Penyesuaian stok',
        help: 'Sistem menyimpan qty sistem saat ini sebagai pembanding hasil hitung fisik.',
        fields: [
          { key: 'warehouse_id', label: 'Gudang', type: 'lookup', lookup: 'warehouses', required: true },
          { key: 'adjustment_date', label: 'Tanggal opname', type: 'date', required: true, defaultToday: true },
          { key: 'reason', label: 'Alasan', type: 'select', enum: 'adjustmentReason', required: true },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Hasil hitung fisik', min: 1,
        columns: [
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '70%' },
          { key: 'counted_qty', label: 'Qty terhitung', type: 'qty', required: true, width: '30%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'items', label: 'Hasil opname',
        columns: [
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          { key: 'system_qty', label: 'Qty sistem', type: 'qty', align: 'right' },
          { key: 'counted_qty', label: 'Qty fisik', type: 'qty', align: 'right' },
          { key: 'diff_qty', label: 'Selisih', type: 'qty', align: 'right', signed: true },
          { key: 'unit_cost', label: 'HPP satuan', type: 'currency', align: 'right' },
        ],
      }],
    },
    actions: approvalActions('inv'),
  },

  /* ====================================================== SUBCONTRACT === */
  'subcontract/subcontracts': {
    module: 'scm', api: 'subcontract/subcontracts', label: 'SPK Subkontraktor', labelOne: 'SPK',
    lookupSource: 'subcontracts', customDetail: 'subcontract',
    columns: [
      codeColumn,
      { key: 'title', label: 'Pekerjaan', type: 'text', sub: 'vendor.name' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'value', label: 'Nilai SPK', type: 'currency', align: 'right' },
      { key: 'pph_rate', label: 'PPh final', type: 'percent', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'vendor_id', label: 'Subkontraktor', lookup: 'subcontractors' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Surat Perintah Kerja',
        help: 'PPN mengikuti status PKP vendor; tarif PPh final PP 9/2022 di-snapshot dari skema yang dipilih.',
        fields: [
          { key: 'vendor_id', label: 'Subkontraktor', type: 'lookup', lookup: 'subcontractors', required: true },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true },
          { key: 'title', label: 'Judul pekerjaan', type: 'text', required: true, span: 2 },
          { key: 'pph_scheme', label: 'Skema PPh final konstruksi', type: 'select', enum: 'pphScheme', required: true, span: 2 },
          { key: 'retention_pct', label: 'Retensi (%)', type: 'percent', default: 5 },
          { key: 'start_date', label: 'Mulai', type: 'date', required: true },
          { key: 'end_date', label: 'Selesai', type: 'date' },
          {
            // Temuan #75: tanggal yang dibaca gate waktu pelepasan retensi.
            key: 'defect_liability_until', label: 'Masa pemeliharaan s/d', type: 'date',
            help: 'Retensi hanya dapat dilepas setelah tanggal ini (atau dengan alasan override).',
          },
          { key: 'scope', label: 'Lingkup pekerjaan', type: 'textarea', span: 2 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
          { key: 'qualification_override_reason', label: 'Alasan override prakualifikasi', type: 'textarea', span: 2, help: 'Isi hanya bila subkon terblokir prakualifikasi (nonaktif / dokumen wajib kedaluwarsa) dan SPK tetap harus dibuat.' },
        ],
      }],
      lines: [{
        key: 'items', label: 'Rincian pekerjaan', min: 1,
        columns: [
          { key: 'wbs_code', label: 'Kode WBS', type: 'text', width: '12%' },
          { key: 'description', label: 'Uraian', type: 'text', width: '34%' },
          { key: 'qty', label: 'Volume', type: 'qty', width: '14%' },
          { key: 'unit', label: 'Satuan', type: 'text', width: '12%' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', required: true, width: '20%' },
        ],
        total: (row) => Number(row.qty || 0) * Number(row.unit_price || 0),
      }],
    },
    actions: [...approvalActions('scm').map((action) => (action.key !== 'submit' ? action : {
      ...action,
      /*
       * Cermin sisi PO: tanpa modal field ini, SPK yang subkonnya menjadi
       * nonaktif (atau SBU-nya kedaluwarsa) di antara draf dan pengajuan
       * TIDAK PERNAH bisa diajukan dari SPA — 422 gate prakualifikasi tidak
       * membawa kunci `errors`, jadi alur confirm-resubmit tidak bisa
       * menyambarnya, dan alasan override hanya dibaca server dari payload
       * submit. Kosongkan bila subkonnya sehat.
       */
      fields: [{
        key: 'qualification_override_reason', label: 'Alasan override prakualifikasi',
        type: 'textarea',
        help: 'Kosongkan bila subkon sehat. Isi hanya bila pengajuan ditolak gate prakualifikasi dan tetap harus jalan.',
      }],
      /*
       * Gate anggaran #33 (kunci galat `budget`, pola confirm-resubmit temuan
       * #72): pengajuan SPK yang melampaui sisa RAP subkon ditolak 422 sampai
       * pengaju mengonfirmasi — actions.js mengulang panggilan dengan
       * confirm_over_budget. Pesan dialog = pesan server yang menyebut
       * anggaran, realisasi, komitmen, dokumen ini, dan pelampauannya.
       */
      confirmResubmit: [{
        flag: 'confirm_over_budget',
        test: /^budget$/,
        title: 'Melampaui sisa anggaran RAP subkon — tetap ajukan?',
        confirmLabel: 'Ya, tetap ajukan',
      }],
    })), {
      /*
       * Temuan #75 (susulan): tanggal masa pemeliharaan baru diketahui setelah
       * SPK disetujui, sedangkan form Ubah tertutup begitu status bergerak —
       * pintu ini mengisi SATU tanggal itu pada SPK submitted/approved; server
       * menolak begitu retensi pernah dilepas.
       */
      key: 'defect-liability', label: 'Catat masa pemeliharaan', path: '{id}/defect-liability', method: 'PUT',
      perm: 'scm.update', when: (row) => ['submitted', 'approved'].includes(row.status),
      fields: [{ key: 'defect_liability_until', label: 'Masa pemeliharaan s/d', type: 'date', required: true, help: 'Gate pelepasan retensi memakai tanggal ini.' }],
    }],
  },

  'subcontract/addenda': {
    module: 'scm', api: 'subcontract/addenda', label: 'Addendum SPK', labelOne: 'Addendum SPK',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text', sub: 'subcontract.code' },
      { key: 'addendum_date', label: 'Tanggal', type: 'date' },
      // Pembeda jejak audit, hadir sejak hari pertama (Crm harus menambalnya
      // belakangan — temuan #61): eskalasi harga bukan pekerjaan tambah.
      // spkAddendumType, bukan ccoChangeType: addendum SPK tidak punya jenis
      // 'waktu' (P0-B) dan servernya menolaknya dengan 422.
      { key: 'change_type', label: 'Jenis', type: 'enum', enum: 'spkAddendumType' },
      // Signed: negatif adalah pekerjaan kurang.
      { key: 'value_change', label: 'Perubahan nilai', type: 'currency' },
      { key: 'status', label: 'Status', type: 'enum', enum: 'documentStatus' },
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'change_type', label: 'Jenis', enum: 'spkAddendumType' },
      { key: 'subcontract_id', label: 'SPK', lookup: 'subcontracts' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Addendum SPK',
        help: 'Pekerjaan tambah masuk sebagai BARIS BARU — baris lama tidak pernah diubah, opnamenya terlanjur dihitung dari nilai lama. Pekerjaan kurang cukup nilai negatif tanpa baris.',
        fields: [
          { key: 'subcontract_id', label: 'SPK', type: 'lookup', lookup: 'subcontracts', required: true, createOnly: true },
          { key: 'addendum_date', label: 'Tanggal', type: 'date', required: true },
          { key: 'title', label: 'Judul', type: 'text', required: true, span: 2 },
          {
            key: 'change_type', label: 'Jenis perubahan', type: 'select', enum: 'spkAddendumType', default: 'tambah_kurang',
            help: 'Pilih "Eskalasi Harga" untuk penyesuaian harga — nilainya dihitung di luar dan masuk lewat perubahan nilai.',
          },
          {
            key: 'value_change', label: 'Perubahan nilai', type: 'currency', required: true,
            help: 'Positif untuk pekerjaan tambah (wajib membawa baris), negatif untuk pekerjaan kurang.',
          },
          {
            key: 'reason', label: 'Sebab', type: 'select',
            options: [
              { value: 'permintaan_pemberi_kerja', label: 'Permintaan pemberi kerja' },
              { value: 'kondisi_lapangan', label: 'Kondisi lapangan' },
              { value: 'desain', label: 'Perubahan desain' },
              { value: 'lainnya', label: 'Lainnya' },
            ],
          },
          { key: 'description', label: 'Uraian', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Baris pekerjaan tambahan (total harus sama dengan perubahan nilai)',
        columns: [
          { key: 'wbs_code', label: 'Kode WBS', type: 'text', width: '12%' },
          { key: 'description', label: 'Uraian', type: 'text', width: '34%' },
          { key: 'qty', label: 'Volume', type: 'qty', width: '14%' },
          { key: 'unit', label: 'Satuan', type: 'text', width: '12%' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', required: true, width: '20%' },
        ],
        total: (row) => Number(row.qty || 0) * Number(row.unit_price || 0),
      }],
    },
    detail: {
      summary: ['value_change'],
      tables: [{
        key: 'items', label: 'Baris pekerjaan tambahan',
        columns: [
          { key: 'wbs_code', label: 'Kode WBS' },
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Volume', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'unit_price', label: 'Harga satuan', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Nilai', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: approvalActions('scm'),
  },

  /* P3 — BAST subkon I/II. Dua prasyarat keras ditegakkan HandoverService saat
     APPROVE (opname terakhir sudah disetujui; untuk BAST I retensi belum dilepas),
     jadi layar ini tidak menyalin aturannya — ia hanya menyediakan pintunya. */
  'subcontract/handovers': {
    module: 'scm', api: 'subcontract/handovers', label: 'BAST Subkon', labelOne: 'BAST Subkontraktor',
    columns: [
      codeColumn,
      { key: 'subcontract_code', label: 'SPK', type: 'code', sub: 'subcontract_title' },
      { key: 'handover_type', label: 'Jenis', type: 'enum', enum: 'handoverType' },
      { key: 'handover_date', label: 'Tanggal', type: 'date' },
      { key: 'retention_release_due', label: 'Retensi jatuh tempo', type: 'date', hideOnNarrow: true },
      statusColumn,
    ],
    filters: [
      { key: 'subcontract_id', label: 'SPK', lookup: 'subcontracts' },
      { key: 'handover_type', label: 'Jenis', enum: 'handoverType' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Berita Acara Serah Terima Subkontraktor',
        help: 'BAST I memulai masa pemeliharaan; BAST II mengakhirinya. Persetujuan ditolak bila masih ada '
          + 'opname yang belum disetujui, dan — untuk BAST I — bila retensinya sudah terlanjur dilepas.',
        fields: [
          { key: 'subcontract_id', label: 'SPK', type: 'lookup', lookup: 'subcontracts', required: true, createOnly: true },
          { key: 'handover_type', label: 'Jenis serah terima', type: 'select', enum: 'handoverType', required: true },
          { key: 'handover_date', label: 'Tanggal serah terima', type: 'date', required: true },
          { key: 'retention_release_due', label: 'Retensi dapat dilepas mulai', type: 'date', help: 'Kosongkan: BAST I menyalinnya dari akhir masa pemeliharaan SPK, dan membiarkannya kosong bila SPK belum mencatatnya.' },
          { key: 'handed_over_by', label: 'Menyerahkan (wakil subkon)', type: 'text' },
          { key: 'received_by', label: 'Menerima (wakil kami)', type: 'text' },
          { key: 'scope_notes', label: 'Lingkup yang diserahterimakan', type: 'textarea', span: 2 },
        ],
      }],
    },
    actions: approvalActions('scm'),
  },

  'subcontract/progress-claims': {
    module: 'scm', api: 'subcontract/progress-claims', label: 'Opname Subkon', labelOne: 'Opname',
    lookupSource: 'progressClaims',
    columns: [
      codeColumn,
      { key: 'subcontract.code', label: 'SPK', type: 'code', sub: 'subcontract.title' },
      { key: 'claim_no', label: 'Opname ke-', type: 'number', align: 'center' },
      // Klaim uang muka (DP) duduk di daftar yang sama dengan opname biasa —
      // tanpa penanda, DP 40 juta terbaca sebagai opname pekerjaan.
      { key: 'is_advance', label: 'UM', type: 'bool', align: 'center' },
      { key: 'period_end', label: 'Periode s/d', type: 'date' },
      { key: 'gross_amount', label: 'Bruto', type: 'currency', align: 'right' },
      { key: 'net_payable', label: 'Netto dibayar', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'subcontract_id', label: 'SPK', lookup: 'subcontracts' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Opname progress',
        help: 'Isi progres kumulatif per baris SPK. Nilai periode dihitung dari selisih terhadap progres sebelumnya.',
        fields: [
          { key: 'subcontract_id', label: 'SPK', type: 'lookup', lookup: 'subcontracts', required: true, createOnly: true },
          { key: 'period_start', label: 'Periode mulai', type: 'date', required: true },
          { key: 'period_end', label: 'Periode selesai', type: 'date', required: true },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Progres per baris pekerjaan', min: 1,
        columns: [
          { key: 'subcontract_item_id', label: 'ID baris SPK', type: 'number', required: true, width: '50%' },
          { key: 'current_progress_pct', label: 'Progres kumulatif (%)', type: 'percent', required: true, width: '50%' },
        ],
      }],
    },
    detail: {
      summary: ['gross_amount', 'retention_amount', 'net_before_tax', 'ppn_amount', 'pph_amount', 'advance_recovery_amount', 'net_payable'],
      tables: [{
        key: 'items', label: 'Rincian progres',
        columns: [
          { key: 'subcontract_item.description', label: 'Uraian' },
          { key: 'prev_progress_pct', label: 'Progres lalu', type: 'percent', align: 'right' },
          { key: 'current_progress_pct', label: 'Progres kini', type: 'percent', align: 'right' },
          { key: 'period_progress_pct', label: 'Periode ini', type: 'percent', align: 'right' },
          { key: 'amount', label: 'Nilai', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: approvalActions('scm'),
  },

  /* P4 — SP3 Induk: SPK mandor upah borongan. Baris = boq_item x tarif upah
     x qty; plafon klaim per baris adalah qty-nya (volume, bukan persen). */
  'subcontract/labor-contracts': {
    module: 'scm', api: 'subcontract/labor-contracts', label: 'SP3 Mandor', labelOne: 'SP3',
    lookupSource: 'laborContracts',
    columns: [
      codeColumn,
      { key: 'title', label: 'Pekerjaan', type: 'text', sub: 'vendor.name' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'value', label: 'Nilai SP3', type: 'currency', align: 'right' },
      { key: 'pph_rate', label: 'PPh final', type: 'percent', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'vendor_id', label: 'Mandor', lookup: 'mandorVendors' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'SP3 Induk (SPK mandor)',
        help: 'Vendor harus bertipe mandor. Tarif PPh final UMKM (PP 55/2022) di-snapshot saat dibuat; skema PPh 21 TER belum diaktifkan.',
        fields: [
          { key: 'vendor_id', label: 'Mandor', type: 'lookup', lookup: 'mandorVendors', required: true },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true },
          { key: 'title', label: 'Judul pekerjaan', type: 'text', required: true, span: 2 },
          { key: 'pph_scheme', label: 'Skema PPh upah', type: 'select', enum: 'laborPphScheme', required: true, span: 2 },
          { key: 'start_date', label: 'Mulai', type: 'date' },
          { key: 'end_date', label: 'Selesai', type: 'date' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
          { key: 'qualification_override_reason', label: 'Alasan override prakualifikasi', type: 'textarea', span: 2, help: 'Isi hanya bila mandor terblokir prakualifikasi (nonaktif / K3L / pakta integritas) dan SP3 tetap harus dibuat.' },
        ],
      }],
      lines: [{
        key: 'items', label: 'Baris upah borongan', min: 1,
        columns: [
          { key: 'boq_item_id', label: 'ID baris BOQ', type: 'number', width: '15%' },
          { key: 'description', label: 'Uraian pekerjaan', type: 'text', width: '35%' },
          { key: 'qty', label: 'Volume', type: 'number', required: true, width: '15%' },
          { key: 'unit', label: 'Satuan', type: 'text', width: '10%' },
          { key: 'unit_rate', label: 'Tarif upah', type: 'currency', required: true, width: '25%' },
        ],
      }],
    },
    detail: {
      summary: ['value', 'ppn_rate', 'pph_rate'],
      tables: [{
        key: 'items', label: 'Baris upah',
        columns: [
          { key: 'line_no', label: 'No', align: 'center' },
          /* Kolom ID penting — angka inilah yang diketik ke kolom "ID baris
             SP3" formulir opname mandor (pola kartu Rincian pekerjaan SPK). */
          { key: 'id', label: 'ID', align: 'center' },
          { key: 'description', label: 'Uraian' },
          { key: 'qty', label: 'Volume', type: 'qty', align: 'right' },
          { key: 'unit', label: 'Satuan' },
          { key: 'unit_rate', label: 'Tarif upah', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    /*
     * Cermin SPK subkon: gate prakualifikasi berjalan ulang saat AJUKAN
     * (LaborContractController::submit, atas data hidup), dan tanpa field ini
     * SP3 yang mandornya menjadi nonaktif (atau K3L/paktanya kedaluwarsa) di
     * antara draf dan pengajuan TIDAK PERNAH bisa diajukan dari SPA — alasan
     * override hanya dibaca server dari payload submit. Kosongkan bila
     * mandornya sehat.
     */
    actions: approvalActions('scm').map((action) => (action.key !== 'submit' ? action : {
      ...action,
      fields: [{
        key: 'qualification_override_reason', label: 'Alasan override prakualifikasi',
        type: 'textarea',
        help: 'Kosongkan bila mandor sehat. Isi hanya bila pengajuan ditolak gate prakualifikasi dan tetap harus jalan.',
      }],
    })),
  },

  /* P4 — Opname mandor: volume per baris SP3 per periode; potongan kasbon
     tercatat di sini dan menjadi fakta akuntansi saat tagihan AP-nya
     disetujui (kredit 1-1370 + offset pada kasbonnya). */
  'subcontract/labor-claims': {
    module: 'scm', api: 'subcontract/labor-claims', label: 'Opname Mandor', labelOne: 'Opname mandor',
    columns: [
      codeColumn,
      { key: 'labor_contract.code', label: 'SP3', type: 'code', sub: 'labor_contract.title' },
      { key: 'claim_no', label: 'Opname ke-', type: 'number', align: 'center' },
      { key: 'period_end', label: 'Periode s/d', type: 'date' },
      { key: 'gross_amount', label: 'Bruto upah', type: 'currency', align: 'right' },
      /* Aturan kejujuran: potongan menyebut KODE kasbonnya, bukan hanya angka. */
      { key: 'kasbon_deduction_amount', label: 'Potongan kasbon', type: 'currency', align: 'right', sub: 'kasbon.code' },
      { key: 'net_payable', label: 'Netto dibayar', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'labor_contract_id', label: 'SP3', lookup: 'laborContracts' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Opname mandor',
        help: 'Volume periode ini per baris SP3; tidak boleh melebihi sisa (qty kontrak dikurangi yang sudah di-opname approved). Potongan kasbon tidak boleh melebihi sisa kasbon maupun upah yang terbayarkan.',
        fields: [
          { key: 'labor_contract_id', label: 'SP3', type: 'lookup', lookup: 'laborContracts', required: true, createOnly: true },
          { key: 'period_start', label: 'Periode mulai', type: 'date', required: true },
          { key: 'period_end', label: 'Periode selesai', type: 'date', required: true },
          { key: 'kasbon_id', label: 'ID kasbon dipotong', type: 'number', help: 'Kasbon berstatus cair milik proyek SP3 ini.' },
          { key: 'kasbon_deduction_amount', label: 'Potongan kasbon', type: 'currency' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'items', label: 'Volume per baris SP3', min: 1,
        columns: [
          { key: 'labor_contract_item_id', label: 'ID baris SP3', type: 'number', required: true, width: '50%' },
          { key: 'qty_this', label: 'Volume periode ini', type: 'number', required: true, width: '50%' },
        ],
      }],
    },
    detail: {
      summary: ['gross_amount', 'ppn_amount', 'pph_amount', 'kasbon_deduction_amount', 'net_payable'],
      tables: [{
        key: 'items', label: 'Rincian volume',
        columns: [
          { key: 'labor_contract_item.description', label: 'Uraian' },
          { key: 'qty_prev', label: 'S/d lalu', type: 'qty', align: 'right' },
          { key: 'qty_this', label: 'Periode ini', type: 'qty', align: 'right' },
          { key: 'labor_contract_item.unit_rate', label: 'Tarif upah', type: 'currency', align: 'right' },
          { key: 'amount', label: 'Nilai', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: approvalActions('scm'),
  },

  /* ========================================================== FINANCE === */
  'finance/revenue-recognition': {
    module: 'fin', api: 'finance/revenue-recognition', label: 'Pengakuan Pendapatan (PSAK 115)', labelOne: 'Run PSAK 115',
    customDetail: 'revenueRun',
    columns: [
      codeColumn,
      { key: 'period_year', label: 'Periode', type: 'period' },
      { key: 'lines_count', label: 'Kontrak', type: 'number', align: 'right' },
      { key: 'total_adjustment', label: 'Penyesuaian run', type: 'currency', align: 'right' },
      { key: 'posted_at', label: 'Diposting', type: 'datetime' },
      statusColumn,
    ],
    editableWhen: () => false,
    deletableWhen: (row) => row.status === 'draft',
    deleteConfirm: 'Hapus run draf ini? Perhitungan dapat dibuat ulang kapan saja.',
    form: {
      sections: [{
        title: 'Hitung pengakuan pendapatan',
        fields: [
          { key: 'period_year', label: 'Tahun', type: 'number', required: true, default: new Date().getFullYear() },
          {
            key: 'period_month', label: 'Bulan', type: 'number', required: true,
            default: new Date().getMonth() + 1,
            help: 'Persentase penyelesaian dihitung per akhir bulan ini. Kebijakan lengkap: docs/KEBIJAKAN-PENDAPATAN.md.',
          },
        ],
      }],
    },
  },

  'finance/accounts': {
    module: 'fin', api: 'finance/accounts', label: 'Bagan Akun (COA)', labelOne: 'Akun',
    lookupSource: 'accounts', noDetail: true, perPage: 100,
    columns: [
      { key: 'code', label: 'Kode', type: 'code', width: '1%' },
      { key: 'name', label: 'Nama akun', type: 'text', indentBy: 'code' },
      { key: 'account_type', label: 'Tipe', type: 'enum', enum: 'accountType' },
      { key: 'normal_balance', label: 'Saldo normal', type: 'enum', enum: 'normalBalance' },
      { key: 'is_postable', label: 'Dapat diposting', type: 'bool', align: 'center' },
      { key: 'is_active', label: 'Aktif', type: 'bool', align: 'center' },
    ],
    filters: [
      { key: 'account_type', label: 'Tipe akun', enum: 'accountType' },
      { key: 'is_postable', label: 'Dapat diposting', type: 'boolFilter' },
    ],
    form: {
      sections: [{
        title: 'Akun',
        fields: [
          { key: 'code', label: 'Kode akun', type: 'text', required: true, help: 'mis. 1-1210' },
          { key: 'name', label: 'Nama akun', type: 'text', required: true },
          { key: 'account_type', label: 'Tipe akun', type: 'select', enum: 'accountType', required: true },
          { key: 'normal_balance', label: 'Saldo normal', type: 'select', enum: 'normalBalance', required: true },
          { key: 'parent_id', label: 'Akun induk', type: 'lookup', lookup: 'accounts' },
          { key: 'is_postable', label: 'Dapat diposting', type: 'bool', default: true, help: 'Akun grup tidak dapat menerima jurnal.' },
          { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
        ],
      }],
    },
  },

  'finance/taxes': {
    module: 'fin', api: 'finance/taxes', label: 'Pajak', labelOne: 'Pajak',
    lookupSource: 'taxes', noDetail: true,
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama pajak', type: 'text' },
      { key: 'tax_type', label: 'Jenis', type: 'enum', enum: 'taxType' },
      { key: 'rate', label: 'Tarif', type: 'percent', align: 'right' },
      { key: 'object_code', label: 'Kode objek', type: 'code' },
      { key: 'coa_account.code', label: 'Akun COA', type: 'code', sub: 'coa_account.name' },
    ],
    filters: [{ key: 'tax_type', label: 'Jenis', enum: 'taxType' }],
    form: {
      sections: [{
        title: 'Pajak',
        fields: [
          { key: 'code', label: 'Kode', type: 'text', required: true },
          { key: 'name', label: 'Nama pajak', type: 'text', required: true },
          { key: 'tax_type', label: 'Jenis', type: 'select', enum: 'taxType', required: true },
          { key: 'rate', label: 'Tarif (%)', type: 'percent', required: true },
          { key: 'object_code', label: 'Kode objek pajak', type: 'text', help: 'Dipakai pada bukti potong e-Bupot. Salin dari daftar kode objek pajak DJP yang berlaku — kodenya berbeda per skema dan sesekali direvisi, jadi jangan disalin antar jenis pajak. Kosongkan bila pajak ini tidak dilaporkan lewat e-Bupot.' },
          { key: 'coa_account_id', label: 'Akun COA', type: 'lookup', lookup: 'postableAccounts' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  'finance/journals': {
    module: 'fin', api: 'finance/journals', label: 'Jurnal', labelOne: 'Jurnal',
    columns: [
      codeColumn,
      { key: 'journal_date', label: 'Tanggal', type: 'date' },
      { key: 'description', label: 'Keterangan', type: 'text', truncate: 70 },
      { key: 'reference_type', label: 'Referensi', type: 'text' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'postingStatus' },
      { key: 'date_from', label: 'Dari', type: 'date' },
      { key: 'date_to', label: 'Sampai', type: 'date' },
    ],
    editableWhen: IS_DRAFT,
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Voucher jurnal',
        help: 'Minimal dua baris; total debit harus sama dengan total kredit.',
        fields: [
          { key: 'journal_date', label: 'Tanggal jurnal', type: 'date', required: true, defaultToday: true },
          { key: 'description', label: 'Keterangan', type: 'text', required: true },
          { key: 'reference_type', label: 'Tipe referensi', type: 'text' },
          { key: 'reference_id', label: 'ID referensi', type: 'number' },
        ],
      }],
      lines: [{
        key: 'lines', label: 'Baris jurnal', min: 2,
        columns: [
          { key: 'account_id', label: 'Akun', type: 'lookup', lookup: 'postableAccounts', required: true, width: '30%' },
          { key: 'description', label: 'Keterangan', type: 'text', width: '26%' },
          { key: 'debit', label: 'Debit', type: 'currency', width: '18%' },
          { key: 'credit', label: 'Kredit', type: 'currency', width: '18%' },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', width: '18%' },
        ],
        balance: { debit: 'debit', credit: 'credit' },
      }],
    },
    detail: {
      tables: [{
        key: 'lines', label: 'Baris jurnal',
        columns: [
          { key: 'account.code', label: 'Kode', type: 'code' },
          { key: 'account.name', label: 'Akun' },
          { key: 'description', label: 'Keterangan' },
          { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
          { key: 'debit', label: 'Debit', type: 'currency', align: 'right' },
          { key: 'credit', label: 'Kredit', type: 'currency', align: 'right' },
        ],
        totals: ['debit', 'credit'],
      }],
    },
    actions: [{
      key: 'post', label: 'Posting Jurnal', path: '{id}/post', method: 'POST',
      // fin.approve, bukan fin.post: JV manual adalah satu-satunya jalur
      // pengeluaran tanpa dokumen, jadi postingnya kini butuh pemeriksa kedua —
      // route API-nya sudah menuntut itu, tombolnya harus setuju.
      perm: 'fin.approve', variant: 'primary', when: IS_DRAFT,
      confirm: 'Posting jurnal ini ke buku besar? Jurnal terposting tidak dapat diubah.',
    }],
  },

  'finance/ar-invoices': {
    module: 'fin', api: 'finance/ar-invoices', label: 'Invoice Termin (AR)', labelOne: 'Invoice',
    // The printed invoice is what the customer actually receives; the screen is
    // only where it is prepared.
    printable: { path: 'core/print/ar-invoices/{id}', prefix: 'invoice' },
    columns: [
      codeColumn,
      { key: 'customer.name', label: 'Pelanggan', type: 'text', sub: 'contract.code' },
      { key: 'invoice_date', label: 'Tgl invoice', type: 'date', hideOnNarrow: true },
      { key: 'due_date', label: 'Jatuh tempo', type: 'date', hideOnNarrow: true },
      { key: 'total', label: 'Total', type: 'currency', align: 'right' },
      { key: 'outstanding', label: 'Sisa', type: 'currency', align: 'right', toneZero: 'green' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'customer_id', label: 'Pelanggan', lookup: 'customers' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      /*
       * Temuan #32: server menolak (422 pada termin_id) penagihan termin yang
       * milestone syaratnya belum tercapai, sampai payload membawa
       * confirm_unachieved_milestone. Dialog menampilkan pesan server apa
       * adanya (pesan itulah yang menyebut nama milestone-nya) lalu mengirim
       * ulang dengan flag ini — dan invoice yang dikonfirmasi mencatat
       * penyimpangannya permanen di uraiannya.
       */
      confirmResubmit: {
        flag: 'confirm_unachieved_milestone',
        test: /^termin_id$/,
        title: 'Milestone syarat termin belum tercapai — tetap tagih?',
        confirmLabel: 'Ya, tetap tagih (tercatat)',
      },
      sections: [{
        title: 'Invoice penagihan',
        help: 'Tiga pintu, pilih SATU. Isi "Opname ke pemilik" untuk menagih volume yang sudah diukur dan '
          + 'ditandatangani (P3) — DPP-nya nilai periode opname itu, dan potongan uang muka dihitung server. '
          + 'Isi "Termin kontrak" untuk menagih satu termin jadwal. Kosongkan keduanya untuk invoice manual.',
        fields: [
          /* P3 — pintu klaim owner. Server menolak opname yang belum disetujui,
             opname yang sudah pernah ditagihkan, dan — kriteria #6 — opname yang
             menyentuh zona ber-BAPP "Nunggu perbaikan", dengan 422 yang menyebut
             zonanya. Layar ini tidak menyalin satu pun aturan itu. */
          {
            key: 'measurement_id', label: 'Opname ke pemilik (OPN)', type: 'lookup', lookup: 'approvedMeasurements', createOnly: true,
            help: 'Hanya opname berstatus Disetujui. DPP, potongan uang muka dan uraian invoice diambil dari opname itu — '
              + 'jangan isi Pelanggan/Kontrak/DPP bersamaan dengan ini.',
          },
          { key: 'termin_id', label: 'Termin kontrak (ID)', type: 'number', createOnly: true },
          {
            key: 'withhold_retention', label: 'Tahan retensi sesuai kontrak', type: 'bool', createOnly: true,
            // Temuan #73 — dua pola retensi: potongan per invoice (checkbox ini)
            // vs termin "Retensi 5%" di jadwal. Satu kontrak hanya boleh satu pola.
            help: 'Hanya untuk kontrak TANPA termin retensi di jadwalnya. Kontrak yang memuat termin "Retensi" menagih retensinya lewat termin itu — memotong di sini dobel, dan server menolaknya.',
          },
          { key: 'customer_id', label: 'Pelanggan', type: 'lookup', lookup: 'customers' },
          { key: 'contract_id', label: 'Kontrak', type: 'lookup', lookup: 'contracts' },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'description', label: 'Keterangan', type: 'text', span: 2 },
          { key: 'dpp', label: 'DPP', type: 'currency' },
          { key: 'ppn_rate', label: 'Tarif PPN (%)', type: 'percent', default: 11 },
          { key: 'retention_withheld', label: 'Retensi ditahan', type: 'currency' },
          /* P3 — DENDA. Nilainya manual dan tidak dihitung dari klausul mana
             pun: tidak ada rumus liquidated damages di sistem ini, jadi ALASAN
             adalah satu-satunya bukti yang dimiliki angkanya. Server menolak
             denda tanpa alasan (422 pada penalty_reason). */
          {
            key: 'penalty_amount', label: 'Denda / potongan', type: 'currency',
            help: 'Kosong atau 0 bila tidak ada. Diisi manual — tidak dihitung dari klausul kontrak.',
          },
          {
            key: 'penalty_reason', label: 'Alasan denda', type: 'text', span: 2,
            help: 'WAJIB bila denda > 0. Sebutkan dasar pemotongannya (keterlambatan, pekerjaan tidak sesuai, '
              + 'atau kesepakatan lain) — kalimat inilah satu-satunya bukti angka itu.',
          },
          /* P3 — uang muka owner. Invoice DP tidak menahan retensi (uang muka
             belum membeli pekerjaan apa pun) dan menjadi dasar potongan
             proporsional pada setiap klaim opname berikutnya. */
          {
            key: 'is_advance', label: 'Invoice uang muka (DP)', type: 'bool', createOnly: true,
            help: 'Hanya untuk invoice manual. Tidak menahan retensi, dan dipotong kembali secara proporsional '
              + 'dari klaim opname berikutnya.',
          },
          { key: 'invoice_date', label: 'Tanggal invoice', type: 'date', defaultToday: true },
          { key: 'due_date', label: 'Jatuh tempo', type: 'date' },
        ],
      }],
    },
    detail: {
      summary: ['dpp', 'ppn_amount', 'retention_withheld', 'advance_recovery_amount', 'penalty_amount',
        'total', 'amount_paid', 'outstanding'],
    },
    actions: [
      ...approvalActions('fin'),
      {
        key: 'faktur', label: 'Catat Faktur Pajak', path: '{id}/faktur', method: 'POST',
        perm: 'fin.update', when: (row) => row.status === 'approved',
        fields: [{ key: 'faktur_pajak_no', label: 'Nomor faktur pajak', type: 'text', required: true, help: 'mis. 010.000-26.00000001' }],
      },
      /*
       * Surat penagihan ke-1/2/3 (T3.7). Produksi 4 Sep 2026: INV/2026/VIII/0004
       * Rp 15,42 M disetujui, jatuh tempo 22 Sep, "diawasi tetapi tanpa
       * tindakan" (ANALISIS-PROSES §3, celah A2) — pengawas jatuh tempo
       * menyebutnya, dan tidak ada surat penagihan di sistem. Tiga aksi, satu
       * per tingkat, yang tampil HANYA untuk tingkat berikutnya: `when`
       * membaca dunning_next_level dari server (satu definisi di
       * ArInvoice::dunningRefusal — belum disetujui, lunas, belum jatuh
       * tempo, sudah surat terakhir → null), bukan menyalin aturannya. POST
       * {id}/dunning menaikkan tingkatnya (tercatat di jejak audit), lalu
       * `printForm` mencetak lembar surat-penagihan-N yang baru terbuka
       * (actions.js). Cetak ULANG surat yang sudah terbit ada di menu
       * Cetak ▾ (katalog, onlyWhen dunning_level = N) — tanpa POST.
       */
      ...[1, 2, 3].map((level) => ({
        key: `dunning-${level}`, label: `Cetak surat penagihan ke-${level}`, path: '{id}/dunning', method: 'POST',
        perm: 'fin.update', when: (row) => row.dunning_next_level === level,
        confirm: (row) => `Surat penagihan ke-${level} ${row.code} akan dicetak dan tingkat penagihan invoice ini naik ke ${level} — `
          + 'tercatat di jejak audit dan disebut pengawas jatuh tempo.'
          + (level > 1 ? ` Surat ke-${level - 1} tidak dapat dicetak ulang setelahnya.` : '')
          + (level === 3 ? ' Ini surat penagihan terakhir.' : ''),
        printForm: `surat-penagihan-${level}`,
        toast: (code) => `Surat penagihan ke-${level} ${code} diterbitkan.`,
      })),
      {
        // Salah tagih adalah kejadian rutin. Sebelum ini dokumen yang terlanjur
        // disetujui tidak bisa ditarik sama sekali: piutang/hutang fiktif
        // menggantung selamanya dan termin terkunci "sudah ditagih" sehingga
        // penggantinya justru ditolak. Membatalkan memposting jurnal PEMBALIK —
        // jurnal aslinya tidak pernah disentuh.
        key: 'cancel', label: 'Batalkan Dokumen', path: '{id}/cancel', method: 'POST',
        perm: 'fin.post', variant: 'danger',
        when: (row) => row.status === 'approved' && Number(row.amount_paid || 0) === 0,
        fields: [{
          key: 'reason', label: 'Alasan pembatalan', type: 'textarea', required: true,
          help: 'Tercatat permanen di dokumen dan jejak audit. Minimal 5 karakter.',
        }],
      },
    ],
  },

  'finance/ap-bills': {
    module: 'fin', api: 'finance/ap-bills', label: 'Tagihan Vendor (AP)', labelOne: 'Tagihan',
    columns: [
      codeColumn,
      { key: 'vendor.name', label: 'Vendor', type: 'text', sub: 'vendor.code' },
      { key: 'bill_date', label: 'Tgl tagihan', type: 'date', hideOnNarrow: true },
      { key: 'due_date', label: 'Jatuh tempo', type: 'date', hideOnNarrow: true },
      { key: 'total_payable', label: 'Total bayar', type: 'currency', align: 'right' },
      { key: 'outstanding', label: 'Sisa', type: 'currency', align: 'right', toneZero: 'green' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'vendor_id', label: 'Vendor', lookup: 'vendors' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Tagihan vendor',
        help: 'Isi PO, opname subkon, opname mandor, atau tagihan periode PPK untuk menyalin nilainya otomatis; kosongkan semuanya untuk tagihan manual.',
        fields: [
          { key: 'purchase_order_id', label: 'Dari PO', type: 'lookup', lookup: 'purchaseOrders', createOnly: true },
          { key: 'subcontract_claim_id', label: 'Dari opname subkon', type: 'lookup', lookup: 'progressClaims', createOnly: true },
          /* P4 — cermin baris di atasnya untuk opname mandor (SP3): DPP netto
             potongan kasbon, PPh final UMKM; hanya opname approved yang lolos
             server (ApBillService::createFromLaborClaim). */
          { key: 'labor_claim_id', label: 'Dari opname mandor', type: 'lookup', lookup: 'laborClaims', createOnly: true },
          /* P5 — cermin yang sama untuk tagihan periode PPK: DPP dari
             kuantitas turunan register/kalender, PPN dari snapshot PPK, dan
             DPP-nya tidak bisa diketik ulang (ApBillService::
             createFromWorkOrderBilling). Tanpa baris ini seam-nya hanya
             terjangkau lewat curl — celah kelas vendor_type P4. */
          { key: 'work_order_billing_id', label: 'Dari tagihan periode PPK', type: 'lookup', lookup: 'workOrderBillings', createOnly: true },
          {
            key: 'is_advance', label: 'Tagihan uang muka (DP) atas PO', type: 'bool', createOnly: true,
            help: 'DP ke pemasok sebelum barang datang. Dicatat sebagai uang muka, BUKAN beban proyek, '
              + 'lalu dikreditkan kembali otomatis saat tagihan final PO yang sama disetujui.',
          },
          {
            key: 'goods_receipt_id', label: 'Atas penerimaan barang (GRN)', type: 'lookup', lookup: 'goodsReceipts', createOnly: true,
            help: 'Untuk barang yang sudah diterima tanpa PO: menagihkan akrual penerimaan yang '
              + 'menggantung di 2-1150 supaya tidak mengendap di neraca.',
          },
          {
            key: 'goods_receipt_ids', label: 'Tagihan parsial: GRN yang ditagih', type: 'multiselect', lookup: 'goodsReceipts', createOnly: true,
            help: 'Isi bersama "Dari PO" untuk menagih sebagian pengiriman: pilih penerimaan (GRN) '
              + 'PO itu yang difakturkan vendor — nilainya dihitung dari qty diterima x harga PO, '
              + 'diskon dan uang muka dipotong proporsional. Kosongkan untuk menagih seluruh PO.',
          },
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors' },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects' },
          { key: 'vendor_invoice_no', label: 'No. invoice vendor', type: 'text', required: true },
          { key: 'faktur_pajak_no', label: 'No. faktur pajak', type: 'text' },
          { key: 'description', label: 'Keterangan', type: 'text', span: 2 },
          { key: 'dpp', label: 'DPP', type: 'currency' },
          { key: 'ppn_amount', label: 'PPN masukan', type: 'currency' },
          { key: 'pph_tax_id', label: 'Jenis PPh dipotong', type: 'lookup', lookup: 'taxes' },
          { key: 'pph_amount', label: 'PPh dipotong', type: 'currency', help: 'Kosongkan untuk menghitung dari tarif pajak yang dipilih.' },
          { key: 'bill_date', label: 'Tanggal tagihan', type: 'date', defaultToday: true },
          { key: 'due_date', label: 'Jatuh tempo', type: 'date' },
        ],
      }],
    },
    detail: { summary: ['dpp', 'ppn_amount', 'pph_amount', 'total_payable', 'amount_paid', 'outstanding'] },
    actions: [
      ...approvalActions('fin'),
      {
        /* Tagihan yang disetujui tidak punya pemilik untuk langkah "buat
           pembayaran" — ia menunggu seseorang ingat: BIL/2026/VII/0002
           (Rp 48,5 jt) di produksi 69 hari lewat jatuh tempo pada 4 Sep 2026
           (ANALISIS-PROSES-BISNIS-2026-09 §3, celah B1; pengawas tenggatnya
           entri ap_due). Tombol ini membuka formulir pembayaran keluar yang
           sudah terisi sisa tagihannya dan tersimpan langsung membuka PAY
           barunya. Alokasi ke tagihan ini dipilih di layar pembayaran itu saat
           mengajukan (kartu Tagihan AP) — formulir pembayaran memang tidak
           membawa alokasi, dan menyalin id tagihan ke sana tanpa tahu apakah
           ada potongan pajak bukan hal yang boleh dilakukan tombol. */
        key: 'create-payment', label: 'Buat pembayaran', perm: 'fin.create', variant: 'primary',
        when: (row) => row.status === 'approved' && Number(row.outstanding || 0) > 0,
        opens: 'finance/payments',
        prefill: (row) => ({
          direction: 'out',
          amount: Number(row.outstanding || 0),
          notes: `Pembayaran ${row.code}${row.vendor?.name ? ` — ${row.vendor.name}` : ''}`,
        }),
      },
      {
        // Salah tagih adalah kejadian rutin. Sebelum ini dokumen yang terlanjur
        // disetujui tidak bisa ditarik sama sekali: piutang/hutang fiktif
        // menggantung selamanya dan termin terkunci "sudah ditagih" sehingga
        // penggantinya justru ditolak. Membatalkan memposting jurnal PEMBALIK —
        // jurnal aslinya tidak pernah disentuh.
        key: 'cancel', label: 'Batalkan Dokumen', path: '{id}/cancel', method: 'POST',
        perm: 'fin.post', variant: 'danger',
        when: (row) => row.status === 'approved' && Number(row.amount_paid || 0) === 0,
        fields: [{
          key: 'reason', label: 'Alasan pembatalan', type: 'textarea', required: true,
          help: 'Tercatat permanen di dokumen dan jejak audit. Minimal 5 karakter.',
        }],
      },
    ],
  },

  'finance/bank-accounts': {
    module: 'fin', api: 'finance/bank-accounts', label: 'Rekening Bank', labelOne: 'Rekening Bank',
    lookupSource: 'bankAccounts', noDetail: true,
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama', type: 'text' },
      { key: 'bank_name', label: 'Bank', type: 'text' },
      { key: 'account_no', label: 'No. rekening', type: 'code' },
      { key: 'account_name', label: 'Atas nama', type: 'text' },
      { key: 'coa_account.code', label: 'Akun COA', type: 'code' },
      { key: 'is_active', label: 'Aktif', type: 'bool', align: 'center' },
    ],
    form: {
      sections: [{
        title: 'Rekening bank',
        fields: [
          { key: 'code', label: 'Kode', type: 'text', required: true },
          { key: 'name', label: 'Nama rekening', type: 'text', required: true },
          { key: 'bank_name', label: 'Bank', type: 'text', required: true },
          { key: 'account_no', label: 'Nomor rekening', type: 'text', required: true },
          { key: 'account_name', label: 'Atas nama', type: 'text', required: true },
          { key: 'coa_account_id', label: 'Akun COA', type: 'lookup', lookup: 'postableAccounts', required: true },
          { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
        ],
      }],
    },
  },

  'finance/payments': {
    module: 'fin', api: 'finance/payments', label: 'Pembayaran', labelOne: 'Pembayaran',
    customDetail: 'payment',
    columns: [
      codeColumn,
      { key: 'payment_date', label: 'Tanggal', type: 'date' },
      { key: 'direction', label: 'Arah', type: 'enum', enum: 'paymentDirection' },
      { key: 'bank_account.name', label: 'Rekening', type: 'text', sub: 'bank_account.bank_name' },
      { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
      { key: 'reference', label: 'Referensi', type: 'text' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'paymentStatus' },
      { key: 'direction', label: 'Arah', enum: 'paymentDirection' },
    ],
    // Uang keluar berjalan draf -> diajukan -> disetujui -> diposting. Yang
    // ditolak harus bisa diperbaiki; yang sudah diajukan tidak, atau
    // persetujuannya tidak berarti apa-apa.
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Pembayaran',
        fields: [
          { key: 'direction', label: 'Arah', type: 'select', enum: 'paymentDirection', required: true, createOnly: true },
          { key: 'payment_date', label: 'Tanggal', type: 'date', required: true, defaultToday: true },
          { key: 'bank_account_id', label: 'Rekening bank', type: 'lookup', lookup: 'bankAccounts', required: true },
          { key: 'amount', label: 'Jumlah', type: 'currency', required: true },
          { key: 'reference', label: 'Referensi transfer', type: 'text' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  'finance/project-costs': {
    module: 'fin', api: 'finance/project-costs', label: 'Biaya Proyek', labelOne: 'Biaya Proyek',
    noDetail: true, canCreate: false, canEdit: false, canDelete: false,
    columns: [
      { key: 'cost_date', label: 'Tanggal', type: 'date' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'cost_category', label: 'Kategori', type: 'enum', enum: 'costCategory' },
      { key: 'description', label: 'Keterangan', type: 'text' },
      { key: 'reference_type', label: 'Sumber', type: 'text' },
      { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'cost_category', label: 'Kategori', enum: 'costCategory' },
    ],
  },

  /* ======================================================== HR PAYROLL === */
  'hr/employees': {
    module: 'hr', api: 'hr/employees', label: 'Karyawan', labelOne: 'Karyawan',
    lookupSource: 'employees', customDetail: 'employee',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama', type: 'text', sub: 'position' },
      { key: 'department', label: 'Departemen', type: 'enum', enum: 'department' },
      { key: 'employment_type', label: 'Status kerja', type: 'enum', enum: 'employmentType' },
      { key: 'ptkp_status', label: 'PTKP', type: 'text', align: 'center' },
      { key: 'base_salary', label: 'Gaji pokok', type: 'currency', align: 'right' },
      { key: 'status', label: 'Status', type: 'status', width: '1%' },
    ],
    filters: [
      { key: 'department', label: 'Departemen', enum: 'department' },
      { key: 'status', label: 'Status', enum: 'employeeStatus' },
      { key: 'employment_type', label: 'Status kerja', enum: 'employmentType' },
    ],
    form: {
      sections: [
        {
          title: 'Data pribadi',
          fields: [
            { key: 'name', label: 'Nama lengkap', type: 'text', required: true, span: 2 },
            { key: 'nik_ktp', label: 'NIK KTP', type: 'text', required: true, help: '16 digit' },
            { key: 'npwp', label: 'NPWP', type: 'text' },
            { key: 'gender', label: 'Jenis kelamin', type: 'select', enum: 'gender', required: true },
            { key: 'birth_date', label: 'Tanggal lahir', type: 'date', required: true },
            { key: 'ptkp_status', label: 'Status PTKP', type: 'select', enum: 'ptkpStatus', required: true },
          ],
        },
        {
          title: 'Kepegawaian',
          fields: [
            { key: 'position', label: 'Jabatan', type: 'text', required: true },
            { key: 'department', label: 'Departemen', type: 'select', enum: 'department', required: true },
            { key: 'employment_type', label: 'Status kerja', type: 'select', enum: 'employmentType', required: true },
            { key: 'pkwt_basis', label: 'Dasar PKWT', type: 'select', enum: 'pkwtBasis', help: 'PKWT selesainya pekerjaan tertentu sah tanpa tanggal akhir (PP 35/2021 Pasal 9) dan tidak ditagih pengawas tenggat.' },
            { key: 'pkwt_end_date', label: 'Akhir PKWT', type: 'date', help: 'Wajib untuk PKWT jangka waktu — yang lewat tanggal demi hukum menjadi PKWTT (PP 35/2021).' },
            { key: 'join_date', label: 'Tanggal masuk', type: 'date', required: true },
            { key: 'status', label: 'Status', type: 'select', enum: 'employeeStatus', default: 'active' },
            { key: 'resign_date', label: 'Tanggal resign', type: 'date' },
          ],
        },
        {
          title: 'Remunerasi & BPJS',
          fields: [
            { key: 'base_salary', label: 'Gaji pokok', type: 'currency', required: true },
            { key: 'fixed_allowances', label: 'Tunjangan tetap', type: 'json', span: 2, help: 'Pasangan nama-tunjangan dan nominal, mis. jabatan / transport.' },
            { key: 'bpjs_kesehatan_no', label: 'No. BPJS Kesehatan', type: 'text' },
            { key: 'bpjs_tk_no', label: 'No. BPJS Ketenagakerjaan', type: 'text' },
            { key: 'bank_name', label: 'Bank', type: 'text' },
            { key: 'bank_account_no', label: 'No. rekening', type: 'text' },
            { key: 'bank_account_name', label: 'Atas nama', type: 'text', span: 2 },
          ],
        },
      ],
    },
  },

  'hr/leave-requests': {
    module: 'hr', api: 'hr/leave-requests', label: 'Cuti & Izin', labelOne: 'Pengajuan Cuti',
    columns: [
      codeColumn,
      { key: 'employee.name', label: 'Karyawan', type: 'text' },
      { key: 'leave_type', label: 'Jenis', type: 'enum', enum: 'leaveType' },
      { key: 'start_date', label: 'Mulai', type: 'date' },
      { key: 'end_date', label: 'Selesai', type: 'date' },
      { key: 'day_count', label: 'Hari', type: 'number', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'employee_id', label: 'Karyawan', lookup: 'employees' },
      { key: 'leave_type', label: 'Jenis', enum: 'leaveType' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Pengajuan cuti/izin',
        fields: [
          { key: 'employee_id', label: 'Karyawan', type: 'lookup', lookup: 'employees', required: true },
          { key: 'leave_type', label: 'Jenis', type: 'select', enum: 'leaveType', required: true, help: 'Hanya cuti tahunan yang memotong saldo 12 hari (UU 13/2003 Pasal 79); sakit/izin/khusus tercatat tanpa memotong.' },
          { key: 'start_date', label: 'Tanggal mulai', type: 'date', required: true },
          { key: 'end_date', label: 'Tanggal selesai', type: 'date', required: true },
          // day_count TIDAK ada di form: server yang menghitung hari kerja dari
          // rentang (Minggu tidak dihitung) — angka ketikan akan diabaikan.
          { key: 'reason', label: 'Alasan / keperluan', type: 'textarea', required: true, span: 2 },
        ],
      }],
    },
    actions: [...approvalActions('hr')],
  },

  'hr/attendance-recaps': {
    module: 'hr', api: 'hr/attendance-recaps', label: 'Rekap Absensi', labelOne: 'Rekap Absensi',
    noDetail: true,
    columns: [
      { key: 'employee.code', label: 'Kode', type: 'code' },
      { key: 'employee.name', label: 'Karyawan', type: 'text' },
      { key: 'period', label: 'Periode', type: 'period' },
      { key: 'work_days', label: 'Hari kerja', type: 'number', align: 'right' },
      { key: 'present_days', label: 'Hadir', type: 'number', align: 'right' },
      { key: 'sick_days', label: 'Sakit', type: 'number', align: 'right' },
      { key: 'leave_days', label: 'Cuti', type: 'number', align: 'right' },
      { key: 'alpha_days', label: 'Alpa', type: 'number', align: 'right' },
      { key: 'overtime_hours', label: 'Lembur (jam)', type: 'number', align: 'right', decimals: 2 },
    ],
    filters: [
      { key: 'employee_id', label: 'Karyawan', lookup: 'employees' },
      { key: 'period_year', label: 'Tahun', type: 'number' },
      { key: 'period_month', label: 'Bulan', type: 'month' },
    ],
    form: {
      sections: [{
        title: 'Rekap absensi bulanan',
        fields: [
          { key: 'employee_id', label: 'Karyawan', type: 'lookup', lookup: 'employees', required: true },
          { key: 'period_year', label: 'Tahun', type: 'number', required: true, defaultYear: true },
          { key: 'period_month', label: 'Bulan', type: 'select', options: 'months', required: true },
          { key: 'work_days', label: 'Hari kerja', type: 'number', required: true },
          { key: 'present_days', label: 'Hari hadir', type: 'number', required: true },
          { key: 'sick_days', label: 'Sakit', type: 'number', default: 0 },
          { key: 'leave_days', label: 'Cuti', type: 'number', default: 0 },
          { key: 'alpha_days', label: 'Alpa', type: 'number', default: 0 },
          { key: 'overtime_hours', label: 'Jam lembur', type: 'number', step: '0.5', default: 0 },
        ],
      }],
    },
  },

  'hr/payroll-runs': {
    module: 'hr', api: 'hr/payroll-runs', label: 'Payroll', labelOne: 'Payroll Run',
    customDetail: 'payroll',
    columns: [
      codeColumn,
      { key: 'period', label: 'Periode', type: 'period' },
      { key: 'run_type', label: 'Jenis', type: 'enum', enum: 'payrollRunType' },
      { key: 'payment_date', label: 'Tgl bayar', type: 'date' },
      { key: 'total_gross', label: 'Bruto', type: 'currency', align: 'right' },
      { key: 'total_deductions', label: 'Potongan', type: 'currency', align: 'right' },
      { key: 'total_net', label: 'Netto', type: 'currency', align: 'right', strong: true },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'documentStatus' },
      { key: 'run_type', label: 'Jenis', enum: 'payrollRunType' },
      { key: 'period_year', label: 'Tahun', type: 'number' },
    ],
    editableWhen: DRAFT_OR_REJECTED,
    deletableWhen: DRAFT_OR_REJECTED,
    form: {
      sections: [{
        title: 'Payroll run',
        fields: [
          { key: 'period_year', label: 'Tahun', type: 'number', required: true, defaultYear: true },
          { key: 'period_month', label: 'Bulan', type: 'select', options: 'months', required: true },
          { key: 'run_type', label: 'Jenis', type: 'select', enum: 'payrollRunType', required: true, default: 'regular' },
          { key: 'payment_date', label: 'Tanggal pembayaran', type: 'date' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
    actions: [
      {
        key: 'calculate', label: 'Hitung Payroll', path: '{id}/calculate', method: 'POST',
        perm: 'hr.update', variant: 'primary', when: DRAFT_OR_REJECTED,
        confirm: 'Hitung ulang payroll? Slip gaji yang sudah ada akan diganti.',
      },
      ...approvalActions('hr'),
    ],
  },

  'hr/certificates': {
    module: 'hr', api: 'hr/certificates', label: 'Sertifikat', labelOne: 'Sertifikat',
    columns: [
      { key: 'employee_id', label: 'Karyawan', type: 'rel', lookup: 'employees' },
      { key: 'certificate_type', label: 'Jenis', type: 'enum', enum: 'certificateType' },
      { key: 'name', label: 'Nama sertifikat', type: 'text', sub: 'number' },
      { key: 'issuer', label: 'Penerbit', type: 'text' },
      { key: 'issued_date', label: 'Terbit', type: 'date' },
      { key: 'expiry_date', label: 'Kedaluwarsa', type: 'date' },
    ],
    filters: [
      { key: 'employee_id', label: 'Karyawan', lookup: 'employees' },
      { key: 'certificate_type', label: 'Jenis', enum: 'certificateType' },
    ],
    form: {
      sections: [{
        title: 'Sertifikat',
        fields: [
          { key: 'employee_id', label: 'Karyawan', type: 'lookup', lookup: 'employees', required: true },
          { key: 'certificate_type', label: 'Jenis', type: 'select', enum: 'certificateType', required: true },
          { key: 'name', label: 'Nama sertifikat', type: 'text', required: true, span: 2 },
          { key: 'number', label: 'Nomor', type: 'text' },
          { key: 'issuer', label: 'Penerbit', type: 'text', help: 'LPJK / Kemnaker / nama principal' },
          { key: 'issued_date', label: 'Tanggal terbit', type: 'date' },
          { key: 'expiry_date', label: 'Tanggal kedaluwarsa', type: 'date', help: 'Kosongkan bila tidak kedaluwarsa. Perpanjangan = ubah tanggal ini.' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  /* ====================================================== SERVICE DESK === */
  'servicedesk/contracts': {
    module: 'svc', api: 'servicedesk/contracts', label: 'Kontrak Layanan', labelOne: 'Kontrak Layanan',
    lookupSource: 'serviceContracts',
    columns: [
      codeColumn,
      { key: 'name', label: 'Kontrak', type: 'text', sub: 'customer_name' },
      { key: 'period_start', label: 'Mulai', type: 'date' },
      { key: 'period_end', label: 'Berakhir', type: 'date' },
      { key: 'contract_value', label: 'Nilai', type: 'currency', align: 'right' },
      { key: 'sla_response_hours', label: 'SLA respons', type: 'number', align: 'right', suffix: ' jam' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'svcContractStatus' },
      { key: 'customer_id', label: 'Pelanggan', lookup: 'customers' },
    ],
    form: {
      sections: [{
        title: 'Kontrak pemeliharaan',
        fields: [
          { key: 'customer_id', label: 'Pelanggan', type: 'lookup', lookup: 'customers', required: true },
          { key: 'contract_id', label: 'Kontrak CRM', type: 'lookup', lookup: 'contracts' },
          { key: 'name', label: 'Nama kontrak', type: 'text', required: true, span: 2 },
          { key: 'period_start', label: 'Periode mulai', type: 'date', required: true },
          { key: 'period_end', label: 'Periode berakhir', type: 'date', required: true },
          { key: 'contract_value', label: 'Nilai kontrak', type: 'currency', required: true },
          { key: 'billing_cycle', label: 'Siklus penagihan', type: 'select', enum: 'billingCycle', required: true },
          { key: 'sla_response_hours', label: 'SLA respons (jam)', type: 'number', required: true },
          { key: 'sla_resolution_hours', label: 'SLA penyelesaian (jam)', type: 'number', required: true },
          { key: 'status', label: 'Status', type: 'select', enum: 'svcContractStatus', default: 'active' },
          { key: 'coverage', label: 'Cakupan layanan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'sites', label: 'Lokasi layanan', min: 1,
        columns: [
          { key: 'site_name', label: 'Nama lokasi', type: 'text', required: true, width: '28%' },
          { key: 'address', label: 'Alamat', type: 'text', width: '30%' },
          { key: 'city', label: 'Kota', type: 'text', width: '14%' },
          { key: 'pic_name', label: 'PIC', type: 'text', width: '14%' },
          { key: 'pic_phone', label: 'Telepon PIC', type: 'text', width: '14%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'sites', label: 'Lokasi layanan',
        columns: [
          { key: 'site_name', label: 'Lokasi' },
          { key: 'address', label: 'Alamat' },
          { key: 'city', label: 'Kota' },
          { key: 'pic_name', label: 'PIC' },
          { key: 'pic_phone', label: 'Telepon' },
        ],
      }],
    },
  },

  'servicedesk/tickets': {
    module: 'svc', api: 'servicedesk/tickets', label: 'Tiket Layanan', labelOne: 'Tiket',
    lookupSource: 'tickets', customDetail: 'ticket',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text', sub: 'customer_name' },
      { key: 'category', label: 'Kategori', type: 'enum', enum: 'ticketCategory' },
      { key: 'priority', label: 'Prioritas', type: 'priority' },
      { key: 'reported_at', label: 'Dilaporkan', type: 'datetime' },
      { key: 'resolution_due_at', label: 'SLA selesai', type: 'sla', breachKey: 'resolution_breached' },
      { key: 'status', label: 'Status', type: 'status', width: '1%' },
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'ticketStatus' },
      { key: 'priority', label: 'Prioritas', enum: 'ticketPriority' },
      { key: 'category', label: 'Kategori', enum: 'ticketCategory' },
      { key: 'service_contract_id', label: 'Kontrak', lookup: 'serviceContracts' },
    ],
    form: {
      sections: [{
        title: 'Tiket layanan',
        help: 'SLA respons & penyelesaian dihitung otomatis dari kontrak (jam kerja, kecuali prioritas kritis yang 24/7).',
        fields: [
          { key: 'service_contract_id', label: 'Kontrak layanan', type: 'lookup', lookup: 'serviceContracts' },
          { key: 'customer_id', label: 'Pelanggan', type: 'lookup', lookup: 'customers' },
          { key: 'site_id', label: 'ID lokasi', type: 'number' },
          { key: 'title', label: 'Judul', type: 'text', required: true, span: 2 },
          { key: 'category', label: 'Kategori', type: 'select', enum: 'ticketCategory', required: true },
          { key: 'priority', label: 'Prioritas', type: 'select', enum: 'ticketPriority', required: true, default: 'medium' },
          { key: 'channel', label: 'Kanal', type: 'select', enum: 'ticketChannel' },
          { key: 'reported_by_name', label: 'Dilaporkan oleh', type: 'text' },
          { key: 'reported_at', label: 'Waktu dilaporkan', type: 'datetime' },
          { key: 'assigned_to', label: 'Teknisi', type: 'lookup', lookup: 'employees' },
          { key: 'description', label: 'Deskripsi', type: 'textarea', span: 2 },
        ],
      }],
    },
    actions: [
      {
        key: 'assign', label: 'Tugaskan', path: '{id}/assign', method: 'POST',
        perm: 'svc.update', when: (row) => !['closed', 'cancelled'].includes(row.status),
        fields: [{ key: 'employee_id', label: 'Teknisi', type: 'lookup', lookup: 'employees', required: true }],
      },
      {
        key: 'activities', label: 'Tambah Aktivitas', path: '{id}/activities', method: 'POST',
        perm: 'svc.update', when: (row) => !['closed', 'cancelled'].includes(row.status),
        fields: [
          { key: 'activity_type', label: 'Jenis aktivitas', type: 'select', enum: 'ticketActivityType', required: true, default: 'comment' },
          { key: 'body', label: 'Isi', type: 'textarea', required: true },
          { key: 'minutes_spent', label: 'Waktu (menit)', type: 'number' },
        ],
      },
      {
        key: 'resolve', label: 'Selesaikan', path: '{id}/resolve', method: 'POST',
        perm: 'svc.update', variant: 'success',
        when: (row) => !['resolved', 'closed', 'cancelled'].includes(row.status),
        fields: [{ key: 'resolution_notes', label: 'Catatan penyelesaian', type: 'textarea', required: true }],
      },
      {
        key: 'close', label: 'Tutup Tiket', path: '{id}/close', method: 'POST',
        perm: 'svc.update', when: (row) => row.status === 'resolved',
        confirm: 'Tutup tiket ini?',
      },
    ],
  },

  'servicedesk/preventive-schedules': {
    module: 'svc', api: 'servicedesk/preventive-schedules', label: 'Jadwal Preventif', labelOne: 'Jadwal PM',
    columns: [
      { key: 'name', label: 'Jadwal', type: 'text', sub: 'service_contract_code' },
      { key: 'site.site_name', label: 'Lokasi', type: 'text' },
      { key: 'frequency', label: 'Frekuensi', type: 'enum', enum: 'pmFrequency' },
      { key: 'next_due_date', label: 'Jatuh tempo', type: 'date', withRelative: true },
      { key: 'assigned_to', label: 'Teknisi', type: 'rel', lookup: 'employees' },
      { key: 'is_active', label: 'Aktif', type: 'bool', align: 'center' },
    ],
    filters: [{ key: 'service_contract_id', label: 'Kontrak', lookup: 'serviceContracts' }],
    form: {
      sections: [{
        title: 'Jadwal pemeliharaan preventif',
        fields: [
          { key: 'service_contract_id', label: 'Kontrak layanan', type: 'lookup', lookup: 'serviceContracts', required: true },
          { key: 'site_id', label: 'ID lokasi', type: 'number' },
          { key: 'name', label: 'Nama jadwal', type: 'text', required: true, span: 2 },
          { key: 'frequency', label: 'Frekuensi', type: 'select', enum: 'pmFrequency', required: true },
          { key: 'next_due_date', label: 'Jatuh tempo berikutnya', type: 'date', required: true },
          { key: 'assigned_to', label: 'Teknisi', type: 'lookup', lookup: 'employees' },
          { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
          { key: 'checklist', label: 'Checklist', type: 'tags', span: 2, help: 'Satu poin per baris.' },
        ],
      }],
    },
    detail: { lists: [{ key: 'checklist', label: 'Checklist' }] },
    collectionActions: [{
      key: 'generate-now', label: 'Buat Tiket PM', path: 'generate-now', method: 'POST',
      perm: 'svc.create', variant: 'primary',
      confirm: 'Buat tiket untuk semua jadwal PM yang sudah jatuh tempo?',
    }],
  },

  'servicedesk/field-reports': {
    module: 'svc', api: 'servicedesk/field-reports', label: 'Berita Acara Lapangan', labelOne: 'Berita Acara',
    columns: [
      codeColumn,
      { key: 'ticket_code', label: 'Tiket', type: 'code' },
      { key: 'report_date', label: 'Tanggal', type: 'date' },
      { key: 'technician_name', label: 'Teknisi', type: 'text' },
      { key: 'customer_sign_name', label: 'TTD pelanggan', type: 'text' },
      statusColumn,
    ],
    filters: [{ key: 'status', label: 'Status', enum: 'fieldReportStatus' }],
    editableWhen: (row) => row.status === 'draft',
    deletableWhen: (row) => row.status === 'draft',
    form: {
      sections: [{
        title: 'Berita acara kunjungan',
        fields: [
          { key: 'ticket_id', label: 'Tiket', type: 'lookup', lookup: 'tickets', required: true },
          { key: 'report_date', label: 'Tanggal kunjungan', type: 'date', required: true, defaultToday: true },
          { key: 'technician_employee_id', label: 'Teknisi', type: 'lookup', lookup: 'employees', required: true },
          { key: 'customer_sign_name', label: 'Nama penandatangan', type: 'text' },
          // Wajib begitu ada baris sparepart: pengesahan pelanggan mengeluarkan
          // barangnya dari gudang ini, dan sejak dry run di submit() berita acara
          // bersuku-cadang tanpa gudang ditolak saat diajukan. Tanpa field ini
          // form tidak pernah bisa mengisinya dan laporannya mentok di draf.
          { key: 'warehouse_id', label: 'Gudang suku cadang', type: 'lookup', lookup: 'warehouses' },
          { key: 'findings', label: 'Temuan', type: 'textarea', required: true, span: 2 },
          { key: 'actions_taken', label: 'Tindakan', type: 'textarea', required: true, span: 2 },
          { key: 'recommendations', label: 'Rekomendasi', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'parts', label: 'Sparepart terpakai',
        columns: [
          { key: 'item_id', label: 'Item', type: 'lookup', lookup: 'items', required: true, width: '45%' },
          { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '20%' },
          { key: 'notes', label: 'Catatan', type: 'text', width: '35%' },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'parts', label: 'Sparepart terpakai',
        columns: [
          { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
          { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
          { key: 'notes', label: 'Catatan' },
        ],
      }],
    },
    actions: [
      {
        key: 'submit', label: 'Ajukan', path: '{id}/submit', method: 'POST',
        perm: 'svc.update', variant: 'primary', when: (row) => row.status === 'draft',
      },
      {
        key: 'acknowledge', label: 'Sahkan Pelanggan', path: '{id}/acknowledge', method: 'POST',
        perm: 'svc.update', variant: 'success', when: (row) => row.status === 'submitted',
        fields: [{ key: 'customer_sign_name', label: 'Nama penandatangan pelanggan', type: 'text', required: true }],
      },
      {
        // The only way back out of "diajukan", and it has to be on screen: a
        // submitted berita acara that lists sparepart blocks the close of its
        // own month until it is signed, and the signature can become impossible
        // afterwards — Finance closes the month, or somebody posts a later
        // movement on the same gudang/item and the mutasi-order guard refuses
        // the bon for good. Without this button the report is unsignable,
        // unerasable and undatable, and neither that month nor any month after
        // it can ever be closed.
        key: 'return-to-draft', label: 'Kembalikan ke Draf', path: '{id}/return-to-draft', method: 'POST',
        perm: 'svc.update', when: (row) => row.status === 'submitted',
        confirm: 'Kembalikan berita acara ini ke draf agar tanggal, gudang dan sparepart-nya bisa diperbaiki?',
      },
    ],
  },

  /* =========================================================== ASSETS === */
  'assets/assets': {
    module: 'ast', api: 'assets/assets', label: 'Aset', labelOne: 'Aset',
    // The history endpoint carries deployments, maintenance and depreciation,
    // none of which the plain show() loads.
    lookupSource: 'assets', customDetail: 'asset',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama aset', type: 'text', sub: 'category.name' },
      // P5 — milik sendiri atau sewa; nilai buku alat sewa NULL tampil '—'
      // (bergaris), bukan Rp 0: alat itu tidak ada di neraca kita.
      { key: 'ownership', label: 'Kepemilikan', type: 'enum', enum: 'assetOwnership', align: 'center' },
      { key: 'serial_no', label: 'No. seri', type: 'code', hideOnNarrow: true },
      { key: 'acquisition_cost', label: 'Harga perolehan', type: 'currency', align: 'right' },
      { key: 'book_value', label: 'Nilai buku', type: 'currency', align: 'right' },
      { key: 'current_project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      statusColumn,
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'assetStatus' },
      { key: 'ownership', label: 'Kepemilikan', enum: 'assetOwnership' },
      { key: 'category_id', label: 'Kategori', lookup: 'assetCategories' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    form: {
      sections: [
        {
          title: 'Aset',
          fields: [
            { key: 'name', label: 'Nama aset', type: 'text', required: true, span: 2 },
            { key: 'category_id', label: 'Kategori', type: 'lookup', lookup: 'assetCategories', required: true },
            // P5 — menentukan bagian mana di bawah yang tampil. createOnly:
            // beli-putus alat sewa (kapitalisasi) adalah peristiwa akuntansi,
            // bukan suntingan register — server menolak perubahannya.
            { key: 'ownership', label: 'Kepemilikan', type: 'select', enum: 'assetOwnership', required: true, default: 'owned', createOnly: true },
            { key: 'serial_no', label: 'Nomor seri', type: 'text' },
            { key: 'brand', label: 'Merek', type: 'text' },
            { key: 'model', label: 'Model', type: 'text' },
            { key: 'status', label: 'Status', type: 'select', enum: 'assetStatusEditable', editOnly: true },
            { key: 'custodian_employee_id', label: 'Penanggung jawab', type: 'lookup', lookup: 'employees' },
            { key: 'warehouse_id', label: 'Gudang', type: 'lookup', lookup: 'warehouses' },
            { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
          ],
        },
        {
          /* P5 — hanya aset MILIK SENDIRI: alat sewa tidak pernah dibeli,
             jadi kolom perolehan pada rented DITOLAK server (prohibited_if),
             bukan sekadar disembunyikan. */
          title: 'Perolehan & penyusutan',
          fields: [
            { key: 'acquisition_date', label: 'Tanggal perolehan', type: 'date', required: true, visibleWhen: ASSET_OWNED },
            { key: 'acquisition_cost', label: 'Harga perolehan', type: 'currency', required: true, visibleWhen: ASSET_OWNED },
            { key: 'salvage_value', label: 'Nilai residu', type: 'currency', default: 0, visibleWhen: ASSET_OWNED },
            { key: 'useful_life_months', label: 'Umur manfaat (bulan)', type: 'number', required: true, visibleWhen: ASSET_OWNED },
            { key: 'depreciation_start_date', label: 'Mulai disusutkan', type: 'date', visibleWhen: ASSET_OWNED },
            // disposal_date/value pindah ke aksi "Hapus Buku / Jual" — update
            // biasa kini ditolak server karena jurnal pelepasan hanya
            // diposting lewat jalur dispose (Temuan 55).
          ],
        },
        {
          /* P5 — hanya alat SEWA (deviasi 3.6): pemilik alatnya vendor
             rental, tarifnya masuk register supaya Evaluasi Sewa vs Beli dan
             PPK punya angka masternya. Penyusutan tidak pernah menyentuh
             alat sewa (gate ownership di DepreciationService). */
          title: 'Sewa',
          fields: [
            { key: 'vendor_id', label: 'Vendor rental (lessor)', type: 'lookup', lookup: 'vendors', required: true, visibleWhen: ASSET_RENTED },
            { key: 'rental_rate', label: 'Tarif sewa', type: 'currency', required: true, visibleWhen: ASSET_RENTED },
            { key: 'rate_basis', label: 'Basis tarif', type: 'select', enum: 'rateBasis', required: true, visibleWhen: ASSET_RENTED },
            { key: 'rental_start', label: 'Mulai sewa', type: 'date', visibleWhen: ASSET_RENTED },
            { key: 'rental_end', label: 'Selesai sewa', type: 'date', visibleWhen: ASSET_RENTED },
          ],
        },
      ],
    },
    actions: [{
      key: 'deploy', label: 'Mobilisasi ke Proyek', path: '{id}/deploy', method: 'POST',
      perm: 'ast.create', variant: 'primary',
      when: (row) => row.status === 'available' && row.ownership !== 'rented',
      fields: [
        { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true },
        { key: 'deployed_from', label: 'Mulai', type: 'date', defaultToday: true },
        { key: 'planned_until', label: 'Rencana sampai', type: 'date' },
        { key: 'daily_rate_internal', label: 'Tarif internal per hari', type: 'currency' },
        { key: 'notes', label: 'Catatan', type: 'textarea' },
      ],
    }, {
      /* P5 — dialog kembar TANPA kotak tarif internal untuk alat SEWA:
         biayanya sampai ke proyek lewat tagihan vendor (PPK -> tagihan AP),
         dan tarif internal di atasnya ditolak server dua pintu
         (DeploymentService) — alat yang sama dibebankan dua kali. Kotak yang
         pasti berujung 422 tidak ditawarkan. */
      key: 'deploy', label: 'Mobilisasi ke Proyek', path: '{id}/deploy', method: 'POST',
      perm: 'ast.create', variant: 'primary',
      when: (row) => row.status === 'available' && row.ownership === 'rented',
      fields: [
        { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true },
        { key: 'deployed_from', label: 'Mulai', type: 'date', defaultToday: true },
        { key: 'planned_until', label: 'Rencana sampai', type: 'date' },
        { key: 'notes', label: 'Catatan', type: 'textarea' },
      ],
    }, {
      /*
       * Satu-satunya jalur ke status disposed (update biasa menolaknya,
       * Temuan 55): memposting jurnal pelepasan — harga perolehan dan
       * akumulasi keluar dari neraca, laba/rugi diakui — lalu menandai aset.
       * ast.post: mengeluarkan aset dari neraca sekelas memposting penyusutan.
       */
      key: 'dispose', label: 'Hapus Buku / Jual', path: '{id}/dispose', method: 'POST',
      perm: 'ast.post', variant: 'danger',
      // P5 — bukan untuk alat sewa: tidak ada apa pun di neraca untuk
      // dilepas, dan server menolaknya (AssetDisposalService). Sewa berakhir
      // dengan mengembalikan mobilisasi lalu menonaktifkan masternya.
      when: (row) => (row.status === 'available' || row.status === 'maintenance') && row.ownership !== 'rented',
      fields: [
        { key: 'disposal_date', label: 'Tanggal pelepasan', type: 'date', required: true, defaultToday: true },
        { key: 'disposal_value', label: 'Nilai pelepasan (hasil penjualan)', type: 'currency', required: true, default: 0, help: 'Isi 0 untuk scrap/hilang tanpa hasil penjualan.' },
        { key: 'reason', label: 'Alasan (dijual / hilang / rusak total)', type: 'text', required: true },
      ],
    }],
  },

  'assets/categories': {
    module: 'ast', api: 'assets/categories', label: 'Kategori Aset', labelOne: 'Kategori Aset',
    lookupSource: 'assetCategories', noDetail: true,
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama kategori', type: 'text' },
      { key: 'useful_life_months_default', label: 'Umur manfaat default', type: 'number', align: 'right', suffix: ' bln' },
      { key: 'depreciation_account_hint', label: 'Akun beban', type: 'code' },
      { key: 'accum_account_hint', label: 'Akun akumulasi', type: 'code' },
      { key: 'asset_account_hint', label: 'Akun harga perolehan', type: 'code' },
    ],
    form: {
      sections: [{
        title: 'Kategori aset',
        fields: [
          { key: 'code', label: 'Kode', type: 'text', required: true },
          { key: 'name', label: 'Nama kategori', type: 'text', required: true },
          { key: 'useful_life_months_default', label: 'Umur manfaat default (bulan)', type: 'number', required: true },
          { key: 'depreciation_account_hint', label: 'Kode akun beban penyusutan', type: 'text' },
          { key: 'accum_account_hint', label: 'Kode akun akumulasi', type: 'text' },
          { key: 'asset_account_hint', label: 'Kode akun harga perolehan', type: 'text', help: 'Dikredit saat aset dihapusbukukan/dijual (mis. 1-2300 Kendaraan). Tanpa akun ini pelepasan aset kategori ini ditolak.' },
        ],
      }],
    },
  },

  'assets/deployments': {
    module: 'ast', api: 'assets/deployments', label: 'Mobilisasi Aset', labelOne: 'Mobilisasi',
    columns: [
      codeColumn,
      { key: 'asset.name', label: 'Aset', type: 'text', sub: 'asset.code' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'deployed_from', label: 'Dari', type: 'date' },
      { key: 'planned_until', label: 'Rencana sampai', type: 'date' },
      { key: 'daily_rate_internal', label: 'Tarif/hari', type: 'currency', align: 'right' },
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'deploymentStatus' },
    ],
    form: {
      sections: [{
        title: 'Mobilisasi aset',
        fields: [
          { key: 'asset_id', label: 'Aset', type: 'lookup', lookup: 'assets', required: true, createOnly: true },
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'deployed_from', label: 'Mulai', type: 'date', required: true, defaultToday: true, createOnly: true },
          { key: 'planned_until', label: 'Rencana sampai', type: 'date' },
          { key: 'daily_rate_internal', label: 'Tarif internal per hari', type: 'currency' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
    actions: [{
      key: 'return', label: 'Demobilisasi', path: '{id}/return', method: 'POST',
      perm: 'ast.post', variant: 'primary', when: (row) => row.status === 'active',
      fields: [
        { key: 'returned_at', label: 'Tanggal kembali', type: 'date', defaultToday: true },
        { key: 'notes', label: 'Catatan', type: 'textarea' },
      ],
    }],
    // Riwayat pembacaan tampil di tempat mesinnya berada: show() memuat
    // equipment_logs, layar detail generik menggambarnya sebagai tabel.
    detail: {
      tables: [{
        key: 'equipment_logs', label: 'Log BBM & jam alat',
        columns: [
          { key: 'log_date', label: 'Tanggal', type: 'date' },
          { key: 'hour_meter', label: 'Hour meter (jam)', type: 'qty', align: 'right' },
          { key: 'fuel_liters', label: 'BBM (liter)', type: 'qty', align: 'right' },
          { key: 'logged_by_name', label: 'Dicatat oleh' },
          { key: 'notes', label: 'Catatan' },
        ],
      }],
    },
  },

  /*
   * Log BBM & jam alat (deviasi #13) — REGISTER, bukan buku besar: tidak ada
   * jurnal, tidak ada stok, tidak ada tarif. Biaya solarnya sudah lewat kas
   * kecil kategori BbmTol; register ini memegang sisi operasionalnya saja
   * (jam mesin dan liter), dicatat oleh orang yang memang ada di lokasi.
   *
   * viewPerm/createPerm menimpa gerbang `${module}.view/.create` bawaan:
   * register milik mesin (Aset) tetapi ditulis dan dibaca di lapangan
   * (Proyek). site-manager memegang prj.* tanpa ast.view, jadi gerbang modul
   * saja akan mengunci justru orang yang mengisi register — sama seperti
   * rute API-nya (ast.view|prj.view untuk baca, prj.update untuk tulis).
   *
   * canEdit/canDelete false dan tanpa detail: register dikoreksi oleh
   * pembacaan BERIKUTNYA, bukan dengan menyunting riwayat — server menolak
   * PUT/DELETE dengan kalimat itu juga.
   */
  'assets/equipment-logs': {
    module: 'ast', api: 'assets/equipment-logs', label: 'Log BBM & Jam Alat', labelOne: 'Log Alat',
    viewPerm: ['ast.view', 'prj.view'],
    createPerm: 'prj.update',
    noDetail: true, canEdit: false, canDelete: false,
    columns: [
      { key: 'log_date', label: 'Tanggal', type: 'date' },
      { key: 'deployment.asset.name', label: 'Aset', type: 'text', sub: 'deployment.code' },
      { key: 'hour_meter', label: 'Hour meter (jam)', type: 'qty', align: 'right' },
      { key: 'fuel_liters', label: 'BBM (liter)', type: 'qty', align: 'right' },
      { key: 'logged_by_name', label: 'Dicatat oleh', type: 'text' },
      { key: 'notes', label: 'Catatan', type: 'text' },
    ],
    filters: [
      { key: 'deployment_id', label: 'Mobilisasi', lookup: 'deployments' },
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    form: {
      sections: [{
        title: 'Log BBM & jam alat',
        help: 'Hour meter adalah ANGKA YANG TERBACA di meter, bukan selisih. Isi minimal salah satu: hour meter atau liter BBM.',
        fields: [
          { key: 'deployment_id', label: 'Mobilisasi', type: 'lookup', lookup: 'deployments', required: true },
          { key: 'log_date', label: 'Tanggal', type: 'date', required: true, defaultToday: true },
          { key: 'hour_meter', label: 'Hour meter (jam)', type: 'number', step: '0.1', min: 0 },
          { key: 'fuel_liters', label: 'BBM diisi (liter)', type: 'number', step: '0.1', min: 0 },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  'assets/maintenances': {
    module: 'ast', api: 'assets/maintenances', label: 'Perawatan Aset', labelOne: 'Perawatan',
    columns: [
      codeColumn,
      { key: 'asset.name', label: 'Aset', type: 'text', sub: 'asset.code' },
      { key: 'maintenance_date', label: 'Tanggal', type: 'date' },
      { key: 'maintenance_type', label: 'Jenis', type: 'enum', enum: 'maintenanceType' },
      { key: 'cost', label: 'Biaya', type: 'currency', align: 'right' },
      { key: 'next_due_date', label: 'Berikutnya', type: 'date', withRelative: true },
    ],
    filters: [
      { key: 'asset_id', label: 'Aset', lookup: 'assets' },
      { key: 'maintenance_type', label: 'Jenis', enum: 'maintenanceType' },
    ],
    form: {
      sections: [{
        title: 'Catatan perawatan',
        fields: [
          { key: 'asset_id', label: 'Aset', type: 'lookup', lookup: 'assets', required: true },
          { key: 'maintenance_date', label: 'Tanggal', type: 'date', required: true, defaultToday: true },
          { key: 'maintenance_type', label: 'Jenis perawatan', type: 'select', enum: 'maintenanceType', required: true },
          { key: 'vendor_id', label: 'Vendor', type: 'lookup', lookup: 'vendors' },
          { key: 'cost', label: 'Biaya', type: 'currency', required: true, default: 0 },
          { key: 'next_due_date', label: 'Jadwal berikutnya', type: 'date' },
          { key: 'description', label: 'Uraian pekerjaan', type: 'textarea', span: 2 },
        ],
      }],
    },
  },

  'assets/depreciation-runs': {
    module: 'ast', api: 'assets/depreciation-runs', label: 'Penyusutan', labelOne: 'Run Penyusutan',
    canEdit: false,
    columns: [
      codeColumn,
      { key: 'period', label: 'Periode', type: 'text' },
      { key: 'entries_count', label: 'Jml aset', type: 'number', align: 'right' },
      { key: 'total_amount', label: 'Total penyusutan', type: 'currency', align: 'right' },
      { key: 'posted_at', label: 'Diposting', type: 'datetime' },
      statusColumn,
    ],
    filters: [{ key: 'status', label: 'Status', enum: 'postingStatus' }],
    deletableWhen: IS_DRAFT,
    form: {
      sections: [{
        title: 'Jalankan penyusutan',
        help: 'Periode harus lebih baru dari periode terakhir yang diposting.',
        fields: [
          { key: 'year', label: 'Tahun', type: 'number', required: true, defaultYear: true },
          { key: 'month', label: 'Bulan', type: 'select', options: 'months', required: true },
        ],
      }],
    },
    detail: {
      tables: [{
        key: 'entries', label: 'Rincian penyusutan',
        columns: [
          { key: 'asset.code', label: 'Kode', type: 'code' },
          { key: 'asset.name', label: 'Aset' },
          { key: 'amount', label: 'Beban bulan ini', type: 'currency', align: 'right' },
          { key: 'book_value_after', label: 'Nilai buku setelah', type: 'currency', align: 'right' },
        ],
        totalKey: 'amount',
      }],
    },
    actions: [{
      key: 'post', label: 'Posting Penyusutan', path: '{id}/post', method: 'POST',
      perm: 'ast.post', variant: 'primary', when: IS_DRAFT,
      confirm: 'Posting run penyusutan ini? Akumulasi penyusutan dan nilai buku aset akan diperbarui.',
    }],
  },

  /* ============================================================== IAM === */
  'iam/users': {
    module: 'iam', api: 'iam/users', label: 'Pengguna', labelOne: 'Pengguna',
    lookupSource: 'users', noDetail: true,
    deleteLabel: 'Nonaktifkan',
    deleteConfirm: 'Nonaktifkan pengguna ini? Semua token API-nya dicabut. Pengguna tidak pernah dihapus permanen karena id-nya dipakai di dokumen.',
    columns: [
      { key: 'name', label: 'Nama', type: 'text', sub: 'email' },
      { key: 'roles', label: 'Peran', type: 'tags' },
      { key: 'employee_id', label: 'Karyawan', type: 'rel', lookup: 'employees' },
      { key: 'is_active', label: 'Aktif', type: 'bool', align: 'center' },
    ],
    filters: [
      { key: 'role', label: 'Peran', lookup: 'roles', valueKey: 'name' },
      { key: 'is_active', label: 'Status', type: 'boolFilter' },
    ],
    form: {
      sections: [{
        title: 'Pengguna',
        fields: [
          { key: 'name', label: 'Nama', type: 'text', required: true },
          { key: 'email', label: 'Email', type: 'text', required: true },
          { key: 'password', label: 'Kata sandi', type: 'password', help: 'Minimal 8 karakter. Kosongkan saat mengubah untuk mempertahankan sandi lama.' },
          { key: 'employee_id', label: 'Karyawan terkait', type: 'lookup', lookup: 'employees' },
          { key: 'roles', label: 'Peran', type: 'multiselect', lookup: 'roles', valueKey: 'name', span: 2 },
          { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
        ],
      }],
    },
  },

  'iam/roles': {
    module: 'iam', api: 'iam/roles', label: 'Peran & Hak Akses', labelOne: 'Peran',
    lookupSource: 'roles', customDetail: 'role',
    columns: [
      { key: 'name', label: 'Peran', type: 'text' },
      { key: 'users_count', label: 'Jumlah pengguna', type: 'number', align: 'right' },
      { key: 'permissions', label: 'Hak akses', type: 'count', suffix: ' izin' },
    ],
    form: {
      sections: [{
        title: 'Peran',
        fields: [{ key: 'name', label: 'Nama peran', type: 'text', required: true, span: 2 }],
      }],
      note: 'Hak akses diatur dari halaman detail peran.',
    },
  },

  /* ====================================================== Engineering === */

  /* Register shop drawing (FM-10-01/21). Status kolomnya CERMIN keputusan
     submittal terkininya — digerakkan DrawingSubmittalService, tidak pernah
     diketik: form ini sengaja tidak menawarkan field status. */
  'engineering/drawings': {
    module: 'eng', api: 'engineering/drawings', label: 'Register Gambar', labelOne: 'Gambar',
    lookupSource: 'drawings',
    columns: [
      { key: 'number', label: 'No. Gambar', type: 'code', width: '1%' },
      { key: 'title', label: 'Judul', type: 'text' },
      { key: 'discipline', label: 'Disiplin', type: 'enum', enum: 'discipline', width: '1%' },
      { key: 'planned_submit_date', label: 'Rencana ajuan', type: 'date' },
      { key: 'current_submittal_code', label: 'SDS berlaku', type: 'text', sub: 'current_revision', hideOnNarrow: true },
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'discipline', label: 'Disiplin', enum: 'discipline' },
      { key: 'status', label: 'Status', enum: 'drawingStatus' },
    ],
    form: {
      sections: [{
        title: 'Register Gambar',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'number', label: 'Nomor gambar', type: 'text', required: true },
          { key: 'title', label: 'Judul gambar', type: 'text', required: true, span: 2 },
          { key: 'discipline', label: 'Disiplin', type: 'select', enum: 'discipline', required: true },
          { key: 'planned_submit_date', label: 'Rencana tanggal ajuan', type: 'date' },
        ],
      }],
      note: 'Status register bergerak sendiri mengikuti keputusan MK pada SDS terbarunya; ajukan revisi dari layar Persetujuan Gambar (SDS).',
    },
  },

  /* P1-ENG seam (lane BACKEND-1): the three keys the PHP registries demand —
     ApprovableDocuments links notifications to 'engineering/ipp', and both
     submittal slugs carry attachment cards (AttachmentRegistryTest holds the
     two lists together). Extended IN PLACE by the SPA lane: lookups instead of
     raw ids, enums.js maps, line tables, detail tables — never re-adding the
     keys. */
  'engineering/drawing-submittals': {
    module: 'eng', api: 'engineering/drawing-submittals', label: 'Persetujuan Gambar (SDS)', labelOne: 'Persetujuan Gambar',
    columns: [
      codeColumn,
      { key: 'drawing_number', label: 'No. Gambar', type: 'text' },
      { key: 'revision', label: 'Rev', type: 'text', width: '1%' },
      { key: 'submitted_at', label: 'Diajukan', type: 'date' },
      { key: 'reviewer_party_label', label: 'Pemeriksa', type: 'text', hideOnNarrow: true },
      { key: 'state_label', label: 'Keputusan', type: 'text' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: (row) => !row.decision && !row.superseded_at,
    deletableWhen: (row) => !row.decision && !row.superseded_at,
    actions: [
      {
        key: 'decision', label: 'Catat Keputusan MK', path: '{id}/decision', method: 'POST',
        perm: 'eng.approve', when: (row) => !row.decision && !row.superseded_at, variant: 'primary',
        fields: [
          { key: 'decision', label: 'Stempel', type: 'select', enum: 'submittalDecision', required: true },
          { key: 'decided_at', label: 'Tanggal stempel', type: 'date', required: true },
          { key: 'notes', label: 'Catatan stempel (apa adanya)', type: 'textarea' },
        ],
      },
    ],
    form: {
      sections: [{
        title: 'Pengajuan Persetujuan Gambar',
        fields: [
          { key: 'drawing_id', label: 'Gambar (register)', type: 'lookup', lookup: 'drawings', required: true, createOnly: true },
          { key: 'revision', label: 'Revisi', type: 'text', required: true, default: 'R0' },
          { key: 'submitted_at', label: 'Tanggal diajukan', type: 'date', required: true },
          { key: 'reviewer_party', label: 'Pemeriksa', type: 'select', enum: 'reviewerParty', required: true },
        ],
      }],
      note: 'Revisi baru menggantikan revisi berjalan gambar yang sama; revisi lama tercap "Digantikan" dan tidak bisa diubah lagi.',
    },
  },

  'engineering/material-submittals': {
    module: 'eng', api: 'engineering/material-submittals', label: 'Persetujuan Material (SMS)', labelOne: 'Persetujuan Material',
    columns: [
      codeColumn,
      { key: 'material_name', label: 'Material', type: 'text' },
      { key: 'brand', label: 'Merek', type: 'text', hideOnNarrow: true },
      { key: 'submitted_at', label: 'Diajukan', type: 'date' },
      { key: 'state_label', label: 'Keputusan', type: 'text' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    editableWhen: (row) => !row.decision,
    deletableWhen: (row) => !row.decision,
    actions: [
      {
        key: 'decision', label: 'Catat Keputusan MK', path: '{id}/decision', method: 'POST',
        perm: 'eng.approve', when: (row) => !row.decision, variant: 'primary',
        fields: [
          { key: 'decision', label: 'Stempel', type: 'select', enum: 'submittalDecision', required: true },
          { key: 'decided_at', label: 'Tanggal stempel', type: 'date', required: true },
          { key: 'notes', label: 'Catatan stempel (apa adanya)', type: 'textarea' },
        ],
      },
    ],
    form: {
      sections: [{
        title: 'Pengajuan Persetujuan Material',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'material_name', label: 'Nama material', type: 'text', required: true },
          { key: 'brand', label: 'Merek', type: 'text' },
          { key: 'spec_reference', label: 'Rujukan spesifikasi', type: 'text' },
          { key: 'item_id', label: 'Item persediaan', type: 'lookup', lookup: 'items' },
          { key: 'sample_attached', label: 'Sampel disertakan', type: 'bool' },
          { key: 'submitted_at', label: 'Tanggal diajukan', type: 'date', required: true },
          { key: 'reviewer_party', label: 'Pemeriksa', type: 'select', enum: 'reviewerParty', required: true },
        ],
      }],
      note: 'Material yang ditolak diajukan ulang sebagai SMS baru — keputusan yang sudah tercatat tidak pernah ditimpa. Ingat: "Disetujui dengan catatan" TIDAK meloloskan baris material pada IPP; hanya Disetujui penuh.',
    },
  },

  /* Transmittal (TRM) — surat pengantar dokumen. Setelah tanda terima
     tercatat, dokumen terkunci (server menolak ubah/hapus dengan menyebut
     nama penerimanya). */
  'engineering/transmittals': {
    module: 'eng', api: 'engineering/transmittals', label: 'Transmittal', labelOne: 'Transmittal',
    columns: [
      codeColumn,
      { key: 'direction', label: 'Arah', type: 'enum', enum: 'transmittalDirection', width: '1%' },
      { key: 'to_party', label: 'Kepada', type: 'text' },
      { key: 'transmittal_date', label: 'Tanggal', type: 'date' },
      { key: 'state_label', label: 'Tanda terima', type: 'text', sub: 'received_by' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'direction', label: 'Arah', enum: 'transmittalDirection' },
    ],
    editableWhen: (row) => !row.received_at,
    deletableWhen: (row) => !row.received_at,
    actions: [
      {
        // Aksi tanda-terima: eng.update, bukan approve — mencatat siapa yang
        // menandatangani bukanlah keputusan (lihat Routes/api.php Engineering).
        key: 'terima', label: 'Catat Tanda Terima', path: '{id}/terima', method: 'POST',
        perm: 'eng.update', when: (row) => !row.received_at, variant: 'primary',
        fields: [
          { key: 'received_by', label: 'Diterima oleh (nama penandatangan)', type: 'text', required: true },
          { key: 'received_at', label: 'Tanggal terima', type: 'date', help: 'Kosongkan untuk memakai waktu saat ini.' },
        ],
      },
    ],
    form: {
      sections: [{
        title: 'Transmittal',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'direction', label: 'Arah', type: 'select', enum: 'transmittalDirection', required: true },
          { key: 'to_party', label: 'Kepada (pihak penerima)', type: 'text', required: true },
          { key: 'transmittal_date', label: 'Tanggal transmittal', type: 'date', required: true, defaultToday: true },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      lines: [{
        key: 'lines', label: 'Dokumen yang disertakan', min: 1,
        help: 'Baris SDS/SMS diisi ID dokumennya (kolom ID pada layar Persetujuan Gambar/Material); baris "Lainnya" cukup uraian teks.',
        columns: [
          { key: 'kind', label: 'Jenis', type: 'select', enum: 'transmittalLineKind', required: true, width: '22%' },
          { key: 'document_id', label: 'ID dokumen (SDS/SMS)', type: 'number', width: '18%' },
          { key: 'description', label: 'Uraian', type: 'text', width: '40%' },
          { key: 'remarks', label: 'Keterangan', type: 'text', width: '20%' },
        ],
      }],
      note: 'Server menolak baris SDS/SMS milik proyek lain dan menyebut nomor dokumennya.',
    },
    detail: {
      tables: [{
        key: 'lines', label: 'Dokumen yang disertakan',
        columns: [
          { key: 'kind', label: 'Jenis', type: 'enum', enum: 'transmittalLineKind' },
          { key: 'document_code', label: 'No. dokumen', type: 'text' },
          { key: 'description', label: 'Uraian', type: 'text' },
          { key: 'remarks', label: 'Keterangan', type: 'text' },
        ],
      }],
    },
  },

  'engineering/ipp': {
    module: 'eng', api: 'engineering/ipp', label: 'Ijin Pelaksanaan (IPP)', labelOne: 'Ijin Pelaksanaan Pekerjaan',
    revisable: true, // P8 (D9)
    columns: [
      codeColumn,
      { key: 'project_code', label: 'Proyek', type: 'code' },
      { key: 'scope_label', label: 'Lingkup', type: 'text', width: '1%' },
      { key: 'description', label: 'Pekerjaan', type: 'text', truncate: 60 },
      { key: 'planned_start', label: 'Rencana mulai', type: 'date' },
      revisionColumn,
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    editableWhen: (row) => DRAFT_OR_REJECTED(row) && IS_LIVE_REVISION(row),
    deletableWhen: (row) => DRAFT_OR_REJECTED(row) && IS_LIVE_REVISION(row),
    /* Ajukan menjalankan GERBANG di IppService::submit: 422-nya menyebut
       setiap nomor SDS/SMS penghambat sekaligus (kunci galat 'status'), dan
       toastError menampilkannya utuh — TANPA flag konfirmasi: bekerja di atas
       gambar yang belum disetujui adalah persis yang dicegah formulir ini. */
    actions: revisableActions('eng', approvalActions('eng')),
    form: {
      sections: [{
        title: 'Ijin Pelaksanaan Pekerjaan',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'scope', label: 'Lingkup', type: 'select', enum: 'ippScope', required: true },
          { key: 'planned_start', label: 'Rencana mulai', type: 'date', required: true },
          { key: 'duration_days', label: 'Durasi (hari)', type: 'number', required: true },
          { key: 'location_id', label: 'Lokasi tapak', type: 'lookup', lookup: 'locations' },
          {
            key: 'wbs_task_id', label: 'Paket pekerjaan (WBS)', type: 'lookup', lookup: 'wbsTasks',
            help: 'Paket daun ber-BOQ pada proyek yang sama — bon gudang yang menunjuk IPP ini mewarisinya. Server menolak paket proyek lain / bukan daun, dengan pesan bernama.',
          },
          { key: 'description', label: 'Uraian pekerjaan', type: 'textarea', required: true, span: 2 },
        ],
      }],
      lines: [
        {
          key: 'materials', label: 'Bahan',
          columns: [
            { key: 'item_id', label: 'Item persediaan', type: 'lookup', lookup: 'items', width: '28%' },
            { key: 'description', label: 'Uraian bahan', type: 'text', required: true, width: '36%' },
            { key: 'qty', label: 'Qty', type: 'qty', required: true, width: '16%' },
            { key: 'unit', label: 'Satuan', type: 'text', required: true, width: '14%' },
          ],
        },
        {
          key: 'equipment', label: 'Alat',
          columns: [
            { key: 'description', label: 'Uraian alat', type: 'text', required: true, width: '48%' },
            { key: 'qty', label: 'Qty', type: 'number', required: true, width: '14%' },
            { key: 'notes', label: 'Keterangan', type: 'text', width: '32%' },
          ],
        },
        {
          key: 'drawings', label: 'Gambar rujukan (SDS)',
          help: 'Gerbang ajuan: setiap SDS di sini harus Disetujui / Disetujui dengan catatan.',
          columns: [
            { key: 'drawing_submittal_id', label: 'SDS', type: 'lookup', lookup: 'drawingSubmittals', required: true, width: '90%' },
          ],
        },
        {
          key: 'material_approvals', label: 'Persetujuan material (SMS)',
          help: 'Gerbang ajuan: setiap SMS di sini harus Disetujui PENUH — "Disetujui dengan catatan" belum meloloskan material.',
          columns: [
            { key: 'material_submittal_id', label: 'SMS', type: 'lookup', lookup: 'materialSubmittals', required: true, width: '90%' },
          ],
        },
      ],
      note: 'IPP tidak dapat diajukan sebelum submittal gambar & material pada barisnya disetujui MK; pesan penolakan menyebut nomor dokumen penghambatnya satu per satu.',
    },
    detail: {
      tables: [
        {
          key: 'materials', label: 'Bahan',
          columns: [
            { key: 'item_id', label: 'Item', type: 'rel', lookup: 'items' },
            { key: 'description', label: 'Uraian', type: 'text' },
            { key: 'qty', label: 'Qty', type: 'qty', align: 'right' },
            { key: 'unit', label: 'Satuan', type: 'text' },
          ],
        },
        {
          key: 'equipment', label: 'Alat',
          columns: [
            { key: 'description', label: 'Uraian', type: 'text' },
            { key: 'qty', label: 'Qty', type: 'number', align: 'right' },
            { key: 'notes', label: 'Keterangan', type: 'text' },
          ],
        },
        {
          key: 'drawings', label: 'Gambar rujukan (SDS)',
          columns: [
            { key: 'submittal_code', label: 'No. SDS', type: 'text' },
            { key: 'drawing_number', label: 'No. gambar', type: 'text' },
            { key: 'revision', label: 'Rev', type: 'text' },
            /* decision_label datang dari IppResource dengan turunan jujurnya:
               null → 'Menunggu keputusan' — bukan stempel pura-pura. */
            { key: 'decision_label', label: 'Keputusan MK', type: 'text' },
          ],
        },
        {
          key: 'material_approvals', label: 'Persetujuan material (SMS)',
          columns: [
            { key: 'submittal_code', label: 'No. SMS', type: 'text' },
            { key: 'material_name', label: 'Material', type: 'text' },
            { key: 'decision_label', label: 'Keputusan MK', type: 'text' },
          ],
        },
      ],
    },
  },

  /* Lokasi tapak (core_locations) — pohon tower/lantai/zona/as/ruang milik
     CORE karena Quality (P1-QC) dan Projects ikut memakainya. Modulnya di
     sini 'prj', BUKAN 'eng': rute api/core/locations digerbangi prj.* (tim
     proyeklah yang menyusun rincian tapak), jadi tombol tambah/ubah layar ini
     harus mengikuti izin yang sama dengan yang ditegakkan server. */
  'core/locations': {
    module: 'prj', api: 'core/locations', label: 'Lokasi Tapak', labelOne: 'Lokasi',
    lookupSource: 'locations',
    columns: [
      codeColumn,
      { key: 'name', label: 'Nama', type: 'text', sub: 'path' },
      { key: 'kind', label: 'Jenjang', type: 'enum', enum: 'locationKind', width: '1%' },
      { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
      { key: 'parent_name', label: 'Induk', type: 'text', sub: 'parent_code', hideOnNarrow: true },
      { key: 'children_count', label: 'Sub-lokasi', type: 'number', align: 'right', width: '1%' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'kind', label: 'Jenjang', enum: 'locationKind' },
    ],
    form: {
      sections: [{
        title: 'Lokasi Tapak',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'parent_id', label: 'Induk', type: 'lookup', lookup: 'locations', help: 'Kosongkan untuk simpul teratas (mis. tower). Induk harus pada proyek yang sama — server menolak silang proyek.' },
          { key: 'kind', label: 'Jenjang', type: 'select', enum: 'locationKind', required: true },
          { key: 'code', label: 'Kode', type: 'text', required: true },
          { key: 'name', label: 'Nama', type: 'text', required: true },
          { key: 'sort_order', label: 'Urutan', type: 'number', default: 0 },
        ],
      }],
      note: 'Lokasi yang masih memiliki sub-lokasi tidak bisa dihapus; pindahkan atau hapus dulu anak-anaknya. Impor massal tersedia lewat Impor Data Master.',
    },
  },

  /* P7 seam (lane BACKEND) — pustaka metode kerja. Kuncinya dituntut registri
     PHP: AttachableDocuments memuat 'core/method-library' (dek pptx/docx
     metode pelaksanaan, kebijakan P0-D), dan AttachmentRegistryTest menuntut
     ada layar yang merendernya. Digerbangi est.* seperti core_locations
     digerbangi prj.*: tabel Core yang dirawat orang modul lain — di sini
     estimator/drafter yang menulis metodenya bersama RAB.

     SATU BARIS = SATU VERSI, dan revisi bukan suntingan: tombol "Terbitkan
     Revisi" membuat versi n+1 dan menstempel pendahulunya, karena penawaran
     yang sudah dikirim mengutip versi yang berlaku SAAT ITU. Daftarnya secara
     bawaan hanya memuat versi berlaku — server yang menyaring, supaya pemilih
     pada layar Penawaran tidak pernah menawarkan dokumen yang sudah ditarik. */
  /* P-0b — kotak keluar pengiriman notifikasi. Baca-saja: baris ditulis
     NotificationService dan job DeliverNotification; satu-satunya kata kerja
     adalah Kirim ulang, dan hanya untuk yang belum diterima penyedia (server
     menolak `sent` dengan 422, dan e-mail yang masih dimatikan pun ditolak
     dengan kalimatnya — bukan diantrekan untuk gagal lagi). Bergerbang
     core.update (pemegang Pengaturan) di kedua sisi, sama dengan rutenya. */
  'core/notification-deliveries': {
    module: 'core', api: 'core/notification-deliveries', label: 'Pengiriman Notifikasi', labelOne: 'Pengiriman',
    viewPerm: 'core.update',
    canCreate: false, canEdit: false, canDelete: false,
    columns: [
      { key: 'created_at', label: 'Dibuat', type: 'datetime', width: '1%' },
      { key: 'channel', label: 'Kanal', type: 'enum', enum: 'deliveryChannel', width: '1%' },
      { key: 'recipient', label: 'Penerima', type: 'text', sub: 'user_name' },
      { key: 'title', label: 'Notifikasi', type: 'text' },
      { key: 'status', label: 'Status', type: 'status', enum: 'deliveryStatus', width: '1%' },
      { key: 'attempts', label: 'Percobaan', type: 'number', align: 'right', width: '1%', hideOnNarrow: true },
      { key: 'error', label: 'Galat / alasan', type: 'text', hideOnNarrow: true },
      { key: 'sent_at', label: 'Terkirim', type: 'datetime', hideOnNarrow: true },
    ],
    filters: [
      { key: 'status', label: 'Status', enum: 'deliveryStatus' },
      { key: 'channel', label: 'Kanal', enum: 'deliveryChannel' },
    ],
    actions: [
      {
        key: 'retry', label: 'Kirim ulang', path: '{id}/retry', method: 'POST', variant: 'primary',
        perm: 'core.update',
        when: (row) => ['queued', 'failed', 'skipped'].includes(row.status),
        confirm: (row) => `Antrekan ulang pengiriman ${row.channel === 'email' ? 'e-mail' : row.channel} ke ${row.recipient || '(tanpa alamat)'}? `
          + 'Percobaan sebelumnya tetap tercatat.',
        toast: () => 'Pengiriman diantrekan ulang — statusnya menjadi Antre sampai pekerja antrean mengambilnya.',
      },
    ],
  },

  /* P-0b — tabel failed_jobs Laravel dari layar. Baca-saja + dua kata kerja:
     Kirim ulang (queue:retry di server) dan Hapus (tombol generik daftar,
     core.delete — cermin rutenya). Kunci dengan dua garis miring sengaja:
     r/core/queue/failed → RESOURCES['core/queue/failed'], api core/queue/failed.
     Job pengiriman notifikasi (delivery_id terisi) TIDAK diberi Kirim ulang
     di sini: barisnya sudah `failed` dan pekerja melewati job yang
     dikembalikan begitu saja — server menolak 422; tombolnya ada di Sistem ›
     Pengiriman Notifikasi (retry_hint di bawah pengecualiannya). */
  'core/queue/failed': {
    module: 'core', api: 'core/queue/failed', label: 'Antrean Gagal', labelOne: 'Job gagal',
    viewPerm: 'core.update',
    canCreate: false, canEdit: false,
    deleteLabel: 'Hapus catatan',
    deleteConfirm: 'Hapus catatan job gagal ini? Job-nya TIDAK dijalankan ulang — pakai "Kirim ulang" bila pekerjaannya masih harus terjadi.',
    columns: [
      { key: 'failed_at', label: 'Gagal pada', type: 'datetime', width: '1%' },
      { key: 'job', label: 'Job', type: 'text', sub: 'queue' },
      { key: 'exception_excerpt', label: 'Pengecualian (baris pertama)', type: 'text', sub: 'retry_hint' },
      { key: 'uuid', label: 'UUID', type: 'code', width: '1%', hideOnNarrow: true },
    ],
    actions: [
      {
        key: 'retry', label: 'Kirim ulang', path: '{id}/retry', method: 'POST', variant: 'primary',
        perm: 'core.update',
        when: (row) => row.delivery_id == null,
        confirm: (row) => `Kembalikan job ${row.job || row.uuid} ke antrean? Pekerja akan mencobanya lagi dari awal; catatan gagal ini dihapus.`,
        toast: () => 'Job dikembalikan ke antrean; pekerja akan mencobanya lagi.',
      },
    ],
  },

  'core/method-library': {
    module: 'est', api: 'core/method-library',
    label: 'Pustaka Metode Kerja', labelOne: 'Metode Kerja',
    columns: [
      codeColumn,
      { key: 'title', label: 'Judul', type: 'text', sub: 'work_package' },
      { key: 'category', label: 'Kategori', type: 'text' },
      { key: 'version', label: 'Versi', type: 'number', align: 'right', width: '1%' },
      { key: 'effective_date', label: 'Berlaku sejak', type: 'date' },
    ],
    filters: [
      { key: 'category', label: 'Kategori' },
    ],
    form: {
      sections: [{
        title: 'Metode kerja',
        fields: [
          {
            key: 'category', label: 'Kategori', type: 'text', required: true, createOnly: true,
            help: 'Bebas — struktur, arsitektur, mep, elv, pekerjaan tanah. Bersama paket kerja ia adalah identitas rangkaian versinya.',
          },
          { key: 'work_package', label: 'Paket pekerjaan', type: 'text', required: true, createOnly: true, span: 2 },
          { key: 'title', label: 'Judul metode', type: 'text', required: true, span: 2 },
          { key: 'summary', label: 'Ringkasan', type: 'textarea', span: 2 },
          { key: 'effective_date', label: 'Berlaku sejak', type: 'date' },
          { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
        ],
      }],
      note: 'Dek pptx/docx metode dilampirkan pada VERSI ini lewat kartu Lampiran, bukan pada metodenya — revisi berikutnya membawa dek-nya sendiri.',
    },
    actions: [{
      key: 'publish-revision', label: 'Terbitkan Revisi', path: '{id}/revisions', method: 'POST',
      perm: 'est.create', when: (row) => row.is_current,
      fields: [
        { key: 'title', label: 'Judul versi baru', type: 'text', required: true },
        { key: 'summary', label: 'Ringkasan', type: 'textarea' },
        { key: 'effective_date', label: 'Berlaku sejak', type: 'date' },
      ],
      navigateToResult: true,
    }],
  },

  /* P1-QC seam (lane BACKEND): the three keys the PHP registries demand —
     ApprovableDocuments links notifications to 'quality/inspections', that same
     slug carries attachment cards (AttachmentRegistryTest holds the JS/PHP lists
     together), and all three are the resources of PrintableDocuments::quality()
     (F/QI, F/NCR, F/BU), so PrintFormReachabilityTest needs each as a RESOURCES
     key. Minimal but functional; extended IN PLACE by the SPA lane (template
     picker, result-line editor, concrete-test line table, NAV group) — never
     re-adding the keys. */
  'quality/inspections': {
    module: 'qc', api: 'quality/inspections', label: 'Inspeksi Mutu (QCI)', labelOne: 'Inspeksi Mutu',
    revisable: true, // P8 (D9)
    /* HANYA gerbang revisi, bukan gerbang status: layar ini tidak pernah punya
       editableWhen dan server tetap penentu status mana yang boleh diubah. */
    editableWhen: IS_LIVE_REVISION,
    deletableWhen: IS_LIVE_REVISION,
    columns: [
      codeColumn,
      { key: 'work_package', label: 'Paket', type: 'text' },
      { key: 'stage', label: 'Tahap', type: 'enum', enum: 'inspectionStage', width: '1%' },
      { key: 'inspected_at', label: 'Tgl Inspeksi', type: 'date' },
      { key: 'passed', label: 'Lulus', type: 'bool', width: '1%' },
      revisionColumn,
      statusColumn,
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'documentStatus' },
    ],
    form: {
      sections: [{
        title: 'Inspeksi Mutu',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          // template_id dikunci saat pembuatan — InspectionUpdateRequest tidak
          // menerimanya (service membacanya dari model). Memilihnya di sini yang
          // memberi tombol "Muat butir dari template" di bawah sumbernya.
          { key: 'template_id', label: 'Template checklist', type: 'lookup', lookup: 'inspectionTemplates', required: true, createOnly: true },
          { key: 'location_id', label: 'Lokasi', type: 'lookup', lookup: 'locations', required: true },
          { key: 'ipp_id', label: 'IPP terkait', type: 'lookup', lookup: 'approvedIpps', help: 'Opsional — mengaitkan inspeksi ke ijin pelaksanaannya.' },
          { key: 'inspected_at', label: 'Tanggal inspeksi', type: 'date', required: true },
          { key: 'inspector_employee_id', label: 'Inspektor', type: 'lookup', lookup: 'employees' },
          { key: 'witness_party', label: 'Disaksikan', type: 'select', enum: 'witnessParty' },
        ],
      }],
      lines: [{
        key: 'results',
        label: 'Butir hasil pemeriksaan',
        help: 'Klik "Muat butir dari template" untuk menarik seluruh butir checklist, lalu tandai hasil tiap butir. Butir & kriteria terkunci (milik template); Anda mengisi Hasil dan Catatan.',
        min: 0,
        columns: [
          { key: 'template_item_id', type: 'hidden' },
          { key: 'check_text', label: 'Butir diperiksa', type: 'text', readonly: true },
          { key: 'acceptance', label: 'Kriteria keberterimaan', type: 'text', readonly: true },
          { key: 'result', label: 'Hasil', type: 'select', enum: 'itemResult', required: true, width: '150px' },
          { key: 'remark', label: 'Catatan', type: 'text' },
        ],
        prefill: {
          label: 'Muat butir dari template',
          sourceField: 'template_id',
          missingSource: 'Pilih template checklist dulu di bagian atas formulir.',
          emptyMessage: 'Template ini belum memiliki butir pemeriksaan.',
          load: async (templateId, api) => {
            const template = await api.get(`quality/inspection-templates/${templateId}`);
            return (template.items || []).map((item) => ({
              template_item_id: item.id,
              check_text: item.check_text,
              acceptance: item.acceptance,
              result: '',
              remark: '',
            }));
          },
        },
      }],
      note: 'Hasil keseluruhan (lulus/tidak) dihitung dari butir; satu butir "tidak sesuai" menggagalkan lembar. Pengajuan tertahan bila ada NCR terbuka dari tahap sebelumnya di lokasi yang sama.',
    },
    // submit menjalankan GERBANG NCR (InspectionService); approve/reject adalah
    // daur Approvable rumah (maker-checker qc.approve) — sama seperti IPP.
    actions: revisableActions('qc', approvalActions('qc')),
    detail: {
      tables: [{
        key: 'results',
        label: 'Butir hasil pemeriksaan',
        columns: [
          { key: 'check_text', label: 'Butir diperiksa', type: 'text' },
          { key: 'acceptance', label: 'Kriteria', type: 'text' },
          { key: 'tolerance', label: 'Toleransi', type: 'text' },
          { key: 'result', label: 'Hasil', type: 'enum', enum: 'itemResult' },
          { key: 'remark', label: 'Catatan', type: 'text' },
        ],
      }],
    },
  },

  'quality/ncr': {
    module: 'qc', api: 'quality/ncr', label: 'Ketidaksesuaian (NCR)', labelOne: 'NCR',
    columns: [
      codeColumn,
      { key: 'stage', label: 'Tahap', type: 'enum', enum: 'inspectionStage', width: '1%' },
      { key: 'description', label: 'Uraian', type: 'text' },
      { key: 'due_date', label: 'Batas Waktu', type: 'date' },
      // Lencana, bukan teks polos: warnanya kini milik enumnya (open merah).
      // Diukur 4 Sep 2026: daftar NCR tanpa lencana (S7 ncr → []), detailnya hijau.
      { key: 'status', label: 'Status', type: 'status', enum: 'ncrStatus', width: '1%' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
      { key: 'status', label: 'Status', enum: 'ncrStatus' },
    ],
    form: {
      sections: [{
        title: 'Laporan Ketidaksesuaian',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          // inspection_id dikunci saat pembuatan (service membacanya dari model).
          // Menunjuk inspeksi mengisi tahap & lokasi NCR otomatis (NcrService).
          { key: 'inspection_id', label: 'Inspeksi asal', type: 'lookup', lookup: 'inspections', createOnly: true, help: 'Opsional. Bila diisi, tahap NCR mengikuti inspeksi ini.' },
          { key: 'location_id', label: 'Lokasi', type: 'lookup', lookup: 'locations' },
          { key: 'stage', label: 'Tahap', type: 'select', enum: 'inspectionStage', help: 'Diisi otomatis dari inspeksi bila mengacu satu; wajib untuk NCR mandiri.' },
          { key: 'description', label: 'Uraian ketidaksesuaian', type: 'textarea', required: true, span: 2 },
          { key: 'root_cause', label: 'Akar masalah', type: 'textarea' },
          { key: 'corrective_action', label: 'Tindakan koreksi', type: 'textarea' },
          { key: 'preventive_action', label: 'Tindakan pencegahan', type: 'textarea' },
          // Penanggung jawab XOR — tepat satu terisi (ditegakkan NcrService 422).
          { key: 'responsible_employee_id', label: 'Penanggung jawab (karyawan)', type: 'lookup', lookup: 'employees', help: 'Isi ini ATAU subkontraktor — tepat satu, tidak keduanya.' },
          { key: 'subcontract_id', label: 'Penanggung jawab (subkontraktor)', type: 'lookup', lookup: 'subcontracts' },
          { key: 'due_date', label: 'Batas waktu', type: 'date' },
        ],
      }],
      note: 'Penanggung jawab tepat satu: karyawan sendiri ATAU subkontraktor. Alur: Terbuka → Perbaikan → Terverifikasi → Ditutup; verifikasi menuntut pemegang lain (qc.approve). NCR terbuka memblokir inspeksi tahap berikutnya dan serah terima pertama (BAST I).',
    },
    // Daur NcrStatus lewat transisi eksplisit — BUKAN submit/approve. Tiap tombol
    // menjaga status asalnya (NcrService); verify boleh dari open ATAU perbaikan.
    actions: [
      {
        key: 'start-correction', label: 'Mulai Perbaikan', path: '{id}/start-correction', method: 'POST',
        perm: 'qc.update', variant: 'primary', when: (row) => row.status === 'open',
        confirm: 'Tandai NCR ini sedang dalam perbaikan oleh penanggung jawabnya?',
      },
      {
        key: 'verify', label: 'Verifikasi', path: '{id}/verify', method: 'POST',
        perm: 'qc.approve', variant: 'success',
        when: (row) => ['open', 'under_correction'].includes(row.status),
        fields: [{ key: 'verified_at', label: 'Tanggal verifikasi', type: 'date', help: 'Kosongkan untuk memakai tanggal hari ini.' }],
      },
      {
        key: 'close', label: 'Tutup', path: '{id}/close', method: 'POST',
        perm: 'qc.update', when: (row) => row.status === 'verified',
        confirm: 'Tutup NCR ini secara administratif? Setelah ditutup tidak dapat diubah lagi.',
      },
    ],
  },

  'quality/concrete-samples': {
    module: 'qc', api: 'quality/concrete-samples', label: 'Benda Uji Beton', labelOne: 'Benda Uji',
    columns: [
      { key: 'pour_date', label: 'Tgl Cor', type: 'date' },
      { key: 'grade', label: 'Mutu', type: 'text', width: '1%' },
      { key: 'target_fc_mpa', label: "Target fc' (MPa)", type: 'number' },
      { key: 'truck_no', label: 'No. Truk', type: 'text' },
      { key: 'volume_m3', label: 'Volume (m³)', type: 'qty' },
      { key: 'sample_count', label: 'Jml', type: 'number', width: '1%' },
    ],
    filters: [
      { key: 'project_id', label: 'Proyek', lookup: 'projects' },
    ],
    form: {
      sections: [{
        title: 'Benda Uji Beton',
        fields: [
          { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true, createOnly: true },
          { key: 'location_id', label: 'Lokasi', type: 'lookup', lookup: 'locations', required: true },
          { key: 'pour_date', label: 'Tanggal cor', type: 'date', required: true },
          { key: 'grade', label: 'Mutu (mis. K-350)', type: 'text', required: true },
          { key: 'slump_cm', label: 'Slump (cm)', type: 'number' },
          { key: 'truck_no', label: 'No. truk mixer', type: 'text' },
          { key: 'volume_m3', label: 'Volume (m³)', type: 'number' },
          { key: 'sample_count', label: 'Jumlah benda uji', type: 'number', required: true, default: 1 },
        ],
      }],
      lines: [{
        key: 'tests',
        label: 'Hasil uji tekan',
        help: 'Umur 7 / 14 / 28 hari. Lulus/tidak dihitung server terhadap target umurnya saat disimpan — tidak diketik. Menyimpan perubahan menghitung ulang seluruh hasil.',
        min: 0,
        columns: [
          { key: 'age_days', label: 'Umur', type: 'select', required: true, width: '130px', options: [
            { value: 7, label: '7 hari' }, { value: 14, label: '14 hari' }, { value: 28, label: '28 hari' },
          ] },
          { key: 'strength_mpa', label: 'Kekuatan (MPa)', type: 'number', step: '0.01', required: true },
          { key: 'lab', label: 'Laboratorium', type: 'text' },
          { key: 'tested_at', label: 'Tanggal uji', type: 'date' },
        ],
      }],
      note: "Mutu K-xxx dikonversi ke fc' silinder (× 0,0980665 × 0,83, SNI/PBI); lulus/tidak setiap uji dihitung server terhadap target umurnya — tidak pernah diketik.",
    },
    detail: {
      tables: [{
        key: 'tests',
        label: 'Hasil uji tekan',
        columns: [
          { key: 'age_days', label: 'Umur (hari)', type: 'number' },
          { key: 'strength_mpa', label: 'Kekuatan (MPa)', type: 'number' },
          { key: 'target_at_age_mpa', label: 'Target umur itu (MPa)', type: 'number' },
          { key: 'lab', label: 'Lab', type: 'text' },
          { key: 'tested_at', label: 'Tgl Uji', type: 'date' },
          { key: 'pass', label: 'Memenuhi', type: 'bool', align: 'center' },
        ],
      }],
    },
  },

  /* P1-QC — pustaka checklist (management screen ditambah lane SPA di atas seam
     backend). Q1..Q31 milik kantor mutu; juga diimpor massal via Impor Data
     Master (ImportableDocuments 'inspection-templates'). */
  'quality/inspection-templates': {
    module: 'qc', api: 'quality/inspection-templates', label: 'Template Inspeksi', labelOne: 'Template Inspeksi',
    lookupSource: 'inspectionTemplates',
    columns: [
      { key: 'code', label: 'Kode', type: 'code', width: '1%' },
      { key: 'work_package', label: 'Paket Pekerjaan', type: 'text' },
      { key: 'stage', label: 'Tahap', type: 'enum', enum: 'inspectionStage', width: '1%' },
      // P6 — jenis: pustaka mutu Q1..Q31 atau checklist 5R.
      { key: 'jenis', label: 'Jenis', type: 'enum', enum: 'templateKind', width: '1%' },
    ],
    filters: [
      { key: 'stage', label: 'Tahap', enum: 'inspectionStage' },
      { key: 'jenis', label: 'Jenis', enum: 'templateKind' },
    ],
    form: {
      sections: [{
        title: 'Template Checklist',
        fields: [
          { key: 'code', label: 'Kode katalog (mis. Q7, 5R1)', type: 'text', required: true },
          { key: 'work_package', label: 'Paket pekerjaan', type: 'text', required: true, span: 2 },
          { key: 'stage', label: 'Tahap (titik henti mutu)', type: 'select', enum: 'inspectionStage', required: true },
          // P6 — kosong berarti checklist mutu; '5r' menjadikan template ini
          // patroli 5R yang diisi lewat layar Inspeksi Mutu biasa.
          { key: 'jenis', label: 'Jenis checklist', type: 'select', enum: 'templateKind', default: 'quality' },
        ],
      }],
      lines: [{
        key: 'items',
        label: 'Butir pemeriksaan',
        min: 1,
        columns: [
          { key: 'check_text', label: 'Butir yang diperiksa', type: 'text', required: true },
          { key: 'acceptance', label: 'Kriteria keberterimaan', type: 'text', required: true },
          { key: 'tolerance', label: 'Toleransi', type: 'text' },
        ],
      }],
      note: 'Pustaka checklist juga dapat diimpor massal lewat Impor Data Master (satu berkas memuat banyak template). Mengubah butir template yang sudah dipakai inspeksi terisi belum didukung — buat template baru bila butirnya perlu berbeda.',
    },
    detail: {
      tables: [{
        key: 'items',
        label: 'Butir pemeriksaan',
        columns: [
          { key: 'check_text', label: 'Butir', type: 'text' },
          { key: 'acceptance', label: 'Kriteria', type: 'text' },
          { key: 'tolerance', label: 'Toleransi', type: 'text' },
        ],
      }],
    },
  },
};

/*
 * Izin persetujuan APA PUN — gerbang tautan Tugas Saya (NAV di bawah) dan
 * kartu "Menunggu persetujuan Anda" di dasbor (T2.11). Bentuk izin, bukan
 * nama: GET core/inbox hanya memuat jenis yang `<awalan>.approve`-nya
 * dipegang pemanggil (ApprovalQueue::pending), jadi tanpa satu pun izin itu
 * kotaknya selalu kosong. Diukur 2 Sep 2026 (HASIL-UJI §1, S5 › cards): kartu
 * tergambar untuk 11 dari 11 peran demo, padahal 8 di antaranya (site-manager,
 * estimator, procurement, warehouse, finance, hr, sales, teknisi) tidak
 * menyetujui apa pun — bagi procurement dan hr, yang tak punya satu ubin pun,
 * kartu kosong itu adalah separuh dasbornya. `.approve-director` sengaja tidak
 * dihitung: tanda tangan tingkat kedua itu diperiksa di Approvable::approve,
 * bukan oleh penyaring kotak masuk, jadi pemegangnya tanpa `.approve` tetap
 * mendapat kotak kosong. Dipakai lewat session.can(ANY_APPROVE), yang
 * memanggil fungsi ini dengan daftar izin yang dipegang; satu predikat untuk
 * sidebar, sumber "Layar" di Ctrl+K, dan dasbor.
 */
export const ANY_APPROVE = (held) => held.some((one) => one.endsWith('.approve'));

/** Sidebar structure. Each entry is gated by the module's `.view` permission. */
export const NAV = [
  {
    label: 'Ringkasan', perm: null,
    items: [{ label: 'Dasbor', route: 'dashboard' }, { label: 'Tugas Saya', route: 'tugas', perm: ANY_APPROVE }, { label: 'Tenggat', route: 'tenggat' }, { label: 'Kalender', route: 'kalender' }],
  },
  {
    label: 'Penjualan', perm: 'crm.view',
    items: [
      { label: 'Pelanggan', route: 'r/crm/customers' },
      { label: 'Prospek', route: 'r/crm/leads' },
      // P7 — berkas lelang duduk di antara prospek dan penawaran karena di
      // situlah pekerjaannya: dokumen lelang dan aanwijzing datang lebih dulu,
      // penawaran menyusul, dan lembar TKDN menguraikan penawaran itu.
      { label: 'Paket Tender', route: 'r/crm/tender-packages' },
      { label: 'Penawaran', route: 'r/crm/quotations' },
      { label: 'Lembar TKDN', route: 'r/crm/tkdn-worksheets' },
      { label: 'RKK Penawaran', route: 'r/crm/rkk-documents' },
      // P7 — lampiran kualifikasi, baca saja. Tempatnya di sini dan bukan di
      // SDM/Aset/Pengadaan karena yang dilayaninya adalah SAMPUL PENAWARAN;
      // ketiga masternya tetap dirawat di modul pemiliknya masing-masing, dan
      // layar ini tidak menulis satu baris pun ke sana.
      { label: 'Penyusun Kualifikasi', route: 'kualifikasi' },
      { label: 'Kontrak', route: 'r/crm/contracts' },
      { label: 'Pekerjaan Tambah-Kurang', route: 'r/crm/contract-change-orders' },
      // Temuan #78: agregasi menang/kalah yang datanya sudah lama dicatat.
      { label: 'Analitik Win-Rate', route: 'pipeline' },
      { label: 'Jaminan & Asuransi', route: 'r/crm/guarantees' },
    ],
  },
  {
    label: 'Estimasi', perm: 'est.view',
    items: [
      { label: 'AHSP', route: 'r/estimation/ahsp' },
      { label: 'BOQ / RAB', route: 'r/estimation/boqs' },
      { label: 'RAP', route: 'r/estimation/cost-budgets' },
      { label: 'Riwayat Harga Satuan', route: 'harga-satuan' },
      /* P7 — pustaka metode kerja. Tabel Core yang dirawat orang modul lain,
         dan tempatnya mengikuti preseden "Lokasi Tapak" di grup Engineering:
         baris itu duduk di grup tempat ORANGNYA bekerja, bukan di grup Sistem
         yang digerbangi iam.view — estimator yang menulis metode pelaksanaan
         bersama RAB-nya tidak memegang iam.view, jadi entri di sana akan
         menyembunyikan layar ini justru dari satu-satunya orang yang mengisinya.
         Digerbangi est.view bersama grupnya, persis gerbang rutenya di server. */
      { label: 'Pustaka Metode Kerja', route: 'r/core/method-library' },
    ],
  },
  {
    /* P1-ENG. Di antara Estimasi dan Proyek karena di situlah pekerjaannya
       duduk: gambar & material disetujui MK sebelum lapangan boleh mulai. */
    label: 'Engineering', perm: 'eng.view',
    items: [
      { label: 'Register Gambar', route: 'r/engineering/drawings' },
      { label: 'Persetujuan Gambar (SDS)', route: 'r/engineering/drawing-submittals' },
      { label: 'Persetujuan Material (SMS)', route: 'r/engineering/material-submittals' },
      { label: 'Transmittal', route: 'r/engineering/transmittals' },
      { label: 'Ijin Pelaksanaan (IPP)', route: 'r/engineering/ipp' },
      // Izin sendiri, pola "Log BBM & Jam Alat": layarnya digerbangi prj.*
      // di server (tim proyek yang menyusun rincian tapak), tetapi tempatnya
      // di sini — lokasi dipakai kolom LOKASI pada IPP (lalu inspeksi P1-QC),
      // jadi orang mencarinya di samping dokumen yang memakainya. Site
      // manager ber-prj.view tanpa eng.view tetap melihat baris ini
      // (visibleNav meloloskan item yang membawa izinnya sendiri).
      { label: 'Lokasi Tapak', route: 'r/core/locations', perm: 'prj.view' },
    ],
  },
  {
    label: 'Proyek', perm: 'prj.view',
    /* Pemisah { divider } (T2.5): grup ini dan Keuangan masing-masing 20
       tautan rata, sidebar admin 121 tautan setinggi 4,9 viewport — diukur
       2 Sep 2026 (HASIL-UJI §1, S5). Struktur datanya tetap datar: hanya
       renderer sidebar (app.js) yang menggambar pemisah sebagai keterangan
       kecil; visibleNav() di bawah membuang pemisah yang bloknya kosong,
       dan sumber "Layar" di Ctrl+K melewatinya. Bukan grup baru — grup baru
       berarti satu judul lagi untuk dibuka, padahal masalahnya justru
       terlalu banyak yang harus dibaca. */
    items: [
      { divider: 'Pelaksanaan' },
      { label: 'Daftar Proyek', route: 'r/projects' },
      { label: 'Laporan Harian', route: 'r/projects/daily-reports' },
      { label: 'Lapangan (mobile)', route: 'lapangan' },
      { label: 'Progres Mingguan', route: 'r/projects/weekly-progress' },
      // P3 — opname ke pemilik, tepat di bawah progres mingguan karena itulah
      // hubungannya: opname yang disetujui MENGGANTIKAN persen yang diketik
      // tangan pada minggu-minggu yang dicakupnya, dan register variasi di
      // bawahnya adalah tempat plafonnya dinaikkan.
      { label: 'Opname Owner (OPN)', route: 'r/projects/progress-measurements' },
      { label: 'Variasi Kontrak (Plafon Opname)', route: 'r/projects/contract-variations' },
      { label: 'EVM & Baseline', route: 'evm' },
      { label: 'Milestone', route: 'r/projects/milestones' },
      { divider: 'Serah terima' },
      // P3 — BAPP per zona, di atas BAST karena urutannya memang begitu: zona
      // diperiksa satu per satu, lalu proyeknya diserahterimakan.
      { label: 'BAPP per Zona', route: 'r/projects/zone-certificates' },
      { label: 'BAST', route: 'r/projects/bast' },
      { divider: 'Izin & K3' },
      // P0-C — tiga izin lapangan: dokumen sungguhan, bukan pad cetak kosong.
      { label: 'Izin Kerja (IKL)', route: 'r/projects/work-permits' },
      { label: 'Izin Lembur (ILB)', route: 'r/projects/overtime-permits' },
      { label: 'Izin Material (IMK)', route: 'r/projects/gate-passes' },
      { label: 'Register K3 (SMK3)', route: 'r/projects/safety-incidents' },
      // P6 — K3 terstruktur: formulir harian FM-10-13 dan register IBPRP,
      // berdampingan dengan register insiden karena satu keluarga SMKK.
      { label: 'Formulir K3 Harian', route: 'r/projects/hse-daily' },
      { label: 'Register IBPRP', route: 'r/projects/risk-register' },
      { label: 'Laporan K3', route: 'k3' },
      { divider: 'Register' },
      { label: 'Register Defect (Punch List)', route: 'defects' },
      { label: 'Varian Material', route: 'varian' },
      { label: 'Penugasan Personel', route: 'r/projects/manpower-assignments' },
    ],
  },
  {
    /* P1-QC. Setelah Proyek: QA lapangan yang dijalankan tim mutu selama
       pelaksanaan dan menggerbangi BAST I (NCR terbuka menahan serah terima).
       Template inspeksi di paling bawah — pustakanya, bukan transaksi harian. */
    label: 'Mutu (QA/QC)', perm: 'qc.view',
    items: [
      { label: 'Inspeksi Mutu (QCI)', route: 'r/quality/inspections' },
      { label: 'Ketidaksesuaian (NCR)', route: 'r/quality/ncr' },
      { label: 'Benda Uji Beton', route: 'r/quality/concrete-samples' },
      { label: 'Template Inspeksi', route: 'r/quality/inspection-templates' },
    ],
  },
  {
    label: 'Pengadaan', perm: 'prc.view',
    items: [
      { label: 'Vendor & Subkon', route: 'r/procurement/vendors' },
      { label: 'Dokumen Vendor', route: 'r/procurement/vendor-documents' },
      { label: 'Permintaan (PR)', route: 'r/procurement/purchase-requisitions' },
      { label: 'RFQ (Banding Penawaran)', route: 'r/procurement/rfqs' },
      { label: 'Pesanan (PO)', route: 'r/procurement/purchase-orders' },
      { label: 'Baris PO Terbuka', route: 'po-outstanding' },
      /* P5 — tiga baris berurutan seperti alur uangnya: PPK dulu (komitmen
         plafon per baris), tagihan periodenya kemudian (kuantitas turunan
         register/kalender), lalu rekapnya. Di bawah PO karena PPK adalah
         saudara PO untuk alat sewa & jasa — tanpa baris menu, ketiga layar
         hanya terjangkau lewat URL (lubang P4 yang tidak boleh berulang). */
      { label: 'PPK Alat & Jasa', route: 'r/procurement/work-orders' },
      { label: 'Tagihan Periode PPK', route: 'r/procurement/work-order-billings' },
      { label: 'Rekap Tagihan Alat', route: 'rekap-alat' },
      { label: 'BA Negosiasi', route: 'r/procurement/negotiation-minutes' },
      { label: 'Keputusan Pemenang', route: 'r/procurement/award-decisions' },
      { label: 'Rencana Pengadaan', route: 'r/procurement/procurement-plans' },
      { label: 'Evaluasi Vendor', route: 'r/procurement/vendor-evaluations' },
    ],
  },
  {
    label: 'Persediaan', perm: 'inv.view',
    items: [
      { label: 'Saldo Stok', route: 'stock' },
      { label: 'Item', route: 'r/inventory/items' },
      { label: 'Kategori Item', route: 'r/inventory/item-categories' },
      { label: 'Gudang', route: 'r/inventory/warehouses' },
      { label: 'Penerimaan (GRN)', route: 'r/inventory/goods-receipts' },
      { label: 'Pengeluaran', route: 'r/inventory/issues' },
      { label: 'Transfer', route: 'r/inventory/transfers' },
      { label: 'Opname', route: 'r/inventory/stock-adjustments' },
    ],
  },
  {
    label: 'Subkontrak', perm: 'scm.view',
    items: [
      { label: 'SPK Subkon', route: 'r/subcontract/subcontracts' },
      { label: 'Addendum SPK', route: 'r/subcontract/addenda' },
      { label: 'Opname Subkon', route: 'r/subcontract/progress-claims' },
      // P3 — BAST subkon I/II, setelah opname karena itu prasyaratnya: BAST I
      // memulai masa pemeliharaan yang dijamin retensi, dan HandoverService
      // menolaknya selagi ada opname yang belum disetujui.
      { label: 'BAST Subkon', route: 'r/subcontract/handovers' },
      // P4 — alur mandor upah borongan, dua layar berurutan seperti pasangan
      // SPK/Opname di atasnya: SP3 dulu (kontraknya), opname kemudian.
      { label: 'SP3 Mandor', route: 'r/subcontract/labor-contracts' },
      { label: 'Opname Mandor', route: 'r/subcontract/labor-claims' },
    ],
  },
  {
    label: 'Keuangan', perm: 'fin.view',
    /* Lima pemisah (T2.5) — lihat catatan di grup Proyek. Urutan baris
       digeser supaya tiap baris duduk di bawah keterangannya; rutenya tidak
       ada yang berubah. */
    items: [
      { divider: 'AR/AP' },
      { label: 'Invoice Termin (AR)', route: 'r/finance/ar-invoices' },
      { label: 'Tagihan Vendor (AP)', route: 'r/finance/ap-bills' },
      { label: 'Pembayaran', route: 'r/finance/payments' },
      { label: 'Termin Siap Ditagih', route: 'siap-tagih' },
      { label: 'Piutang Retensi', route: 'retensi' },
      { divider: 'Kas' },
      { label: 'Kasir Kas Kecil', route: 'kas-kecil' },
      { label: 'Kas Kecil & Kasbon', route: 'r/finance/petty-cash-funds' },
      { label: 'Rekonsiliasi Bank', route: 'bank-recon' },
      { divider: 'Pelaporan' },
      { label: 'Jurnal', route: 'r/finance/journals' },
      { label: 'Biaya Proyek', route: 'r/finance/project-costs' },
      { label: 'Pengakuan Pendapatan', route: 'r/finance/revenue-recognition' },
      { label: 'Periode Fiskal', route: 'periods' },
      { label: 'Laporan Keuangan', route: 'reports' },
      // Tepat di bawah Laporan Keuangan, bukan di sebelah Jurnal: buku besar
      // adalah drill-down di balik neraca saldo, jadi orang yang baru membaca
      // baris 1-1400 Rp 332.510.000 mencarinya di sini. Tanpa baris ini layar
      // hanya bisa dicapai dengan mengetik #/buku-besar sendiri.
      { label: 'Buku Besar', route: 'buku-besar' },
      { divider: 'Pajak' },
      { label: 'Ekspor Pajak', route: 'tax-exports' },
      { label: 'Kalender Pajak', route: 'kalender-pajak' },
      // Tepat di bawah Kalender Pajak: lembar cetaknya menjangkar pada baris
      // masa kalender itu, jadi keduanya bertetangga di menu maupun di data.
      { label: 'Ekualisasi Pajak', route: 'ekualisasi-pajak' },
      { divider: 'Master' },
      { label: 'Bagan Akun', route: 'r/finance/accounts' },
      { label: 'Pajak', route: 'r/finance/taxes' },
      { label: 'Rekening Bank', route: 'r/finance/bank-accounts' },
    ],
  },
  {
    label: 'SDM & Payroll', perm: 'hr.view',
    items: [
      { label: 'Karyawan', route: 'r/hr/employees' },
      { label: 'Sertifikat & PKWT', route: 'sertifikat' },
      { label: 'Cuti & Izin', route: 'r/hr/leave-requests' },
      { label: 'Absensi Harian', route: 'absensi' },
      { label: 'Rekap Absensi', route: 'r/hr/attendance-recaps' },
      { label: 'Payroll', route: 'r/hr/payroll-runs' },
    ],
  },
  {
    label: 'Layanan', perm: 'svc.view',
    items: [
      { label: 'Tiket', route: 'r/servicedesk/tickets' },
      { label: 'Tiket Lewat SLA', route: 'sla-breaches' },
      { label: 'Kontrak Layanan', route: 'r/servicedesk/contracts' },
      { label: 'Jadwal Preventif', route: 'r/servicedesk/preventive-schedules' },
      { label: 'Berita Acara', route: 'r/servicedesk/field-reports' },
    ],
  },
  {
    label: 'Aset', perm: 'ast.view',
    items: [
      { label: 'Daftar Aset', route: 'r/assets/assets' },
      { label: 'Kategori Aset', route: 'r/assets/categories' },
      { label: 'Mobilisasi', route: 'r/assets/deployments' },
      // Izin sendiri, seperti "Impor Data Master": site manager memegang
      // prj.view tanpa ast.view, dan justru dialah yang mengisi register ini
      // — tanpa baris izin ini menu Aset tidak pernah tampil untuknya.
      { label: 'Log BBM & Jam Alat', route: 'r/assets/equipment-logs', perm: ['ast.view', 'prj.view'] },
      { label: 'Perawatan', route: 'r/assets/maintenances' },
      { label: 'Penyusutan', route: 'r/assets/depreciation-runs' },
      { label: 'Utilisasi Aset', route: 'asset-utilization' },
      // P5 — BACA SAJA: jam register x tarif vs harga beli/penyusutan; layar
      // ini tidak menulis apa pun dan tidak menyimpan kesimpulan apa pun.
      { label: 'Evaluasi Sewa vs Beli', route: 'sewa-vs-beli' },
    ],
  },
  {
    label: 'Sistem', perm: 'iam.view',
    items: [
      { label: 'Pengguna', route: 'r/iam/users' },
      { label: 'Peran & Hak Akses', route: 'r/iam/roles' },
      { label: 'Profil Perusahaan', route: 'company' },
      // Carries its own permission so it reaches a warehouse or procurement
      // officer, who has no business with the rest of Sistem. Any of the four
      // create rights is enough to see the screen; the screen itself lists only
      // the tables the caller may actually read.
      // prj.create menyusul di P8: impor Lokasi Tapak (P1-ENG) bergerbang prj,
      // dan pintunya harus terlihat oleh kerani proyek juga.
      { label: 'Impor Data Master', route: 'master-data', perm: ['inv.create', 'prc.create', 'crm.create', 'hr.create', 'prj.create'] },
      // Beside its sibling rather than under Estimasi: the two screens are one
      // pair (each one's empty state points at the other), and this one spans
      // two modules — penawaran is crm, BOQ/AHSP/RAP are est — so living under
      // Estimasi (perm est.view) would hide it from the salesperson who imports
      // penawaran. Its own perm carries it out of Sistem the same way its
      // neighbour's does, so an estimator with est.create and no iam.view still
      // sees it. P8 melebarkan daftarnya: importer warisan (laporan harian,
      // kartu stok, SP3, progress pay — prj/inv/scm) dan template inspeksi (qc)
      // menumpang layar yang sama, dan pintunya harus terlihat oleh kerani
      // modul-modul itu juga. Layarnya sendiri tetap hanya menampilkan jenis
      // yang boleh dibaca si pemanggil (GET core/document-import menyaring).
      { label: 'Impor Dokumen', route: 'impor-dokumen', perm: ['crm.create', 'est.create', 'prj.create', 'inv.create', 'scm.create', 'qc.create'] },
      { label: 'Pengaturan', route: 'settings' },
      // P-0b: kotak keluar e-mail — pasangan sakelar "Kirim juga lewat email"
      // di Pengaturan, jadi bergerbang orang yang sama (core.update).
      { label: 'Pengiriman Notifikasi', route: 'r/core/notification-deliveries', perm: 'core.update' },
      { label: 'Antrean Gagal', route: 'r/core/queue/failed', perm: 'core.update' },
    ],
  },
];

/**
 * NAV yang boleh dilihat pemegang izin `can` — satu penyaring untuk sidebar
 * (app.js) dan sumber "Layar" di Ctrl+K (search.js, T2.5), supaya palet tidak
 * pernah menawarkan layar yang barisnya sendiri disembunyikan dari menu.
 *
 * Grup digerbangi izinnya sendiri; sebuah baris boleh membawa izin sendiri.
 * Grup yang izinnya gagal tetap tampil bila salah satu barisnya lolos sendiri
 * — begitulah "Impor Data Master" sampai ke petugas gudang yang memegang
 * inv.create tanpa urusan dengan sisa grup Sistem. Baris berizin sendiri sudah
 * diperiksa; izin grup hanya menggerbangi baris yang tidak menyatakan izin.
 *
 * Pemisah ikut lolos hanya bila bloknya (sampai pemisah berikutnya) masih
 * berisi baris; keterangan di atas ruang kosong terbaca sebagai baris hilang.
 */
export function visibleNav(can) {
  return NAV
    .map((group) => {
      const gated = Boolean(group.perm) && !can(group.perm);
      const items = group.items.filter((item) => item.divider || (item.perm ? can(item.perm) : !gated));
      return { ...group, items: withoutEmptyDividers(items) };
    })
    .filter((group) => group.items.some((item) => item.route));
}

function withoutEmptyDividers(items) {
  return items.filter((item, index) => {
    if (!item.divider) return true;
    const next = items.slice(index + 1).find((one) => one.divider || one.route);
    return Boolean(next && next.route);
  });
}

export function resource(key) {
  return RESOURCES[key] || null;
}

export { ENUMS };
