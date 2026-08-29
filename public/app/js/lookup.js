/* Remote reference data for <select> pickers (customers, vendors, items, …).
   Each source is fetched once per session and cached; forms use the cache to
   render option lists and tables use it to resolve foreign keys to names. */

import { api } from './api.js';

/*
 * `title` is the Indonesian noun a picker uses when it has to explain itself —
 * "Daftar Item tidak bisa dimuat…". It is deliberately NOT a permission name:
 * inventory/items is guarded by inv.view and estimation/* by est.view, so the
 * path prefix is not the permission prefix, and a message naming the wrong
 * permission sends the user to the wrong admin.
 */
export const SOURCES = {
  customers: { path: 'crm/customers', label: 'name', sub: 'code', title: 'Pelanggan' },
  contracts: { path: 'crm/contracts', label: 'title', sub: 'code', title: 'Kontrak' },
  /* P3: register variasi kontrak menautkan volume ke CCO-nya. Dijaga crm.view
     di server sementara layarnya digerbangi prj.* — sama seperti `locations`
     di bawah, dan loadSource menoleransi 403. */
  contractChangeOrders: { path: 'crm/contract-change-orders', label: 'title', sub: 'code', title: 'Pekerjaan tambah-kurang' },
  quotations: { path: 'crm/quotations', label: 'title', sub: 'code', title: 'Penawaran' },
  leads: { path: 'crm/leads', label: 'name', sub: 'company_name', title: 'Prospek' },
  projects: { path: 'projects', label: 'name', sub: 'code', title: 'Proyek' },
  // Daftar SEMUA daun dari semua proyek, karena form.js tidak punya lookup
  // berantai — sub-nya 'PRJ-2026-001 · B.3' sebagai pembeda, dan server yang
  // menolak (422, berbahasa Indonesia) bila paket proyek lain yang dipilih.
  wbsTasks: { path: 'projects/wbs-tasks', label: 'name', sub: 'picker_label', params: { leaf: 1 }, title: 'Paket pekerjaan (WBS)' },
  /* P3 — hanya opname ke pemilik yang sudah DISETUJUI: klaim owner hanya boleh
     disusun dari volume yang sudah ditandatangani (ArInvoiceService menolak
     status lain dengan 422 bernama). Dijaga prj.view sementara layar pemakainya
     (Invoice Termin) digerbangi fin.*; loadSource menoleransi 403, pola
     `approvedIpps` di bawah. */
  approvedMeasurements: { path: 'projects/progress-measurements', label: 'code', sub: 'picker_label', params: { status: 'approved' }, title: 'Opname ke pemilik disetujui' },
  boqs: { path: 'estimation/boqs', label: 'title', sub: 'code', title: 'BOQ' },
  ahsp: { path: 'estimation/ahsp', label: 'name', sub: 'code', title: 'AHSP' },
  // picker_label (VendorResource): kode + rating + bendera "nonaktif" /
  // "dok. wajib kedaluwarsa" — masalah vendor terlihat SAAT memilih,
  // bukan sesudah PO/SPK terlanjur dibuat (temuan #68/#69).
  vendors: { path: 'procurement/vendors', label: 'name', sub: 'picker_label', title: 'Vendor' },
  subcontractors: { path: 'procurement/vendors', label: 'name', sub: 'picker_label', params: { is_subcontractor: 1 }, title: 'Subkontraktor' },
  // P4 — pemilih mandor (SP3) membaca vendor_type, bukan boolean lama.
  mandorVendors: { path: 'procurement/vendors', label: 'name', sub: 'picker_label', params: { vendor_type: 'mandor' }, title: 'Mandor' },
  laborContracts: { path: 'subcontract/labor-contracts', label: 'code', sub: 'title', title: 'SP3 mandor' },
  laborClaims: { path: 'subcontract/labor-claims', label: 'code', sub: 'notes', title: 'Opname mandor' },
  // P5 — pemilih PPK (tagihan periode alat sewa & jasa).
  workOrders: { path: 'procurement/work-orders', label: 'code', sub: 'title', title: 'PPK alat & jasa' },
  purchaseRequisitions: { path: 'procurement/purchase-requisitions', label: 'purpose', sub: 'code', title: 'Permintaan pembelian' },
  purchaseOrders: { path: 'procurement/purchase-orders', label: 'code', sub: 'notes', title: 'Pesanan pembelian' },
  rfqs: { path: 'procurement/rfqs', label: 'code', sub: 'notes', title: 'RFQ (banding penawaran)' },
  items: { path: 'inventory/items', label: 'name', sub: 'code', title: 'Item' },
  itemCategories: { path: 'inventory/item-categories', label: 'name', sub: 'code', title: 'Kategori item' },
  warehouses: { path: 'inventory/warehouses', label: 'name', sub: 'code', title: 'Gudang' },
  goodsReceipts: { path: 'inventory/goods-receipts', label: 'code', sub: 'delivery_note_no', title: 'Penerimaan barang' },
  // Rujukan gudang pada izin gerbang IMK (P0-C): transfer_id tampil sebagai
  // nomor TRF-nya di panel detail, bukan id mentah.
  transfers: { path: 'inventory/transfers', label: 'code', sub: 'notes', title: 'Transfer antar gudang' },
  // Untuk formulir retur material: memilih bon asal yang barisnya akan disalin.
  issues: { path: 'inventory/issues', label: 'code', sub: 'purpose', title: 'Pengeluaran barang' },
  employees: { path: 'hr/employees', label: 'name', sub: 'code', title: 'Karyawan' },
  users: { path: 'iam/users', label: 'name', sub: 'email', title: 'Pengguna' },
  accounts: { path: 'finance/accounts', label: 'name', sub: 'code', title: 'Akun COA' },
  postableAccounts: { path: 'finance/accounts', label: 'name', sub: 'code', params: { is_postable: 1 }, title: 'Akun COA' },
  // Laci kas kecil: hanya keluarga 1-11xx, sama dengan yang ditegakkan
  // PettyCashFundService — lihat catatan di AccountController::index.
  pettyCashAccounts: { path: 'finance/accounts', label: 'name', sub: 'code', params: { is_postable: 1, code_prefix: '1-11' }, title: 'Akun kas kecil' },
  taxes: { path: 'finance/taxes', label: 'name', sub: 'code', title: 'Pajak' },
  bankAccounts: { path: 'finance/bank-accounts', label: 'name', sub: 'bank_name', title: 'Rekening bank' },
  subcontracts: { path: 'subcontract/subcontracts', label: 'title', sub: 'code', title: 'Subkontrak' },
  progressClaims: { path: 'subcontract/progress-claims', label: 'code', sub: 'notes', title: 'Klaim progres' },
  serviceContracts: { path: 'servicedesk/contracts', label: 'name', sub: 'code', title: 'Kontrak layanan' },
  tickets: { path: 'servicedesk/tickets', label: 'title', sub: 'code', title: 'Tiket' },
  assetCategories: { path: 'assets/categories', label: 'name', sub: 'code', title: 'Kategori aset' },
  assets: { path: 'assets/assets', label: 'name', sub: 'code', title: 'Aset' },
  // picker_label (DeploymentResource): kode + nama alat, plus tanggal
  // demobilisasi bila sudah kembali — log susulan dalam rentang mobilisasi
  // yang sudah selesai tetap sah, jadi mobilisasi lama tidak disaring dari
  // picker; penjaga rentang tanggalnya ada di server (EquipmentLogService).
  deployments: { path: 'assets/deployments', label: 'code', sub: 'picker_label', title: 'Mobilisasi alat' },
  roles: { path: 'iam/roles', label: 'name', sub: null, title: 'Peran' },
  /* P1-ENG. Lokasi tapak lintas proyek dalam satu daftar (form.js tidak punya
     lookup berantai — pola wbsTasks): sub-nya kode unik GSP-T1-L01, dan server
     yang menolak lokasi milik proyek lain. Sumber ini dijaga prj.view
     (api/core/locations), bukan eng.view — loadSource menoleransi 403. */
  locations: { path: 'core/locations', label: 'name', sub: 'code', title: 'Lokasi tapak' },
  drawings: { path: 'engineering/drawings', label: 'number', sub: 'title', title: 'Register gambar' },
  drawingSubmittals: { path: 'engineering/drawing-submittals', label: 'code', sub: 'drawing_number', title: 'Persetujuan gambar (SDS)' },
  materialSubmittals: { path: 'engineering/material-submittals', label: 'code', sub: 'material_name', title: 'Persetujuan material (SMS)' },
  /* Hanya IPP berstatus disetujui: bon gudang hanya boleh menunjuk ijin yang
     sudah hidup (IssueService menolak status lain dengan 422 bernama). */
  approvedIpps: { path: 'engineering/ipp', label: 'code', sub: 'description', params: { status: 'approved' }, title: 'IPP disetujui' },
  /* P1-QC. Pustaka checklist untuk pemilih template pada lembar inspeksi —
     sub-nya kode katalog (Q7), label-nya paket pekerjaan. Prefill baris hasil
     inspeksi menarik butir template terpilih lewat get show-nya. Dijaga
     qc.view; loadSource menoleransi 403. */
  inspectionTemplates: { path: 'quality/inspection-templates', label: 'work_package', sub: 'code', title: 'Template inspeksi' },
  /* Inspeksi sebagai asal NCR (opsional — NCR mandiri tidak menunjuk satu).
     Menunjuk inspeksi mengisi tahap NCR otomatis (NcrService). */
  inspections: { path: 'quality/inspections', label: 'work_package', sub: 'code', title: 'Inspeksi mutu' },
};

const cache = new Map();
const inflight = new Map();

/*
 * How each source last ended: 'ok' | 'forbidden' | 'failed', plus whether it hit
 * the row ceiling. The cache alone cannot answer this — a 403 and a genuinely
 * empty master list both cache [], and telling a clerk "Belum ada data" when the
 * truth is "you may not read this list" sends them looking for the wrong fix.
 */
const states = new Map();

const PAGE_SIZE = 500;

/* A ceiling, so a mis-typed source cannot page forever. 20 pages is 10 000 rows
 * — far past the point where a <select> is the right control, which is why
 * hitting it says so out loud rather than truncating in silence. */
const MAX_PAGES = 20;

/** The ceiling as a row count, so a picker can name it in its warning. */
export const ROW_CEILING = MAX_PAGES * PAGE_SIZE;

/**
 * All rows of a source, cached.
 *
 * This used to fetch ONE page of 500 and take whatever came back. With 2 000
 * items, item 501 onward simply was not in the DOM: it could not be selected on
 * a PR line, a PO line, a goods receipt, a material issue or an AHSP component,
 * and nothing said so — the fetch succeeded, the list was just short. The same
 * ceiling applied to employees, vendors, customers and every other picker.
 *
 * It now pages until the source is exhausted, and if it hits the ceiling it
 * TELLS the user the list is incomplete instead of quietly handing them a
 * partial one.
 */
export async function loadSource(name) {
  if (cache.has(name)) return cache.get(name);
  if (inflight.has(name)) return inflight.get(name);

  const source = SOURCES[name];
  if (!source) throw new Error(`Sumber referensi tidak dikenal: ${name}`);

  states.set(name, { state: 'loading', truncated: false });

  const promise = fetchAllPages(source)
    .then(({ rows, truncated }) => {
      cache.set(name, rows);
      states.set(name, { state: 'ok', truncated });
      inflight.delete(name);
      return rows;
    })
    .catch((error) => {
      inflight.delete(name);
      // A picker the user has no permission for should not break the form.
      if (error.status === 403) {
        cache.set(name, []);
        states.set(name, { state: 'forbidden', truncated: false });
        return [];
      }
      // Nothing is cached, so the field's "Coba lagi" really re-fetches instead
      // of handing back a memoised empty list forever.
      states.set(name, { state: 'failed', truncated: false });
      throw error;
    });

  inflight.set(name, promise);
  return promise;
}

async function fetchAllPages(source) {
  const rows = [];

  for (let page = 1; page <= MAX_PAGES; page++) {
    const payload = await api.get(source.path, {
      per_page: PAGE_SIZE,
      page,
      ...(source.params || {}),
    });

    // Some endpoints return a plain array, some a paginator.
    const batch = Array.isArray(payload) ? payload : ((payload && payload.data) || []);
    rows.push(...batch);

    // A short page is the last page. An endpoint that ignores `page` returns the
    // same full batch every time, which the ceiling below stops.
    if (batch.length < PAGE_SIZE) return { rows, truncated: false };
  }

  /*
   * Truncation used to be announced with an 8-second toastError fired right
   * here — at preload time, which is BEFORE the form has finished rendering, so
   * it was usually gone before anyone could read it and the picker then looked
   * exactly like a complete one. It is now carried by the field itself, for as
   * long as the source stays truncated. See noticeFor().
   */
  return { rows, truncated: true };
}

/**
 * How a source stands right now, without triggering a fetch. Synchronous, so a
 * picker can render its final state in the same tick as the rest of the form —
 * every real screen calls preload() first, which means the answer is already
 * 'ok' / 'forbidden' / 'failed' by the time buildInput runs.
 */
export function sourceState(name) {
  const source = SOURCES[name] || {};
  const entry = states.get(name);
  const state = entry ? entry.state
    : (cache.has(name) ? 'ok' : (inflight.has(name) ? 'loading' : 'idle'));

  return {
    rows: cache.get(name) || [],
    status: state,
    truncated: Boolean(entry && entry.truncated),
    title: source.title || 'referensi',
  };
}

/** The one sentence a picker shows when its source is not trustworthy. */
export function noticeFor(state) {
  if (!state) return null;

  if (state.status === 'forbidden') {
    return `Daftar ${state.title} tidak bisa dimuat — akun Anda tidak punya hak aksesnya. `
      + 'Hubungi admin kalau isian ini memang harus Anda isi.';
  }
  if (state.status === 'failed') {
    return `Daftar ${state.title} gagal dimuat, jadi isian ini kosong bukan karena datanya tidak ada.`;
  }
  if (state.truncated) {
    return `Daftar ${state.title} lebih dari ${ROW_CEILING.toLocaleString('id-ID')} baris dan dipotong — `
      + 'baris di bawah itu tidak muncul di sini. Cek lewat layar daftarnya sebelum menyimpulkan datanya tidak ada.';
  }
  return null;
}

/** Cached rows without triggering a fetch (null when not loaded yet). */
export function peek(name) {
  return cache.get(name) || null;
}

export function optionsFor(name, rows) {
  const source = SOURCES[name] || {};
  return (rows || []).map((row) => ({
    value: row.id,
    label: source.sub && row[source.sub] ? `${row[source.sub]} — ${row[source.label] ?? ''}`.trim() : String(row[source.label] ?? row.id),
    row,
  }));
}

/** Display label for one id, from cache. Returns null when unknown. */
export function labelFor(name, id) {
  if (id === null || id === undefined || id === '') return null;
  const rows = cache.get(name);
  if (!rows) return null;
  const row = rows.find((candidate) => String(candidate.id) === String(id));
  if (!row) return null;
  const source = SOURCES[name];
  return source.sub && row[source.sub] ? `${row[source.sub]} — ${row[source.label] ?? ''}`.trim() : String(row[source.label] ?? id);
}

export function rowFor(name, id) {
  const rows = cache.get(name) || [];
  return rows.find((candidate) => String(candidate.id) === String(id)) || null;
}

/** Warm several sources at once; used before rendering a form or table. */
export function preload(names) {
  return Promise.all([...new Set(names.filter(Boolean))].map((name) => loadSource(name).catch(() => [])));
}

/*
 * Drop caches so freshly created master data shows up in pickers.
 *
 * The status map has to go with the rows every time. A stale 'forbidden' left
 * behind after an admin grants inv.view would keep every item picker disabled
 * with "Tidak ada hak akses" for the rest of the session, and reloading the page
 * would be the only cure — for a permission the user now actually has.
 */
export function invalidate(name) {
  if (name) {
    cache.delete(name);
    states.delete(name);
  } else {
    cache.clear();
    states.clear();
  }
}

/** Invalidate every source that reads from a given API path. */
export function invalidateByPath(path) {
  for (const [name, source] of Object.entries(SOURCES)) {
    if (source.path === path) {
      cache.delete(name);
      states.delete(name);
    }
  }
}
