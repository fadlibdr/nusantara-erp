/* Periode Fiskal — kalender pembukuan, tutup buku, dan jejak permanennya.
 *
 * Sebelum layar ini, "tutup buku" hanyalah satu kolom status yang tidak pernah
 * diubah siapa pun, dan data demo memperlihatkan akibatnya telanjang: sebelas
 * dari dua belas periode 2026 masih terbuka, dan satu-satunya yang berstatus
 * ditutup — Januari 2026 — tidak menyimpan siapa yang menutupnya, kapan, maupun
 * alasannya. Artinya assertPeriodOpen(), penjaga yang dilewati SELURUH lapisan
 * posting, sedang menjaga pintu yang tidak pernah dikunci.
 *
 * Sepuluh syarat di bawah — lima penghalang keras, lima peringatan — DIHITUNG
 * ULANG dari data sumber setiap kali layar dibuka, dan dihitung ulang sekali
 * lagi di dalam transaksi penutupan. Tidak ada yang disimpan lalu dipercaya.
 * Jadi daftar periksa ini sekaligus menjadi runbook tutup bulan yang selama ini
 * hanya ada di kepala satu orang.
 *
 * Jumlah itu milik PeriodCloseService::checklist(), bukan layar ini: yang
 * dirender adalah apa pun yang dikirim API, jadi syarat yang bertambah muncul
 * sendiri — hanya kalimat di atas yang tidak ikut, dan memang pernah tertinggal
 * menyebut sembilan setelah syarat kesepuluh ditambahkan.
 *
 * Tombol yang tidak boleh ditekan dirender NONAKTIF BESERTA ALASANNYA, bukan
 * disembunyikan: kontrol yang tidak terlihat akan ditanyakan lewat WhatsApp.
 */

import { api, session } from '../api.js';
import {
  el, clear, button, badge, icon, field, modal, toast, toastError,
  withBusy, errorState, skeletonTable,
} from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';
import { RESOURCES } from '../schema.js';

/* Route layar ini sendiri. Item "periode sebelumnya masih terbuka" menautkan
   balik ke sini, dan tombol "Perbaiki" yang memuat ulang halaman yang sedang
   dibuka terbaca sebagai tombol mati. */
const SELF_ROUTE = 'periods';

/** Sama dengan PeriodCloseService::NOTE_MIN. Server tetap yang memutuskan. */
const NOTE_MIN = 10;

/*
 * Tahun tidak pernah diambil dari jam browser. Permintaan pertama sengaja tidak
 * membawa parameter year: server menjawab dengan tahun pilihannya sendiri
 * beserta seluruh tahun yang benar-benar punya periode, dan dari jawaban itulah
 * chip tahun dan tombol "buat kalender" dibangun. Workstation yang jamnya
 * meleset setahun kalau tidak akan membuka kalender kosong lalu menawarkan
 * membuat tahun yang salah.
 */
const state = { year: null, selected: null, serverYear: null };

const SEVERITY_BADGE = {
  block: ['Wajib', 'red'],
  warn: ['Peringatan', 'amber'],
};

/* Ikon yang memang digambar ui.js: centang untuk selesai, segitiga peringatan
   untuk yang belum, dan strip datar untuk "tidak berlaku di perusahaan ini". */
function markFor(item) {
  if (item.status === 'ok') return { name: 'check', colour: 'var(--success)' };
  if (item.status === 'na') return { name: null, colour: 'var(--muted)' };
  return { name: 'warn', colour: item.severity === 'block' ? 'var(--danger)' : 'var(--warning)' };
}

/**
 * Boleh tidaknya sebuah tautan perbaikan ditampilkan.
 *
 * Tautan perbaikan keluar dari Keuangan ke empat modul: payroll (hr),
 * penyusutan (ast), dan — lewat rincian per sumber pada item dokumen
 * menggantung — GRN/pengeluaran/transfer/opname (inv) serta berita acara
 * lapangan (svc). Sumber yang kosong dibuang DanglingDocuments::scan(), jadi
 * tautan item itu sendiri adalah sumber pertama yang benar-benar menggantung
 * dan bisa mendarat di modul mana pun dari daftar tadi.
 *
 * Pemegang fin.view belum tentu punya izin di sana. Tombol yang mendarat di
 * "Anda tidak memiliki hak akses" lebih buruk daripada tidak ada tombol:
 * kalimat penjelasnya sudah menyebutkan apa yang kurang.
 */
function canFollow(link) {
  if (!link || link === SELF_ROUTE) return false;
  if (!link.startsWith('r/')) return true; // layar Keuangan lain — kita sudah lolos fin.view
  const def = RESOURCES[link.slice(2)];
  return Boolean(def) && session.can(`${def.module}.view`);
}

/** Bulan tertua yang sudah berakhir tetapi masih terbuka — antrean tutup buku. */
function nextInCloseOrder(periods) {
  return periods.find((period) => period.has_ended && !period.is_closed) || null;
}

export async function renderPeriods(host) {
  clear(host);
  const reload = () => renderPeriods(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Periode Fiskal' }),
      el('.desc', {
        text: 'Kalender pembukuan dan tutup buku. Setelah sebuah periode ditutup, tidak ada '
          + 'dokumen yang dapat diposting pada tanggal di dalamnya.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(6, 4));

  let data;
  try {
    data = await api.get('finance/fiscal-periods', { year: state.year });
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  // Jawaban pertama datang tanpa parameter tahun, jadi inilah "tahun sekarang"
  // menurut server — dipakai untuk membatasi tombol buat-kalender.
  if (state.serverYear === null) state.serverYear = data.year;
  state.year = data.year;

  const periods = data.periods || [];

  clear(body);
  // Tahun yang kosong menawarkan pembuatan kalendernya sendiri lewat emptyYear;
  // dua tombol "Buat kalender" bersebelahan untuk dua tahun berbeda hanya
  // mengundang salah klik.
  body.appendChild(yearRow(data, reload, periods.length > 0));

  if (!periods.length) {
    body.appendChild(emptyYear(data, reload));
    body.appendChild(howItWorks());
    return;
  }

  // Dipilih SEBELUM kalender digambar, supaya kartu yang tersorot adalah kartu
  // yang daftar periksanya sedang ditampilkan di bawah. Bawaannya bulan tertua
  // yang sudah berakhir dan masih terbuka — bulan yang memang berikutnya dalam
  // urutan tutup buku, dan hampir pasti alasan layar ini dibuka.
  const current = periods.find((period) => period.id === state.selected)
    || nextInCloseOrder(periods)
    || periods[periods.length - 1];

  state.selected = current.id;

  body.appendChild(summaryRow(periods, data.year));

  const detail = el('div');
  body.appendChild(calendarGrid(periods, (period) => showChecklist(detail, period, reload)));
  body.appendChild(detail);
  body.appendChild(howItWorks());

  await showChecklist(detail, current, reload);
}

/* --------------------------------------------------------------- kalender */

function yearRow(data, reload, offerNextYear) {
  const years = (data.years || []).length ? data.years : [data.year];
  const nextYear = Math.max(...years) + 1;

  const nodes = years.map((year) => button(String(year), {
    size: 'sm',
    variant: year === data.year ? 'primary' : '',
    onClick: () => {
      state.year = year;
      state.selected = null;
      reload();
    },
  }));

  // Batas dua tahun ke depan ditegakkan server (FiscalPeriodController::generate);
  // di sini tombolnya tidak ditawarkan sama sekali supaya tidak ada klik yang
  // pasti ditolak.
  if (offerNextYear && session.can('fin.create') && nextYear <= state.serverYear + 2) {
    nodes.push(button(`Buat kalender ${nextYear}`, {
      size: 'sm',
      iconName: 'plus',
      onClick: (event) => withBusy(event.currentTarget, () => generate(nextYear, reload)),
    }));
  }

  return el('div', {
    style: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center', marginBottom: '14px' },
  }, nodes);
}

function emptyYear(data, reload) {
  return el('.alert.info', [
    icon('warn', 15),
    el('div', { style: { flex: '1' } }, [
      el('div', {
        text: `Belum ada periode fiskal untuk ${data.year}. Selama kalendernya belum dibuat, `
          + `setiap dokumen bertanggal ${data.year} ditolak saat diposting — jurnalnya tidak punya `
          + 'periode untuk ditempati.',
      }),
      session.can('fin.create')
        ? el('div', { style: { marginTop: '8px' } }, button(`Buat kalender ${data.year}`, {
          size: 'sm',
          variant: 'primary',
          onClick: (event) => withBusy(event.currentTarget, () => generate(data.year, reload)),
        }))
        : el('.muted', {
          style: { fontSize: '12px', marginTop: '4px' },
          text: 'Membuat kalender memerlukan izin fin.create.',
        }),
    ]),
  ]);
}

function summaryRow(periods, year) {
  const closed = periods.filter((period) => period.is_closed);
  const next = nextInCloseOrder(periods);
  const last = closed.length ? closed[closed.length - 1] : null;

  return el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Periode ditutup' }),
      el('.value.sm', { text: `${closed.length} dari ${periods.length}` }),
      el('.delta', { text: `tahun buku ${year}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Berikutnya dalam antrean' }),
      el('.value.sm', { text: next ? next.label : '—' }),
      next
        ? el('.delta.down', { text: 'sudah berakhir, masih terbuka' })
        : el('.delta', { text: 'tidak ada bulan berakhir yang masih terbuka' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Penutupan terakhir' }),
      el('.value.sm', { text: last ? last.label : '—' }),
      // closed_at kosong pada periode yang ditutup sebelum riwayat ada; itu
      // fakta yang layak dibaca, bukan tanggal yang layak dikarang.
      last
        ? el('.delta', {
          text: last.closed_at
            ? `oleh ${last.closed_by ? last.closed_by.name : '—'} · ${fmt.date(last.closed_at)}`
            : 'tanpa catatan penutupan',
        })
        : el('.delta', { text: 'belum ada periode yang ditutup' }),
    ]),
  ]);
}

function periodCard(period) {
  const subtitle = () => {
    if (period.is_closed) {
      return period.closed_at
        ? `${period.closed_by ? period.closed_by.name : '—'} · ${fmt.date(period.closed_at)}`
        : 'ditutup tanpa catatan';
    }
    if (period.is_current) return 'bulan berjalan';
    return period.has_ended ? 'sudah berakhir — menunggu tutup buku' : 'belum berakhir';
  };

  // margin:0 melawan `.card + .card { margin-top: 16px }` di app.css. Di dalam
  // grid, aturan itu kena SETIAP kartu kecuali yang pertama dan menurunkannya
  // 16px di dalam barisnya sendiri — dua belas bulan tampil bertangga.
  return el('.card', {
    role: 'button',
    tabindex: '0',
    style: { padding: '10px 12px', cursor: 'pointer', margin: '0' },
  }, [
    el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } }, [
      el('strong', { text: fmt.MONTHS[period.month - 1] || period.code }),
      el('.spacer', { style: { flex: '1' } }),
      badge(period.status_label, period.is_closed ? '' : 'green'),
    ]),
    el('.muted', { style: { fontSize: '11.5px', marginTop: '4px' }, text: subtitle() }),
  ]);
}

function calendarGrid(periods, onSelect) {
  const cards = new Map();

  const paint = () => cards.forEach((node, id) => {
    const on = id === state.selected;
    node.style.borderColor = on ? 'var(--primary)' : '';
    node.style.boxShadow = on ? '0 0 0 1px var(--primary)' : '';
    node.setAttribute('aria-pressed', String(on));
  });

  const nodes = periods.map((period) => {
    const card = periodCard(period);
    const pick = () => {
      if (state.selected === period.id) return;
      state.selected = period.id;
      paint();
      onSelect(period);
    };

    card.addEventListener('click', pick);
    card.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') return;
      event.preventDefault(); // tanpa ini Space menggulung halaman di bawah kartu
      pick();
    });

    cards.set(period.id, card);
    return card;
  });

  paint();

  return el('div', {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))',
      gap: '10px',
      marginBottom: '16px',
    },
  }, nodes);
}

/* -------------------------------------------------------------- checklist */

/*
 * Daftar periksa adalah bacaan terberat di modul Keuangan — satu rekonsiliasi
 * bank per rekening aktif, satu neraca saldo, dan satu ringkasan ekspor pajak —
 * jadi mengklik empat bulan berturut-turut meninggalkan empat permintaan yang
 * balapan. Tanpa nomor urut ini, jawaban yang paling lambat yang menang, dan
 * layar menampilkan daftar periksa bulan yang sudah tidak dipilih lagi: angka
 * yang benar untuk periode yang salah, tanpa satu pun tanda bahwa itu terjadi.
 */
let checklistSeq = 0;

async function showChecklist(host, period, reload) {
  const seq = ++checklistSeq;
  const retry = () => showChecklist(host, period, reload);

  clear(host);
  host.appendChild(el('.card', skeletonTable(10, 3)));

  let data;
  try {
    data = await api.get(`finance/fiscal-periods/${period.id}/checklist`);
  } catch (error) {
    if (seq !== checklistSeq) return undefined;
    return clear(host).appendChild(errorState(error, retry));
  }

  if (seq !== checklistSeq) return undefined; // bulan lain sudah dipilih sejak tadi

  clear(host);
  host.appendChild(checklistCard(data, reload));
  host.appendChild(historyCard(data));
  return undefined;
}

function checklistCard(data, reload) {
  const summary = data.summary;

  return el('.card', [
    // .card-head tidak membungkus barisnya sendiri di app.css, dan kepala ini
    // membawa empat lencana sekaligus — di layar sempit mereka terdesak keluar.
    el('.card-head', { style: { flexWrap: 'wrap' } }, [
      el('h2', { text: `Daftar periksa — ${data.label}` }),
      el('.sub', { text: `${fmt.date(data.period_start)} s/d ${fmt.date(data.period_end)}` }),
      el('.spacer'),
      badge(data.status_label, data.is_closed ? '' : 'green'),
      badge(`${summary.blockers} penghalang`, summary.blockers ? 'red' : 'green'),
      badge(`${summary.warnings} peringatan`, summary.warnings ? 'amber' : ''),
    ]),
    el('.card-body', data.items.map((item, index) => checklistItem(item, index))),
    actionFoot(data, reload),
  ]);
}

function checklistItem(item, index) {
  const mark = markFor(item);
  const [severityLabel, severityTone] = SEVERITY_BADGE[item.severity] || ['', ''];
  const sources = item.status === 'fail' ? (item.sources || []) : [];

  return el('div', {
    style: {
      display: 'flex',
      gap: '10px',
      alignItems: 'flex-start',
      padding: index ? '11px 0 0' : '0',
      marginTop: index ? '11px' : '0',
      borderTop: index ? '1px solid var(--border)' : '',
    },
  }, [
    el('span', { style: { flex: '0 0 18px', marginTop: '1px', color: mark.colour } },
      mark.name ? icon(mark.name, 16) : el('span', { text: '—' })),
    el('div', { style: { flex: '1', minWidth: '0' } }, [
      el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' } }, [
        el('strong', { text: item.label, style: { fontSize: '13px' } }),
        item.status === 'fail' && severityLabel ? badge(severityLabel, severityTone) : null,
        item.status === 'na' ? badge('Tidak berlaku') : null,
      ]),
      el('.muted', { style: { fontSize: '12px', marginTop: '3px', lineHeight: '1.55' }, text: item.detail }),
      // Rincian per sumber hanya dikirim oleh dokumen menggantung: tabel mana,
      // berapa, nomor apa saja. Itu daftar kerja yang sesungguhnya.
      sources.length ? el('div', { style: { marginTop: '6px', display: 'flex', flexDirection: 'column', gap: '4px' } },
        sources.map((source) => el('div', {
          style: { display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap', fontSize: '12px' },
        }, [
          el('span.mono', { text: `${source.label} (${source.count})` }),
          el('.muted', {
            text: `${(source.codes || []).join(', ')}${(source.codes || []).length < source.count ? ', …' : ''}`,
          }),
          canFollow(source.link)
            ? button('Buka', { size: 'sm', variant: 'ghost', onClick: () => navigate(source.link) })
            : null,
        ]))) : null,
    ]),
    // Dokumen menggantung sudah punya tombol per sumber di atas; satu tombol
    // umum di sebelahnya hanya menduakan tautan pertama.
    item.status === 'fail' && !sources.length && canFollow(item.link)
      ? button(item.severity === 'block' ? 'Perbaiki' : 'Tinjau', {
        size: 'sm',
        onClick: () => navigate(item.link),
      })
      : null,
  ]);
}

function actionFoot(data, reload) {
  const summary = data.summary;
  const canClose = session.can('fin.post');
  const canReopen = session.can('fin.approve');

  if (!canClose && !canReopen) {
    return el('.card-foot', el('.muted', {
      style: { fontSize: '12px', marginRight: 'auto' },
      text: 'Menutup periode memerlukan izin fin.post, membukanya kembali fin.approve. '
        + 'Anda dapat membaca daftar periksa ini, tetapi tidak menjalankan keduanya.',
    }));
  }

  /*
   * Satu kalimat alasan, yang relevan saja. Pada periode terbuka,
   * reopen_blocked_reason berbunyi "tidak sedang ditutup" dan itu bising;
   * pada periode tertutup, close_blocked_reason berbunyi "sudah ditutup" dan
   * lencana di kepala kartu sudah mengatakannya.
   */
  const reason = data.is_closed
    ? (summary.can_reopen ? null : summary.reopen_blocked_reason)
    : (summary.can_close ? null : summary.close_blocked_reason);

  return el('.card-foot', [
    reason
      ? el('.muted', { style: { fontSize: '12px', marginRight: 'auto', maxWidth: '62ch' }, text: reason })
      : el('.spacer', { style: { flex: '1' } }),
    canClose
      ? button(summary.blockers ? `Tutup Periode (${summary.blockers} penghalang)` : `Tutup ${data.label}`, {
        variant: 'primary',
        disabled: !summary.can_close,
        title: summary.close_blocked_reason || undefined,
        onClick: () => openCloseModal(data, reload),
      })
      : null,
    canReopen
      ? button('Buka Kembali', {
        disabled: !summary.can_reopen,
        title: summary.reopen_blocked_reason || undefined,
        onClick: () => openReopenModal(data, reload),
      })
      : null,
  ]);
}

function historyCard(data) {
  const events = [...(data.events || [])].reverse(); // server mengirim terlama dulu
  const shortByKey = new Map(data.items.map((item) => [item.key, item.short]));

  const empty = data.is_closed
    ? 'Periode ini ditutup sebelum riwayat penutupan dicatat, jadi tidak ada catatan siapa yang '
      + 'menutupnya maupun alasannya. Penutupan berikutnya akan tercatat di sini.'
    : 'Belum pernah ditutup atau dibuka kembali.';

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Riwayat penutupan' }),
      el('.sub', { text: 'tercatat permanen' }),
    ]),
    el('.card-body', events.length
      ? el('.timeline', events.map((event) => el(`.timeline-item.${event.action === 'reopened' ? 'pending' : 'ok'}`, [
        el('b', { text: event.action_label }),
        el('.meta', { text: `${event.user_name || 'Sistem'} · ${fmt.dateTime(event.created_at)}` }),
        event.note ? el('.note', { text: event.note }) : null,
        (event.overrides || []).length
          ? el('.note', {
            style: { color: 'var(--warning)' },
            text: `Peringatan diabaikan: ${event.overrides.map((key) => shortByKey.get(key) || key).join(', ')}.`,
          })
          : null,
      ])))
      : el('p.muted', { text: empty, style: { margin: '0', fontSize: '13px' } })),
  ]);
}

/* ---------------------------------------------------------------- actions */

function openCloseModal(data, reload) {
  // Hanya peringatan yang BENAR-BENAR gagal yang perlu diakui. Server menghitung
  // ulang daftarnya di dalam transaksi, jadi peringatan yang muncul setelah layar
  // digambar akan ditolak di sana — dan memang harus begitu.
  const warnings = data.items.filter((item) => item.severity === 'warn' && item.status === 'fail');
  const boxes = new Map();

  const note = el('textarea', {
    rows: '3',
    placeholder: 'Mis. rekening koran BCA Juni belum diterima dari bank; disetujui manajer keuangan.',
  });

  const body = el('div', [
    el('p', {
      style: { margin: '0 0 12px', color: 'var(--text-2)', fontSize: '13px', lineHeight: '1.6' },
      text: `Setelah ${data.label} ditutup, tidak ada dokumen yang dapat diposting pada tanggal di `
        + 'dalamnya, dan pembatalan dokumen lama akan dibalik dengan jurnal bertanggal hari ini — '
        + 'bukan tanggal aslinya.',
    }),
    warnings.length
      ? el('.alert.warn', { style: { marginBottom: '12px' } }, [
        icon('warn', 15),
        el('div', {
          text: `${warnings.length} peringatan belum selesai. Centang satu per satu yang Anda terima `
            + 'dan tulis alasannya — nama Anda, alasan, dan daftar peringatan yang diabaikan '
            + 'tersimpan permanen di riwayat periode ini.',
        }),
      ])
      : null,
    ...warnings.map((item) => {
      const box = el('input', { type: 'checkbox', style: { marginTop: '2px', flex: 'none' } });
      boxes.set(item.key, box);

      return el('label', {
        style: { display: 'flex', gap: '10px', alignItems: 'flex-start', padding: '8px 0', cursor: 'pointer' },
      }, [
        box,
        el('div', { style: { flex: '1', minWidth: '0' } }, [
          el('strong', { text: item.label, style: { fontSize: '13px' } }),
          el('.muted', { style: { fontSize: '12px', marginTop: '2px', lineHeight: '1.55' }, text: item.detail }),
        ]),
      ]);
    }),
    warnings.length
      ? field('Alasan mengabaikan peringatan', note, {
        required: true,
        help: `Minimal ${NOTE_MIN} karakter. Inilah satu-satunya penjelasan yang akan dibaca auditor.`,
      })
      : null,
  ]);

  const cancel = button('Batal', { onClick: () => dialog.requestClose() });

  const submit = button('Tutup Periode', {
    variant: 'primary',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      const acknowledged = [...boxes.entries()].filter(([, box]) => box.checked).map(([key]) => key);
      const missing = warnings.filter((item) => !acknowledged.includes(item.key));

      if (missing.length) {
        toast(`Centang dulu peringatan yang Anda terima: ${missing.map((item) => item.short).join(', ')}.`,
          { tone: 'info' });
        return;
      }

      if (warnings.length && note.value.trim().length < NOTE_MIN) {
        toast(`Alasan wajib diisi minimal ${NOTE_MIN} karakter — alasannya tercatat permanen.`,
          { tone: 'info' });
        return;
      }

      try {
        const result = await api.post(`finance/fiscal-periods/${data.id}/close`, {
          note: note.value.trim() || null,
          acknowledge: acknowledged,
        });
        const diabaikan = acknowledged.length
          ? ` ${acknowledged.length} peringatan diabaikan dan tercatat.`
          : '';
        dialog.close();
        toast(`Periode ${result.label} ditutup.${diabaikan}`);
        reload();
      } catch (error) {
        toastError(error);
      }
    }),
  });

  const dialog = modal({
    title: `Tutup ${data.label}`,
    body,
    footer: [cancel, submit],
    /*
     * Tanpa ini fokus jatuh ke `button.primary` — aturan cadangan modal() —
     * ketika periode bersih dan tidak ada satu pun kotak centang di badannya.
     * Artinya dialog terbuka dengan "Tutup Periode" persis di bawah tombol
     * Enter, dan satu refleks menutup buku sebulan penuh.
     */
    initialFocus: () => boxes.values().next().value || cancel,
    dirty: () => note.value.trim() !== '',
    dirtyPrompt: {
      title: 'Buang alasan yang sudah diketik?',
      message: 'Alasan penutupan yang Anda tulis belum terkirim dan akan hilang.',
      confirmLabel: 'Buang',
      cancelLabel: 'Kembali menulis',
    },
  });
}

function openReopenModal(data, reload) {
  const note = el('textarea', {
    rows: '3',
    placeholder: 'Mis. tagihan vendor Juni terlambat masuk dan harus dibukukan pada bulannya; disetujui direktur.',
  });

  const body = el('div', [
    el('.alert.warn', { style: { marginBottom: '12px' } }, [
      icon('warn', 15),
      el('div', {
        text: `Membuka kembali ${data.label} membuat angka yang sudah dilaporkan bisa berubah lagi — `
          + 'termasuk saldo awal bulan-bulan sesudahnya. Alasannya wajib diisi dan tersimpan permanen '
          + 'bersama nama Anda.',
      }),
    ]),
    field('Alasan membuka kembali', note, {
      required: true,
      help: 'Sebutkan dokumen atau koreksi yang menuntut periode ini dibuka.',
    }),
  ]);

  const dialog = modal({
    title: `Buka kembali ${data.label}`,
    body,
    width: 'narrow',
    footer: [
      button('Batal', { onClick: () => dialog.requestClose() }),
      button('Buka Kembali', {
        variant: 'danger',
        onClick: (event) => withBusy(event.currentTarget, async () => {
          if (!note.value.trim()) {
            toast('Alasan membuka periode wajib diisi — ini tercatat permanen.', { tone: 'info' });
            return;
          }

          try {
            const result = await api.post(`finance/fiscal-periods/${data.id}/reopen`, {
              note: note.value.trim(),
            });
            dialog.close();
            toast(`Periode ${result.label} dibuka kembali.`);
            reload();
          } catch (error) {
            toastError(error);
          }
        }),
      }),
    ],
    dirty: () => note.value.trim() !== '',
    dirtyPrompt: {
      title: 'Buang alasan yang sudah diketik?',
      message: 'Alasan pembukaan yang Anda tulis belum terkirim dan akan hilang.',
      confirmLabel: 'Buang',
      cancelLabel: 'Kembali menulis',
    },
  });
}

async function generate(year, reload) {
  try {
    const result = await api.post('finance/fiscal-periods/generate', { year });
    // Pesannya ada DI DALAM data, bukan di amplop: api.post() mengembalikan
    // payload.data saja dan membuang message di sebelahnya.
    toast(result.message || `Kalender fiskal ${result.year} dibuat.`,
      { tone: result.created ? 'ok' : 'info' });
    state.year = result.year;
    state.selected = null;
    reload();
  } catch (error) {
    toastError(error);
  }
}

/* ------------------------------------------------------------ cara kerjanya */

function howItWorks() {
  // Kartu ini menyusul sebuah <div> pembungkus, bukan .card, jadi aturan
  // `.card + .card` tidak kena dan jaraknya harus disetel sendiri.
  return el('.card', { style: { marginTop: '16px' } }, [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', { text: 'Daftar periksa dihitung ulang dari data sumber setiap kali layar ini dibuka — tidak ada yang disimpan lalu dipercaya. Ia dihitung ulang sekali lagi di dalam transaksi penutupan, jadi draf jurnal yang muncul lima detik setelah layar digambar tetap menghentikan penutupan.' }),
      el('p', { text: `Penghalang keras tidak bisa ditawar. Peringatan bisa diabaikan, tetapi harus dicentang satu per satu dan disertai alasan minimal ${NOTE_MIN} karakter; nama pengabai, alasannya, dan daftar peringatannya tersimpan permanen dan tidak dapat disunting.` }),
      el('p', { text: 'Buku ditutup berurutan dari bulan terlama dan dibuka kembali dari yang terbaru. Selama Februari masih terbuka, saldo awal Juni masih bisa bergeser di belakangnya — itulah sebabnya melompati bulan ditolak.' }),
      el('p', { text: 'Sesudah ditutup, tidak ada dokumen yang dapat diposting pada tanggal di dalam periode itu, dan pembatalan dokumen lama dibalik dengan jurnal bertanggal hari ini. Periode yang sudah diukur oleh run PSAK 115 terposting tidak dapat dibuka lagi selamanya: koreksi yang ditemukan hari ini dibukukan hari ini.' }),
      el('p', { text: 'Kalender dibuat di muka, tidak pernah mendadak. Tugas terjadwal fin:ensure-calendar menambahkan bulan-bulan berikutnya setiap pagi, supaya posting pada 2 Januari tidak pernah gagal hanya karena periodenya belum ada.' }),
    ]),
  ]);
}
