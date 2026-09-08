/*
 * Papan kanban atas resource ber-enum status (Fase 1 / P1-G).
 *
 * SATU ATURAN MENENTUKAN SELURUH BERKAS INI: sebuah kartu yang diseret ke kolom
 * lain menjalankan AKSI YANG SUDAH ADA lewat `runAction()` — jalur yang sama
 * persis dengan tombol di halaman dokumen. Bukan endpoint baru, bukan
 * `PUT {id}` yang menulis status langsung, dan bukan salinan aturan transisi.
 * Yang mengikut secara gratis karena itu: catatan persetujuan inline,
 * maker-checker, konfirmasi bertingkat (`confirmResubmit`), dialog alasan wajib
 * pada Tolak, toast berbahasa Indonesia yang menyebut kode dokumennya, dan
 * tawaran "dokumen berikutnya" setelah menyetujui. Sebuah papan yang menulis
 * statusnya sendiri akan kehilangan keenamnya sekaligus, diam-diam.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * DUA PENOLAKAN YANG BERBEDA, DAN KEDUANYA HARUS ADA
 *
 * 1. YANG BISA DIKETAHUI SEBELUM MENCOBA — izin yang tidak dipegang, dan status
 *    kartu yang tidak memenuhi `when` aksinya. Predikatnya diambil UTUH dari
 *    `actionButtons()` (`session.can(action.perm)` lalu `!action.when ||
 *    action.when(row)`), dalam urutan yang sama, karena papan yang memakai
 *    predikat kedua akan menawarkan perpindahan yang tombolnya sendiri
 *    sembunyikan. Kartu kembali ke kolom asalnya dan kalimatnya menyebut
 *    dokumennya, kolom tujuannya DAN aksi yang kurang.
 *
 * 2. YANG HANYA BISA DIKETAHUI DENGAN MENCOBA — maker-checker (pengaju tidak
 *    boleh menyetujui pengajuannya sendiri), tangga persetujuan bertingkat,
 *    ambang direktur, prasyarat BAST. Tidak satu pun ada di muatan daftar:
 *    `approvals` di-load hanya pada detail, dan tidak ada medan `can_approve`
 *    di mana pun. Papan TIDAK BOLEH menebaknya — ia mencoba, membiarkan
 *    `runAction` menampilkan kalimat servernya (yang menyebut pengajunya dan
 *    jalan keluarnya), lalu mengembalikan kartunya.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * MENGEMBALIKAN KARTU ADALAH PEKERJAAN TANGAN. SortableJS tidak punya API
 * batal: `onEnd` menyala SETELAH DOM dipindahkan, dan tidak ada satu pun
 * metode instans yang mengembalikannya. Satu-satunya jalan adalah idiom
 * pustakanya sendiri — simpan tetangga di kolom asal sebelum drop, lalu
 * `insertBefore` / `appendChild`. Karena itu pula pengurutan DI DALAM kolom
 * dimatikan (`sort: false`): papan ini tentang KOLOM, dan urutan di dalam satu
 * kolom tidak berarti apa-apa — sementara membiarkannya menambah satu bentuk
 * pembatalan lagi yang indeksnya bergeser.
 *
 * `runAction` SELALU resolve `undefined` dan tidak pernah melempar: batal,
 * 422 dan berhasil tidak bisa dibedakan dari nilai kembaliannya. Yang
 * menandakan berhasil hanyalah `onDone` yang menyala — jadi papan memasang
 * bendera di dalamnya dan mengembalikan kartu bila bendera itu tidak menyala.
 */

import { api, session } from '../api.js';
import { el, clear, button, modal, closeModal, toast, errorState, emptyState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { enumLabel } from '../enums.js';
import { navigate } from '../router.js';
import { preload, labelFor } from '../lookup.js';
import { runAction, inlineNote } from './actions.js';
import { sortable } from '../vendorload.js';

/** Kartu per kolom yang diambil; papan bukan daftar lengkap. */
const PER_LANE = 50;

/** Nama grup SortableJS; satu papan = satu grup. */
const GROUP = 'erp-board';

export async function renderBoard(host, { key, def }) {
  clear(host);

  const board = def.board;

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: `Papan ${def.label}` }),
      el('.desc', {
        text: 'Seret kartu ke kolom berikutnya untuk menjalankan aksinya. Perpindahan memakai tombol yang '
          + 'sama dengan halaman dokumen — termasuk catatan, alasan wajib, dan aturan persetujuan.',
      }),
    ]),
    el('.actions', [
      button('Tampilan daftar', { variant: 'ghost', onClick: () => navigate(`r/${key}`) }),
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderBoard(host, { key, def }) }),
    ]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(4, 4));

  let rows;
  let lanesMeta = null;
  try {
    /*
     * Jalur data yang SAMA dengan layar daftar: endpoint yang sama, saringan
     * status di server, dan pemanasan lookup yang sama supaya nama relasi di
     * kartu ditulis fungsi yang sama dengan tabelnya.
     *
     * `board.api` (F-3) menukar SUMBER BACAnya saja — perpindahan tetap
     * `runAction` ke `def.api`. Rute papan mengambil N teratas PER KOLOM dan
     * memulangkan jumlah sebenarnya per kolom di meta.lanes; tanpa itu satu
     * halaman berisi 300 kartu Menang mendorong kolom "Baru" keluar halaman
     * dan papannya tampak kosong justru di kolom yang paling dikerjakan.
     */
    const payload = await api.list(board.api || def.api, { per_page: PER_LANE * board.lanes.length });
    rows = payload.data || [];
    lanesMeta = (payload.meta && payload.meta.lanes) || null;
    await preload((def.columns || []).map((column) => column.lookup));
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => renderBoard(host, { key, def })));
  }

  clear(body);
  paint(body, { key, def, board, rows, lanesMeta, reload: () => renderBoard(host, { key, def }) });
}

function paint(host, ctx) {
  const { board, rows } = ctx;
  const grid = el('.board-grid');
  const byStatus = new Map(board.lanes.map((lane) => [lane, []]));

  rows.forEach((row) => {
    if (byStatus.has(row.status)) byStatus.get(row.status).push(row);
  });

  /* Kartu berstatus DI LUAR lanes tidak dibuang diam-diam: papan menyebut
     berapa banyak, karena "tidak terlihat di papan" dan "tidak ada" adalah dua
     hal yang berbeda, dan yang kedua akan membuat orang mengira dokumennya
     hilang. */
  const outside = rows.filter((row) => !byStatus.has(row.status));

  board.lanes.forEach((lane) => {
    const cards = el('.board-cards', { dataset: { status: lane } });
    const laneRows = byStatus.get(lane);

    laneRows.forEach((row) => cards.appendChild(card(row, ctx)));

    if (!laneRows.length) {
      cards.appendChild(el('.board-empty', emptyState('Kosong.', { kind: 'inbox', compact: true, title: null })));
    }

    /* Jumlah SEBENARNYA kolom ini, bila servernya memulangkannya. Lencana
       tetap memajang yang digambar (itulah yang bisa dihitung ulang setelah
       satu kartu pindah); selisihnya dikatakan satu baris di bawahnya, karena
       "50" pada kolom berisi 120 prospek adalah angka yang salah dibaca setiap
       hari tanpa pernah terasa salah. */
    const meta = (ctx.lanesMeta || []).find((one) => one.status === lane);
    const hidden = meta && typeof meta.count === 'number' ? meta.count - laneRows.length : 0;

    grid.appendChild(el('.board-lane', [
      el('.board-lane-head', [
        el('span.cell-main', { text: enumLabel(board.enum, lane) || lane }),
        el('span.board-count', { text: String(laneRows.length) }),
      ]),
      hidden > 0
        ? el('.cell-sub', {
          text: `${laneRows.length} dari ${meta.count} digambar — ${hidden} lainnya ada di tampilan daftar.`,
          style: { padding: '0 10px 6px' },
        })
        : null,
      cards,
    ]));
  });

  host.appendChild(grid);
  ctx.grid = grid;

  if (outside.length) {
    host.appendChild(el('p.cell-sub', {
      text: `${outside.length} dokumen berstatus di luar papan ini (${[...new Set(outside.map((row) => enumLabel(board.enum, row.status) || row.status))].join(', ')}) `
        + 'tidak digambar — buka tampilan daftar untuk melihatnya.',
      style: { marginTop: '12px' },
    }));
  }

  bindDrag(grid, ctx);
}

/** Satu kartu: kode, judul, dan angka yang membedakan satu dokumen dari lainnya. */
function card(row, { def }) {
  const columns = def.columns || [];
  const money = columns.find((column) => column.type === 'currency');
  const date = columns.find((column) => column.type === 'date');
  const rel = columns.find((column) => column.type === 'rel');

  return el('.board-card', {
    dataset: { id: String(row.id) },
    tabindex: '0',
    onclick: () => navigate(`d/${def.apiKey || def.api}/${row.id}`),
    onkeydown: (event) => {
      if (event.key === 'Enter') navigate(`d/${def.apiKey || def.api}/${row.id}`);
    },
  }, [
    el('.board-card-head', [
      el('span.cell-main.mono', { text: row.code || `#${row.id}` }),
      money && row[money.key] !== null && row[money.key] !== undefined
        ? el('span.num.cell-sub', { text: fmt.rupiahShort(row[money.key]) })
        : null,
    ]),
    /* Baris kedua kartu: kolom TEKS PERTAMA layar daftarnya, bukan daftar
       nama medan yang ditebak. PR menyebutnya `purpose`, NCR `description`,
       SPK `title` — menebaknya satu per satu berarti kartu ber-em-dash untuk
       setiap resource yang tidak ada di daftar tebakan. */
    el('span.cell-sub', {
      text: row[(columns.find((column) => column.type === 'text') || {}).key] || row.title || row.name || '—',
      style: { display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
    }),
    el('.board-card-foot', [
      rel ? el('span.cell-sub', { text: labelFor(rel.lookup, row[rel.key]) || '' }) : null,
      date && row[date.key] ? el('span.cell-sub', { text: fmt.date(row[date.key]) }) : null,
    ]),
    /* `board.card.fields` (F-3): kalimat SIAP PAKAI dari server, ditulis apa
       adanya. Papan prospek memakainya untuk pemilik kartu — sebuah kartu
       tanpa pemilik berbunyi "Belum ditugaskan", kalimat yang sama dengan
       daftar dan CSV — dan untuk aktivitas yang lewat tanggal. Nilai kosong
       DILEWATI: baris "0 aktivitas" yang selalu ada mengajari orang
       mengabaikan barisnya. */
    ...(((def.board && def.board.card && def.board.card.fields) || [])
      .filter((field) => row[field] !== null && row[field] !== undefined && row[field] !== '')
      .map((field) => el('span.cell-sub', { text: String(row[field]), style: { display: 'block' } }))),
  ]);
}

/**
 * Seret-lepas, dipasang pada SETIAP kolom.
 *
 * `onMove` dibaca dari opsi kolom ASAL, bukan tujuan — memasangnya di satu
 * kolom saja berarti ia tidak pernah jalan untuk kartu yang berangkat dari
 * kolom lain. Karena itu setiap kolom mendapat opsi yang sama.
 */
function bindDrag(grid, ctx) {
  const lanes = [...grid.querySelectorAll('.board-cards')];

  sortable().then((Sortable) => {
    lanes.forEach((lane) => {
      Sortable.create(lane, {
        group: GROUP,
        // Papan ini tentang KOLOM. Urutan di dalam satu kolom tidak berarti
        // apa-apa, dan mematikannya menghapus satu bentuk pembatalan yang
        // indeksnya bergeser.
        sort: false,
        animation: 140,
        draggable: '.board-card',
        // Kolom kosong hanya bisa menerima kartu bila ia punya tinggi; ambang
        // bawaan 5 px, dan .board-cards diberi min-height di app.css.
        emptyInsertThreshold: 10,
        ghostClass: 'board-card-ghost',
        onEnd: (event) => onDrop(event, ctx),
      });
    });
  }).catch((error) => {
    /* 15 KB vendor yang tidak sampai tidak boleh mematikan layar: papan tetap
       terbaca sebagai kolom, dan setiap kartu tetap bisa dibuka. Yang hilang
       hanya seretnya, dan itu dikatakan — bukan didiamkan. */
    console.error('Papan: SortableJS gagal dimuat — kartu tetap bisa dibuka, tetapi tidak bisa diseret.', error);
    grid.parentElement.appendChild(el('.alert.warn',
      'Seret-lepas tidak tersedia (berkas pustaka gagal dimuat). Kartu tetap bisa dibuka, dan aksinya '
      + 'tersedia di halaman dokumen.'));
  });
}

async function onDrop(event, ctx) {
  const { item, from, to, oldIndex } = event;

  // Dijatuhkan kembali ke tempatnya: tidak ada yang berpindah, dan tanpa
  // baris ini papan akan mengirim satu persetujuan setiap kali orang
  // mengangkat kartu lalu meletakkannya lagi.
  if (from === to) return;

  const toStatus = to.dataset.status;
  const fromStatus = from.dataset.status;
  const row = ctx.rows.find((one) => String(one.id) === item.dataset.id);

  // Tetangga di kolom ASAL, disimpan SEBELUM apa pun yang bisa gagal:
  // inilah satu-satunya cara mengembalikan kartu, karena SortableJS tidak
  // punya API batal dan DOM sudah terlanjur dipindahkan.
  const successor = from.children[oldIndex] || null;
  const putBack = () => {
    if (successor) from.insertBefore(item, successor);
    else from.appendChild(item);
    refreshCounts(ctx);
  };

  if (!row) {
    putBack();
    return;
  }

  const refusal = refuse(ctx, row, fromStatus, toStatus);

  if (refusal) {
    putBack();
    toast(refusal, { tone: 'warn', title: 'Perpindahan ditolak', timeout: 8000 });
    return;
  }

  const action = actionFor(ctx, toStatus);

  /* CATATAN PERSETUJUAN INLINE. Pada bilah aksi ia panel yang sudah ada di
     layar sebelum tombol ditekan; sebuah gerakan seret tidak punya tempat
     seperti itu, jadi panel YANG SAMA (actions.js inlineNote — diekspor, bukan
     disalin) ditawarkan dalam satu dialog sebelum aksinya berjalan. Membatalkan
     dialog membatalkan perpindahan, dan kartunya kembali. */
  const note = action.inlineNote ? await askInlineNote(action, row) : null;

  if (note === false) {
    putBack();
    return;
  }

  let moved = false;

  await runAction(action, row, ctx.def, {
    // TIDAK pernah kartunya: withBusy mengosongkan innerHTML node yang
    // diberikan padanya, dan sebuah kartu yang dikosongkan tidak kembali.
    onDone: () => { moved = true; },
    inline: note ? note.read : null,
  });

  if (!moved) {
    // Batal, 422 maker-checker, 403, atau galat jaringan — `runAction` sudah
    // menampilkan kalimatnya, dan papan hanya perlu jujur tentang di mana
    // kartunya sebenarnya berada.
    putBack();
    return;
  }

  // Berhasil: muat ulang, karena satu aksi bisa mengubah lebih dari satu
  // medan kartu (kode, nilai, tanggal) dan karena server berhak menaruh
  // dokumennya di status yang BUKAN kolom tujuan.
  ctx.reload();
}

/**
 * Panel catatan yang SAMA dengan bilah aksi, ditawarkan sebelum aksinya jalan.
 *
 * @returns {Promise<{read: Function}|null|false>} panel, null bila aksi ini
 *   memang tidak punya catatan, dan `false` bila orangnya membatalkan.
 */
function askInlineNote(action, row) {
  const note = inlineNote(action, row);

  return new Promise((resolve) => {
    let settled = false;

    modal({
      title: action.label,
      width: 'narrow',
      body: el('div', [
        el('p.cell-sub', {
          /* TIDAK mengonjugasikan labelnya: "akan setujui" salah, dan setiap
             tebakan pasif ("akan disetujui", "akan mulai perbaikan") benar
             untuk sebagian label saja. Label aksi dikutip apa adanya. */
          text: `${row.code || 'Dokumen ini'} — ${action.label}. Catatan bersifat opsional.`,
          style: { margin: '0 0 10px' },
        }),
        note.node,
      ]),
      onClose: () => { if (!settled) { settled = true; resolve(false); } },
      footer: el('div', { style: { display: 'flex', gap: '8px', width: '100%' } }, [
        el('.spacer', { style: { flex: '1' } }),
        button('Batal', { variant: 'ghost', onClick: () => closeModal() }),
        button(action.label, {
          variant: action.variant || 'primary',
          onClick: () => { settled = true; resolve(note); closeModal(); },
        }),
      ]),
    });
  });
}

/**
 * Kalimat penolakan, atau null bila perpindahan ini boleh dicoba.
 *
 * Predikatnya diambil utuh dari actionButtons(): izin dulu, lalu `when`.
 */
function refuse(ctx, row, fromStatus, toStatus) {
  const { def, board } = ctx;
  const label = (status) => enumLabel(board.enum, status) || status;
  const what = `${def.labelOne || def.label} ${row.code || `#${row.id}`}`;

  const key = board.moves[toStatus];

  if (!key) {
    return `${what} tidak bisa dipindah ke ${label(toStatus)}: tidak ada aksi yang memindahkan dokumen ke kolom itu.`;
  }

  const action = (def.actions || []).find((one) => one.key === key);

  if (!action || !action.path || action.navigateTo || action.navigateToResult) {
    /* Aksi yang membuka formulir (`opens`, tanpa `path`) atau yang berpindah
       halaman sesudahnya tidak boleh menjadi perpindahan papan: yang pertama
       tidak pernah POST, dan yang kedua meninggalkan papan sebelum kartunya
       sempat diperbarui. Dijaga uji, jadi baris ini adalah jaring pengaman. */
    return `${what} tidak bisa dipindah ke ${label(toStatus)}: aksi itu membuka layar lain, bukan memindahkan dokumen.`;
  }

  // canAct(): hak pinjaman sebuah delegasi hanya berlaku di pintu keputusan
  // dokumen — perpindahan papan yang lain memakai izin yang dipegang sendiri.
  if (!session.canAct(action)) {
    return `${what} tidak bisa dipindah ke ${label(toStatus)}: aksi ${action.label} tidak tersedia untuk Anda.`;
  }

  if (action.when && !action.when(row)) {
    return `${what} tidak bisa dipindah dari ${label(fromStatus)} ke ${label(toStatus)}: `
      + `aksi ${action.label} tidak berlaku untuk dokumen berstatus ${label(fromStatus)}.`;
  }

  return null;
}

function actionFor(ctx, toStatus) {
  return (ctx.def.actions || []).find((one) => one.key === ctx.board.moves[toStatus]);
}

/** Angka di kepala kolom mengikuti kartu yang benar-benar ada di dalamnya. */
function refreshCounts(ctx) {
  (ctx.grid || document).querySelectorAll('.board-lane').forEach((lane) => {
    const count = lane.querySelectorAll('.board-card').length;
    const badgeNode = lane.querySelector('.board-count');
    if (badgeNode) badgeNode.textContent = String(count);
  });
}
