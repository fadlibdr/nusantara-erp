/* RFQ — matriks banding penawaran vendor (temuan #34 tahap 3).

   Layar detail khusus karena tabulasi banding tidak muat di detail generik:
   barisnya adalah barang, KOLOMNYA vendor yang diundang, dan selnya harga
   yang diketikkan staf pengadaan — plus pilihan pemenang per baris atau satu
   vendor sekaligus, dan "Buat PO" yang membawa harga pemenang tanpa
   pengetikan ulang. Daftar/form-nya tetap generik lewat RESOURCES
   ['procurement/rfqs'] (customDetail: 'rfq'). */

import { api, session } from '../api.js';
import { el, clear, button, badge, errorState, toast, toastError, withBusy, confirmDialog } from '../ui.js';
import * as fmt from '../format.js';
import { preload, labelFor } from '../lookup.js';
import { RESOURCES } from '../schema.js';
import { openForm, promptFields } from './form.js';
import { formButtons } from './detail.js';
import { printButtonsFor } from '../printcatalog.js';
import { navigate } from '../router.js';

const IS_DRAFT = (rfq) => rfq.status === 'draft';

export async function renderRfq(host, { id }) {
  /* Kerangka dipasang SEBELUM menunggu — kerangka yang sama, di tempat yang
     sama, seperti renderDetail generik dan kesembilan layar custom lain
     (loadOrFail di custom.js, renderProject di project.js).

     clear(host) di baris ini membuang kerangka yang dititipkan app.js sebelum
     memanggil layar custom. Tanpa penggantinya, tautan-dalam DINGIN ke sebuah
     RFQ — hash ditempel di bilah alamat, katalog cetak belum ter-cache —
     mengosongkan #view lalu menunggu fetch di bawah dengan layar PUTIH:
     lubang yang sama persis yang urutan app.js dipasang untuk menutup, hanya
     dibuka kembali satu baris setelahnya. */
  clear(host).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '18px', width: '40%' } }))));

  let rfq;
  try {
    [rfq] = await Promise.all([
      api.get(`procurement/rfqs/${id}`),
      preload(['projects', 'purchaseRequisitions']),
    ]);
  } catch (error) {
    // Kerangka dibuang lebih dulu: panel error DI BAWAH kerangka terbaca
    // seolah layarnya masih memuat sesuatu di atas pesan kegagalannya.
    clear(host);
    return host.appendChild(errorState(error, () => renderRfq(host, { id })));
  }

  // P2 — penilaian berbobot dilayani endpoint terpisah (RfqResource sengaja
  // tidak memuatnya). Gagal memuatnya tidak boleh menjatuhkan seluruh layar:
  // matriks harga tetap berguna tanpa kartu penilaian.
  let evaluations = [];
  try {
    evaluations = (await api.get(`procurement/rfqs/${id}/evaluations`)) || [];
  } catch (error) {
    evaluations = [];
  }

  const reload = () => renderRfq(host, { id });
  const def = RESOURCES['procurement/rfqs'];
  const editable = IS_DRAFT(rfq) && session.can('prc.update');
  const vendors = rfq.vendors || [];
  const items = rfq.items || [];

  // Kerangka di atas dibuang di sini, satu tick sebelum isinya digambar —
  // bukan sebelum fetch-nya, yang akan mengembalikan halaman putih itu.
  clear(host);

  /* ------------------------------------------------------------- kepala */

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', [rfq.code, ' ', badge(rfq.status_label || rfq.status, rfq.status === 'draft' ? 'warn' : '')]),
      el('.desc', {
        text: [
          `Tanggal ${fmt.date(rfq.rfq_date)}`,
          rfq.due_date ? `batas masuk penawaran ${fmt.date(rfq.due_date)}` : null,
          rfq.project_id ? `proyek ${labelFor('projects', rfq.project_id)}` : null,
        ].filter(Boolean).join(' · '),
      }),
    ]),
    el('.actions', [
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload }),
      // Tabulasi banding penawaran dalam format formulir rumah — lanskap, satu
      // kolom per vendor. Katalognya sudah dimuat app.js sebelum layar ini
      // digambar, jadi panggilan ini sinkron.
      ...formButtons(printButtonsFor(def || {}, 'procurement/rfqs'), rfq),
      editable && def
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'procurement/rfqs', row: rfq, onSaved: reload }) })
        : null,
      editable ? button('Tutup RFQ', {
        onClick: async (event) => {
          const ok = await confirmDialog({
            title: 'Tutup RFQ',
            message: 'Tutup lembar banding ini? Harga dan pemenangnya membeku, tidak bisa diubah lagi.',
            confirmLabel: 'Tutup RFQ',
          });
          if (!ok) return;
          await withBusy(event.currentTarget, async () => {
            try {
              await api.post(`procurement/rfqs/${rfq.id}/close`, {});
              toast('RFQ ditutup.');
              reload();
            } catch (error) { toastError(error); }
          });
        },
      }) : null,
      IS_DRAFT(rfq) && session.can('prc.create')
        ? button('Buat PO dari RFQ', { variant: 'primary', onClick: (event) => createPo(rfq, event.currentTarget, reload) })
        : null,
    ]),
  ]));

  if (rfq.purchase_requisition_id) {
    host.appendChild(el('.alert.info', [
      'Baris lembar ini disalin dari PR ',
      el('a', {
        text: '#'.concat(rfq.purchase_requisition_id),
        href: '#',
        onClick: (event) => { event.preventDefault(); navigate(`d/procurement/purchase-requisitions/${rfq.purchase_requisition_id}`); },
      }),
      ' — kuantitas mengikuti kebutuhan yang disetujui di sana.',
    ]));
  }

  /* -------------------------------------------------------- matriks harga */

  if (!vendors.length || !items.length) {
    host.appendChild(el('.alert.warn',
      'Lembar banding butuh baris barang dan minimal satu vendor terundang — lengkapi lewat tombol Ubah.'));
    return;
  }

  // Sel input per (baris, vendor): dibaca tombol "Simpan harga" sekaligus.
  const cellInputs = new Map(); // key `${itemId}:${vendorId}` -> input

  const quoteOf = (line, vendorId) => (line.quotes || []).find((quote) => Number(quote.vendor_id) === Number(vendorId));

  const header = el('tr', [
    el('th', { text: '#' }),
    el('th', { text: 'Uraian' }),
    el('th.right', { text: 'Qty' }),
    ...vendors.map((invited) => el('th', [
      el('div', { text: invited.name || `#${invited.vendor_id}` }),
      editable ? button('Menangkan semua', {
        size: 'sm',
        title: 'Jadikan vendor ini pemenang seluruh baris (harus sudah menawar semua baris).',
        onClick: (event) => chooseWinner(rfq, { vendor_id: invited.vendor_id }, event.currentTarget, reload),
      }) : null,
    ])),
  ]);

  const rows = items.map((line) => el('tr', [
    el('td', { text: String(line.line_no) }),
    el('td', [
      el('div', { text: line.description }),
      line.boq_item_id ? el('.muted', { text: `BOQ #${line.boq_item_id}`, style: { fontSize: '11px' } }) : null,
    ]),
    el('td.right', { text: fmt.qty(line.qty, line.unit) }),
    ...vendors.map((invited) => {
      const quote = quoteOf(line, invited.vendor_id);
      const cell = el('td.right');

      if (editable) {
        const input = el('input', {
          type: 'number', min: '0', step: '0.01',
          value: quote ? String(Number(quote.unit_price)) : '',
          placeholder: '—',
          style: { width: '110px', textAlign: 'right' },
        });
        cellInputs.set(`${line.id}:${invited.vendor_id}`, input);
        cell.appendChild(input);
      } else {
        cell.appendChild(el('div', { text: quote ? fmt.rupiah(quote.unit_price) : '—' }));
      }

      if (quote && quote.is_winner) {
        cell.appendChild(el('div', badge('Pemenang', 'ok')));
      } else if (editable && quote) {
        cell.appendChild(el('div', button('Menang', {
          size: 'sm',
          title: 'Jadikan penawaran ini pemenang baris ini.',
          onClick: (event) => chooseWinner(rfq, { vendor_id: invited.vendor_id, rfq_item_id: line.id }, event.currentTarget, reload),
        })));
      }

      return cell;
    }),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Tabulasi penawaran' }),
      editable ? button('Simpan harga', {
        variant: 'primary',
        onClick: (event) => saveQuotes(rfq, cellInputs, event.currentTarget, reload),
      }) : null,
    ]),
    el('.table-wrap', el('table.data', [el('thead', header), el('tbody', rows)])),
    el('.muted', {
      style: { padding: '8px 12px', fontSize: '12px' },
      text: 'Ketik harga satuan yang diterima dari tiap vendor lalu "Simpan harga". Tombol "Menang" memilih '
        + 'pemenang per baris; "Menangkan semua" memilih satu vendor untuk seluruh lembar. Pemenang bukan '
        + 'otomatis yang termurah — termurah yang tidak sanggup kirim bukan pemenang.',
    }),
  ]));

  /* --------------------------------------------- penilaian berbobot (P2) */

  host.appendChild(scoringCard(rfq, evaluations, editable, reload));

  /* ------------------------------------------------------- PO turunannya */

  const orders = rfq.purchase_orders || [];
  if (orders.length) {
    host.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'PO dari lembar ini' })),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [el('th', { text: 'PO' }), el('th', { text: 'Vendor' })])),
        el('tbody', orders.map((po) => el('tr', [
          el('td', el('a', {
            text: po.code, href: '#',
            onClick: (event) => { event.preventDefault(); navigate(`d/procurement/purchase-orders/${po.id}`); },
          })),
          el('td', { text: (vendors.find((invited) => Number(invited.vendor_id) === Number(po.vendor_id)) || {}).name || `#${po.vendor_id}` }),
        ]))),
      ])),
    ]));
  }
}

/* Kirim seluruh sel yang terisi. Sel kosong tidak dikirim: vendor yang tidak
   menawar sebuah baris memang tidak punya harga, bukan berharga nol. */
async function saveQuotes(rfq, cellInputs, trigger, reload) {
  const quotes = [];

  for (const [key, input] of cellInputs) {
    if (input.value === '') continue;
    const [itemId, vendorId] = key.split(':');
    quotes.push({
      rfq_item_id: Number(itemId),
      vendor_id: Number(vendorId),
      unit_price: Number(input.value),
    });
  }

  if (!quotes.length) {
    toast('Belum ada harga yang diketik.', { tone: 'err' });
    return;
  }

  await withBusy(trigger, async () => {
    try {
      await api.post(`procurement/rfqs/${rfq.id}/quotes`, { quotes });
      toast('Penawaran tercatat.');
      reload();
    } catch (error) { toastError(error); }
  });
}

/* Empat aspek yang DIKETIK panitia (0–100). Skor harga TIDAK di sini — server
   menghitungnya dari rasio penawaran terhadap RAB (BidEvaluationService). */
const SCORE_ASPECTS = [
  ['mutu_score', 'Mutu'],
  ['waktu_score', 'Waktu'],
  ['keuangan_score', 'Keuangan'],
  ['k3_score', 'K3'],
];

/* Tabulasi penilaian berbobot: satu baris per vendor terundang. Kolom skor bisa
   diketik saat draf; harga, nilai akhir dan peringkat dihitung server dan
   ditampilkan apa adanya. Vendor tanpa skor apa pun sengaja tidak dikirim,
   sehingga tabulasi cetak membiarkannya bergaris ("belum dinilai"), bukan nol. */
function scoringCard(rfq, evaluations, editable, reload) {
  const vendors = rfq.vendors || [];
  const byVendor = new Map((evaluations || []).map((row) => [Number(row.vendor_id), row]));
  const inputs = new Map(); // `${vendorId}:${field}` -> input

  const show = (value, decimals = 2) => (value === null || value === undefined || value === '' ? '—' : fmt.num(value, decimals));

  const scoreCell = (vid, field, value, max) => {
    if (!editable) return el('td.right', { text: show(value) });
    const input = el('input', {
      type: 'number', min: '0', max: String(max), step: '0.01',
      value: (value === null || value === undefined) ? '' : String(Number(value)),
      placeholder: '—', style: { width: '84px', textAlign: 'right' },
    });
    inputs.set(`${vid}:${field}`, input);
    return el('td.right', input);
  };

  const rabCell = (vid, value) => {
    if (!editable) return el('td.right', { text: value === null || value === undefined || value === '' ? '—' : fmt.rupiah(value) });
    const input = el('input', {
      type: 'number', min: '0', step: '0.01',
      value: (value === null || value === undefined) ? '' : String(Number(value)),
      placeholder: 'auto BOQ', style: { width: '120px', textAlign: 'right' },
    });
    inputs.set(`${vid}:rab_amount`, input);
    return el('td.right', input);
  };

  const header = el('tr', [
    el('th', { text: 'Vendor' }),
    el('th.right', { text: 'RAB (HPS)' }),
    ...SCORE_ASPECTS.map(([, label]) => el('th.right', { text: label })),
    el('th.right', { text: 'Harga*' }),
    el('th.right', { text: 'Nilai akhir' }),
    el('th.right', { text: 'Peringkat' }),
  ]);

  const rows = vendors.map((invited) => {
    const vid = Number(invited.vendor_id);
    const ev = byVendor.get(vid) || {};

    return el('tr', [
      el('td', { text: invited.name || `#${vid}` }),
      rabCell(vid, ev.rab_amount),
      ...SCORE_ASPECTS.map(([field]) => scoreCell(vid, field, ev[field], 100)),
      el('td.right', { text: show(ev.harga_score) }),
      el('td.right', ev.weighted_score === undefined || ev.weighted_score === null
        ? el('span', { text: '—' })
        : el('strong', { text: show(ev.weighted_score) })),
      el('td.right', ev.rank ? badge(`#${ev.rank}`, ev.rank === 1 ? 'ok' : '') : el('span', { text: '—' })),
    ]);
  });

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Penilaian berbobot (sistem nilai)' }),
      editable ? button('Simpan penilaian', {
        variant: 'primary',
        onClick: (event) => saveEvaluations(rfq, inputs, event.currentTarget, reload),
      }) : null,
    ]),
    el('.table-wrap', el('table.data', [el('thead', header), el('tbody', rows)])),
    el('.muted', {
      style: { padding: '8px 12px', fontSize: '12px' },
      text: 'Skor mutu/waktu/keuangan/K3 diisi 0–100 oleh panitia; RAB kosong memakai harga BOQ '
        + 'baris. *Skor harga dihitung server dari rasio penawaran terhadap RAB — tidak diketik. '
        + 'Nilai akhir dan peringkat dihitung ulang atas seluruh vendor tiap kali disimpan. Vendor '
        + 'yang tidak diberi skor apa pun dibiarkan belum dinilai (bergaris pada tabulasi cetak), '
        + 'bukan diberi nol.',
    }),
  ]);
}

/* Satu baris per vendor yang diberi MINIMAL satu skor aspek. Vendor tanpa satu
   pun angka tidak dikirim — supaya "belum dinilai" tetap belum dinilai, bukan
   nol yang menyusup ke peringkat. */
async function saveEvaluations(rfq, inputs, trigger, reload) {
  const byVendor = new Map();

  for (const [key, input] of inputs) {
    if (input.value === '') continue;
    const [vendorId, field] = key.split(':');
    const vid = Number(vendorId);
    if (!byVendor.has(vid)) byVendor.set(vid, { vendor_id: vid });
    byVendor.get(vid)[field] = Number(input.value);
  }

  const evaluations = [...byVendor.values()].filter((row) =>
    ['mutu_score', 'waktu_score', 'keuangan_score', 'k3_score'].some((field) => row[field] !== undefined));

  if (!evaluations.length) {
    toast('Isi minimal satu skor aspek (mutu/waktu/keuangan/K3) untuk satu vendor.', { tone: 'err' });
    return;
  }

  await withBusy(trigger, async () => {
    try {
      await api.post(`procurement/rfqs/${rfq.id}/evaluations`, { evaluations });
      toast('Penilaian berbobot tersimpan.');
      reload();
    } catch (error) { toastError(error); }
  });
}

async function chooseWinner(rfq, payload, trigger, reload) {
  await withBusy(trigger, async () => {
    try {
      await api.post(`procurement/rfqs/${rfq.id}/choose-winner`, payload);
      toast('Pemenang tercatat.');
      reload();
    } catch (error) { toastError(error); }
  });
}

/* "Buat PO dari RFQ": satu PO per vendor pemenang. Bila pemenang terbelah ke
   beberapa vendor, staf memilih vendor mana yang dibuatkan PO putaran ini. */
async function createPo(rfq, trigger, reload) {
  const winners = [];
  (rfq.items || []).forEach((line) => (line.quotes || []).forEach((quote) => {
    if (quote.is_winner && !winners.includes(Number(quote.vendor_id))) winners.push(Number(quote.vendor_id));
  }));

  if (!winners.length) {
    toast('Pilih pemenang dulu sebelum membuat PO.', { tone: 'err' });
    return;
  }

  const vendorName = (vendorId) => ((rfq.vendors || []).find((invited) => Number(invited.vendor_id) === vendorId) || {}).name || `#${vendorId}`;

  let vendorId = winners[0];
  if (winners.length > 1) {
    const values = await promptFields('Buat PO dari RFQ', [{
      key: 'vendor_id', label: 'Vendor pemenang', type: 'select', required: true,
      options: winners.map((winner) => ({ value: winner, label: vendorName(winner) })),
      help: 'Pemenang terbelah ke beberapa vendor — satu PO per vendor; ulangi untuk vendor berikutnya.',
    }], { submitLabel: 'Buat PO' });
    if (values === null) return;
    vendorId = Number(values.vendor_id);
  } else {
    const ok = await confirmDialog({
      title: 'Buat PO dari RFQ',
      message: `Buat PO draf untuk ${vendorName(vendorId)} berisi baris kemenangannya pada harga penawaran pemenang?`,
      confirmLabel: 'Buat PO',
    });
    if (!ok) return;
  }

  await withBusy(trigger, async () => {
    try {
      const po = await api.post(`procurement/rfqs/${rfq.id}/create-po`, { vendor_id: vendorId });
      toast(`PO ${po.code} dibuat dari harga pemenang.`);
      if (po && po.id) navigate(`d/procurement/purchase-orders/${po.id}`);
      else reload();
    } catch (error) { toastError(error); }
  });
}
