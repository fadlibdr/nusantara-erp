/* Kepuasan pelanggan (CSAT) — F-9.
 *
 * Dua permukaan di satu berkas, karena keduanya menegakkan aturan yang sama
 * dan memisahkannya berarti dua tempat yang bisa berselisih:
 *
 *   csatCard(ticket, reload)   kartu di layar detail tiket — undangan yang
 *                              terbit, penilaian yang masuk, tombol terbitkan
 *   renderCsat(host)           layar ringkasan #/csat — rata-rata yang jujur
 *
 * TIGA ATURAN YANG DIPEGANG DI SINI, dan yang ketiganya adalah alasan berkas
 * ini ditulis dan bukan disalin dari kartu lampiran:
 *
 * 1. RATA-RATA TIDAK PERNAH SENDIRIAN. Server mengirim `average` bersama
 *    `rated` / `invited` / `ratable`, dan setiap tempat yang menuliskan
 *    rata-ratanya WAJIB menuliskan jumlah yang menopangnya di baris yang sama
 *    ("4,6 dari 5" · "12 dari 47 tiket dinilai"). Sebuah 4,6 telanjang dari
 *    dua jawaban terbaca sama dengan 4,6 dari dua ratus.
 *
 * 2. NOL PENILAIAN ADALAH KALIMAT, BUKAN ANGKA. `average === null` menjadi
 *    "Belum ada penilaian", tidak pernah "0,0" dan tidak pernah "—" telanjang.
 *    Ini pola yang sama dengan TIDAK_TERUKUR (F-2) dan jarak absensi yang
 *    bergaris (F-4): angka yang tidak diukur tidak boleh berpakaian angka.
 *
 * 3. TIDAK ADA SATU KATA PUN TENTANG SUREL. MAIL_MAILER=log di pengembangan
 *    DAN di produksi: tidak satu surel pun benar-benar terkirim hari ini.
 *    Dialog penerbitan mengatakan apa adanya — penerbitlah yang mengirim
 *    tautannya lewat salurannya sendiri — persis seperti kartu Persetujuan
 *    Eksternal (views/external.js) mengatakannya. */

import { api, session } from '../api.js';
import { el, clear, button, badge, modal, confirmDialog, errorState, emptyState, toast, toastError, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { promptFields } from './form.js';
import { navigate } from '../router.js';

/* Modules\ServiceDesk\Enums\CsatScore — label yang sama dengan halaman publik
   dan dengan PHP-nya. */
const SCORE_LABELS = {
  1: 'Sangat tidak puas',
  2: 'Tidak puas',
  3: 'Cukup',
  4: 'Puas',
  5: 'Sangat puas',
};

const STATE_CHIP = {
  rated: () => badge('Dinilai', 'green'),
  revoked: () => badge('Dicabut', ''),
  expired: () => badge('Kedaluwarsa', ''),
  live: () => badge('Terbit', 'amber'),
};

/** Nada lencana skor: 4–5 baik, 3 menengah, 1–2 buruk. */
function scoreTone(score) {
  if (score >= 4) return 'green';
  if (score === 3) return 'amber';
  return 'red';
}

/* SATU DESIMAL, SELALU, dan formatter-nya sendiri — bukan fmt.num().
 *
 * fmt.num(value, decimals) hanya mengenal 0 dan 2 desimal (`decimals === 2 ?
 * money2 : money0`), jadi fmt.num(4.6, 1) mencetak "5": rata-rata 4,6 akan
 * tampil sebagai bintang lima bulat. Terukur 10 Sep 2026 di peramban.
 *
 * Satu desimal yang DIPAKSA ada juga menjaga hal kedua: "5 dari 5" terbaca
 * seperti satu penilaian, "5,0 dari 5" terbaca seperti rata-rata. */
const AVERAGE_FORMAT = new Intl.NumberFormat('id-ID', {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
});

/**
 * "4,6 dari 5" — atau kalimatnya bila belum ada yang menilai.
 * Satu fungsi, karena setiap tempat yang menulis rata-rata harus menulisnya
 * dengan cara yang sama.
 */
function averageText(summary) {
  if (summary.average === null || summary.average === undefined) return 'Belum ada penilaian';
  return `${AVERAGE_FORMAT.format(Number(summary.average))} dari 5`;
}

/** Jumlah yang menopang rata-ratanya — SELALU di samping angkanya. */
function averageBasis(summary) {
  if (!summary.rated) {
    return summary.ratable
      ? `0 dari ${summary.ratable} tiket selesai dinilai`
      : 'Belum ada tiket selesai';
  }
  return `${summary.rated} dari ${summary.ratable} tiket selesai dinilai`;
}

/* ============================================================ KARTU TIKET */

/** Satu baris undangan/penilaian. */
function ratingRow(row, { canIssue, onChanged }) {
  const subLines = [];

  if (row.state === 'rated') {
    subLines.push(`Dinilai ${fmt.dateTime(row.rated_at)}${row.rated_via === 'link' ? ' lewat tautan' : ''}`);
    if (row.comment) subLines.push(`“${row.comment}”`);
  } else if (row.state === 'revoked') {
    subLines.push(`Dicabut ${fmt.dateTime(row.revoked_at)}${row.revoked_by_name ? ` oleh ${row.revoked_by_name}` : ''}`);
  }

  subLines.push(`Diterbitkan ${fmt.relativeDays(row.created_at)}`
    + `${row.issued_by_name ? ` oleh ${row.issued_by_name}` : ''}`
    + `${row.expires_at && row.state === 'live' ? ` · berlaku s/d ${fmt.dateTime(row.expires_at)}` : ''}`);

  const revocable = canIssue && row.state === 'live';

  return el('.attachment', [
    el('.attachment-main', [
      el('.attachment-name', { text: row.recipient_name + (row.recipient_email ? ` (${row.recipient_email})` : '') }),
      ...subLines.map((line) => el('.cell-sub', { text: line })),
    ]),
    el('.row-actions', [
      row.state === 'rated' ? badge(`${row.score} dari 5 — ${row.score_label || SCORE_LABELS[row.score] || ''}`, scoreTone(row.score)) : STATE_CHIP[row.state](),
      revocable
        ? button('Cabut', {
          size: 'sm',
          variant: 'ghost',
          iconName: 'trash',
          onClick: () => confirmDialog({
            title: `Cabut tautan untuk ${row.recipient_name}?`,
            message: 'Tautan yang dicabut tidak bisa dipakai menilai. Penilaian yang sudah tercatat tidak dapat dicabut.',
            tone: 'danger',
            onConfirm: async () => {
              await api.post(`servicedesk/csat/${row.id}/revoke`);
              toast(`Tautan untuk ${row.recipient_name} dicabut.`);
              onChanged();
            },
          }),
        })
        : null,
    ]),
  ]);
}

/** Satu-satunya layar tempat URL polos itu pernah ada. */
function showIssuedUrl(url, name) {
  const input = el('input', { type: 'text' });
  input.value = url;
  input.readOnly = true;
  input.addEventListener('focus', () => input.select());

  const copy = button('Salin', {
    variant: 'primary',
    onClick: async () => {
      try {
        await navigator.clipboard.writeText(url);
      } catch {
        // Clipboard API butuh secure context; fallback seleksi bekerja di
        // deployment HTTP polos yang DEPLOYMENT.md masih izinkan.
        input.select();
        document.execCommand('copy');
      }
      toast('Tautan disalin.');
    },
  });

  const dialog = modal({
    title: 'Tautan penilaian diterbitkan',
    width: 'narrow',
    body: el('div', [
      /* KALIMAT YANG BENAR TENTANG SUREL (perangkap D). Sistem ini tidak
         mengirim surel — MAIL_MAILER=log — jadi tidak ada "sudah dikirim ke
         pelanggan" di sini, betapa pun enaknya itu dibaca. */
      el('p', {
        text: `Kirim tautan ini kepada ${name} lewat saluran Anda sendiri (WhatsApp/e-mail). `
          + 'Sistem tidak mengirimkannya.',
        style: { marginTop: '0' },
      }),
      el('.attachment-add', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, [input, copy]),
      el('.help', {
        text: 'Salin sekarang — tautan hanya ditampilkan sekali dan tidak dapat dilihat lagi. '
          + 'Bila hilang: cabut tautan ini, lalu terbitkan yang baru.',
      }),
    ]),
    footer: [button('Tutup', { onClick: () => dialog.close() })],
  });
}

/** 'YYYY-MM-DD HH:MM:00' waktu lokal, `days` hari dari sekarang. */
function expiresAtFromDays(days) {
  const date = new Date(Date.now() + days * 86_400_000);
  const pad = (part) => String(part).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} `
    + `${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
}

/* MASA BERLAKUNYA DATANG DARI SERVER (meta `default_validity_days`), tidak
   dipegang berkas ini: satu angka yang hidup di dua tempat adalah dialog yang
   berbohong pada hari konstantanya berubah, dengan suite tetap hijau. */
async function issueLink(ticketId, onChanged, { defaultDays, maxDays }) {
  const values = await promptFields('Terbitkan Tautan Penilaian', [
    { key: 'recipient_name', label: 'Nama penilai di pihak pelanggan', required: true },
    {
      key: 'recipient_email',
      label: 'E-mail',
      help: 'Opsional, arsip untuk siapa tautan diterbitkan — sistem tidak mengirim e-mail.',
    },
    {
      key: 'days', label: 'Masa berlaku (hari)', type: 'number', min: 1, max: maxDays || null,
      help: `${defaultDays ? `Kosongkan untuk ${defaultDays} hari.` : 'Kosongkan untuk masa berlaku bawaan.'}`
        + `${maxDays ? ` Paling lama ${maxDays} hari.` : ''}`,
    },
  ], {
    submitLabel: 'Terbitkan',
    message: 'Tautan berlaku sekali pakai. Anda akan melihat URL-nya tepat satu kali dan mengirimkannya sendiri.',
  });
  if (values === null) return;

  const payload = {
    recipient_name: values.recipient_name,
    recipient_email: values.recipient_email,
  };
  if (values.days) payload.expires_at = expiresAtFromDays(values.days);

  try {
    const issued = await api.post(`servicedesk/tickets/${ticketId}/csat`, payload);
    onChanged();
    showIssuedUrl(issued.url, values.recipient_name);
  } catch (error) {
    toastError(error);
  }
}

/**
 * Kartu CSAT untuk layar detail tiket.
 *
 * Mengembalikan null bila pemanggil tidak memegang svc.view: gerbang yang sama
 * dengan endpoint-nya, supaya kartu yang pasti 403 tidak pernah digambar.
 */
export function csatCard(ticket) {
  if (!session.can('svc.view')) return null;

  const canIssue = session.can('svc.update');
  const body = el('.card-body');
  const card = el('.card', [
    el('.card-head', [el('h2', { text: 'Kepuasan Pelanggan (CSAT)' }), el('.spacer')]),
    body,
  ]);

  async function load() {
    clear(body).appendChild(el('p.muted', { text: 'Memuat…', style: { margin: 0 } }));

    let payload;
    try {
      payload = await api.list(`servicedesk/tickets/${ticket.id}/csat`);
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
      return;
    }

    const rows = payload.data || [];
    const meta = payload.meta || {};
    clear(body);

    const rated = rows.find((row) => row.state === 'rated');

    if (rated) {
      body.appendChild(el('.stat', [
        el('.label', { text: 'Penilaian pelanggan' }),
        el('.value', { text: `${rated.score} dari 5` }),
        el('.delta', { text: `${rated.score_label || SCORE_LABELS[rated.score] || ''} · ${fmt.dateTime(rated.rated_at)}` }),
      ]));
    }

    if (!rows.length) {
      body.appendChild(el('p.muted', {
        text: 'Belum ada tautan penilaian untuk tiket ini.',
        style: { margin: '0 0 10px' },
      }));
    } else {
      rows.forEach((row) => body.appendChild(ratingRow(row, { canIssue, onChanged: load })));
    }

    if (!canIssue) return;

    /* KENAPA tombolnya tidak ada — bukan sekadar tidak ada. Tombol yang hilang
       tanpa satu kalimat pun terbaca sebagai fitur yang rusak. */
    if (meta.already_rated) {
      body.appendChild(el('.help', {
        text: 'Tiket ini sudah dinilai. Satu tiket dinilai sekali, jadi tidak ada tautan baru yang bisa diterbitkan.',
      }));
      return;
    }

    if (!meta.ratable) {
      body.appendChild(el('.help', {
        text: 'Tautan penilaian baru dapat diterbitkan setelah tiket dinyatakan selesai '
          + `(${(meta.ratable_statuses || []).join(' / ')}); tiket ini ${meta.ticket_status || '—'}.`,
      }));
      return;
    }

    body.appendChild(el('.attachment-add', [
      el('.row-actions', [
        button('Terbitkan Tautan Penilaian', {
          size: 'sm',
          iconName: 'plus',
          onClick: () => issueLink(ticket.id, load, {
            defaultDays: meta.default_validity_days,
            maxDays: meta.max_validity_days,
          }),
        }),
      ]),
      el('.help', {
        text: 'URL-nya hanya tampil sekali saat diterbitkan, dan Anda yang mengirimkannya kepada pelanggan '
          + '— sistem ini tidak mengirim e-mail.',
      }),
    ]));
  }

  load();
  return card;
}

/* ========================================================= LAYAR RINGKASAN */

function summaryStats(summary) {
  const rateText = summary.response_rate === null || summary.response_rate === undefined
    ? 'Belum ada undangan'
    : fmt.percent(summary.response_rate * 100, { decimals: 0 });

  return el('.stat-row', [
    /* ANGKA UTAMA — dan jumlah yang menopangnya di baris di bawahnya, selalu.
       Keduanya digambar oleh satu ekspresi supaya tidak ada jalan menghapus
       yang kedua tanpa menyentuh yang pertama. */
    el('.stat', [
      el('.label', { text: 'Rata-rata kepuasan' }),
      el('.value', { text: averageText(summary) }),
      el('.delta', { text: averageBasis(summary) }),
    ]),
    el('.stat', [
      el('.label', { text: 'Tiket selesai' }),
      el('.value', { text: fmt.num(summary.ratable, 0) }),
      el('.delta', { text: `${summary.invited} sudah diundang menilai` }),
    ]),
    /* "TIKET YANG DIUNDANG", bukan "undangan": `invited` menghitung TIKET yang
       punya minimal satu undangan, dan beberapa undangan per tiket memang
       sengaja dibolehkan (yang pertama hilang di WhatsApp, PIC-nya berganti).
       Kata "undangan" di sini membuat satu angka melayani dua arti pada satu
       layar — terukur 10 Sep 2026: "16 dari 21 undangan dijawab" saat 24
       undangan benar-benar terbit. */
    el('.stat', [
      el('.label', { text: 'Tingkat jawaban' }),
      el('.value', { text: rateText }),
      el('.delta', { text: `${summary.rated} dari ${summary.invited} tiket yang diundang sudah menjawab` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Puas (4–5)' }),
      el('.value', { text: summary.rated ? `${summary.satisfied} dari ${summary.rated}` : 'Belum ada penilaian' }),
      el('.delta', { text: summary.rated ? 'dari jawaban yang masuk' : 'tidak dihitung dari yang belum menjawab' }),
    ]),
  ]);
}

function distributionCard(summary) {
  const total = summary.rated;
  const body = el('.card-body');

  if (!total) {
    body.appendChild(emptyState(
      'Belum ada satu pun penilaian pada rentang ini. Sebaran bintang baru muncul setelah pelanggan menjawab.',
      { title: 'Belum ada penilaian', kind: 'inbox' },
    ));
  } else {
    const table = el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Penilaian' }),
        el('th', { text: 'Jumlah', style: { textAlign: 'right' } }),
        el('th', { text: 'Bagian', style: { textAlign: 'right' } }),
      ])),
      el('tbody', [5, 4, 3, 2, 1].map((score) => el('tr', [
        el('td', [badge(`${score}`, scoreTone(score)), el('span', { text: ` ${SCORE_LABELS[score]}`, style: { marginLeft: '6px' } })]),
        el('td', { text: fmt.num(summary.distribution[score] || 0, 0), style: { textAlign: 'right' } }),
        el('td', { text: fmt.percent(((summary.distribution[score] || 0) / total) * 100, { decimals: 0 }), style: { textAlign: 'right' } }),
      ]))),
    ]);
    body.appendChild(el('.table-wrap', table));
  }

  return el('.card', [
    el('.card-head', el('h2', { text: 'Sebaran penilaian' })),
    body,
  ]);
}

/* SATU HALAMAN PENILAIAN, dan pagernya. Layarnya meminta 50 penilaian terbaru
   sekali jalan; tanpa pager, komentar ke-51 — yang bintang satunya justru
   paling perlu dibaca — tidak bisa dicapai dari layar ini selamanya. */
const COMMENT_PAGE_SIZE = 50;

/**
 * Kartu komentar — judulnya dari angka SERVER, dan pagernya dari `meta`.
 *
 * `summary.commented` adalah berapa komentar yang benar-benar ada pada rentang
 * ini; `rows` hanyalah halaman yang kebetulan dimuat. Menghitung judulnya dari
 * `rows` mencetak UKURAN HALAMAN sebagai jumlah — "Komentar pelanggan (50)" di
 * samping ubin "61 dari 62 tiket selesai dinilai" (terukur di Chromium 10 Sep
 * 2026) — persis kelas cacat yang aturan 1 berkas ini ada untuk menutup.
 */
function commentsCard(rows, summary, meta, goToPage) {
  const body = el('.card-body');

  const withComment = rows.filter((row) => row.comment);
  const total = Number(meta.total || rows.length);
  const perPage = Number(meta.per_page || COMMENT_PAGE_SIZE);
  const page = Number(meta.current_page || 1);
  const lastPage = Number(meta.last_page || 1);
  const from = total ? (page - 1) * perPage + 1 : 0;
  const to = (page - 1) * perPage + rows.length;

  if (!withComment.length) {
    body.appendChild(el('p.muted', {
      text: rows.length
        ? 'Penilaian pada halaman ini tidak disertai komentar.'
        : 'Belum ada penilaian yang masuk.',
      style: { margin: 0 },
    }));
  } else {
    body.appendChild(el('.timeline', withComment.map((row) => el('.timeline-item', [
      el('b', { text: `${row.score} dari 5 — ${row.score_label || SCORE_LABELS[row.score] || ''}` }),
      el('.meta', {
        text: `${row.ticket_code || ''} · ${row.recipient_name} · ${fmt.dateTime(row.rated_at)}`,
        style: { cursor: row.ticket_id ? 'pointer' : 'default' },
        onclick: row.ticket_id ? () => navigate(`d/servicedesk/tickets/${row.ticket_id}`) : null,
      }),
      el('.note', { text: row.comment, style: { whiteSpace: 'pre-wrap' } }),
    ]))));
  }

  /* Halaman mana yang sedang dibaca, dan bahwa ada sisanya. Sebuah daftar yang
     berhenti tanpa satu kalimat pun terbaca sebagai daftar yang habis. */
  if (lastPage > 1) {
    body.appendChild(el('.pager', [
      el('span', { text: `Menampilkan penilaian ${from}–${to} dari ${total} (halaman ${page} dari ${lastPage})` }),
      el('.spacer'),
      button('Sebelumnya', {
        size: 'sm', variant: 'ghost', disabled: page <= 1,
        onClick: () => goToPage(page - 1),
      }),
      button('Berikutnya', {
        size: 'sm', variant: 'ghost', disabled: page >= lastPage,
        onClick: () => goToPage(page + 1),
      }),
    ]));
  }

  return el('.card', [
    el('.card-head', el('h2', {
      text: `Komentar pelanggan (${summary.commented ?? withComment.length})`,
    })),
    body,
  ]);
}

export async function renderCsat(host, page = 1) {
  clear(host);

  const reload = () => renderCsat(host, page);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Kepuasan Pelanggan (CSAT)' }),
      /* Dua kalimat, dan keduanya menutup satu lubang: yang pertama menyebut
         penyebutnya (rata-rata hanya dari yang dinilai), yang kedua menyebut
         RENTANGnya. Sebuah angka tanpa rentang terbaca sebagai "bulan ini"
         oleh siapa pun yang membacanya di rapat bulanan. */
      el('.desc', {
        text: 'Rata-rata dihitung HANYA dari tiket yang benar-benar dinilai pelanggan; '
          + 'tiket yang belum dijawab tidak dihitung nol. '
          + 'Angka di bawah mencakup SELURUH riwayat tiket yang sudah selesai, bukan satu bulan.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(5, 3));

  let payload;
  try {
    payload = await api.list('servicedesk/csat-summary', { per_page: COMMENT_PAGE_SIZE, page });
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  const summary = (payload.meta || {}).summary;
  const rows = payload.data || [];

  clear(body);

  if (!summary) {
    body.appendChild(errorState(new Error('Ringkasan tidak dikirim server.'), reload));
    return;
  }

  body.appendChild(summaryStats(summary));
  body.appendChild(el('.detail-grid', [
    el('div', [commentsCard(rows, summary, payload.meta || {}, (next) => renderCsat(host, next))]),
    el('div', [distributionCard(summary)]),
  ]));
}
