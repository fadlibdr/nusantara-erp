/* Generic list screen: search, filters, table, pagination, row actions. */

import { api, session } from '../api.js';
import { el, clear, button, icon, badge, emptyState, errorState, skeletonTable, toast, toastError, confirmDialog, withBusy, pluck, pageHeader } from '../ui.js';
import { renderCell, sumColumn } from '../cells.js';
import { ENUMS } from '../enums.js';
import { loadSource, optionsFor, preload, invalidateByPath, invalidate, sourceState, noticeFor } from '../lookup.js';
import { combobox } from '../combobox.js';
import { openForm, promptFields, startForm } from './form.js';
import { canCreateResource } from '../create.js';
import { runAction, postAction, isBulkAction } from './actions.js';
import { prefs } from '../prefs.js';
import { navigate, replacePath } from '../router.js';
import { MONTHS, rupiah, date } from '../format.js';
import { csvValue, toCsv, downloadCsv, csvFilename } from '../csv.js';
import { downloadPdf, openPrintable, xlsxName } from '../print.js';
import { loadPrintForms, printButtonsFor, printablePath, printableFor, xlsxPath } from '../printcatalog.js';

const state = new Map(); // per-resource UI state, kept across navigations

function stateFor(key, def) {
  // perPage null = belum pernah diisi; renderList mengisinya dari bawaan skema
  // sekali saja, supaya pilihan pengguna dari pemilih baris-per-halaman ikut
  // menetap di Map ini — aturan yang sama dengan sort dan filter.
  if (!state.has(key)) {
    /*
     * `defaultFilters` skema: nilai awal SEKALI, pada kunjungan pertama sesi
     * ini. Sesudah itu ia milik pemakainya — mengosongkan saringan tidak boleh
     * dilawan setiap kali layarnya dibuka ulang.
     *
     * Antrean kerja harian (crm/activities) memerlukannya: tanpa nilai awal ia
     * terbuka di ARSIP — 105 aktivitas yang selesai bertahun lalu berdiri di
     * atas pekerjaan hari ini, karena urutannya due_at menaik LINTAS keadaan —
     * dan pemberitahuan 08:30 hanya membawa saringannya bila orangnya menekan
     * tautan itu, bukan bila ia membuka layarnya dari sidebar (verifikasi F-3
     * putaran 2, 8 Sep 2026).
     */
    const defaults = (def && def.defaultFilters) || {};
    state.set(key, {
      q: '', page: 1, filters: { ...defaults }, perPage: null, sort: null, dir: null, dateFrom: '', dateTo: '',
    });
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
  const ui = stateFor(key, def);
  // Hanya kunjungan pertama yang memakai bawaan skema: menimpa tanpa syarat di
  // sini (versi lama) membuang pilihan baris-per-halaman pengguna pada setiap
  // navigasi sidebar — persis state yang Map di atas ada untuk mengingat.
  if (!ui.perPage) ui.perPage = def.perPage || 20;
  seedFromUrl(key, def, ui);

  // def.createPerm menimpa `${module}.create` untuk register lintas-modul:
  // menulis log BBM (assets/equipment-logs) digerbangi prj.update — memberi
  // site manager ast.create hanya demi tombol ini berarti memberinya hak
  // MENCETAK ASET, kuasa yang jauh lebih lebar. Cermin gerbang POST servernya.
  // Predikat yang SAMA dengan Buat ▾ di bilah atas (js/create.js, UX-D.4): dua pintu, satu jawaban.
  const canCreate = canCreateResource(def);

  const tableHost = el('.card');
  /* Kepala halaman lewat pageHeader() (UX-E1.1, README E1): markup yang sama
     persis dengan yang dulu dirakit tangan di sini — h1, .desc, .actions — dari
     satu pembangun. `def.description` adalah kunci skema opsional; kalimatnya
     juga menjadi petunjuk kartu layar di beranda modulnya (module.js).
     Aksi koleksi SENGAJA tanpa `variant`: Tambah harus tetap satu-satunya
     .btn.primary di bilah ini — onboarding menyorotnya lewat selektor itu, dan
     servicedesk/preventive-schedules mendeklarasikan variant 'primary'. */
  const head = pageHeader({
    title: def.label,
    description: def.description,
    actions: [
      ...(def.collectionActions || [])
        // canAct(): lihat session.canAct — aksi koleksi tidak pernah berbentuk
        // pintu keputusan dokumen, jadi ia menjawab sama dengan can().
        .filter((action) => session.canAct(action))
        .map((action) => button(action.label, {
          onClick: async (event) => {
            await runAction(action, null, def, { trigger: event.currentTarget, onDone: load });
          },
        })),
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => load() }),
      canCreate
        ? button(`Tambah ${def.labelOne}`, {
          variant: 'primary', iconName: 'plus',
          onClick: () => startForm({ def, key, onSaved: afterCreate }),
        })
        : null,
    ],
  });

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

  /*
   * Urutan muat (UX-E1.2): hanya jawaban permintaan TERBARU yang boleh melukis.
   *
   * load() tidak pernah membatalkan permintaan sebelumnya, jadi jawaban yang
   * tiba TERLAMBAT menang — dan itu sudah terjadi sebelum paket ini: kotak Cari
   * yang diketik pelan memicu dua permintaan, dan bila yang pertama lebih
   * lambat, daftar yang tergambar milik kata yang sudah dihapus sementara kotak,
   * chip, dan bilah alamat menyebut kata yang baru. Tab status (UX-E1.8)
   * memperbesarnya: panah kanan tiga kali = tiga muatan dalam sedetik.
   * Nomor urut ini membuat jawaban usang dibuang diam-diam; permintaan yang
   * lebih baru memiliki layarnya, termasuk keadaan "memuat"-nya.
   */
  let loadSeq = 0;

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
  /*
   * Keadaan layar ini sebagai query string — SATU penyerialisasi, dipakai bilah
   * alamat (syncUrl) dan tampilan tersimpan (UI-4c). Dua salinan akan hanyut,
   * dan hanyutnya berarti sebuah tampilan yang disimpan hari ini memulihkan
   * saringan yang BERBEDA besok.
   */
  function stateQuery({ withPage } = {}) {
    const params = new URLSearchParams();
    if (withPage && ui.page > 1) params.set('page', String(ui.page));
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
    return params.toString();
  }

  /*
   * `replacePath()`, bukan `history.replaceState()` langsung.
   *
   * Router menyimpan hash PENUH — query dan semuanya — di `current`, dan
   * pendengar hashchange-nya melewatkan hash yang sama dengan `current`. Sebuah
   * penulisan URL di belakang punggungnya membuat `current` basi, dan basinya
   * berbunyi: buka `?status=draft`, ganti saringan ke `approved` (syncUrl
   * menulis URL baru, `current` masih `draft`), lalu ikuti tautan ke
   * `?status=draft` — hash-nya sama dengan `current`, jadi router MELEWATKANNYA.
   * Layarnya tetap menampilkan `approved` sementara bilah alamat berkata
   * `draft`, dan tidak ada galat di mana pun.
   *
   * Cacat ini lebih tua dari paket ini (syncUrl selalu menulis sendiri), tetapi
   * tampilan tersimpan membuatnya bisa ditemui dalam satu alur biasa:
   * terapkan tampilan, lalu ikuti tautan ke keadaan yang tadi dibuka.
   * `replacePath` ADA justru untuk ini — ia menulis URL dan memberi tahu router
   * bahwa itulah lokasi sekarang.
   */
  function syncUrl() {
    if (hashPath() !== `r/${key}`) return; // sudah pindah layar saat load selesai

    const query = stateQuery({ withPage: true });
    replacePath(`r/${key}${query ? `?${query}` : ''}`);
  }

  async function load() {
    const seq = ++loadSeq;
    loading = true;
    error = null;
    /* Seleksi mati di setiap muatan — halaman lain, filter lain, muat ulang.
       Sebuah centang yang bertahan melewati perubahan filter menunjuk dokumen
       yang TIDAK ADA LAGI di layar, dan bilah seleksi akan dengan tenang
       menawarkan "Posting (3)" untuk tiga baris yang tidak bisa dilihat
       siapa pun sebelum menekannya. */
    picked.clear();
    syncBulk();
    renderAll();
    let answer = null;
    let failure = null;
    try {
      answer = await api.list(def.api, {
        ...queryParams(),
        per_page: ui.perPage,
        page: ui.page,
      });
    } catch (caught) {
      // Permintaan yang lebih baru sudah berjalan: penolakan ini milik keadaan
      // yang sudah ditinggalkan, termasuk pemulihan sort di bawah.
      if (seq !== loadSeq) return;
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
      failure = caught;
    }
    if (seq !== loadSeq) return;
    payload = failure ? null : answer;
    error = failure;
    loading = false;
    syncUrl();
    if (stillOnScreen()) renderAll();
  }

  /* Satu definisi "sedang disaring" dan satu jalan menghapusnya, dipakai
     tombol Reset di bilah filter DAN tombol "Hapus filter" di keadaan kosong
     (P1-B) — dua tombol dengan dua salinan logika akan hanyut. */
  function isFiltered() {
    return Boolean(ui.q || ui.dateFrom || ui.dateTo || Object.values(ui.filters).some(Boolean));
  }

  function resetFilters() {
    ui.q = '';
    ui.filters = {};
    ui.dateFrom = '';
    ui.dateTo = '';
    ui.page = 1;
    load();
  }

  /*
   * ============================ SELEKSI + AKSI MASSAL (UI-4b) ================
   *
   * Yang ditambahkan: kolom centang, bilah seleksi, dan satu putaran BERURUTAN
   * yang menjalankan aksi yang sudah dideklarasikan schema.js pada tiap baris
   * terpilih.
   *
   * Ia tidak digambar di mana-mana kecuali di layar yang benar-benar punya
   * aksi massal. 97 sumber daya; 38 mendeklarasikan aksi baris (78 aksi);
   * setelah delapan bentuk per-dokumen dan pintu keputusan disingkirkan
   * (isBulkAction), 21 layar dengan 26 aksi yang tersisa. Di 76 layar sisanya
   * TIDAK ADA satu pun kotak centang — aturan yang sama dengan setujui massal
   * di #/tugas, tempat `batch_cap` kosong berarti nol kotak centang: sebuah
   * kotak yang bisa dicentang lalu tidak menawarkan apa pun adalah janji yang
   * tidak ditepati.
   *
   * Izin digerbangi sekali di sini (session.canAct), keadaan baris per baris
   * (action.when) — dua gerbang yang sama persis dengan bilah aksi dokumen,
   * dipanggil dari tempat yang sama, supaya bilah seleksi tidak pernah bisa
   * menawarkan apa yang halaman dokumen menolak menawarkan.
   */
  const bulkActions = (def.actions || []).filter(isBulkAction).filter((action) => session.canAct(action));
  const picking = bulkActions.length > 0;
  const picked = new Map(); // id -> row; barisnya ikut disimpan, bukan idnya saja

  const allowsBulk = (action, row) => !action.when || action.when(row);
  const bulkableRow = (row) => bulkActions.some((action) => allowsBulk(action, row));
  const codeOf = (row) => row.code || row.number || `#${row.id}`;

  const bulkCount = el('span');
  const bulkButtons = el('.cluster');
  /* Bilah seleksi HANYA ada saat ada yang terpilih (prototipe: `.selbar`
     "muncul hanya bila ada baris tercentang"). Ia tidak boleh menempati ruang
     permanen di atas tabel untuk mengumumkan bahwa nol baris terpilih. */
  const bulkBar = picking
    ? el('.selbar', { hidden: true }, [
      icon('check', 16),
      bulkCount,
      el('span.spacer'),
      bulkButtons,
      button('Batalkan pilihan', { size: 'sm', variant: 'ghost', onClick: () => clearPicked() }),
    ])
    : null;

  function clearPicked() {
    picked.clear();
    syncBulk();
    renderRows();
  }

  function syncBulk() {
    if (!bulkBar) return;
    bulkBar.hidden = picked.size === 0;
    if (!picked.size) return;

    bulkCount.textContent = `${picked.size} baris terpilih`;
    clear(bulkButtons);
    const chosen = [...picked.values()];
    bulkActions.forEach((action) => {
      /* Angka di label adalah angka yang AKAN dikerjakan, bukan jumlah yang
         dicentang. Keduanya berbeda begitu satu baris terpilih tidak berada
         dalam keadaan yang tepat untuk aksi ini — dan "Posting (3)" yang
         memposting dua adalah tombol yang berbohong tepat sebelum menulis
         jurnal. */
      const n = chosen.filter((row) => allowsBulk(action, row)).length;
      bulkButtons.appendChild(button(`${action.label} (${n})`, {
        size: 'sm',
        variant: action.variant || 'primary',
        disabled: n === 0,
        title: n === chosen.length
          ? undefined
          : `${chosen.length - n} dari ${chosen.length} baris terpilih tidak dalam keadaan yang tepat untuk "${action.label}" dan akan dilewati.`,
        onClick: (event) => runBulk(action, event.currentTarget),
      }));
    });
  }

  /*
   * Putaran massalnya.
   *
   * BERURUTAN, bukan paralel — alasan yang sama dengan setujui massal di
   * tugas.js: tiap panggilan menulis jurnal atau stok, ada batas laju 120
   * permintaan/menit, dan sepuluh permintaan serempak yang separuhnya kena 429
   * adalah hasil yang tidak bisa dibaca siapa pun.
   *
   * Kegagalan TIDAK menghentikan sisanya, dan tiap kegagalan disebut dengan
   * NOMOR DOKUMENNYA: "8 diposting, 2 gagal" yang tidak menyebut mana adalah
   * laporan yang memaksa orang memeriksa sepuluh dokumen satu per satu — yaitu
   * persis pekerjaan yang tombol ini ada untuk menghapus.
   *
   * Lewat postAction(), bukan runAction(): runAction menelan galat menjadi
   * toast dan selalu resolve `undefined`, jadi sebuah putaran di atasnya tidak
   * bisa membedakan yang berhasil dari yang ditolak. Lihat komentarnya di
   * actions.js — yang dibagi adalah permintaannya, bukan pembungkusnya.
   */
  async function runBulk(action, trigger) {
    const chosen = [...picked.values()].filter((row) => allowsBulk(action, row));
    if (!chosen.length) return;

    /*
     * Konfirmasi SEKALI untuk seluruh batch — dan selalu, termasuk untuk aksi
     * yang lewat tombol barisnya tidak berkonfirmasi.
     *
     * Bukan karena lebih banyak dialog lebih aman (P1-G membuang modal catatan
     * persetujuan justru karena itu), melainkan karena kekeliruannya berbeda
     * JENIS: satu klik keliru berharga satu dokumen yang halamannya masih
     * terbuka, satu klik massal keliru berharga seluruh halaman, dan tidak ada
     * satu pun aksi di sini yang punya jalan kembali. Dialog ini juga
     * satu-satunya tempat orang membaca DAFTAR NOMOR yang akan disentuh.
     *
     * `action.confirm` berbentuk fungsi baris sengaja TIDAK dipanggil: ia
     * menulis kalimat untuk SATU dokumen ("Surat penagihan ke-2 INV/… akan
     * dicetak"), dan memanggilnya untuk baris pertama lalu menampilkannya di
     * atas sepuluh nomor adalah kalimat yang salah untuk sembilan di antaranya.
     * Yang berbentuk string adalah akibat yang berlaku untuk semuanya, jadi ia
     * ikut dicetak.
     */
    const sentence = typeof action.confirm === 'string' ? `${action.confirm} ` : '';
    const listed = chosen.map(codeOf);
    const shown = listed.slice(0, 12).join(', ');
    const ok = await confirmDialog({
      title: `${action.label} ${chosen.length} ${def.labelOne.toLowerCase()}`,
      message: `${sentence}Tindakan ini berjalan satu per satu pada ${chosen.length} dokumen: ${shown}${listed.length > 12 ? `, dan ${listed.length - 12} lainnya` : ''}.`,
      confirmLabel: `${action.label} ${chosen.length}`,
      tone: action.variant === 'danger' ? 'danger' : 'primary',
    });
    if (!ok) return;

    const done = [];
    const failed = [];
    await withBusy(trigger, async () => {
      for (const row of chosen) {
        try {
          // `action.body`: muatan tetap milik aksinya, aturan yang sama dengan
          // runAction — tanpa ini aksi bertubuh tetap akan terkirim kosong.
          await postAction(action, row, def, { ...(action.body || {}) });
          done.push(codeOf(row));
        } catch (error) {
          failed.push(`${codeOf(row)}: ${(error && error.message) || 'gagal'}`);
        }
      }
    });

    if (done.length) toast(`${done.length} dokumen: ${action.label.toLowerCase()} berhasil — ${done.join(', ')}.`, { timeout: 6000 });
    if (failed.length) toast(`${failed.length} gagal — ${failed.join(' · ')}`, { tone: 'err', timeout: 12000 });

    picked.clear();
    load();
  }

  /*
   * ========================== TAMPILAN TERSIMPAN (UI-4c) =====================
   *
   * Sebuah saringan yang disusun ulang setiap pagi adalah pekerjaan yang
   * diulang setiap pagi. UI-4a memberi jalan MEMBACA keadaan itu (chip) dan
   * membuangnya; paket ini memberi jalan MENYIMPANNYA dengan nama.
   *
   * YANG DISIMPAN ADALAH QUERY STRING-NYA, bukan bentuk data baru. `query`
   * berasal dari stateQuery() — penyerialisasi yang sama yang menulis bilah
   * alamat — dan dipulihkan lewat seedFromUrl(), pembaca yang sudah ada. Maka
   * sebuah tampilan tersimpan persis sebuah tautan yang disalin dari bilah
   * alamat, dengan nama, dan tidak ada bentuk kedua yang harus dirawat saat
   * daftar filter sebuah resource berubah.
   *
   * DI MANA ia disimpan bukan keputusan paket ini: `core_user_preferences`
   * sudah ada sejak P1-C dengan server sebagai kebenaran dan localStorage
   * sebagai cermin (CONVENTIONS §15). Sebuah tampilan yang hanya hidup di satu
   * peramban adalah persis cacat yang P1-C pindahkan favorit ke server untuk
   * memperbaikinya: disusun di desktop kantor, tidak ada di tablet lapangan.
   */
  const viewRoute = `r/${key}`;
  let viewRow = null;

  /*
   * Dua query dianggap SAMA bila pasangannya sama — urutannya tidak dihitung,
   * dan `page` tidak ikut.
   *
   * Urutan ui.filters mengikuti urutan orangnya MENGISI filter: memilih status
   * lalu proyek menghasilkan string yang berbeda dari proyek lalu status,
   * untuk saringan yang sama persis. Tanpa pembakuan ini sebuah tampilan yang
   * baru saja diterapkan langsung terlihat "tidak aktif" hanya karena ia
   * disimpan dengan urutan klik yang lain.
   */
  function canonical(query) {
    return [...new URLSearchParams(query || '').entries()]
      .filter(([name]) => name !== 'page')
      .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
      .map(([name, value]) => `${name}=${value}`)
      .join('&');
  }

  function activeView(views) {
    const now = canonical(stateQuery());
    return views.find((one) => canonical(one.query) === now) || null;
  }

  /*
   * Terapkan tampilan. Keadaan dikosongkan LEBIH DULU, dan itu bukan kehati-
   * hatian berlebih: seedFromUrl() sengaja tidak mereset apa pun bila query-nya
   * tidak mengandung satu kunci yang dikenalnya — aturan yang benar untuk URL
   * polos (navigasi sidebar harus mengingat saringan yang tadi dipasang), dan
   * salah persis untuk tampilan "Semua PO", yang query-nya memang KOSONG dan
   * yang seluruh gunanya adalah membuang saringan yang sedang terpasang.
   *
   * Lebih luas dari resetFilters(): `sort` ikut dikosongkan, karena urutan ada
   * di dalam tampilan. resetFilters() sengaja MEMPERTAHANKAN urutan — "berhenti
   * menyaring" bukan "kembali ke urutan bawaan" — jadi keduanya tetap dua
   * fungsi, bukan satu.
   */
  function applyView(view) {
    // replacePath, alasan yang sama dengan syncUrl di atas.
    replacePath(`r/${key}${view.query ? `?${view.query}` : ''}`);
    ui.q = '';
    ui.filters = {};
    ui.dateFrom = '';
    ui.dateTo = '';
    ui.sort = null;
    ui.dir = null;
    ui.page = 1;
    seedFromUrl(key, def, ui);
    load();
  }

  async function saveCurrentView() {
    const views = prefs.savedViews(viewRoute);
    const answer = await promptFields('Simpan tampilan ini', [{
      key: 'name',
      label: 'Nama tampilan',
      type: 'text',
      required: true,
      maxlength: 60,
      help: `Tersimpan pada akun Anda, jadi ia ikut ke perangkat lain. Menyimpan dengan nama yang sudah ada akan menimpanya.`,
    }], { submitLabel: 'Simpan' });
    if (answer === null) return;

    const name = String(answer.name || '').trim();
    if (!name) return;

    const saved = prefs.saveView(viewRoute, name, stateQuery());
    if (!saved) {
      // Plafon penuh. Kalimatnya menyebut angkanya DAN jalan keluarnya: sebuah
      // penolakan yang hanya berkata "gagal" memaksa orang menebak.
      toast(`Sudah ada ${prefs.limitOf('list.views')} tampilan tersimpan — hapus salah satunya lebih dulu.`,
        { tone: 'err', timeout: 8000 });
      return;
    }

    toast(`Tampilan "${name}" tersimpan.`);
    paintViews();
  }

  async function dropView(view) {
    /* Konfirmasi, walau ia "hanya" sebuah preferensi: yang dibuang adalah
       saringan yang disusun orangnya, tidak ada urungkan, dan tanda × yang
       sama di baris chip di bawahnya hanya membuang satu filter — dua akibat
       yang sangat berbeda di balik glyph yang sama. */
    const ok = await confirmDialog({
      title: 'Hapus tampilan tersimpan',
      message: `Tampilan "${view.name}" akan dihapus dari akun Anda. Daftar yang sedang tampil tidak berubah.`,
      confirmLabel: 'Hapus tampilan',
      tone: 'danger',
    });
    if (!ok) return;

    prefs.deleteView(viewRoute, view.name);
    toast(`Tampilan "${view.name}" dihapus.`);
    paintViews();
  }

  function paintViews() {
    if (!viewRow) return;
    clear(viewRow);

    const views = prefs.savedViews(viewRoute);
    const active = activeView(views);
    const worthSaving = Boolean(stateQuery());

    /* Barisnya tidak ada saat tidak ada yang bisa dikatakannya: belum ada
       tampilan tersimpan DAN daftarnya sedang polos. Sebuah baris yang hanya
       berisi "Simpan tampilan ini" untuk daftar tanpa satu pun saringan
       menawarkan menyimpan tampilan "semuanya, apa adanya". */
    viewRow.hidden = !views.length && !worthSaving;
    if (viewRow.hidden) return;

    viewRow.appendChild(el('span.chips-label', { text: 'Tampilan:' }));

    for (const view of views) {
      const on = active && active.name === view.name;
      const drop = el('button.chip-x', {
        type: 'button',
        'aria-label': `Hapus tampilan ${view.name}`,
        title: `Hapus tampilan tersimpan "${view.name}"`,
      }, icon('close', 11));
      drop.addEventListener('click', (event) => { event.stopPropagation(); dropView(view); });

      const chip = el(`button.chip.chip-view${on ? '.on' : ''}`, {
        type: 'button',
        title: on ? `Tampilan "${view.name}" sedang diterapkan` : `Terapkan tampilan "${view.name}"`,
        'aria-pressed': on ? 'true' : 'false',
      }, [el('b', { text: view.name })]);
      chip.addEventListener('click', () => applyView(view));

      // Tombol hapus DI LUAR chip-nya: sebuah <button> di dalam <button> bukan
      // HTML yang sah, dan peramban memperbaikinya dengan mengeluarkannya dari
      // sana — di tempat yang tidak dipilih siapa pun.
      viewRow.appendChild(el('span.chip-wrap', [chip, drop]));
    }

    /*
     * "Simpan tampilan ini" hilang dalam dua keadaan, dan keduanya berarti
     * tidak ada yang baru untuk disimpan:
     *
     *  - yang tampil PERSIS sebuah tampilan yang sudah tersimpan — tombol yang
     *    menyimpan salinan kedua dengan nama lain adalah cara paling cepat
     *    mengumpulkan tampilan kembar;
     *  - daftarnya polos DAN belum ada satu pun tampilan tersimpan. Itu
     *    penyimpanan pertama yang menawarkan "semuanya, apa adanya" — sebuah
     *    tampilan yang persis sama dengan membuka layarnya.
     *
     * Begitu sebuah tampilan ada, keadaan POLOS justru layak dinamai: ia jalan
     * kembali dari tampilan tersaring, dan "Semua PO" adalah nama yang lebih
     * jelas daripada mengingat bahwa Reset melakukannya.
     */
    if (!active && (worthSaving || views.length)) {
      viewRow.appendChild(button('Simpan tampilan ini', {
        size: 'sm', variant: 'ghost', iconName: 'plus', onClick: saveCurrentView,
      }));
    }
  }

  /*
   * Chip filter aktif (UI-4a).
   *
   * Ia MENGGAMBAR keadaan yang sudah ada — ui.q, ui.filters, ui.dateFrom/To —
   * dan tidak menyimpan apa pun sendiri. Yang ditambahkannya dua hal yang bilah
   * kontrol tidak punya: ringkasan "daftar ini sedang dipersempit oleh APA" di
   * satu tempat, dan cara membuang SATU filter dengan satu klik. Tombol Reset
   * yang sudah ada hanya bisa membuang semuanya sekaligus.
   *
   * Label nilainya TIDAK dihitung ulang di sini. Tiap kontrol sudah tahu
   * labelnya sendiri — <select> punya <option> terpilih, combobox lookup punya
   * labelOf() yang bahkan jujur soal id yang tidak ada di sumber
   * ('#42 (tidak ada di daftar)') — jadi buildFilter yang MELAPORKAN labelnya
   * ke sini. Menghitungnya dua kali berarti dua sumber kebenaran yang akan
   * hanyut, dan chip yang menyebut nilai berbeda dari kontrol di atasnya lebih
   * buruk daripada tidak ada chip sama sekali.
   */
  let activeFilters = [];
  let chipRow = null;

  function paintChips() {
    if (!chipRow) return;
    clear(chipRow);

    const chips = [];
    if (ui.q) {
      chips.push({ label: 'Cari', text: ui.q, clear: () => { ui.q = ''; ui.page = 1; load(); } });
    }
    for (const one of activeFilters) {
      const text = one.text();
      if (text) chips.push({ label: one.label, text, clear: one.clear });
    }
    if (ui.dateFrom) {
      chips.push({ label: 'Sejak', text: date(ui.dateFrom), clear: () => { ui.dateFrom = ''; ui.page = 1; load(); } });
    }
    if (ui.dateTo) {
      chips.push({ label: 'Sampai', text: date(ui.dateTo), clear: () => { ui.dateTo = ''; ui.page = 1; load(); } });
    }

    chipRow.hidden = chips.length === 0;
    if (!chips.length) return;

    chipRow.appendChild(el('span.chips-label', { text: 'Disaring:' }));
    for (const chip of chips) {
      const drop = el('button.chip-x', {
        type: 'button',
        'aria-label': `Hapus filter ${chip.label.toLowerCase()}`,
        title: `Hapus filter ${chip.label.toLowerCase()}`,
      }, icon('close', 11));
      drop.addEventListener('click', chip.clear);
      chipRow.appendChild(el('span.chip', [
        el('span.chip-key', { text: `${chip.label}: ` }),
        el('b', { text: chip.text }),
        drop,
      ]));
    }
    // "Hapus semua" memakai resetFilters() yang sudah ada — satu definisi
    // "berhenti menyaring", dipakai tombol Reset, keadaan kosong, dan di sini.
    chipRow.appendChild(button('Hapus semua', { size: 'sm', variant: 'ghost', onClick: resetFilters }));
  }

  function filterBar() {
    filterCombos = [];
    activeFilters = [];
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

    if (isFiltered()) {
      bar.appendChild(button('Reset', { size: 'sm', variant: 'ghost', onClick: resetFilters }));
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

    /* Deskriptor chip: `text` dibaca SETIAP kali chip digambar, bukan sekali
       saat bilah dibangun — label lookup baru tiba setelah sumbernya dimuat,
       dan sebuah string yang dibekukan di sini akan selamanya berbunyi
       'Memuat…'. */
    const reportChip = (text) => activeFilters.push({
      label: filter.label,
      text,
      clear: () => apply(''),
    });

    if (filter.type === 'date') {
      const input = el('input.filter-w', {
        type: 'date', value: ui.filters[filter.key] || '', 'aria-label': filter.label, title: filter.label,
      });
      input.addEventListener('change', () => apply(input.value));
      reportChip(() => (ui.filters[filter.key] ? date(ui.filters[filter.key]) : ''));
      return input;
    }

    if (filter.type === 'number') {
      const input = el('input.filter-w', {
        type: 'number', placeholder: filter.label, value: ui.filters[filter.key] || '', 'aria-label': filter.label,
      });
      input.addEventListener('change', () => apply(input.value));
      reportChip(() => String(ui.filters[filter.key] ?? ''));
      return input;
    }

    // Enum menang atas lookup, urutan yang sama dengan rantai else-if <select>
    // lama — filter yang membawa keduanya tidak boleh berpindah kontrol.
    if (filter.lookup && !filter.enum) return lookupFilter(filter, apply, reportChip);

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
    /* Label chip dibaca dari <option> yang TERPILIH, bukan dihitung ulang dari
       ENUMS/MONTHS: kalau keduanya pernah berbeda, yang benar adalah yang
       dilihat pemakai di kontrolnya. Nilai yang tidak punya <option> (seed URL
       basi) memberi selectedIndex 0 — yaitu option placeholder berlabel filter
       — jadi chipnya sengaja dibiarkan kosong dan tidak berbohong. */
    reportChip(() => {
      const current = ui.filters[filter.key];
      if (current === undefined || current === '') return '';
      const chosen = [...select.options].find((option) => option.value === String(current));
      return chosen && chosen.value !== '' ? chosen.text : '';
    });
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
  function lookupFilter(filter, apply, reportChip) {
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

    /* Chip lookup memakai labelOf() yang SAMA dengan comboboxnya — termasuk
       kejujurannya soal id yang tidak ada di sumber ('#42 (tidak ada di
       daftar)'). Dibaca ulang tiap kali chip digambar, karena sumber lookup
       tiba asinkron: chip yang dibekukan saat bilah dibangun akan berbunyi
       'Memuat…' selamanya. */
    if (reportChip) {
      reportChip(() => {
        const now = sourceState(filter.lookup);
        return labelOf(optionsOf(now.rows), now);
      });
    }

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
      // Sumbernya baru tiba: chipnya masih menyebut 'Memuat…' sampai digambar
      // ulang di sini.
      paintChips();
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
      .filter((form) => printableFor(form, row))
      .flatMap((form) => [
        labelled(button('', {
          size: 'sm',
          variant: 'ghost',
          iconName: 'print',
          title: `Cetak ${form.label} dalam format formulir perusahaan`,
          onClick: (event) => {
            event.stopPropagation();
            openPrintable(printablePath(form, row), event.currentTarget);
          },
        }), 'Cetak'),
        /* P8 — pendamping ekspornya, hanya bila katalog menandai slug-nya
           (form.xlsx). Baris daftar adalah SATU-SATUNYA rumah tombol untuk
           layar noDetail, jadi ekspor harus ikut duduk di sini. */
        form.xlsx
          ? labelled(button('', {
            size: 'sm',
            variant: 'ghost',
            iconName: 'download',
            title: `Unduh ${form.label} sebagai XLSX — sel yang di kertas bergaris adalah sel kosong, bukan 0`,
            onClick: (event) => {
              event.stopPropagation();
              downloadPdf(xlsxPath(form, row), xlsxName(form.form, row.code || row[form.idField || 'id']), event.currentTarget);
            },
          }), 'Unduh')
          : null,
      ])
      .filter(Boolean);
  }

  /* Dokumen yang baru dibuat dibuka, bukan dikembalikan ke daftar: langkah
     berikutnya hampir selalu ada di halamannya (Ajukan, lampiran, cetak), dan
     mendarat di daftar berarti mencari barisnya dulu — satu klik ekstra pada
     setiap dokumen (diukur 2 Sep 2026: 13 klik PO 2 baris, 1 di antaranya ini).
     Layar tanpa halaman detail tetap memuat ulang daftarnya. */
  function afterCreate(saved) {
    if (saved && saved.id && !def.noDetail) navigate(`d/${key}/${saved.id}`);
    else load();
  }

  /* Kata kerja untuk layar sentuh: app.css menulis data-label di samping ikon
     pada pointer: coarse, karena title tidak pernah tampil tanpa hover
     (ASESMEN-UX §1.5). Di layar biasa tombolnya tetap ikon saja. */
  function labelled(btn, label) {
    btn.dataset.label = label;
    return btn;
  }

  function rowActions(row) {
    const wrap = el('.cell-actions');

    printRowButtons(row).forEach((btn) => wrap.appendChild(btn));

    const canEdit = def.canEdit !== false && Boolean(def.form) && session.can(`${def.module}.update`) &&
      (!def.editableWhen || def.editableWhen(row));
    const canDelete = def.canDelete !== false && session.can(`${def.module}.delete`) &&
      (!def.deletableWhen || def.deletableWhen(row));

    if (canEdit) {
      wrap.appendChild(labelled(button('', {
        size: 'sm', variant: 'ghost', iconName: 'edit', title: 'Ubah',
        onClick: (event) => {
          event.stopPropagation();
          startForm({ def, key, row, onSaved: () => load() });
        },
      }), 'Ubah'));
    }

    if (canDelete) {
      wrap.appendChild(labelled(button('', {
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
      }), def.deleteLabel || 'Hapus'));
    }

    return wrap.childElementCount ? wrap : null;
  }

  /*
   * Kotak centang satu baris.
   *
   * Dimatikan — bukan disembunyikan — untuk baris yang tidak punya satu pun
   * aksi massal yang berlaku, dengan alasannya di `title`. Sebuah sel kosong
   * di kolom pertama terbaca sebagai gangguan render; sebuah kotak mati yang
   * menyebutkan alasannya terbaca sebagai keadaan dokumen, dan itu memang yang
   * sedang dikatakannya.
   */
  function pickBox(row) {
    const allowed = bulkableRow(row);
    const box = el('input', {
      type: 'checkbox',
      checked: picked.has(row.id),
      disabled: !allowed,
      'aria-label': `Pilih ${codeOf(row)}`,
      title: allowed ? '' : `${codeOf(row)} tidak dalam keadaan yang tepat untuk aksi massal mana pun di layar ini.`,
    });
    box.addEventListener('change', () => {
      if (box.checked) picked.set(row.id, row);
      else picked.delete(row.id);
      // Kelas .selected dipasang di sini, bukan lewat renderRows(): menggambar
      // ulang seluruh tabel pada tiap centang membuang fokus papan ketik yang
      // sedang berada DI kotak itu.
      const tr = box.closest('tr');
      if (tr) tr.classList.toggle('selected', box.checked);
      syncBulk();
      syncPickAll();
    });
    return box;
  }

  let pickAll = null;

  /* "Pilih semua" berarti semua baris HALAMAN INI yang memang bisa dikenai
     aksi — bukan seluruh hasil filter. Daftar ini tidak pernah memegang baris
     yang tidak dimuatnya, dan sebuah centang yang diam-diam berarti 1.400
     dokumen adalah kuasa yang tidak pernah diminta siapa pun. */
  function pickAllBox(pickable) {
    pickAll = el('input', {
      type: 'checkbox',
      disabled: pickable.length === 0,
      'aria-label': 'Pilih semua baris di halaman ini',
      title: pickable.length
        ? `Pilih ${pickable.length} baris di halaman ini`
        : 'Tidak ada baris di halaman ini yang bisa dikenai aksi massal.',
    });
    pickAll.addEventListener('change', () => {
      if (pickAll.checked) pickable.forEach((row) => picked.set(row.id, row));
      else pickable.forEach((row) => picked.delete(row.id));
      syncBulk();
      renderRows();
    });
    syncPickAll(pickable);
    return pickAll;
  }

  function syncPickAll(pickable) {
    if (!pickAll) return;
    const rows = pickable || (payload ? (payload.data || []).filter(bulkableRow) : []);
    const on = rows.filter((row) => picked.has(row.id)).length;
    pickAll.checked = rows.length > 0 && on === rows.length;
    // Sebagian terpilih = keadaan ketiga, bukan "tidak terpilih": kotak kosong
    // di atas tiga baris tercentang mengundang klik yang MEMBUANG pilihan itu.
    pickAll.indeterminate = on > 0 && on < rows.length;
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

    /* Dua keadaan kosong yang berbeda (P1-B): tidak ada baris sama sekali
       (ilustrasi nampan + Tambah) versus filter/pencarian yang menyaring
       semuanya (ilustrasi corong/kaca pembesar + "Hapus filter" — jalan
       keluarnya ada di tempat orang membacanya, bukan di bilah di atas).
       Keduanya dulu satu kalimat dengan gambar yang sama. */
    if (!rows.length) {
      const onlySearch = Boolean(ui.q) && !ui.dateFrom && !ui.dateTo && !Object.values(ui.filters).some(Boolean);
      // Kalimatnya menyebut apa yang disaring (verifikasi P1-B 5 Sep 2026: kotak cari saja
      // dulu berbunyi "untuk filter ini", dan judul mengulang kalimatnya).
      body.appendChild(isFiltered()
        ? emptyState(onlySearch
          ? `Tidak ada ${def.label.toLowerCase()} yang cocok dengan "${ui.q}".`
          : `Tidak ada ${def.label.toLowerCase()} yang lolos filter yang dipasang.`, {
          title: onlySearch ? 'Tidak ada hasil pencarian' : 'Tidak ada hasil',
          kind: onlySearch ? 'search' : 'filter',
          action: button(onlySearch ? 'Hapus pencarian' : 'Hapus filter', { iconName: 'close', onClick: resetFilters }),
        })
        : emptyState(`Belum ada ${def.label.toLowerCase()} yang tercatat.`, {
          kind: 'inbox',
          action: canCreate
            ? button(`Tambah ${def.labelOne}`, { variant: 'primary', iconName: 'plus', onClick: () => startForm({ def, key, onSaved: afterCreate }) })
            : null,
        }));
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

    /* Baris yang bisa dikenai SETIDAKNYA satu aksi massal. Kotak centang baris
       lain digambar mati dengan alasannya di title: menyembunyikannya membuat
       kolomnya berlubang dan tidak menjelaskan apa pun. */
    const pickable = picking ? rows.filter(bulkableRow) : [];

    const table = el('table.data', [
      el('thead', el('tr', [
        picking ? el('th.pick', pickAllBox(pickable)) : null,
        ...def.columns.map((column) => headerCell(column, sortable)),
        hasActions ? el('th.right', { text: '', style: { width: '1%' } }) : null,
      ])),
      el('tbody', rows.map((row) => {
        const chosen = picking && picked.has(row.id);
        const tr = el(`tr${openable ? '.clickable' : ''}${chosen ? '.selected' : ''}`, [
          picking ? el('td.pick', pickBox(row)) : null,
          ...def.columns.map((column) =>
            el(`td${column.align ? `.${column.align}` : ''}${column.type === 'code' ? '.code' : ''}${column.hideOnNarrow ? '.hide-narrow' : ''}`, renderCell(row, column))),
          hasActions ? el('td.right', rowActions(row)) : null,
        ]);
        if (openable) {
          /* Klik pada kotak centang TIDAK membuka dokumennya. Tanpa penjaga ini
             mencentang satu baris berarti berpindah halaman, dan seleksi yang
             baru lahir itu ikut mati di muatan berikutnya. */
          tr.addEventListener('click', (event) => {
            if (event.target.closest('td.pick')) return;
            navigate(`d/${key}/${row.id}`);
          });
        }
        return tr;
      })),
      moneyColumns.length
        ? el('tfoot', el('tr', [
          picking ? el('td.pick') : null,
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

    if (bulkBar) body.appendChild(bulkBar);
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
    /* .fs-secondary, bukan `fontSize: '12.5px'` sebaris: 12,5 px adalah salah
       satu nilai di luar skala yang audit UI-1 daftarkan, dan ia ada di lapisan
       GENERIK — satu di tiap layar daftar.

       KOREKSI UI-7: geometrinya dulu tetap sebaris di sini, dengan alasan "ia
       memang geometri, bukan tipografi". Alasan itu melewatkan satu hal — gaya
       sebaris mengalahkan MEDIA QUERY. Lantai sasaran sentuh 36 px di
       `@media (pointer: coarse)` karena itu tidak pernah berlaku pada kontrol
       ini, di 70 layar daftar sekaligus. Sekarang `.pager select` di app.css. */
    const perPageSelect = el('select.fs-secondary', {
      'aria-label': 'Baris per halaman',
      title: 'Baris per halaman',
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

    /* Tampilan tersimpan DI ATAS chip saringan, urutan yang sama dengan
       prototipe: yang pertama dibaca adalah "saya sedang melihat tampilan
       mana", baru "apa yang sedang mempersempitnya". */
    viewRow = el('.chips.views', { hidden: true });
    tableHost.appendChild(viewRow);
    paintViews();

    // Chip digambar SESUDAH bilahnya, karena buildFilter yang mengisi
    // activeFilters saat bilah itu dibangun.
    /* `.chips.active-filters`, bukan `.chips` telanjang: sejak UI-4c ADA DUA
       baris chip di layar ini, dan `querySelector('.chips')` memulangkan yang
       pertama — baris tampilan. Nol pemakai `.active-filters` diperiksa lebih
       dulu di seluruh public/app, aturan yang sama dengan primitif UI-2. */
    chipRow = el('.chips.active-filters', { hidden: true });
    tableHost.appendChild(chipRow);
    paintChips();

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
