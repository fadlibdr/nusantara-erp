/* Usulan PR dari kekurangan stok (F-6).
 *
 * Layar ini MEMBACA kekurangan dan menawarkan satu tombol yang membuat PR
 * DRAF. Ia tidak mengajukan dan tidak menyetujui apa pun — yang menekan Ajukan
 * tetap manusia, di layar Permintaan Pembelian, lewat izin prc.update yang
 * sudah ada. Alasannya sama dengan alasan Usulan Rekap Absensi (F-4) berhenti
 * di isian formulir: sebuah ambang yang salah ketik satu digit akan mengubah
 * dirinya menjadi pesanan pembelian, dan PO adalah uang perusahaan yang keluar.
 *
 * TIGA HAL YANG DITULIS DI LAYAR, bukan disembunyikan di kode:
 *
 *  1. dari mana ambang tiap baris datang (aturan gudang atau stok minimum
 *     item), dan angka yang KALAH di sebelahnya;
 *  2. item mana yang DILEWATI dan karena PR yang mana — dengan kodenya, supaya
 *     orangnya bisa membuka dokumen itu dan memutuskan sendiri;
 *  3. aturan pelewatan itu sendiri, kalimat penuh, di atas daftarnya. Server
 *     yang mengirim kalimatnya (payload.why_skipped): sebuah salinan di sini
 *     akan menyimpang dari aturan yang benar-benar dijalankan pada suntingan
 *     pertama.
 */

import { api, session } from '../api.js';
import { el, clear, button, badge, errorState, emptyState, skeletonTable, toast, toastError, withBusy } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor } from '../lookup.js';
import { navigate } from '../router.js';

const state = { warehouse: '' };

export async function renderReorder(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Usulan Pesan Ulang' }),
      el('.desc', {
        text: 'Kekurangan stok per gudang × item, ditawarkan sebagai PR draf. '
          + 'Layar ini tidak pernah mengajukan dan tidak pernah menyetujui — PR yang dibuat berstatus Draf '
          + 'dan Anda yang memeriksanya di layar Permintaan Pembelian.',
      }),
    ]),
  ]));

  const select = el('select.filter-w', { 'aria-label': 'Gudang' });
  select.appendChild(el('option', { value: '', text: 'Semua gudang' }));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  }, [select]);

  const body = el('div');
  host.append(controls, body);

  const warehouses = await loadSource('warehouses').catch(() => []);
  optionsFor('warehouses', warehouses).forEach((option) =>
    select.appendChild(el('option', { value: option.value, text: option.label })));
  select.value = state.warehouse;
  select.addEventListener('change', () => { state.warehouse = select.value; load(); });

  /* Nomor urut muatan: dua penggantian gudang beruntun mengirim dua permintaan,
     dan yang berangkat lebih dulu boleh mendarat belakangan — tabel gudang lama
     lalu menimpa gudang yang tertulis di kotak. Idiom yang sama dengan
     usulanrekap.js dan absensi.js. */
  let loadToken = 0;

  async function load() {
    const token = ++loadToken;
    clear(body).appendChild(skeletonTable(6, 6));

    let payload;
    try {
      payload = await api.get('inventory/reorder/proposal', { warehouse_id: state.warehouse || undefined });
    } catch (error) {
      if (token !== loadToken) return;
      clear(body).appendChild(errorState(error, load));
      return;
    }

    if (token !== loadToken) return;
    draw(payload);
  }

  function draw(payload) {
    clear(body);

    const rows = payload.rows || [];
    const counts = payload.rules || { proposable: 0, skipped: 0 };

    if (!rows.length) {
      body.appendChild(emptyState(
        'Tidak ada pasangan gudang × item yang berada di bawah ambangnya. Ambang itu sendiri diatur di '
        + 'Persediaan › Aturan Reorder, dan item tanpa aturan memakai stok minimum pada kartu itemnya.',
        { title: 'Tidak ada yang perlu dipesan', kind: 'done' },
      ));
      return;
    }

    body.appendChild(el('.stat-row', [
      el('.stat', [el('.label', { text: 'Bisa diusulkan' }), el('.value', { text: String(counts.proposable) })]),
      el('.stat', [el('.label', { text: 'Dilewati' }), el('.value', { text: String(counts.skipped) })]),
      el('.stat', [el('.label', { text: 'Baris kekurangan' }), el('.value', { text: String(rows.length) })]),
    ]));

    /* Aturan pelewatannya datang dari server sebagai kalimat jadi. Kartu ini
       selalu ada, juga ketika nol item dilewati: aturan yang hanya muncul saat
       ia menggigit terbaca seperti kejadian, bukan seperti aturan. */
    body.appendChild(el('.card', [
      el('.card-head', [el('h2', { text: 'Yang dilewati — dan kenapa' }), el('.spacer')]),
      el('.card-body', el('.cell-sub', { text: payload.why_skipped })),
    ]));

    const canCreate = session.can('prc.create');

    const createButton = button('Buat PR draf', {
      iconName: 'plus', variant: 'primary',
      onClick: (event) => create(event.currentTarget),
    });

    const head = el('.card-head', [
      el('h2', { text: 'Kekurangan stok' }),
      el('.spacer'),
      canCreate && counts.proposable > 0 ? createButton : null,
    ]);

    if (!canCreate) {
      head.appendChild(el('.cell-sub', { text: 'Membuat PR menuntut izin prc.create.' }));
    } else if (counts.proposable === 0) {
      head.appendChild(el('.cell-sub', { text: 'Semua kekurangan sudah ada di PR terbuka.' }));
    }

    body.appendChild(el('.card', [
      head,
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Item' }), el('th', { text: 'Gudang' }),
          el('th.right', { text: 'Stok' }), el('th.right', { text: 'Ambang' }),
          el('th.right', { text: 'Kurang' }), el('th.right', { text: 'Usulan pesan' }),
          el('th', { text: 'Status' }),
        ])),
        el('tbody', rows.map((row) => el('tr', [
          el('td', el('span', [
            el('span.cell-main', { text: row.item_name }),
            el('span.cell-sub.mono', { text: row.item_code }),
          ])),
          el('td', { text: row.warehouse_name }),
          el('td.right.num', { text: fmt.qty(row.qty, row.unit) }),
          el('td.right.num', el('span', [
            el('span', { text: fmt.qty(row.reorder_point) }),
            el('span.cell-sub', {
              text: row.threshold_source === 'rule'
                ? `${row.threshold_source_label} · stok min. item ${fmt.qty(row.min_stock)}`
                : row.threshold_source_label,
            }),
          ])),
          el('td.right.num', { text: fmt.qty(row.shortage_qty), style: { color: 'var(--danger)' } }),
          el('td.right.num', el('span', [
            el('span', { text: fmt.qty(row.suggested_qty, row.unit) }),
            row.reorder_qty != null ? el('span.cell-sub', { text: 'jumlah pesan aturan' }) : null,
          ])),
          el('td', row.skipped
            ? el('span', [
              /* Tanpa nada: keping netral. Sebuah keping merah untuk baris
                 yang dilewati akan terbaca sebagai kegagalan, padahal yang
                 terjadi justru aturan idempotensinya bekerja. */
              badge('Dilewati'),
              el('span.cell-sub', { text: row.skipped_reason }),
            ])
            : badge('Akan diusulkan', 'amber')),
        ]))),
      ])),
    ]));
  }

  async function create(trigger) {
    await withBusy(trigger, async () => {
      let payload;
      try {
        payload = await api.post('inventory/reorder/requisitions', {
          warehouse_id: state.warehouse || undefined,
        });
      } catch (error) {
        toastError(error);
        return;
      }

      const created = payload.created || [];
      toast(payload.message, { tone: created.length ? 'ok' : 'info' });

      /* Satu PR → langsung ke dokumennya, karena yang berikutnya dilakukan
         orangnya adalah memeriksanya. Lebih dari satu → muat ulang daftar ini,
         yang sekarang menunjukkan setiap barisnya sebagai "Dilewati" beserta
         kode PR-nya: bukti bahwa idempotensinya benar-benar bekerja, di layar,
         tanpa harus mengklik dua kali untuk mengetahuinya. */
      if (created.length === 1) {
        navigate(`d/procurement/purchase-requisitions/${created[0].id}`);
        return;
      }

      await load();
    });
  }

  await load();
}
