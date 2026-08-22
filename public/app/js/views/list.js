/* Generic list screen: search, filters, table, pagination, row actions. */

import { api, session } from '../api.js';
import { el, clear, button, icon, badge, emptyState, errorState, skeletonTable, toast, toastError, confirmDialog, withBusy, pluck } from '../ui.js';
import { renderCell, sumColumn } from '../cells.js';
import { ENUMS } from '../enums.js';
import { loadSource, optionsFor, preload, invalidateByPath, invalidate, sourceState, noticeFor } from '../lookup.js';
import { combobox } from '../combobox.js';
import { openForm } from './form.js';
import { runAction } from './actions.js';
import { navigate } from '../router.js';
import { MONTHS, rupiah } from '../format.js';
import { csvValue, toCsv, downloadCsv, csvFilename } from '../csv.js';
import { openPrintable } from '../print.js';
import { loadPrintForms, printButtonsFor, printablePath } from '../printcatalog.js';

const state = new Map(); // per-resource UI state, kept across navigations

function stateFor(key) {
  // perPage null = belum pernah diisi; renderList mengisinya dari bawaan skema
  // sekali saja, supaya pilihan pengguna dari pemilih baris-per-halaman ikut
  // menetap di Map ini — aturan yang sama dengan sort dan filter.
  if (!state.has(key)) {
    state.set(key, { q: '', page: 1, filters: {}, perPage: null, sort: null, dir: null, dateFrom: '', dateTo: '' });
  }
  return state.get(key);
}

/* ------------------------------------------------------- state di query hash */

/* Kunci query yang selalu milik daftar; di luar ini hanya def.filters[].key
   yang diterima — tautan rakitan tidak bisa menyelundupkan parameter ke API. */
const RESERVED_PARAMS = ['page', 'q', 'sort', 'dir', 'date_from', 'date_to'];

/** Bagian path dari hash saat ini, tanpa query ('r/projects' dari '#/r/projects?page=2'). */
function hashPath() {
  const raw = (location.hash || '').replace(/^#\/?/, '');
  const cut = raw.indexOf('?');
  return cut === -1 ? raw : raw.slice(0, cut);
}

/** Bagian query dari hash saat ini ('' bila tidak ada). */
function hashQuery() {
  const raw = (location.hash || '').replace(/^#\/?/, '');
  const cut = raw.indexOf('?');
  return cut === -1 ? '' : raw.slice(cut + 1);
}

/*
 * Tautan yang dibagikan membawa state daftar di query hash-nya. Bila ada kunci
 * yang dikenali, state daftar ini di-reset lalu diisi dari URL — tautan harus
 * mereproduksi tampilan persis, bukan menumpangi sisa state sesi. URL polos
 * membiarkan Map in-memory bekerja seperti biasa, jadi navigasi sidebar tetap
 * mengingat filter yang dipasang tadi.
 */
function seedFromUrl(key, def, ui) {
  if (hashPath() !== `r/${key}`) return;

  const params = new URLSearchParams(hashQuery());
  const declared = new Set((def.filters || []).map((filter) => filter.key));
  const recognized = [...params.keys()]
    .some((name) => RESERVED_PARAMS.includes(name) || declared.has(name));
  if (!recognized) return;

  const page = Number.parseInt(params.get('page') || '1', 10);
  ui.page = Number.isInteger(page) && page > 0 ? page : 1;
  ui.q = params.get('q') || '';
  ui.sort = params.get('sort') || null;
  ui.dir = ui.sort ? (params.get('dir') === 'desc' ? 'desc' : 'asc') : null;
  /*
   * Resource yang MENDEKLARASIKAN date_from/date_to sebagai filter skema
   * (finance/journals hari ini) menampilkan nilainya dari ui.filters — bila
   * seed URL ditaruh di ui.dateFrom, input skemanya tampak kosong padahal
   * filternya terkirim, dan queryParams() menimpa setiap perubahan berikutnya
   * dengan nilai usang itu. Maka pasangan ini diarahkan ke tempat yang dibaca
   * input penampilnya; format kirim ke API identik dua-duanya. Pada resource
   * yang sudah diadopsi, migrasi filterBar tetap memindahkannya ke
   * ui.dateFrom/ui.dateTo begitu meta.date_column tiba.
   */
  const legacyDates = declared.has('date_from') || declared.has('date_to');
  ui.dateFrom = legacyDates ? '' : (params.get('date_from') || '');
  ui.dateTo = legacyDates ? '' : (params.get('date_to') || '');
  ui.filters = {};
  for (const filter of def.filters || []) {
    // Kunci reservasi lain sudah tertampung di atas; date_from/date_to hanya
    // sampai ke perulangan ini bila resource mendeklarasikannya sebagai filter
    // skema — dan saat itu justru HARUS masuk ui.filters (lihat legacyDates).
    if (RESERVED_PARAMS.includes(filter.key) && filter.key !== 'date_from' && filter.key !== 'date_to') continue;
    const value = params.get(filter.key);
    if (value !== null && value !== '') ui.filters[filter.key] = value;
  }
}

export async function renderList(host, { key, def }) {
  const ui = stateFor(key);
  // Hanya kunjungan pertama yang memakai bawaan skema: menimpa tanpa syarat di
  // sini (versi lama) membuang pilihan baris-per-halaman pengguna pada setiap
  // navigasi sidebar — persis state yang Map di atas ada untuk mengingat.
  if (!ui.perPage) ui.perPage = def.perPage || 20;
  seedFromUrl(key, def, ui);

  const canCreate = def.canCreate !== false && Boolean(def.form) && session.can(`${def.module}.create`);

  const tableHost = el('.card');
  const head = el('.page-head', [
    el('div', [el('h1', { text: def.label }), def.description ? el('.desc', { text: def.description }) : null]),
    el('.actions', [
      ...(def.collectionActions || [])
        .filter((action) => session.can(action.perm))
        .map((action) => button(action.label, {
          onClick: async (event) => {
            await runAction(action, null, def, { trigger: event.currentTarget, onDone: load });
          },
        })),
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => load() }),
      canCreate
        ? button(`Tambah ${def.labelOne}`, {
          variant: 'primary', iconName: 'plus',
          onClick: () => openForm({ def, key, onSaved: () => load() }),
        })
        : null,
    ]),
  ]);

  clear(host);
  host.append(head, tableHost);

  /*
   * Apakah gambar INI masih yang ada di layar.
   *
   * Ketiga penjaga di bawah dulu berbunyi `host.isConnected` — dan itu selalu
   * benar. host adalah #view: satu simpul yang dibuat sekali oleh buildShell()
   * dan hanya DIKOSONGKAN saat berpindah layar, tidak pernah dicabut. Jadi
   * syaratnya terbaca sebagai perlindungan sambil tidak pernah sekali pun
   * menahan apa pun.
   *
   * tableHost sebaliknya milik gambar ini sendiri: berpindah layar menjalankan
   * clear(#view), yang mencabutnya, dan renderList berikutnya memasang tableHost
   * miliknya sendiri. Maka syarat ini benar-benar bisa bernilai salah, dan
   * salahnya berarti persis "layar ini sudah ditinggalkan".
   *
   * Yang dicegahnya jujur lebih kecil daripada yang dijanjikan versi lama:
   * BUKAN "mengecat di atas layar baru". renderAll dan renderRows hanya menulis
   * ke dalam tableHost, dan tableHost yatim tidak terlihat siapa pun. Yang
   * dicegahnya adalah KERJANYA — merakit bilah filter lengkap dengan
   * combobox-nya, lalu seluruh tabel, untuk layar yang sudah ditinggalkan,
   * setiap kali sebuah endpoint lambat mendarat terlambat.
   *
   * Efek yang benar-benar keluar dari tableHost ada dua, dan keduanya di luar
   * jangkauan penjaga ini: syncUrl() menulis ke bilah alamat — ia sudah punya
   * penjaganya sendiri, hashPath(), dan penjaga itu bekerja — dan toast
   * pemulihan sort 422 di load(), yang terbit sebelum baris penjaga tercapai.
   */
  const stillOnScreen = () => tableHost.isConnected;

  // Warm every lookup the table or filters resolve so ids render as names.
  const sources = [
    ...def.columns.filter((column) => column.type === 'rel').map((column) => column.lookup),
    ...(def.filters || []).map((filter) => filter.lookup),
  ];
  preload(sources).then(() => {
    if (stillOnScreen()) renderRows();
  });

  // Formulir rumah yang dicetak dari BARIS, bukan dari layar detail — lihat
  // rowActions(). Sama seperti preload di atas: begitu jawabannya tiba,
  // barisnya digambar ulang supaya tombolnya ikut muncul.
  loadPrintForms().then(() => {
    if (stillOnScreen()) renderRows();
  });

  let payload = null;
  let loading = true;
  let error = null;

  /* Combobox filter milik bilah yang sedang terpasang. Popup-nya menempel di
     <body> (position: fixed), jadi clear(tableHost) pada renderAll TIDAK ikut
     mencabutnya: filter yang popup-nya terbuka saat muatan pertama tiba
     meninggalkan daftar yatim melayang di atas tabel sampai klik berikutnya.
     renderAll menutupnya eksplisit sebelum menukar bilah. */
  let filterCombos = [];

  /*
   * Parameter query daftar SAAT INI, dipakai load() dan — sebagai potret
   * sekali di awal jalan — ekspor CSV: satu sumber, supaya berkas ekspor
   * jujur "daftar ini, semua halaman".
   * ui.filters disebar lebih dulu: dua resource lama masih menyimpan
   * date_from/date_to di sana sampai meta.date_column tiba dan memindahkannya.
   */
  function queryParams() {
    const params = { ...ui.filters };
    if (ui.q) params.q = ui.q;
    if (ui.sort) {
      params.sort = ui.sort;
      params.dir = ui.dir === 'desc' ? 'desc' : 'asc';
    }
    if (ui.dateFrom) params.date_from = ui.dateFrom;
    if (ui.dateTo) params.date_to = ui.dateTo;
    return params;
  }

  /*
   * Cerminkan state non-default ke query hash lewat history.replaceState:
   * TIDAK memicu hashchange, jadi router.resolve() tidak berjalan ulang dan
   * fokus tidak lepas. replace, bukan push — klik halaman tidak menyampah
   * riwayat; Back dari detail tetap kembali ke daftar BESERTA state-nya karena
   * entri riwayat daftar sudah ditulisi query-nya. Default dihilangkan supaya
   * daftar yang belum disentuh tetap ber-URL polos.
   */
  function syncUrl() {
    if (hashPath() !== `r/${key}`) return; // sudah pindah layar saat load selesai

    const params = new URLSearchParams();
    if (ui.page > 1) params.set('page', String(ui.page));
    if (ui.q) params.set('q', ui.q);
    if (ui.sort) {
      params.set('sort', ui.sort);
      params.set('dir', ui.dir === 'desc' ? 'desc' : 'asc');
    }
    if (ui.dateFrom) params.set('date_from', ui.dateFrom);
    if (ui.dateTo) params.set('date_to', ui.dateTo);
    for (const [name, value] of Object.entries(ui.filters)) {
      if (value !== undefined && value !== null && value !== '') params.set(name, String(value));
    }

    const query = params.toString();
    history.replaceState(null, '', `#/r/${key}${query ? `?${query}` : ''}`);
  }

  async function load() {
    loading = true;
    error = null;
    renderAll();
    try {
      payload = await api.list(def.api, {
        ...queryParams(),
        per_page: ui.perPage,
        page: ui.page,
      });
    } catch (caught) {
      /*
       * Sort yang ditolak server (422 dengan errors.sort) hanya bisa datang
       * dari seed URL usang/rakitan — header hanya menawarkan kunci
       * meta.sortable — atau dari whitelist yang menyempit setelah deploy.
       * Server SENGAJA menolak keras (pemanggil API bertipe harus mendengar
       * refusal itu), jadi pemulihannya di sini: lepaskan sort-nya, beri tahu
       * pelan lewat toast, lalu muat ulang tanpa sort. syncUrl pada muatan
       * ulang menghapus sort dari URL, jadi F5 maupun tautan yang disalin
       * ulang sudah bersih. Tidak mungkin berulang: ui.sort sudah null,
       * permintaan kedua tidak membawa sort sama sekali.
       */
      if (caught.status === 422 && caught.errors && caught.errors.sort && ui.sort) {
        const column = def.columns.find((one) => one.key === ui.sort);
        toast(`Urutan '${column ? column.label : ui.sort}' tidak tersedia — daftar memakai urutan bawaan.`, { tone: 'info' });
        ui.sort = null;
        ui.dir = null;
        // Halaman ikut kembali ke 1, aturan yang sama dengan klik header:
        // "halaman 3" dari urutan yang dibuang menunjuk baris yang sama sekali
        // berbeda pada urutan bawaan, dan toast hanya bercerita soal urutan.
        ui.page = 1;
        return load();
      }
      error = caught;
      payload = null;
    }
    loading = false;
    syncUrl();
    if (stillOnScreen()) renderAll();
  }

  function filterBar() {
    filterCombos = [];
    const bar = el('.filters');
    const meta = (payload && payload.meta) || {};

    const searchInput = el('input', {
      type: 'text',
      placeholder: `Cari ${def.label.toLowerCase()}…`,
      value: ui.q,
      'aria-label': 'Cari',
    });
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        ui.q = searchInput.value.trim();
        ui.page = 1;
        load();
      }, 320);
    });
    bar.appendChild(el('.search', [icon('search', 14), searchInput]));

    /*
     * Pasangan tanggal muncul ketika server mengumumkan kolom tanggal dokumennya
     * (meta.date_column) — baru setelah respons pertama; satu render telat pada
     * muat dingin diterima, filterBar memang dibangun ulang tiap renderAll.
     * Dua resource lama mendeklarasikan date_from/date_to sebagai filter skema;
     * begitu meta.date_column ada, pasangan skema itu DITEKAN supaya tidak dobel
     * — schema.js read-only, jadi penekanan di sinilah mekanismenya; format
     * kirim ke API identik dua-duanya. Nilai yang terlanjur tersimpan di
     * ui.filters dipindahkan agar tidak hilang dan tidak terkirim ganda.
     */
    const dateColumn = meta.date_column || null;
    if (dateColumn) {
      if (ui.filters.date_from) {
        ui.dateFrom = ui.dateFrom || ui.filters.date_from;
        delete ui.filters.date_from;
      }
      if (ui.filters.date_to) {
        ui.dateTo = ui.dateTo || ui.filters.date_to;
        delete ui.filters.date_to;
      }
    }

    for (const filter of def.filters || []) {
      if (dateColumn && (filter.key === 'date_from' || filter.key === 'date_to')) continue;
      bar.appendChild(buildFilter(filter));
    }

    if (dateColumn) {
      bar.appendChild(dateFilterInput('Dari tanggal', ui.dateFrom, (value) => {
        ui.dateFrom = value;
        ui.page = 1;
        load();
      }));
      bar.appendChild(dateFilterInput('Sampai tanggal', ui.dateTo, (value) => {
        ui.dateTo = value;
        ui.page = 1;
        load();
      }));
    }

    if (ui.q || ui.dateFrom || ui.dateTo || Object.values(ui.filters).some(Boolean)) {
      bar.appendChild(button('Reset', {
        size: 'sm', variant: 'ghost',
        onClick: () => {
          ui.q = '';
          ui.filters = {};
          ui.dateFrom = '';
          ui.dateTo = '';
          ui.page = 1;
          load();
        },
      }));
    }

    // Ekspor memakai endpoint dan parameter yang sama dengan daftar itu sendiri,
    // jadi hak aksesnya identik dengan melihatnya.
    const hasRows = Boolean(payload && (payload.data || []).length);
    bar.appendChild(button('Ekspor CSV', {
      size: 'sm', variant: 'ghost', iconName: 'download',
      disabled: !hasRows,
      title: hasRows ? 'Unduh daftar ini (semua halaman) sebagai CSV' : 'Tidak ada data untuk diekspor',
      onClick: (event) => exportCsv(event.currentTarget),
    }));

    return bar;
  }

  function dateFilterInput(label, value, apply) {
    const input = el('input.filter-w', { type: 'date', value, 'aria-label': label, title: label });
    input.addEventListener('change', () => apply(input.value));
    return input;
  }

  /*
   * Ekspor CSV dibangun di sisi klien dengan menelusuri halaman, BUKAN lewat
   * ?export=csv server: makna sel hanya hidup di klien — kolom rel membawa
   * foreign key yang di-resolve cache lookup, label enum dari enums.js, kunci
   * bertitik dari Resource — CSV generik server hanya bisa memuntahkan id
   * mentah. Penelusuran membawa parameter query yang identik dengan daftar,
   * jadi berkasnya jujur "daftar ini, semua halaman". Halaman mana pun yang
   * gagal membatalkan seluruh ekspor — tidak pernah ada CSV terpotong
   * diam-diam.
   */
  async function exportCsv(trigger) {
    const meta = (payload && payload.meta) || {};
    const total = meta.total ?? (payload && payload.data ? payload.data.length : 0);

    // Cermin plafon 10.000 milik lookup.js: di atas itu bukan ekspor lagi.
    if (total > 10000) {
      toast(`Terlalu banyak baris (${total.toLocaleString('id-ID')}). Persempit dengan filter tanggal dulu.`, { tone: 'err' });
      return;
    }
    if (total > 1000) {
      const proceed = await confirmDialog({
        title: 'Ekspor CSV',
        message: `Akan mengunduh ${total.toLocaleString('id-ID')} baris dalam beberapa permintaan. Lanjutkan?`,
        confirmLabel: 'Ekspor',
        tone: 'primary',
      });
      if (!proceed) return;
    }

    try {
      await withBusy(trigger, async () => {
        /*
         * Parameter DIBEKUKAN saat tombol diklik: penelusuran memakan beberapa
         * permintaan dan bilah filter tetap hidup selama itu (withBusy hanya
         * menonaktifkan tombolnya sendiri, dan load() dari filter yang diubah
         * membangun ulang bilah). Membaca queryParams() per halaman menjahit
         * dua query berbeda ke satu berkas tanpa peringatan; potret ini
         * menjamin berkasnya utuh milik daftar SAAT ekspor dimulai.
         */
        const params = queryParams();
        const rows = [];
        let page = 1;
        let lastPage = 1;
        do {
          const batch = await api.list(def.api, { ...params, per_page: 200, page });
          rows.push(...((batch && batch.data) || []));
          lastPage = (batch && batch.meta && batch.meta.last_page) || 1;
          page += 1;
        } while (page <= lastPage);

        const csv = toCsv(
          def.columns.map((column) => column.label),
          rows.map((row) => def.columns.map((column) => csvValue(row, column))),
        );
        downloadCsv(csvFilename(def.label), csv);
      });
    } catch (caught) {
      toastError(caught);
    }
  }

  function buildFilter(filter) {
    const apply = (value) => {
      if (value === '' || value === undefined) delete ui.filters[filter.key];
      else ui.filters[filter.key] = value;
      ui.page = 1;
      load();
    };

    if (filter.type === 'date') {
      const input = el('input.filter-w', {
        type: 'date', value: ui.filters[filter.key] || '', 'aria-label': filter.label, title: filter.label,
      });
      input.addEventListener('change', () => apply(input.value));
      return input;
    }

    if (filter.type === 'number') {
      const input = el('input.filter-w', {
        type: 'number', placeholder: filter.label, value: ui.filters[filter.key] || '', 'aria-label': filter.label,
      });
      input.addEventListener('change', () => apply(input.value));
      return input;
    }

    // Enum menang atas lookup, urutan yang sama dengan rantai else-if <select>
    // lama — filter yang membawa keduanya tidak boleh berpindah kontrol.
    if (filter.lookup && !filter.enum) return lookupFilter(filter, apply);

    const select = el('select.filter-w', { 'aria-label': filter.label });
    select.appendChild(el('option', { value: '', text: filter.label }));

    if (filter.type === 'boolFilter') {
      select.appendChild(el('option', { value: '1', text: 'Ya' }));
      select.appendChild(el('option', { value: '0', text: 'Tidak' }));
    } else if (filter.type === 'month') {
      MONTHS.forEach((label, index) => select.appendChild(el('option', { value: index + 1, text: label })));
    } else if (filter.enum) {
      (ENUMS[filter.enum] || []).forEach((option) =>
        select.appendChild(el('option', { value: option.value, text: option.label })));
    }

    select.value = ui.filters[filter.key] || '';
    select.addEventListener('change', () => apply(select.value));
    return select;
  }

  /*
   * Filter lookup memakai combobox.js yang sama dengan form — BUKAN <select>
   * yang mem-preload seluruh sumber sebagai <option>: filter proyek/vendor pada
   * daftar PO menaruh ribuan node di DOM per bilah filter, dan memilih satu
   * berarti scroll manual di dropdown native (temuan #1, separuh yang tersisa).
   *
   * Bentuk filter = "kosong berarti tanpa filter": allowEmpty memetakan baris
   * "—", tombol ×, dan kotak yang dikosongkan ke penghapusan kunci filter —
   * makna yang sama persis dengan <option value=""> milik <select> lama.
   */
  function lookupFilter(filter, apply) {
    const optionsOf = (rows) => {
      const options = optionsFor(filter.lookup, rows);
      // Kontrak nilai <select> lama: valueKey mengirim kolom baris, bukan id
      // (filter Peran di iam/users mengirim 'name').
      return filter.valueKey
        ? options.map((option) => ({ ...option, value: option.row[filter.valueKey] }))
        : options;
    };

    const current = ui.filters[filter.key];

    /* Id seed URL yang tidak ada di sumber tetap terlihat ('#42 (tidak ada di
       daftar)'). <select> lama diam: tanpa <option> yang cocok ia menampilkan
       label filter seolah tidak aktif, padahal filternya terkirim — dan daftar
       kosong tampak seperti bug tanpa sebab. */
    const labelOf = (options, state) => {
      if (current === undefined || current === null || current === '') return '';
      const found = options.find((option) => String(option.value) === String(current));
      if (found) return found.label;
      return state.status === 'ok' ? `#${current} (tidak ada di daftar)` : `#${current}`;
    };

    // Kalimat status form.js (lookupPlaceholder) untuk keadaan tak terpakai;
    // saat sumber sehat, label filternya sendiri — pengganti option pertama
    // <select> lama yang memperkenalkan filter ini.
    const placeholderOf = (state) => {
      if (state.status === 'idle' || state.status === 'loading') return 'Memuat…';
      if (state.status === 'forbidden') return 'Tidak ada hak akses';
      if (state.status === 'failed') return 'Gagal memuat';
      return filter.label;
    };

    const state = sourceState(filter.lookup);
    const options = optionsOf(state.rows);

    const combo = combobox({
      value: current === undefined || current === '' ? null : current,
      label: labelOf(options, state),
      options,
      placeholder: placeholderOf(state),
      allowEmpty: true, // kosong = tanpa filter
      notice: noticeFor(state),
      onRetry: state.status === 'failed' ? () => retry() : null,
    });

    const sync = () => {
      const next = sourceState(filter.lookup);
      const nextOptions = optionsOf(next.rows);
      combo.setOptions(nextOptions, {
        label: labelOf(nextOptions, next),
        placeholder: placeholderOf(next),
        notice: noticeFor(next),
        onRetry: next.status === 'failed' ? () => retry() : null,
      });
    };

    const retry = () => {
      invalidate(filter.lookup); // status ikut, atau 'failed' basi menolak sembuh
      sync();                    // langsung 'Memuat…' supaya kliknya terasa
      loadSource(filter.lookup).then(sync, sync);
    };

    // preload() di atas sudah menghangatkan sumber yang sama (loadSource
    // menggabungkan lewat peta inflight); jalur ini untuk bilah yang dibangun
    // sebelum sumbernya tiba — dan penangan gagalnya, karena janji dari
    // pemanggilan ulang pasca-'failed' tidak lagi tertangkap preload().
    if (state.status !== 'ok') loadSource(filter.lookup).then(sync, sync);

    /* Lebar dipatok sejajar rentang select.filter-w (min 138 / max 230):
       .combo mengisi 100% induknya, jadi sebagai item flex telanjang di
       .filters lebarnya ikut lebar intrinsik <input> — berubah-ubah antar
       browser/font. Kelas filter-w tidak bisa dipakai ulang: selektornya
       terkualifikasi elemen (input./select.) dan tidak mengenai .combo-field. */
    combo.node.style.width = '190px';
    combo.input.setAttribute('aria-label', filter.label);
    combo.input.title = filter.label;

    // Satu-satunya event yang dipancarkan combobox, dan hanya saat nilai
    // benar-benar berpindah — tidak ada muat ulang sia-sia dari settle() yang
    // menetapkan kembali nilai yang sama.
    combo.input.addEventListener('change', () => {
      const picked = combo.read();
      apply(picked === null ? '' : picked);
    });

    filterCombos.push(combo);
    return combo.node;
  }

  /* Formulir rumah pada BARIS daftar, dan hanya untuk layar tanpa detail.
     Layar yang punya detail membawa tombolnya di sana (detail.js), dan
     mengulanginya per baris hanya akan meramaikan tabel.

     Ini bukan kehalusan: 'projects/weekly-progress' berstatus noDetail SEJAK
     AWAL sementara tombol "Detail Schedule"-nya dideklarasikan di schema.js —
     formulirnya jalan, endpoint-nya jalan, tapi tidak ada satu layar pun yang
     membawa tombolnya. Barisnya adalah satu-satunya tempat yang jujur. */
  function printRowButtons(row) {
    if (!def.noDetail) return [];

    return printButtonsFor(def, key)
      .filter((form) => row[form.idField || 'id'])
      .map((form) => button('', {
        size: 'sm',
        variant: 'ghost',
        iconName: 'print',
        title: `Cetak ${form.label} dalam format formulir perusahaan`,
        onClick: (event) => {
          event.stopPropagation();
          openPrintable(printablePath(form, row), event.currentTarget);
        },
      }));
  }

  function rowActions(row) {
    const wrap = el('div', { style: { display: 'flex', gap: '4px', justifyContent: 'flex-end' } });

    printRowButtons(row).forEach((btn) => wrap.appendChild(btn));

    const canEdit = def.canEdit !== false && Boolean(def.form) && session.can(`${def.module}.update`) &&
      (!def.editableWhen || def.editableWhen(row));
    const canDelete = def.canDelete !== false && session.can(`${def.module}.delete`) &&
      (!def.deletableWhen || def.deletableWhen(row));

    if (canEdit) {
      wrap.appendChild(button('', {
        size: 'sm', variant: 'ghost', iconName: 'edit', title: 'Ubah',
        onClick: (event) => {
          event.stopPropagation();
          openForm({ def, key, row, onSaved: () => load() });
        },
      }));
    }

    if (canDelete) {
      wrap.appendChild(button('', {
        size: 'sm', variant: 'ghost', iconName: 'trash', title: def.deleteLabel || 'Hapus',
        onClick: async (event) => {
          event.stopPropagation();
          await confirmDialog({
            title: `${def.deleteLabel || 'Hapus'} ${def.labelOne}`,
            message: def.deleteConfirm || `${def.deleteLabel || 'Hapus'} "${row.code || row.name || row.title || `#${row.id}`}"? Tindakan ini tidak dapat dibatalkan.`,
            confirmLabel: def.deleteLabel || 'Hapus',
            onConfirm: async () => {
              await api.del(`${def.api}/${row.id}`);
              invalidateByPath(def.api);
              toast(`${def.labelOne} dihapus.`);
              load();
            },
          });
        },
      }));
    }

    return wrap.childElementCount ? wrap : null;
  }

  function renderRows() {
    /*
     * Dipanggil dari tiga tempat, dan dua di antaranya JANJI yang mendarat
     * kapan saja: preload() sumber lookup dan loadPrintForms() di atas
     * menggambar ulang baris begitu jawabannya tiba. Keduanya rutin menang
     * balapan melawan permintaan daftar itu sendiri — dan tanpa penjaga ini
     * renderRows menghapus kerangka pemuatan lalu menaruh keadaan kosong
     * "Belum ada <label> yang tercatat." di tempatnya, karena payload memang
     * masih null. Di koneksi lambat pembaca melihat "tidak ada data" dulu,
     * baru datanya: layar yang berbohong lalu meralat diri.
     *
     * `error` ikut dijaga karena persis sama: panel error yang sudah tergambar
     * akan tertimpa keadaan kosong, yang berarti "tidak ada apa-apa di sini"
     * padahal yang benar "kami tidak berhasil menanyakannya".
     *
     * Aman untuk renderAll(), satu-satunya pemanggil yang tersisa: ia hanya
     * sampai ke sini setelah kedua cabang itu lewat.
     */
    if (loading || error) return;

    const body = tableHost.querySelector('.list-body');
    if (!body) return;
    clear(body);

    const rows = payload ? payload.data || [] : [];

    if (!rows.length) {
      body.appendChild(emptyState(
        ui.q || ui.dateFrom || ui.dateTo || Object.keys(ui.filters).length
          ? 'Tidak ada data yang cocok dengan pencarian atau filter.'
          : `Belum ada ${def.label.toLowerCase()} yang tercatat.`,
        {
          action: canCreate && !ui.q
            ? button(`Tambah ${def.labelOne}`, { variant: 'primary', iconName: 'plus', onClick: () => openForm({ def, key, onSaved: () => load() }) })
            : null,
        },
      ));
      return;
    }

    const hasActions = rows.some((row) => rowActions(row));
    const openable = !def.noDetail;
    const meta = (payload && payload.meta) || {};
    const sortable = Array.isArray(meta.sortable) ? meta.sortable : [];

    /* Total kolom uang untuk HALAMAN INI — dijumlah dari payload yang sudah
       di layar, karena total seluruh hasil filter butuh dukungan API yang
       belum ada. Labelnya menyebut cakupannya supaya angka Rp di kaki tabel
       tidak pernah terbaca sebagai total outstanding seluruh filter. */
    const moneyColumns = def.columns.filter((column) => column.type === 'currency' || column.type === 'currency2');

    const table = el('table.data', [
      el('thead', el('tr', [
        ...def.columns.map((column) => headerCell(column, sortable)),
        hasActions ? el('th.right', { text: '', style: { width: '1%' } }) : null,
      ])),
      el('tbody', rows.map((row) => {
        const tr = el(`tr${openable ? '.clickable' : ''}`, [
          ...def.columns.map((column) =>
            el(`td${column.align ? `.${column.align}` : ''}${column.type === 'code' ? '.code' : ''}${column.hideOnNarrow ? '.hide-narrow' : ''}`, renderCell(row, column))),
          hasActions ? el('td.right', rowActions(row)) : null,
        ]);
        if (openable) {
          tr.addEventListener('click', () => navigate(`d/${key}/${row.id}`));
        }
        return tr;
      })),
      moneyColumns.length
        ? el('tfoot', el('tr', [
          ...def.columns.map((column, index) => {
            const narrow = column.hideOnNarrow ? '.hide-narrow' : '';
            if (column.type === 'currency' || column.type === 'currency2') {
              return el(`td.right${narrow}`, {
                text: rupiah(sumColumn(rows, column.key), column.type === 'currency2' ? { decimals: 2 } : {}),
              });
            }
            return el(`td${column.align ? `.${column.align}` : ''}${narrow}`, { text: index === 0 ? 'Total halaman ini' : '' });
          }),
          hasActions ? el('td') : null,
        ]))
        : null,
    ]);

    body.appendChild(el('.table-wrap', table));
    body.appendChild(pager());
  }

  /*
   * Judul kolom menjadi <button> sungguhan hanya bila server mengumumkannya
   * bisa diurutkan (meta.sortable, whitelist per-controller): tombol natif itu
   * fokusable dan merespons Enter/Space tanpa penanganan tombol baru — standar
   * aksesibilitas yang sama dengan roving-tabindex baris. Siklus klik:
   * asc → desc → kembali ke urutan default. Kolom turunan (customer.name dsb.)
   * memang tidak pernah muncul di meta.sortable dan tetap diam — benar; seam
   * `column.sortKey` di schema.js dicatat untuk hari itu, tidak dibangun.
   */
  function headerCell(column, sortable) {
    // hide-narrow ikut di th: td yang hilang tanpa judulnya membuat seluruh
    // baris bergeser satu kolom di bawah 760px.
    const th = el(
      `th${column.align ? `.${column.align}` : ''}${column.hideOnNarrow ? '.hide-narrow' : ''}`,
      { style: column.width ? { width: column.width } : {} },
    );

    if (!sortable.includes(column.key)) {
      th.textContent = column.label;
      return th;
    }

    const active = ui.sort === column.key;
    if (active) th.setAttribute('aria-sort', ui.dir === 'desc' ? 'descending' : 'ascending');

    const control = el('button.th-sort', { type: 'button', title: 'Urutkan' }, [
      column.label,
      // Satu penanda saja, pada kolom yang sedang aktif.
      active ? el('span', { text: ui.dir === 'desc' ? '▼' : '▲', 'aria-hidden': 'true' }) : null,
    ]);
    control.addEventListener('click', () => {
      if (ui.sort !== column.key) {
        ui.sort = column.key;
        ui.dir = 'asc';
      } else if (ui.dir === 'asc') {
        ui.dir = 'desc';
      } else {
        ui.sort = null;
        ui.dir = null;
      }
      ui.page = 1; // urutan baru = halaman baru; halaman 3 dari urutan lama menyesatkan
      load();
    });

    th.appendChild(control);
    return th;
  }

  function pager() {
    const meta = (payload && payload.meta) || {};
    const total = meta.total;
    const lastPage = meta.last_page || 1;
    const from = meta.from || 0;
    const to = meta.to || 0;

    /*
     * Pemilih baris-per-halaman: rentang terdokumentasi 25/50/100/200 plus
     * bawaan resource ini (20 pada kebanyakan daftar) — tanpa opsi bawaan itu
     * kontrol menampilkan "25" padahal yang berlaku 20, dan pengguna tidak
     * pernah bisa kembali ke bawaan setelah memilih. Ekspor CSV tidak
     * terpengaruh: penelusurannya memakai per_page 200 miliknya sendiri dan
     * selalu mengambil semua halaman, berapa pun pilihan di sini.
     */
    const choices = [...new Set([def.perPage || 20, 25, 50, 100, 200])].sort((a, b) => a - b);
    const perPageSelect = el('select', {
      'aria-label': 'Baris per halaman',
      title: 'Baris per halaman',
      style: { width: 'auto', height: '28px', fontSize: '12.5px' },
    }, choices.map((size) => el('option', { value: String(size), text: `${size} / halaman` })));
    perPageSelect.value = String(ui.perPage);
    perPageSelect.addEventListener('change', () => {
      ui.perPage = Number(perPageSelect.value);
      // Aturan yang sama dengan ganti urutan: "halaman 3" dari ukuran halaman
      // lama menunjuk baris yang sama sekali berbeda pada ukuran baru.
      ui.page = 1;
      load();
    });

    return el('.pager', [
      el('span', {
        text: total !== undefined
          ? `Menampilkan ${from}–${to} dari ${total} data`
          : `Halaman ${meta.current_page || ui.page}`,
      }),
      el('.spacer'),
      perPageSelect,
      button('', {
        size: 'sm', iconName: 'back', title: 'Sebelumnya',
        disabled: (meta.current_page || ui.page) <= 1,
        onClick: () => { ui.page -= 1; load(); },
      }),
      el('span.num', { text: `${meta.current_page || ui.page} / ${lastPage}` }),
      button('', {
        size: 'sm', iconName: 'chevronRight', title: 'Berikutnya',
        disabled: (meta.current_page || ui.page) >= lastPage,
        onClick: () => { ui.page += 1; load(); },
      }),
    ]);
  }

  function renderAll() {
    // Sebelum bilah lama dibuang — lihat catatan filterCombos di atas.
    filterCombos.forEach((combo) => combo.closePopup());
    clear(tableHost);
    tableHost.appendChild(filterBar());

    const body = el('.list-body');
    tableHost.appendChild(body);

    if (loading) {
      body.appendChild(skeletonTable(6, Math.min(def.columns.length, 6)));
      return;
    }
    if (error) {
      body.appendChild(el('.card-body', errorState(error, () => load())));
      return;
    }
    renderRows();
  }

  await load();
}
