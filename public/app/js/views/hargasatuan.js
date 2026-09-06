/* Riwayat harga satuan — tren harga beli satu item (Temuan 17).

   inv_items hanya menyimpan satu avg_cost dan satu last_price, dan harga AHSP
   adalah satu angka cache yang tertimpa tiap kali dianalisa ulang. Padahal
   harga tiap pembelian selalu ada: kesepakatannya di baris PO, valuasi
   barangnya di GRN. Estimator yang menyusun RAB besi beton tidak pernah bisa
   melihat bahwa tiga PO terakhir membayar 12.500 → 13.100 → 13.750 per kg —
   di pasar material yang bergerak, itu selisih margin yang nyata.

   Layar ini hanya membaca endpoint estimation/price-history. DUA SUMBER TIDAK
   PERNAH DILEBUR: harga PO adalah yang disepakati, unit cost GRN adalah nilai
   barang yang benar-benar datang (ongkos angkut, kiriman parsial) — keduanya
   digambar pada satu garis waktu tetapi tetap bisa dibedakan, karena selisih
   di antara keduanya justru yang perlu dilihat pembeli.

   Harga yang sudah tersimpan di BOQ TIDAK ikut bergerak oleh apa pun di sini:
   baris BOQ membeku saat ditambahkan dan BOQ terkunci saat disetujui — layar
   ini alat menyusun harga BERIKUTNYA, bukan tuas mengubah harga kemarin. */

import { api, session } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor } from '../lookup.js';
import { lineChart } from '../charts.js';

const state = { itemId: null, from: '', to: '' };

const SOURCE = {
  po: ['Harga PO', ''],
  grn: ['Valuasi GRN', 'amber'],
};

function statCard(label, value, hint, { title } = {}) {
  return el('.stat', [
    el('.label', { text: label }),
    el('.value.sm', { text: value, title: title || null }),
    hint ? el('.delta', { text: hint }) : null,
  ]);
}

/* ------------------------------------------------------------------ grafik */

/** Garis waktu harga. Titik PO dan GRN dibedakan warna; judul tiap titik
    menyebut dokumen dan vendornya, karena satu angka tanpa dokumen tidak bisa
    diperiksa siapa pun. */
/**
 * Tren harga satuan — `charts.js lineChart` sejak P1-E.
 *
 * Dua sifat grafik ini yang TIDAK boleh hilang dalam migrasi, dan keduanya
 * sudah punya tempatnya di API charts.js:
 *
 *  1. **Sumbu harga tidak dipaksa mulai dari nol.** Tren 12.500 → 13.750 pada
 *     sumbu 0..14.000 tampak datar, padahal 10 % itulah yang dicari layar ini.
 *     `yMin`/`yMax` dihitung di sini persis seperti sebelumnya (ruang 25 %
 *     di atas dan di bawah rentang data; harga yang seluruhnya sama memakai
 *     5 % dari harganya sendiri sebagai ruang, supaya garis datar tidak
 *     menempel di tepi plot).
 *  2. **PO dan GRN pada SATU garis kronologis, dibedakan per TITIK.** Harga
 *     yang disepakati dan harga yang benar-benar datang adalah cerita yang
 *     sama secara waktu; memecahnya jadi dua seri akan memutus garisnya.
 *     `points[].token` mewarnai titik GRN sendiri — dulu `dot.style.fill =
 *     'var(--warning)'`, satu-satunya warna literal yang tersisa di layar ini.
 */
function trendChart(series) {
  const points = series
    .map((row) => ({ ...row, at: Date.parse(row.date), price: Number(row.unit_price) || 0 }))
    .filter((row) => Number.isFinite(row.at));
  if (!points.length) return null;

  const prices = points.map((point) => point.price);
  const lo = Math.min(...prices);
  const hi = Math.max(...prices);
  const room = (hi - lo) || Math.max(hi * 0.05, 1);
  const yLo = Math.max(0, lo - room * 0.25);
  const yHi = hi + room * 0.25;

  return lineChart({
    series: [{
      label: 'Harga satuan',
      token: '--chart-1',
      points: points.map((point) => ({
        x: point.date,
        y: point.price,
        // Titik GRN sedikit lebih besar DAN berwarna sendiri: pembedanya tidak
        // pernah warna saja (aturan yang sama dengan legenda departemen).
        r: point.source === 'grn' ? 3.5 : 3,
        token: point.source === 'grn' ? '--chart-7' : null,
        title: `${fmt.date(point.date)} — ${point.code}`
          + `${point.vendor_name ? ` (${point.vendor_name})` : ''}: ${fmt.rupiah(point.price)}`,
      })),
    }],
    yMin: yLo,
    yMax: yHi,
    /* Lima garis kisi, seperti grafik tangan (i = 0..4) — dan itu benar HANYA
       karena charts.js menjangkarkan tick pada `lo` ketika KEDUA tepi sumbu
       dipaksa. Sampai verifikasi P1-E ia menjangkarkannya pada kelipatan
       langkah, jadi kalimat ini salah untuk hampir setiap riwayat harga
       sungguhan: terukur atas 205 deret harga, 5 garis pada 21,5 % kasus dan
       161 sumbu tanpa label lantai maupun langit-langit. Item demo lolos hanya
       karena kedua harganya sama. */
    yStep: (yHi - yLo) / 4,
    yFormat: (value) => fmt.rupiahShort(value),
    ariaLabel: 'Tren harga satuan',
    // Satu seri: legenda svg akan menuliskan "Harga satuan" saja dan tidak
    // menjelaskan apa pun. Yang perlu dijelaskan adalah PO vs GRN, dan itu
    // pembedaan per titik — legendanya tetap di DOM, di bawah grafik.
    legend: false,
  });
}

/* ------------------------------------------------------------------- layar */

export async function renderHargaSatuan(host) {
  clear(host);

  if (!session.can('est.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki hak akses est.view untuk riwayat harga satuan.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Riwayat Harga Satuan' }),
      el('.desc', {
        text: 'Harga beli aktual satu item dari PO yang disetujui dan valuasi penerimaan gudang (GRN), '
          + 'digambar sebagai satu garis waktu — pembanding sebelum harga AHSP atau RAB berikutnya ditulis.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() })]),
  ]));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const body = el('div');
  host.append(controls, body);

  /* Pemilih item memakai lookup inventori. Bila daftar itu tidak terbaca
     (403 inv.view, jaringan), layarnya tetap berdiri dan mengatakan kenapa —
     bukan dropdown kosong yang terbaca "tidak ada item di sistem ini". */
  const itemRows = await loadSource('items').catch(() => []);
  const options = optionsFor('items', itemRows);

  const itemSelect = el('select.filter-w', {
    'aria-label': 'Item',
    onchange: () => {
      state.itemId = itemSelect.value ? Number(itemSelect.value) : null;
      load();
    },
  });
  options.forEach((option) => itemSelect.appendChild(el('option', { value: option.value, text: option.label })));
  if (!options.some((option) => String(option.value) === String(state.itemId))) {
    state.itemId = options.length ? Number(options[0].value) : null;
  }
  itemSelect.value = state.itemId === null ? '' : String(state.itemId);

  const fromInput = el('input.filter-w', {
    type: 'date',
    value: state.from,
    'aria-label': 'Dari tanggal',
    onchange: () => { state.from = fromInput.value; load(); },
  });
  const toInput = el('input.filter-w', {
    type: 'date',
    value: state.to,
    'aria-label': 'Sampai tanggal',
    onchange: () => { state.to = toInput.value; load(); },
  });

  controls.append(
    itemSelect,
    fromInput,
    toInput,
    el('span.cell-sub', { text: 'kosong = seluruh riwayat' }),
    el('.spacer'),
    button('Muat ulang', { size: 'sm', variant: 'ghost', iconName: 'refresh', onClick: () => load() }),
  );

  function paint(report) {
    clear(body);

    const item = report.item;
    const summary = report.summary || {};
    const series = report.series || [];

    body.appendChild(el('.stat-row', [
      statCard('Harga terakhir',
        summary.latest_price === null || summary.latest_price === undefined ? '—' : fmt.rupiah(summary.latest_price),
        summary.latest_date ? `per ${fmt.date(summary.latest_date)}` : 'belum ada pembelian'),
      statCard('Terendah — tertinggi',
        summary.count ? `${fmt.rupiahShort(summary.min_price)} — ${fmt.rupiahShort(summary.max_price)}` : '—',
        `${summary.count || 0} titik harga`,
        { title: summary.count ? `${fmt.rupiah(summary.min_price)} — ${fmt.rupiah(summary.max_price)}` : null }),
      statCard('Rata-rata tertimbang',
        summary.weighted_avg_price === null || summary.weighted_avg_price === undefined ? '—' : fmt.rupiah(summary.weighted_avg_price),
        'ditimbang volume tiap pembelian'),
      statCard('Cache item master',
        item ? fmt.rupiah(item.avg_cost) : '—',
        item ? `avg cost · last price ${fmt.rupiah(item.last_price)}` : 'item tidak ditemukan'),
    ]));

    if (report.truncated) {
      body.appendChild(el('.alert.info',
        'Riwayat item ini lebih panjang dari yang ditampilkan; hanya pembelian termutakhir yang '
        + 'digambar. Persempit rentang tanggal untuk membaca periode yang lebih lama.'));
    }

    if (!series.length) {
      body.appendChild(el('.alert.info',
        'Belum ada pembelian tercatat untuk item ini pada rentang tanggal terpilih — belum ada PO '
        + 'disetujui dan belum ada penerimaan gudang terposting. Harga di layar lain (AHSP, BOQ) '
        + 'berarti masih berdiri di atas taksiran, bukan riwayat.'));
      return;
    }

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: item ? `Tren harga — ${item.name}` : 'Tren harga' }),
        el('.spacer'),
        el('.cell-sub', { text: item ? `${item.code} · satuan ${item.unit}` : '' }),
      ]),
      el('.card-body', [
        trendChart(series) || el('p.muted', { text: 'Belum ada titik harga yang bisa digambar.', style: { margin: 0 } }),
        /* Swatch memakai token GRAFIK yang sama dengan titiknya (P1-E) — dulu
           `--warning`, yang tidak pernah dipakai grafik itu sendiri di kertas:
           blok cetak app.css hanya menukar token --chart-*, jadi legenda
           berwarna --warning tercetak berbeda dari titik yang diwakilinya. */
        el('.legend', [
          el('span', [el('i', { style: { background: 'var(--chart-1)' } }), 'Harga PO (disepakati)']),
          el('span', [el('i', { style: { background: 'var(--chart-7)' } }), 'Valuasi GRN (barang datang)']),
        ]),
      ]),
    ]));

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: 'Rincian per dokumen' }),
        el('.cell-sub', { text: 'urut tanggal, terlama di atas' }),
      ]),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Tanggal' }),
          el('th', { text: 'Dokumen' }),
          el('th', { text: 'Sumber' }),
          el('th', { text: 'Vendor' }),
          el('th.right', { text: 'Qty' }),
          el('th.right', { text: 'Harga satuan' }),
        ])),
        el('tbody', series.map((row) => {
          const [label, tone] = SOURCE[row.source] || [row.source, ''];
          return el('tr', [
            el('td', { text: fmt.date(row.date) }),
            el('td', el('span.cell-main.mono', { text: row.code })),
            el('td', badge(label, tone)),
            el('td', { text: row.vendor_name || '—' }),
            el('td.right.num', { text: fmt.qty(row.qty, row.unit || (item ? item.unit : '')) }),
            el('td.right.num', { text: fmt.rupiah(row.unit_price) }),
          ]);
        })),
      ])),
    ]));

    body.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Cara membacanya' })),
      el('.card-body', [
        el('p', { text: 'Harga PO adalah harga yang disepakati dengan vendor — hanya PO berstatus disetujui atau selesai yang dihitung; draf dan yang ditolak bukan riwayat. Valuasi GRN adalah nilai barang saat benar-benar diterima gudang, dan boleh berbeda dari PO-nya (ongkos, kiriman parsial). Selisih keduanya justru informasi, bukan galat.' }),
        el('p', { text: 'Rata-rata tertimbang menimbang tiap harga dengan volume pembeliannya: pesanan uji 100 kg tidak boleh menarik angka sejauh pesanan 2.000 ton, karena angka ini dipakai mengutip volume proyek berikutnya.' }),
        el('p', { text: 'Harga yang sudah tersimpan di BOQ tidak ikut berubah oleh data di sini — baris BOQ membeku saat ditambahkan dan terkunci saat disetujui. Memakai harga baru berarti menganalisa ulang AHSP lalu menulis BOQ versi berikutnya.' }),
      ]),
    ]));
  }

  async function load() {
    clear(body);

    if (state.itemId === null) {
      body.appendChild(el('.alert.warn',
        'Daftar item tidak terbaca atau belum ada item. Riwayat harga membutuhkan daftar item '
        + 'inventori (hak akses inv.view) untuk memilih itemnya.'));
      return;
    }

    body.appendChild(skeletonTable(6, 5));

    let report;
    try {
      report = await api.get('estimation/price-history', {
        item_id: state.itemId,
        date_from: state.from || undefined,
        date_to: state.to || undefined,
      });
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
      return;
    }

    if (!report) {
      clear(body).appendChild(el('.alert.warn',
        'Server tidak mengirimkan isi riwayat untuk item ini. Coba muat ulang.'));
      return;
    }

    paint(report);
  }

  await load();
}
