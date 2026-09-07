/* Tugas Saya — kotak masuk persetujuan lengkap.
 *
 * Kartu dasbor hanya cuplikan lima baris; layar ini adalah antreannya: semua
 * jenis dokumen di ApprovableDocuments yang boleh disetujui pemanggil, yang
 * paling lama menunggu di atas, dengan saringan per jenis. Satu permintaan
 * (GET core/inbox); tidak ada logika per modul di sini — jenis dokumen baru
 * ikut otomatis begitu terdaftar di server.
 *
 * Dulu pekerjaan sampai lewat tiga pintu yang harus diperiksa bergantian
 * (kartu dasbor 11 jenis, lonceng yang basi, Tenggat untuk yang lewat).
 * Layar ini menjawab satu pertanyaan saja: "apa yang menunggu keputusan
 * saya sekarang" — dan menjawabnya lengkap.
 *
 * F-1 menambahkan tiga hal, semuanya di layar ini karena inilah layar yang
 * dibuka penyetuju:
 *
 *   SPANDUK "a.n." — bila pembaca sedang memegang delegasi, ia harus tahu
 *       SEBELUM menekan Setujui bahwa hak yang dipakainya pinjaman, dan
 *       dari siapa. Jejaknya akan berbunyi "Budi a.n. Sari" apa pun yang
 *       terjadi; spanduk ini memastikan itu bukan kejutan.
 *   DELEGASI SAYA — memberi dan mencabut. Di sini, bukan di Pengaturan:
 *       Pengaturan menuntut core.view, yang tidak dipegang seorang manajer
 *       proyek — justru orang yang perlu menyerahkan haknya sebelum cuti.
 *   SETUJUI TERPILIH — hanya bila pemilik mengisi plafonnya. Loopnya di sini,
 *       memanggil endpoint approve MILIK TIAP MODUL satu per satu: maker-
 *       checker, ambang direktur, jurnal, stok dan pemberitahuan berjalan
 *       persis seperti menyetujui satu-satu, karena memang itu yang terjadi. */
import { api } from '../api.js';
import { el, clear, button, badge, emptyState, errorState, skeletonTable, toast, toastError, withBusy } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

/** "10 Sep 2026 – 20 Sep 2026", atau "sejak 10 Sep 2026" bila tanpa akhir. */
function windowText(row) {
  const from = row.starts_at ? fmt.date(row.starts_at) : null;
  const to = row.ends_at ? fmt.date(row.ends_at) : null;
  if (from && to) return `${from} – ${to}`;
  if (from) return `sejak ${from}, tanpa tanggal akhir`;
  return 'tanpa jendela';
}

/* Spanduk hak pinjaman. Digambar hanya bila ADA delegasi berjalan — sebuah
   spanduk yang selalu ada berhenti dibaca pada hari kedua. */
function delegationBanner(delegations) {
  if (!delegations || !delegations.length) return null;

  const lines = delegations.map((row) => {
    const scope = row.scope ? `persetujuan ${row.scope}` : 'seluruh persetujuannya';
    const why = row.reason ? ` — ${row.reason}` : '';
    return `${row.giver || 'Pemberi'} (${scope}, ${windowText(row)})${why}`;
  });

  return el('.alert.info', { style: { marginBottom: '12px' } }, [
    el('div', { style: { flex: '1' } }, [
      el('div', { text: `Anda memegang delegasi persetujuan dari ${delegations.length === 1 ? '' : `${delegations.length} orang: `}${lines.join('; ')}.` }),
      el('.cell-sub', {
        text: 'Persetujuan yang Anda berikan dengan hak ini tercatat sebagai "Anda a.n. pemberinya". '
          + 'Dokumen yang DIAJUKAN pemberi delegasi tetap tidak boleh Anda setujui.',
      }),
    ]),
  ]);
}

/* ------------------------------------------------------ Delegasi persetujuan */

function delegationCard() {
  const body = el('.card-body');
  const card = el('.card', [
    el('.card-head', [el('h2', { text: 'Delegasi Persetujuan' }), el('.spacer')]),
    body,
  ]);

  let scopes = [];
  let canDelegateForOthers = false;

  function form(reload) {
    const delegate = el('input', { type: 'number', min: '1', placeholder: 'ID pengguna penerima' });
    const scope = el('select', [el('option', { value: '', text: 'Semua persetujuan saya' })]);
    for (const option of scopes) scope.appendChild(el('option', { value: option.value, text: option.label }));
    const from = el('input', { type: 'date' });
    const to = el('input', { type: 'date' });
    const reason = el('input', { type: 'text', maxlength: 200, placeholder: 'mis. cuti tahunan' });

    const submit = button('Berikan delegasi', {
      variant: 'primary',
      onClick: () => withBusy(submit, async () => {
        try {
          const payload = await api.post('core/approval-delegations', {
            delegate_user_id: Number(delegate.value) || null,
            scope: scope.value || null,
            starts_at: from.value || null,
            ends_at: to.value || null,
            reason: reason.value.trim() || null,
          });
          // Pesannya datang dari server dan bisa berupa peringatan jujur
          // ("pemberinya tidak memegang satu pun hak dalam lingkup ini").
          toast(payload && payload.message ? payload.message : 'Delegasi disimpan.', { timeout: 6000 });
          await reload();
        } catch (error) {
          toastError(error);
        }
      }),
    });

    return el('.form-grid', { style: { marginTop: '12px' } }, [
      el('.field', [el('label', { text: 'Penerima (ID pengguna)' }), delegate,
        el('.help', { text: 'Delegasi hanya meminjamkan izin yang benar-benar Anda pegang; ia tidak pernah membuat hak baru.' })]),
      el('.field', [el('label', { text: 'Lingkup' }), scope]),
      el('.field', [el('label', { text: 'Mulai' }), from]),
      el('.field', [el('label', { text: 'Selesai (boleh kosong)' }), to,
        el('.help', { text: 'Kosong = berlaku sampai dicabut.' })]),
      el('.field', [el('label', { text: 'Alasan' }), reason]),
      el('.field', [el('label', { text: ' ' }), submit]),
    ]);
  }

  async function reload() {
    clear(body).appendChild(el('.skeleton', { style: { height: '16px' } }));

    let payload;
    try {
      payload = await api.list('core/approval-delegations');
    } catch (error) {
      clear(body).appendChild(errorState(error, reload));
      return;
    }

    const rows = payload.data || [];
    scopes = (payload.meta && payload.meta.scopes) || [];
    canDelegateForOthers = Boolean(payload.meta && payload.meta.can_delegate_for_others);

    clear(body);

    if (!rows.length) {
      body.appendChild(emptyState(
        'Belum ada delegasi. Berikan satu sebelum cuti agar antrean persetujuan tidak berhenti.',
        { title: 'Tidak ada delegasi', kind: 'done' },
      ));
    } else {
      body.appendChild(el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Pemberi' }), el('th', { text: 'Penerima' }), el('th', { text: 'Lingkup' }),
          el('th', { text: 'Jendela' }), el('th', { text: 'Keadaan' }), el('th', { text: '' }),
        ])),
        el('tbody', rows.map((row) => el('tr', [
          el('td', { text: (row.giver && row.giver.name) || '—' }),
          el('td', { text: (row.delegate && row.delegate.name) || '—' }),
          el('td', { text: row.scope_label || 'Semua persetujuan' }),
          el('td', [
            el('span.cell-main', { text: windowText(row) }),
            row.reason ? el('span.cell-sub', { text: row.reason }) : null,
          ]),
          el('td', [badge(row.state, row.is_active ? 'ok' : '')]),
          el('td', [row.can_revoke && !row.revoked_at
            ? button('Cabut', {
              size: 'sm',
              variant: 'ghost',
              onClick: async () => {
                try {
                  await api.del(`core/approval-delegations/${row.id}`);
                  toast('Delegasi dicabut.');
                  await reload();
                } catch (error) {
                  toastError(error);
                }
              },
            })
            : null]),
        ]))),
      ])));
    }

    body.appendChild(el('p.muted', {
      style: { margin: '10px 0 0', fontSize: '12.5px' },
      text: canDelegateForOthers
        ? 'Anda memegang iam.update, sehingga dapat mendelegasikan hak orang lain juga; kirim ID pemberinya lewat API bila perlu.'
        : 'Anda hanya dapat mendelegasikan hak persetujuan MILIK ANDA SENDIRI.',
    }));

    body.appendChild(form(reload));
  }

  reload();
  return card;
}

/* -------------------------------------------------------------- Tugas Saya */

export async function renderTugas(host) {
  clear(host);
  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Tugas Saya' }),
      el('.desc', { text: 'Dokumen yang menunggu keputusan Anda, yang paling lama menunggu di atas.' }),
    ]),
    el('.actions', [button('Muat ulang', { iconName: 'refresh', onClick: () => renderTugas(host) })]),
  ]));

  const card = el('.card');
  host.appendChild(card);
  card.appendChild(skeletonTable(5, 5));

  let payload;
  try {
    payload = await api.list('core/inbox');
  } catch (error) {
    clear(card).appendChild(el('.card-body', errorState(error, () => renderTugas(host))));
    return;
  }

  const rows = payload.data || [];
  const meta = payload.meta || {};
  const types = [...new Set(rows.map((r) => r.label))].sort();
  let filter = '';

  const banner = delegationBanner(meta.delegations);
  if (banner) host.insertBefore(banner, card);

  /* Plafon setujui massal. null/kosong = fitur mati, dan tidak ada satu pun
     kotak centang atau tombol yang digambar — sebuah tombol yang ada tetapi
     menolak bekerja lebih buruk daripada tidak ada tombol. */
  const cap = Number(meta.batch_cap) > 0 ? Number(meta.batch_cap) : null;
  const selected = new Set();

  const select = el('select.filter-w', [
    el('option', { value: '', text: `Semua jenis (${rows.length})` }),
    ...types.map((t) => el('option', { value: t, text: `${t} (${rows.filter((r) => r.label === t).length})` })),
  ]);
  select.addEventListener('change', () => { filter = select.value; paint(); });

  const bulkCount = el('span.muted', { style: { fontSize: '12.5px' } });
  const bulkButton = button('Setujui terpilih', {
    variant: 'primary',
    size: 'sm',
    disabled: true,
    onClick: () => withBusy(bulkButton, () => approveSelected()),
  });
  const bulkBar = cap
    ? el('.filters', { style: { borderTop: '1px solid var(--border)' } }, [
      bulkCount, el('span', { style: { flex: '1' } }), bulkButton,
    ])
    : null;

  function syncBulk() {
    if (!cap) return;
    bulkButton.disabled = selected.size === 0;
    bulkCount.textContent = selected.size === 0
      ? `Pilih dokumen untuk disetujui sekaligus (maksimum ${cap}).`
      : `${selected.size} dipilih dari maksimum ${cap}.`;
  }

  /* Loop di KLIEN. Tiap dokumen memanggil endpoint approve modulnya sendiri,
     berurutan — bukan paralel: setiap panggilan menulis jurnal/stok dan ada
     batas laju 120 permintaan/menit, dan sepuluh permintaan serempak yang
     separuhnya kena 429 adalah hasil yang tidak bisa dibaca siapa pun.
     Kegagalan TIDAK menghentikan sisanya, dan tiap kegagalan disebut dengan
     kode dokumennya: "8 disetujui, 2 ditolak" yang tidak menyebut yang mana
     adalah laporan yang memaksa orang memeriksa sepuluh dokumen. */
  async function approveSelected() {
    const chosen = rows.filter((row) => selected.has(row.link));
    const done = [];
    const failed = [];

    for (const row of chosen) {
      try {
        await api.post(row.approve_url, {});
        done.push(row.code);
      } catch (error) {
        failed.push(`${row.code}: ${(error && error.message) || 'gagal'}`);
      }
    }

    if (done.length) toast(`${done.length} dokumen disetujui: ${done.join(', ')}.`, { timeout: 6000 });
    if (failed.length) {
      toast(`${failed.length} tidak disetujui — ${failed.join(' · ')}`, { tone: 'error', timeout: 12000 });
    }

    renderTugas(host);
  }

  const body = el('div');
  clear(card);
  card.appendChild(el('.filters', [el('span.muted', { text: 'Jenis dokumen' }), select]));
  card.appendChild(body);
  if (bulkBar) card.appendChild(bulkBar);

  function paint() {
    clear(body);
    const shown = filter ? rows.filter((r) => r.label === filter) : rows;

    if (meta.failed && meta.failed.length) {
      body.appendChild(el('.alert.warn', { style: { margin: '12px 16px 0' } },
        `Gagal dimuat: ${meta.failed.join(', ')}. Daftar ini belum lengkap.`));
    }

    if (!shown.length) {
      // Gambar galat saat sumbernya gagal, centang saat memang kosong (P1-B).
      body.appendChild(meta.failed && meta.failed.length
        ? emptyState('Tidak ada dokumen yang dapat ditampilkan.', { title: 'Daftar belum lengkap', kind: 'error' })
        : emptyState('Tidak ada dokumen yang menunggu keputusan Anda.', { title: 'Kotak masuk kosong', kind: 'done' }));
      return;
    }

    body.appendChild(el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        ...(cap ? [el('th', { text: '', style: { width: '34px' } })] : []),
        el('th', { text: 'Dokumen' }), el('th', { text: 'Keterangan' }), el('th', { text: 'Diajukan oleh' }),
        el('th', { text: 'Menunggu' }), el('th.right', { text: 'Nilai' }),
      ])),
      el('tbody', shown.map((r) => {
        let box = null;
        if (cap) {
          box = el('input', { type: 'checkbox' });
          // Baris tanpa endpoint approve tidak bisa ikut: memanggil URL yang
          // tidak ada akan menjadi 404 di tengah antrean, bukan penolakan.
          box.disabled = !r.approve_url;
          box.checked = selected.has(r.link);
          box.title = r.approve_url ? '' : 'Jenis dokumen ini disetujui lewat layarnya sendiri.';
          box.addEventListener('click', (event) => event.stopPropagation());
          box.addEventListener('change', () => {
            if (box.checked) {
              if (selected.size >= cap) {
                box.checked = false;
                toast(`Maksimum ${cap} dokumen sekali jalan.`, { tone: 'info', timeout: 4000 });
                return;
              }
              selected.add(r.link);
            } else {
              selected.delete(r.link);
            }
            syncBulk();
          });
        }

        const tr = el('tr.clickable', [
          ...(cap ? [el('td', [box])] : []),
          el('td', [el('span.cell-main.mono', { text: r.code }), el('span.cell-sub', { text: r.label })]),
          el('td', { text: r.title || '—', style: { maxWidth: '420px' } }),
          el('td', { text: r.submitted_by || '—' }),
          el('td', r.days_waiting === null || r.days_waiting === undefined
            ? '—'
            // 7 hari ke atas berwarna: antrean yang menua adalah temuan, bukan angka.
            : badge(`${r.days_waiting} hari`, r.days_waiting >= 14 ? 'red' : r.days_waiting >= 7 ? 'amber' : '')),
          el('td.right.num', { text: r.amount === null || r.amount === undefined ? '—' : fmt.rupiah(r.amount) }),
        ]);
        tr.addEventListener('click', () => navigate(r.link.replace(/^#\//, '')));
        return tr;
      })),
    ])));
  }

  paint();
  syncBulk();

  // Delegasi dimuat sesudah antreannya: antrean adalah alasan orang membuka
  // layar ini, dan kegagalan memuat delegasi tidak boleh menumbangkannya.
  host.appendChild(delegationCard());
}
