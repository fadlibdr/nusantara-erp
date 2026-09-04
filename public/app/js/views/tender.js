/* P7 — layar paket tender: lembar TKDN, RKK penawaran, dan penyusun kualifikasi.

   TIGA LAYAR DI SATU BERKAS karena ketiganya melayani satu berkas lelang dan
   memakai aturan kejujuran yang sama; memecahnya menjadi tiga modul akan
   menyalin aturan itu tiga kali.

   MENGAPA TKDN DAN RKK TIDAK MEMAKAI LAYAR DETAIL GENERIK. Keduanya menyusun
   baris yang DIMILIKI orang lain — baris penawaran sendiri, baris register
   risiko proyek (Projects), baris RAB (Estimation). Kisi `lines` generik hanya
   bisa menawarkan kotak id mentah untuk masing-masing, dan sebuah kotak id
   mentah pada dokumen penawaran berjarak satu salah ketik dari mengutip
   penilaian bahaya proyek orang lain ke dalam RKK yang kita tandatangani.
   Karena itu keduanya `customDetail` dengan pemilih yang menampilkan baris
   yang sebenarnya.

   ================================ ATURAN KEJUJURAN YANG BERLAKU DI SINI ====

   1. PERSEN TKDN TIDAK PERNAH TAMPIL SENDIRIAN. Di mana pun angka paket
      muncul, cakupannya ("n dari m item dinilai") berdiri di sebelahnya.
      TkdnService sudah menolak menghitung baris yang belum diuraikan biayanya
      sebagai 0% maupun 100%; layar yang menampilkan hasilnya tanpa cakupan
      akan membatalkan kehati-hatian itu di titik terakhir sebelum orang
      membaca angkanya.

   2. BARIS PENAWARAN TANPA URAIAN BIAYA BERKATA "BELUM DINILAI", bukan 0%.
      Selnya bergaris (—), badge-nya kuning, dan nilainya ikut dijumlahkan pada
      "nilai belum dinilai" di kartu rekap.

   3. BARIS TAUTAN YANG SUMBERNYA LENYAP TETAP TAMPIL, berlabel "sumber tidak
      ditemukan". Baris register risiko yang dihapus adalah fakta tentang RKK
      ini; menghilangkannya dari layar membuat RKK terbaca lengkap. Baris biaya
      SMKK yang baris RAB-nya lenyap TIDAK ikut dijumlahkan — 0,00 di sana
      berarti "tidak berbiaya", yang bukan yang kita ketahui.

   4. TIDAK ADA KOTAK RUPIAH PADA PEMILIH SMKK. Nilai biaya SMKK ADALAH nilai
      baris RAB yang ditunjuk; kotak rupiah di sini akan menjadi angka kedua
      untuk uang yang sama, bebas berselisih dengan RAB yang ditandatangani
      bersamanya. Kunci yang dikirim layar ini dipaku SMKK_PAYLOAD_KEYS di
      bawah dan diadu dengan RkkSmkkCostsRequest oleh TenderSpaWiringTest.

   5. SERTIFIKAT KEDALUWARSA TIDAK DICAMPUR DAN TIDAK DIBUANG. Ia berdiri di
      kartunya sendiri dengan tanggal lewatnya, supaya ada yang sempat
      memperpanjangnya sebelum batas pemasukan.

   6. PEMILIH YANG TERPOTONG MENGAKU TERPOTONG. Sebuah daftar yang diam-diam
      menampilkan 200 dari 340 bahaya adalah daftar yang menyembunyikan 140. */

import { api, session } from '../api.js';
import {
  el, clear, button, badge, errorState, skeletonTable,
  toast, toastError, withBusy, modal, icon,
} from '../ui.js';
import * as fmt from '../format.js';
import { enumLabel } from '../enums.js';
import { loadSource, optionsFor, preload, labelFor } from '../lookup.js';
import { RESOURCES } from '../schema.js';
import { openForm, promptFields } from './form.js';
import { formButtons } from './detail.js';
import { printButtonsFor, printFormsFor, loadPrintForms } from '../printcatalog.js';
import { navigate, back } from '../router.js';

/**
 * Kunci yang dikirim layar ini ke rkk-documents/{id}/smkk-costs.
 *
 * TIDAK ADA 'amount' DI SINI dan tidak akan pernah ada — lihat aturan 4 di
 * kepala berkas. Konstanta ini dipakai runtime (payload dirakit darinya), jadi
 * uji yang membacanya membaca perilaku, bukan komentar.
 */
export const SMKK_PAYLOAD_KEYS = ['boq_item_id', 'category', 'sort_order'];

/** Berapa baris yang diambil satu pemilih sekali muat — lihat aturan 6. */
const PICKER_PAGE = 200;

/** Sel bergaris: nilai yang datanya tidak ada, bukan nol. */
const RULED = '—';

const money = (value) => (value === null || value === undefined ? RULED : fmt.rupiah(value, { decimals: 2 }));
const pct = (value) => (value === null || value === undefined ? RULED : `${fmt.num(value, 2)}%`);
const textOr = (value) => (value === null || value === undefined || value === '' ? RULED : String(value));

/* Remah roti "#id" dari router diganti kodenya, seperti detail.js/custom.js;
   Terakhir dibuka (T2.5) membaca judulnya dari sini. */
function fillCrumb(title) {
  const crumb = document.querySelector('#crumbs b');
  if (crumb) crumb.textContent = title;
}

function skeleton(host) {
  return clear(host).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '18px', width: '40%' } }))));
}

/* ======================================================== LEMBAR TKDN ==== */

/**
 * Rekap TKDN — persen paket DAN cakupannya, dalam satu kartu yang tidak bisa
 * dibaca separuh.
 */
function tkdnSummaryCard(summary) {
  const items = summary.items || [];
  // "Dinilai" di sini berarti dinilai PENUH. Baris yang uraian biayanya jauh
  // lebih kecil daripada nilai barisnya tidak boleh ikut menghitung cakupan —
  // itulah cacat yang membuat satu baris Rp 1 memutihkan lembarnya.
  const assessed = items.filter((row) => row.assessment === 'penuh').length;
  const partial = items.filter((row) => row.assessment === 'sebagian').length;
  const total = items.length;
  const coverage = `${assessed} dari ${total} item dinilai`
    + (partial > 0 ? ` (${partial} baru dinilai sebagian)` : '');

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Rekapitulasi TKDN Jasa' }),
      el('.sub', { text: summary.basis || '' }),
    ]),
    el('.card-body', [
      el('dl.kv', [
        el('dt', { text: 'TKDN jasa (paket)' }),
        el('dd', [
          el('strong', { text: pct(summary.tkdn_pct) }),
          // Cakupan menempel pada angkanya, bukan pada baris lain di kartu:
          // sebuah tangkapan layar yang memuat persennya memuat cakupannya.
          el('.cell-sub', { text: `${coverage} · cakupan nilai ${pct(summary.coverage_pct)}` }),
        ]),
        el('dt', { text: 'Biaya komponen dalam negeri' }),
        el('dd', { text: money(summary.cost_domestic) }),
        el('dt', { text: 'Biaya komponen luar negeri' }),
        el('dd', { text: money(summary.cost_foreign) }),
        el('dt', { text: 'Nilai penawaran belum dinilai' }),
        el('dd', [
          el('span', { text: money(summary.unassessed_value) }),
          summary.unassessed_value > 0
            ? el('.cell-sub', {
              style: { color: 'var(--warning)' },
              text: 'Baris penawaran ini belum diuraikan biayanya. Nilainya TIDAK dihitung 0% dan '
                + 'tidak dihitung 100% — ia tidak masuk pembilang maupun penyebut persen di atas.',
            })
            : null,
        ]),
        // Ember ketiga berdiri sendiri. Menggabungkannya ke "belum dinilai"
        // akan menghapus fakta bahwa sebagian biayanya SUDAH diuraikan;
        // menggabungkannya ke cakupan mengembalikan cacat Rp 1 itu sendiri.
        summary.partially_assessed_value > 0 ? el('dt', { text: 'Nilai penawaran baru dinilai sebagian' }) : null,
        summary.partially_assessed_value > 0
          ? el('dd', [
            el('span', { text: money(summary.partially_assessed_value) }),
            el('.cell-sub', {
              style: { color: 'var(--warning)' },
              text: 'Uraian biaya baris ini belum mencapai '
                + `${pct(summary.min_cost_to_value_pct)} dari nilai barisnya sendiri `
                + `(baru ${pct(summary.cost_to_value_pct)} atas baris yang sudah diuraikan). `
                + 'Biayanya tetap dihitung pada persen di atas; NILAI barisnya tidak masuk cakupan.',
            }),
          ])
          : null,
      ]),
      summary.fully_assessed
        ? null
        : el('.alert.warn', [
          icon('warn', 15),
          el('div', {
            text: 'Lembar ini belum menilai seluruh baris penawaran. Persen di atas berlaku untuk '
              + `${coverage}; mencantumkannya pada dokumen penawaran tanpa kalimat cakupan ini `
              + 'adalah klaim yang lebih luas daripada yang diperiksa.',
          }),
        ]),
      // Ambangnya diumumkan di layar yang memakainya, bukan hanya di config:
      // pembaca yang melihat "DINILAI SEBAGIAN" berhak tahu sebagian dari apa,
      // dan bahwa angkanya bukan angka Permen.
      summary.basis_cakupan
        ? el('.cell-sub', { style: { marginTop: '8px' }, text: summary.basis_cakupan })
        : null,
    ]),
  ]);
}

/** Satu baris penawaran, dengan komponen biayanya di bawahnya. */
function tkdnItemRows(summary, worksheet, editable, onEdit, onRemove) {
  const byQuotationItem = new Map();
  for (const row of worksheet.items || []) {
    if (!byQuotationItem.has(row.quotation_item_id)) byQuotationItem.set(row.quotation_item_id, []);
    byQuotationItem.get(row.quotation_item_id).push(row);
  }

  const nodes = [];

  for (const item of summary.items || []) {
    nodes.push(el('tr', { style: { background: 'var(--surface-2)' } }, [
      el('td', { colspan: '4' }, [
        el('strong', { text: textOr(item.description) }),
        el('.cell-sub', { text: `Baris penawaran #${item.quotation_item_id} · ${money(item.amount)}` }),
      ]),
      el('td.right.num', [
        // Baris "sebagian" TETAP mencetak persennya: aritmetika barisnya sah,
        // yang belum lengkap adalah uraiannya. Rasio biaya-terhadap-nilai
        // berdiri di bawahnya supaya persen itu tidak pernah dibaca sendirian.
        el('span', { text: item.assessed ? pct(item.tkdn_pct) : RULED }),
        item.assessment === 'sebagian'
          ? el('.cell-sub', {
            style: { color: 'var(--warning)' },
            text: `biaya ${pct(item.cost_to_value_pct)} dari nilai baris`,
          })
          : null,
      ]),
      el('td', [
        // Kata-katanya, bukan sel kosong: sebuah baris kosong terbaca sebagai
        // baris bernilai nol persen, yang tidak pernah ada yang menyatakannya.
        // Dan "Dinilai" polos pada baris ber-Rp 1 adalah kebohongan yang sama
        // dengan cara yang lebih halus — karena itu tiga kata, bukan dua.
        item.assessment === 'penuh'
          ? badge('Dinilai', 'ok')
          : (item.assessment === 'sebagian'
            ? badge('DINILAI SEBAGIAN', 'warn')
            : badge('BELUM DINILAI', 'warn')),
      ]),
    ]));

    const rows = byQuotationItem.get(item.quotation_item_id) || [];

    if (!rows.length) {
      nodes.push(el('tr', el('td', {
        colspan: '6',
        class: 'muted',
        style: { paddingLeft: '26px' },
        text: 'Belum ada komponen biaya pada baris ini.',
      })));
      continue;
    }

    for (const row of rows) {
      nodes.push(el('tr', [
        el('td', { style: { paddingLeft: '26px' }, text: textOr(row.description) }),
        el('td', { text: textOr(row.cost_group_label) }),
        el('td', { text: determinantText(row) }),
        el('td.right.num', { text: money(row.amount) }),
        el('td.right.num', { text: pct(row.domestic_factor_pct) }),
        el('td.right', editable
          ? el('div', { style: { display: 'flex', gap: '4px', justifyContent: 'flex-end' } }, [
            button('', { iconName: 'edit', size: 'sm', title: 'Ubah komponen', onClick: () => onEdit(row) }),
            button('', { iconName: 'trash', size: 'sm', title: 'Hapus komponen', onClick: () => onRemove(row) }),
          ])
          : { text: '' }),
      ]));
    }
  }

  return nodes;
}

/** Kolom penentu KDN baris itu, dieja sesuai kelompoknya (Lampiran IV huruf B). */
function determinantText(row) {
  // textOr di setiap cabang: enumLabel memulangkan nilai MENTAHNYA bila tidak
  // dikenali, dan nilai mentah sebuah kolom kosong adalah null — yang akan
  // menjadi sel benar-benar kosong, bukan sel bergaris.
  if (row.cost_group === 'tenaga_kerja') return textOr(enumLabel('tkdnNationality', row.nationality));
  if (row.cost_group === 'jasa_umum') {
    return row.provider_origin ? `Penyedia ${enumLabel('tkdnOrigin', row.provider_origin)}` : RULED;
  }
  if (row.cost_group === 'alat_kerja') {
    const made = `Dibuat ${textOr(enumLabel('tkdnOrigin', row.made_in))}`;
    const owned = textOr(enumLabel('tkdnOwnership', row.owned_by));
    const share = row.owned_by === 'campuran' && row.domestic_share_pct !== null
      ? ` (${fmt.num(row.domestic_share_pct, 2)}% DN)`
      : '';
    return `${made} · ${owned}${share}`;
  }
  return RULED;
}

/**
 * Dialog komponen biaya — DUA LANGKAH, disengaja.
 *
 * Langkah pertama menanyakan baris penawaran, kelompok biaya, uraian dan
 * biayanya; langkah kedua menanyakan kolom penentu KELOMPOK ITU SAJA. Satu
 * dialog berisi semua kolom penentu akan menawarkan "Kewarganegaraan" pada
 * baris alat kerja, dan sebuah kolom yang tidak berlaku adalah kolom yang
 * cepat atau lambat terisi.
 */
async function componentDialog(worksheet, quotationItems, existing) {
  const lineOptions = quotationItems.map((item) => ({
    value: item.id,
    label: `#${item.line_no} · ${item.description}`,
  }));

  if (!lineOptions.length) {
    toast('Penawaran lembar ini belum punya baris. Isi baris penawarannya dulu.', { tone: 'info' });
    return null;
  }

  const head = await promptFields(existing ? 'Ubah komponen biaya' : 'Tambah komponen biaya', [
    {
      key: 'quotation_item_id',
      label: 'Baris penawaran yang menanggung biaya ini',
      type: 'select',
      options: lineOptions,
      required: true,
      // `default`, bukan `value`: promptFields memanggil buildInput(spec,
      // undefined), jadi nilai awal sebuah dialog HANYA bisa datang lewat
      // spec.default. Memakai `value` di sini akan membuka dialog "Ubah"
      // dalam keadaan kosong dan menyimpannya kembali sebagai baris baru.
      default: existing?.quotation_item_id,
      help: 'Hanya baris penawaran lembar ini. Baris lain ditolak server dengan pesan yang menyebut nomornya.',
    },
    {
      key: 'cost_group',
      label: 'Kelompok biaya',
      type: 'select',
      enum: 'tkdnCostGroup',
      required: true,
      default: existing?.cost_group,
      help: 'Menentukan kolom mana yang menentukan KDN baris ini (Permenperin 35/2025 Lampiran IV huruf B).',
    },
    { key: 'description', label: 'Uraian komponen', type: 'text', required: true, default: existing?.description },
    { key: 'amount', label: 'Biaya komponen', type: 'currency', required: true, default: existing?.amount },
  ], { submitLabel: 'Lanjut' });

  if (!head) return null;

  const determinant = await promptFields(
    `Penentu KDN — ${enumLabel('tkdnCostGroup', head.cost_group)}`,
    determinantFields(head.cost_group, existing),
    { submitLabel: existing ? 'Simpan' : 'Tambah' },
  );

  if (!determinant) return null;

  // <select> memulangkan string; server menerimanya, tetapi id yang dikirim
  // sebagai angka adalah id yang tidak bisa berubah arti di jalan.
  return { ...head, ...determinant, quotation_item_id: Number(head.quotation_item_id) };
}

/** Kolom penentu satu kelompok biaya — tidak lebih, tidak kurang. */
function determinantFields(group, existing) {
  if (group === 'tenaga_kerja') {
    return [{
      key: 'nationality',
      label: 'Kewarganegaraan tenaga kerja',
      type: 'select',
      enum: 'tkdnNationality',
      required: true,
      default: existing?.nationality,
      help: 'Tidak ada bawaan diam-diam: baris tanpa kewarganegaraan ditolak, bukan dianggap WNI.',
    }];
  }

  if (group === 'jasa_umum') {
    return [{
      key: 'provider_origin',
      label: 'Asal penyedia jasa',
      type: 'select',
      enum: 'tkdnOrigin',
      required: true,
      default: existing?.provider_origin,
    }];
  }

  return [
    {
      key: 'made_in',
      label: 'Alat dibuat di',
      type: 'select',
      enum: 'tkdnOrigin',
      required: true,
      default: existing?.made_in,
      help: 'Alat buatan dalam negeri bernilai 100% KDN berapa pun kepemilikannya (tiga baris pertama tabel B.2).',
    },
    {
      key: 'owned_by',
      label: 'Alat dimiliki',
      type: 'select',
      enum: 'tkdnOwnership',
      required: true,
      default: existing?.owned_by,
      help: 'Menentukan jawabannya hanya bila alatnya buatan luar negeri.',
    },
    {
      key: 'domestic_share_pct',
      label: 'Porsi saham dalam negeri (%)',
      type: 'percent',
      default: existing?.domestic_share_pct,
      help: 'Wajib bila kepemilikannya campuran; diabaikan bila tidak.',
    },
  ];
}

export async function renderTkdnWorksheet(host, { id }) {
  skeleton(host);

  let worksheet;
  try {
    worksheet = await api.get(`crm/tkdn-worksheets/${id}`);
  } catch (error) {
    clear(host);
    return host.appendChild(errorState(error, () => renderTkdnWorksheet(host, { id })));
  }

  // Baris penawaran diambil dari penawarannya sendiri: lembar TKDN membawa
  // ringkasan per baris, tetapi pemilih butuh nomor baris dan uraiannya.
  // Gagal memuatnya tidak menjatuhkan layar — rekapnya tetap terbaca; yang
  // hilang hanya kemampuan menambah komponen, dan itu dikatakan.
  let quotationItems = [];
  let quotationError = null;
  if (worksheet.quotation_id) {
    try {
      quotationItems = (await api.get(`crm/quotations/${worksheet.quotation_id}`)).items || [];
    } catch (error) {
      quotationError = error;
    }
  }

  // labelFor('tenderPackages', …) mengembalikan id mentah bila sumbernya belum
  // dimuat, dan '#12' di baris judul terbaca sebagai kerusakan data.
  await preload(['tenderPackages']).catch(() => {});

  const def = RESOURCES['crm/tkdn-worksheets'];
  const summary = worksheet.summary || {};
  const reload = () => renderTkdnWorksheet(host, { id });
  const editable = session.can('crm.update') && !quotationError;

  // withBusy MENUNTUT sebuah tombol (ia membaca node.innerHTML), dan dua dari
  // tiga pemanggil di bawah datang dari tombol baris yang sudah dibuang render
  // ulangnya. Tanpa penjaga ini, "Hapus komponen" melempar TypeError sebelum
  // sempat memanggil apa pun — layar diam, baris tetap ada.
  const put = async (items) => {
    try {
      await api.put(`crm/tkdn-worksheets/${worksheet.id}/items`, { items });
      toast('Rincian komponen TKDN diperbarui.');
      reload();
    } catch (error) { toastError(error); }
  };

  const save = async (items, trigger) => (trigger ? withBusy(trigger, () => put(items)) : put(items));

  const payloadRows = () => (worksheet.items || []).map((row, index) => ({
    quotation_item_id: row.quotation_item_id,
    cost_group: row.cost_group,
    description: row.description,
    amount: row.amount,
    sort_order: index + 1,
    nationality: row.nationality,
    made_in: row.made_in,
    owned_by: row.owned_by,
    domestic_share_pct: row.domestic_share_pct,
    provider_origin: row.provider_origin,
  }));

  clear(host);

  fillCrumb(worksheet.code || `Lembar TKDN #${worksheet.id}`);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: worksheet.code || `Lembar TKDN #${worksheet.id}` }),
      el('.desc', {
        text: [
          worksheet.quotation ? `Penawaran ${worksheet.quotation.code} · ${worksheet.quotation.title}` : null,
          worksheet.tender_package_id ? `Paket tender ${labelFor('tenderPackages', worksheet.tender_package_id)}` : null,
        ].filter(Boolean).join(' · '),
      }),
    ]),
    el('.actions', [
      button('', { iconName: 'back', title: 'Kembali', onClick: () => back() }),
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload }),
      ...formButtons(printButtonsFor(def || {}, 'crm/tkdn-worksheets'), worksheet),
      def && session.can('crm.update')
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'crm/tkdn-worksheets', row: worksheet, onSaved: reload }) })
        : null,
      editable
        ? button('Tambah Komponen', {
          variant: 'primary',
          iconName: 'plus',
          onClick: async (event) => {
            // currentTarget dibaca SEBELUM await pertama: ia hanya terisi
            // selama event masih ter-dispatch, dan sesudah dialognya ditutup
            // ia null — withBusy(null) melempar TypeError sebelum sempat
            // menyimpan apa pun, dan tombolnya terlihat seperti tidak ditekan.
            const trigger = event.currentTarget;
            const created = await componentDialog(worksheet, quotationItems, null);
            if (!created) return;
            await save([...payloadRows(), { ...created, sort_order: (worksheet.items || []).length + 1 }], trigger);
          },
        })
        : null,
    ]),
  ]));

  if (quotationError) {
    host.appendChild(el('.alert.warn', [
      icon('warn', 15),
      el('div', {
        text: 'Baris penawaran tidak dapat dimuat, jadi komponen biaya tidak bisa ditambah atau diubah dari '
          + 'layar ini. Rekap di bawah tetap dihitung dari data yang tersimpan.',
      }),
    ]));
  }

  host.appendChild(tkdnSummaryCard(summary));

  const onEdit = async (row) => {
    const edited = await componentDialog(worksheet, quotationItems, row);
    if (!edited) return;
    const index = (worksheet.items || []).findIndex((candidate) => candidate.id === row.id);
    // -1 hanya mungkin bila layar dan datanya sudah tidak sepaham; menulis
    // items[-1] akan MENAMBAH baris diam-diam sambil membiarkan yang lama.
    if (index < 0) return toast('Baris ini sudah tidak ada. Muat ulang halaman dulu.', { tone: 'info' });
    const items = payloadRows();
    items[index] = { ...edited, sort_order: index + 1 };
    await save(items, null);
  };

  const onRemove = async (row) => {
    const items = payloadRows().filter((_, index) => (worksheet.items || [])[index].id !== row.id);
    await save(items, null);
  };

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Rincian biaya per baris penawaran' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Uraian' }),
        el('th', { text: 'Kelompok biaya' }),
        el('th', { text: 'Penentu KDN' }),
        el('th.right', { text: 'Biaya' }),
        el('th.right', { text: 'KDN' }),
        el('th', { text: '' }),
      ])),
      el('tbody', (summary.items || []).length
        ? tkdnItemRows(summary, worksheet, editable, onEdit, onRemove)
        : el('tr', el('td', { colspan: '6', class: 'muted', text: 'Penawaran lembar ini belum punya baris.' }))),
    ])),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara membaca' })),
    el('.card-body', [
      el('p', { text: 'Persen paket dihitung hanya dari baris penawaran yang sudah diuraikan biayanya. Baris berbadge BELUM DINILAI tidak masuk pembilang maupun penyebutnya — nilainya dilaporkan terpisah sebagai "nilai penawaran belum dinilai".' }),
      el('p', { text: 'Kolom penentu KDN berbeda per kelompok biaya: tenaga kerja dinilai dari kewarganegaraan, alat kerja dari negara pembuat dikali kepemilikan, jasa umum dari asal penyedia. Tidak ada kolom persen yang bisa diketik.' }),
      el('p', { text: 'Sel bergaris (—) berarti datanya tidak ada — bukan nol persen.' }),
    ]),
  ]));
}

/* ================================================== RKK PENAWARAN ======== */

/** Baris IBPRP yang sumbernya lenyap tetap tampil, dan berkata begitu. */
function ibprpTable(rows) {
  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', [
      el('th', { text: 'Uraian pekerjaan' }),
      el('th', { text: 'Identifikasi bahaya' }),
      el('th', { text: 'Dampak' }),
      el('th.right', { text: 'F' }),
      el('th.right', { text: 'A' }),
      el('th.right', { text: 'F×A' }),
      el('th', { text: 'Pengendalian' }),
      el('th.right', { text: 'Risiko sisa' }),
    ])),
    el('tbody', rows.length
      ? rows.map((row) => (row.available
        ? el('tr', [
          el('td', { text: textOr(row.activity) }),
          el('td', { text: textOr(row.hazard) }),
          el('td', { text: textOr(row.impact) }),
          el('td.right.num', { text: textOr(row.likelihood) }),
          el('td.right.num', { text: textOr(row.severity) }),
          el('td.right.num.strong', { text: textOr(row.initial_score) }),
          el('td', { text: textOr(row.controls) }),
          el('td.right.num', { text: textOr(row.residual_score) }),
        ])
        : el('tr', { style: { color: 'var(--warning)' } }, [
          el('td', { colspan: '8' }, [
            el('strong', { text: `Baris register #${row.risk_entry_id}: sumber tidak ditemukan.` }),
            el('.cell-sub', {
              text: 'Penilaian risiko ini sudah dihapus dari register proyeknya. Barisnya tetap '
                + 'ditampilkan — dan tercetak bergaris pada F/RKK — karena hilangnya adalah fakta '
                + 'tentang RKK ini; membuangnya membuat lembar ini terbaca lengkap.',
            }),
          ]),
        ])))
      : el('tr', el('td', { colspan: '8', class: 'muted', text: 'RKK ini belum menaut satu pun baris IBPRP.' }))),
  ]));
}

/** Baris biaya SMKK — rupiahnya TURUNAN dari baris RAB, tidak pernah diketik. */
function smkkTable(rows, total) {
  const missing = rows.filter((row) => !row.available).length;

  return el('div', [
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Komponen' }),
        el('th', { text: 'Kode RAB' }),
        el('th', { text: 'Uraian baris RAB' }),
        el('th.right', { text: 'Vol' }),
        el('th', { text: 'Sat' }),
        el('th.right', { text: 'Harga satuan' }),
        el('th.right', { text: 'Jumlah' }),
      ])),
      el('tbody', rows.length
        ? rows.map((row) => el('tr', row.available ? {} : { style: { color: 'var(--warning)' } }, [
          el('td', { text: textOr(row.category) }),
          el('td.mono', { text: textOr(row.wbs_code) }),
          el('td', row.available
            ? { text: textOr(row.description) }
            : [
              el('strong', { text: `Baris RAB #${row.boq_item_id}: sumber tidak ditemukan.` }),
              el('.cell-sub', { text: 'Tidak ikut dijumlahkan — nol rupiah di sini berarti "tidak berbiaya", yang bukan yang kita ketahui.' }),
            ]),
          el('td.right.num', { text: row.qty === null || row.qty === undefined ? RULED : fmt.num(row.qty, 2) }),
          el('td', { text: textOr(row.unit) }),
          el('td.right.num', { text: money(row.unit_price) }),
          el('td.right.num.strong', { text: money(row.amount) }),
        ]))
        : el('tr', el('td', { colspan: '7', class: 'muted', text: 'RKK ini belum menaut satu pun baris biaya SMKK pada RAB.' }))),
      el('tfoot', el('tr', [
        el('td', { colspan: '6', text: 'Jumlah biaya penerapan SMKK' }),
        el('td.right.num.strong', { text: money(total) }),
      ])),
    ])),
    missing
      ? el('.card-body', el('p.muted', {
        style: { margin: 0, color: 'var(--warning)' },
        text: `${missing} baris tidak ikut dijumlahkan karena baris RAB-nya tidak ditemukan.`,
      }))
      : null,
  ]);
}

/**
 * Pemilih baris — satu modal berisi kotak centang atas baris nyata milik modul
 * lain, bukan kotak id.
 *
 * `rows` sudah dimuat pemanggil supaya kegagalan memuatnya menjadi kalimat di
 * layar, bukan modal kosong.
 */
function pickerDialog({ title, help, rows, checkedIds, idOf, render, extraOf, truncated, onSubmit }) {
  const boxes = new Map();
  const extras = new Map();

  /* Tautan yang sumbernya sudah lenyap TIDAK punya kotak centang di sini —
     barisnya tidak ada lagi untuk digambar. Menyimpan dari dialog ini karena
     itu MENGHAPUSNYA, dan menghapus sesuatu tanpa mengatakannya adalah persis
     yang tidak boleh dilakukan lembar ini: baris hilang itu ditampilkan
     bergaris di halaman supaya orang tahu ada yang perlu diperiksa. Jadi
     dikatakan di muka, sebelum tombol Simpan ditekan. */
  const shown = new Set(rows.map((row) => idOf(row)));
  const dropped = [...checkedIds].filter((rowId) => !shown.has(rowId));

  const body = el('div', [
    el('p', { style: { margin: '0 0 12px', color: 'var(--text-2)', fontSize: '13px', lineHeight: '1.6' }, text: help }),
    truncated
      ? el('.alert.warn', { style: { marginBottom: '12px' } }, [
        icon('warn', 15),
        el('div', { text: truncated }),
      ])
      : null,
    dropped.length
      ? el('.alert.warn', { style: { marginBottom: '12px' } }, [
        icon('warn', 15),
        el('div', {
          text: `${dropped.length} tautan menunjuk baris yang sudah tidak ada (#${dropped.join(', #')}). `
            + 'Baris itu tidak bisa ditampilkan di sini, jadi menyimpan dari dialog ini akan MENGHAPUS '
            + 'tautannya. Batalkan bila Anda ingin memeriksanya lebih dulu.',
        }),
      ])
      : null,
    rows.length
      ? el('div', rows.map((row) => {
        const rowId = idOf(row);
        const box = el('input', { type: 'checkbox', style: { marginTop: '3px', flex: 'none' } });
        box.checked = checkedIds.has(rowId);
        boxes.set(rowId, box);

        const extra = extraOf ? extraOf(row) : null;
        if (extra) extras.set(rowId, extra);

        /* Kotak isian tambahan berdiri DI LUAR <label>, bukan di dalamnya.
           Sebuah <input type=text> di dalam label kotak-centang akan menyalakan
           centangnya setiap kali pemakai mengklik untuk menaruh kursor — jadi
           mengetik nama komponen pada baris yang salah pilih akan mematikan
           pilihannya, dan tidak ada yang menyadarinya sampai lembar dicetak. */
        return el('div', {
          style: { padding: '8px 0', borderBottom: '1px solid var(--border)' },
        }, [
          el('label', {
            style: { display: 'flex', gap: '10px', alignItems: 'flex-start', cursor: 'pointer' },
          }, [box, el('div', { style: { flex: '1', minWidth: '0' } }, render(row))]),
          extra,
        ]);
      }))
      : el('p.muted', { text: 'Tidak ada baris yang bisa dipilih.' }),
  ]);

  const submit = button('Simpan tautan', {
    variant: 'primary',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      const picked = [...boxes.entries()].filter(([, box]) => box.checked).map(([rowId]) => rowId);
      const ok = await onSubmit(picked, extras);
      if (ok) dialog.close();
    }),
  });

  const dialog = modal({
    title,
    width: 'wide',
    body,
    footer: [button('Batal', { onClick: () => dialog.close() }), submit],
  });

  return dialog;
}

export async function renderRkkDocument(host, { id }) {
  skeleton(host);

  let rkk;
  try {
    rkk = await api.get(`crm/rkk-documents/${id}`);
  } catch (error) {
    clear(host);
    return host.appendChild(errorState(error, () => renderRkkDocument(host, { id })));
  }

  await preload(['projects', 'boqs', 'tenderPackages']).catch(() => {});

  const def = RESOURCES['crm/rkk-documents'];
  const reload = () => renderRkkDocument(host, { id });
  const editable = session.can('crm.update');
  const ibprpRows = rkk.ibprp_rows || [];
  const smkkRows = rkk.smkk_rows || [];

  clear(host);

  fillCrumb(rkk.code || `RKK #${rkk.id}`);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: rkk.code || `RKK #${rkk.id}` }),
      el('.desc', {
        text: [
          rkk.title,
          rkk.tender_package ? `paket ${rkk.tender_package.code}` : null,
          rkk.project_id ? `sumber IBPRP: ${labelFor('projects', rkk.project_id)}` : null,
        ].filter(Boolean).join(' · '),
      }),
    ]),
    el('.actions', [
      button('', { iconName: 'back', title: 'Kembali', onClick: () => back() }),
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload }),
      ...formButtons(printButtonsFor(def || {}, 'crm/rkk-documents'), rkk),
      def && editable
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'crm/rkk-documents', row: rkk, onSaved: reload }) })
        : null,
    ]),
  ]));

  if (!rkk.project_id) {
    host.appendChild(el('.alert.info', [
      icon('warn', 15),
      el('div', {
        text: 'RKK ini belum menyebut proyek sumber IBPRP-nya, jadi tidak ada register risiko yang boleh '
          + 'ditaut. Isi "Proyek sumber IBPRP" lewat tombol Ubah — F/RKK mencetak kode proyek itu supaya '
          + 'pembacanya tahu penilaian bahaya ini dibuat untuk pekerjaan yang mana.',
      }),
    ]));
  }

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'A. Kebijakan keselamatan konstruksi' })),
    el('.card-body', rkk.policy
      ? el('p', { style: { margin: 0, whiteSpace: 'pre-wrap' }, text: rkk.policy })
      : el('p.muted', { style: { margin: 0 }, text: 'Kebijakan keselamatan konstruksi belum diisi.' })),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'B. IBPRP — dibaca hidup dari register risiko proyek' }),
      editable && rkk.project_id
        ? button('Pilih Baris IBPRP', { iconName: 'edit', onClick: () => openIbprpPicker(rkk, ibprpRows, reload) })
        : null,
    ]),
    ibprpTable(ibprpRows),
    el('.card-body', el('p.muted', {
      style: { margin: 0, fontSize: '12px' },
      text: 'Nilai F, A dan risiko sisa dibaca dari register saat halaman ini dibuka dan saat F/RKK dicetak — '
        + 'tidak disalin saat menaut, jadi lembar ini tidak bisa membeku pada penilaian yang sudah direvisi.',
    })),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'C. Program & sasaran keselamatan konstruksi' }),
    ]),
    el('.card-body', rkk.program
      ? el('p', { style: { margin: 0, whiteSpace: 'pre-wrap' }, text: rkk.program })
      : el('p.muted', { style: { margin: 0 }, text: 'Program keselamatan konstruksi belum diisi.' })),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'D. Biaya penerapan SMKK — baris RAB' }),
      editable && rkk.boq_id
        ? button('Pilih Baris Biaya SMKK', { iconName: 'edit', onClick: () => openSmkkPicker(rkk, smkkRows, reload) })
        : null,
    ]),
    smkkTable(smkkRows, rkk.smkk_total),
    el('.card-body', el('p.muted', {
      style: { margin: 0, fontSize: '12px' },
      text: rkk.boq_id
        ? 'Rupiah pada tabel ini adalah nilai baris RAB yang ditunjuk. Tidak ada kotak rupiah pada pemilihnya '
          + 'dan tidak ada angka kedua yang disimpan di sisi RKK — lembar ini tidak bisa berselisih dengan '
          + 'RAB yang ditandatangani bersamanya.'
        : 'RKK ini belum menunjuk RAB, jadi belum ada baris biaya yang boleh ditaut. Isi "BoQ / RAB" lewat '
          + 'tombol Ubah.',
    })),
  ]));
}

async function openIbprpPicker(rkk, currentRows, reload) {
  let payload;
  try {
    payload = await api.list('projects/risk-register', { project_id: rkk.project_id, per_page: PICKER_PAGE });
  } catch (error) {
    return toastError(error);
  }

  const rows = payload.data || [];
  const total = payload.meta?.total ?? rows.length;
  const checked = new Set(currentRows.map((row) => row.risk_entry_id));

  pickerDialog({
    title: 'Pilih baris IBPRP',
    help: `Baris register risiko proyek ${labelFor('projects', rkk.project_id)}. Yang dicentang tercetak pada `
      + 'bagian B F/RKK, dengan nilai yang dibaca dari register — bukan salinan.',
    rows,
    checkedIds: checked,
    idOf: (row) => row.id,
    truncated: total > rows.length
      ? `Menampilkan ${rows.length} dari ${total} baris register. Baris yang belum tampil tidak bisa dicentang `
        + 'di sini; persempit registernya di layar Register IBPRP lebih dulu.'
      : null,
    render: (row) => el('div', [
      el('strong', { text: textOr(row.activity), style: { fontSize: '13px' } }),
      el('.muted', {
        style: { fontSize: '12px', marginTop: '2px', lineHeight: '1.55' },
        text: `${textOr(row.hazard)} · F×A ${textOr(row.initial_score)} · risiko sisa ${textOr(row.residual_score)}`,
      }),
    ]),
    onSubmit: async (picked) => {
      try {
        await api.put(`crm/rkk-documents/${rkk.id}/ibprp-links`, { ibprp_links: picked });
        toast('Tautan IBPRP diperbarui.');
        reload();
        return true;
      } catch (error) {
        toastError(error);
        return false;
      }
    },
  });
}

async function openSmkkPicker(rkk, currentRows, reload) {
  let rows;
  try {
    rows = await api.get(`estimation/boqs/${rkk.boq_id}/items`);
  } catch (error) {
    // 403 di sini bukan kerusakan: layar ini digerbangi crm.view sementara
    // baris RAB digerbangi est.view. Kalimat, bukan modal kosong.
    return toastError(error);
  }

  const existing = new Map(currentRows.map((row) => [row.boq_item_id, row.category]));

  pickerDialog({
    title: 'Pilih baris biaya SMKK',
    help: 'Baris RAB yang menjadi biaya penerapan SMKK. Nilainya diambil dari baris RAB itu — tidak ada '
      + 'kotak rupiah di sini, dan tidak akan pernah ada.',
    rows: rows || [],
    checkedIds: new Set(existing.keys()),
    idOf: (row) => row.id,
    render: (row) => el('div', [
      el('strong', { text: `${textOr(row.wbs_code)} · ${textOr(row.description)}`, style: { fontSize: '13px' } }),
      el('.muted', {
        style: { fontSize: '12px', marginTop: '2px' },
        text: `${fmt.num(row.qty, 2)} ${textOr(row.unit)} × ${money(row.unit_price)} = ${money(row.amount)}`,
      }),
    ]),
    // Komponen (mis. "APD & rambu") adalah label RUMAH untuk baris RAB itu,
    // bukan angka: satu-satunya hal yang layar ini tambahkan pada barisnya.
    extraOf: (row) => el('input', {
      type: 'text',
      placeholder: 'Komponen SMKK (opsional) — mis. APD & rambu',
      'aria-label': `Komponen SMKK untuk baris ${row.wbs_code || row.id}`,
      style: { marginTop: '6px', marginLeft: '26px', width: 'calc(100% - 26px)' },
      value: existing.get(row.id) || '',
    }),
    onSubmit: async (picked, extras) => {
      const payload = picked.map((boqItemId, index) => {
        const values = {
          boq_item_id: boqItemId,
          category: extras.get(boqItemId)?.value?.trim() || null,
          sort_order: index + 1,
        };
        // Dirakit DARI konstanta, bukan sekadar mengikutinya: kunci yang tidak
        // terdaftar tidak bisa ikut terkirim tanpa mengubah daftarnya.
        return Object.fromEntries(SMKK_PAYLOAD_KEYS.map((key) => [key, values[key]]));
      });

      try {
        await api.put(`crm/rkk-documents/${rkk.id}/smkk-costs`, { smkk_costs: payload });
        toast('Baris biaya SMKK diperbarui.');
        reload();
        return true;
      } catch (error) {
        toastError(error);
        return false;
      }
    },
  });
}

/* ============================================ PENYUSUN KUALIFIKASI ======= */

const qualificationState = {
  asOf: '',
  packageId: '',
};

/**
 * Penyusun kualifikasi — BACA SAJA, tiga bagian, satu tanggal acuan.
 *
 * Tidak ada tabel pemilihan di layar ini karena tidak ada satu pun di server:
 * yang ditampilkan adalah apa yang PERUSAHAAN bisa ajukan, dirakit dari master
 * yang dirawat modul pemiliknya. Memperbaiki isinya berarti memperbaiki
 * masternya — satu-satunya tempat yang tidak bisa basi.
 */
export async function renderKualifikasi(host) {
  clear(host);

  const asOfInput = el('input.filter-w', {
    type: 'date',
    value: qualificationState.asOf || fmt.today(),
    'aria-label': 'Personil per tanggal',
    title: 'Personil per tanggal',
  });
  asOfInput.addEventListener('change', () => {
    qualificationState.asOf = asOfInput.value;
    renderKualifikasi(host);
  });

  const packageSelect = el('select.filter-w', { 'aria-label': 'Paket tender untuk tombol cetak' });
  packageSelect.appendChild(el('option', { value: '', text: 'Pilih paket tender untuk mencetak…' }));

  // Simpul, bukan id: layar ini merender ulang setiap kali tanggal acuannya
  // berubah, dan dua simpul ber-id sama di satu dokumen membuat querySelector
  // memilih yang mana pun yang kebetulan lebih dulu.
  const printHost = el('span');

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Penyusun Kualifikasi' }),
      el('.desc', {
        text: 'Baca saja: personil bersertifikat, dukungan alat, dan daftar subkon — dirakit dari master '
          + 'SDM, Aset, dan Pengadaan. Tidak ada yang ditulis di sini; perbaikan dilakukan di masternya.',
      }),
    ]),
    el('.actions', [
      asOfInput,
      packageSelect,
      printHost,
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderKualifikasi(host) }),
    ]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(6, 7));

  let personnel;
  let equipment;
  let subcontractors;
  try {
    [personnel, equipment, subcontractors] = await Promise.all([
      api.get('crm/tender-qualification/personnel', { as_of: asOfInput.value }),
      // Masa sewa berakhir persis seperti sertifikat berakhir: tabel alat
      // menjawab tanggal acuan yang sama dengan tabel personil.
      api.get('crm/tender-qualification/equipment', { as_of: asOfInput.value }),
      api.get('crm/tender-qualification/subcontractors'),
      // Katalog cetak: layar ini bukan rute detail custom, jadi app.js tidak
      // memuatnya lebih dulu — tanpa baris ini printFormsFor() menjawab dari
      // cache kosong dan tombol F/SBD & F/DA tidak pernah muncul.
      loadPrintForms(),
    ]);
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => renderKualifikasi(host)));
  }

  // Tombol cetak F/SBD dan F/DA berjangkar pada PAKET TENDER, bukan pada layar
  // ini: judul paket, pemberi tugas dan nomor lelang tidak diketahui satu baris
  // sertifikat pun. Tanpa paket terpilih tidak ada tombol — bukan tombol yang
  // mencetak lembar bergaris judulnya.
  const paintPrint = () => {
    clear(printHost);
    if (!packageSelect.value) return;
    formButtons(printFormsFor('crm/tender-packages'), { id: Number(packageSelect.value) })
      .forEach((node) => printHost.appendChild(node));
  };

  // Simpulnya sendiri, dicat ulang saat paket berganti: tanggal yang dijawab
  // lembar cetak adalah milik PAKETNYA, bukan milik layar ini.
  const noticeHost = el('div');
  host.insertBefore(noticeHost, body);

  let packageRows = [];

  const selectedPackage = () =>
    packageRows.find((row) => String(row.id) === String(packageSelect.value)) || null;

  /* LEMBAR MENJAWAB TANGGALNYA SENDIRI, LAYAR MENJAWAB TANGGAL ACUANNYA.
     F/SBD dan F/DA dijawab per BATAS PEMASUKAN paket yang dipilih — tanggal
     panitia menilai berkas yang dimasukkan, dan tanggal yang tercetak di kepala
     lembar. Paket yang belum mencatat batas pemasukan jatuh ke hari cetak.
     Tanggal acuan di layar ini bebas, jadi keduanya masih bisa menjawab dua
     pertanyaan berbeda; selama itu bisa terjadi ia harus tertulis, bukan
     ditemukan sendiri oleh orang yang membandingkan lembar cetaknya dengan
     layar yang baru saja dilihatnya. */
  const paintNotice = () => {
    clear(noticeHost);

    const row = selectedPackage();
    if (!row) return;

    const deadline = row.submission_deadline ? String(row.submission_deadline).slice(0, 10) : null;
    const sheetDate = deadline || fmt.today();
    if (sheetDate === asOfInput.value) return;

    noticeHost.appendChild(el('.alert.warn', [
      icon('warn', 15),
      el('div', {
        text: `Tabel di bawah dijawab per ${fmt.date(asOfInput.value)}, tetapi F/SBD dan F/DA dijawab per `
          + `${fmt.date(sheetDate)} — ${deadline
            ? 'batas pemasukan paket yang dipilih'
            : 'hari cetak, karena paket yang dipilih belum mencatat batas pemasukan'}. `
          + 'Samakan tanggal acuan dengan tanggal itu untuk lembar yang cocok dengan layar.',
      }),
    ]));
  };

  loadSource('tenderPackages').then((rows) => {
    packageRows = rows || [];
    optionsFor('tenderPackages', packageRows).forEach((option) =>
      packageSelect.appendChild(el('option', { value: option.value, text: option.label })));
    packageSelect.value = qualificationState.packageId || '';
    paintPrint();
    paintNotice();
  }, () => {});

  packageSelect.addEventListener('change', () => {
    qualificationState.packageId = packageSelect.value;
    paintPrint();
    paintNotice();
  });

  clear(body);

  const memenuhi = personnel.memenuhi || [];
  const kedaluwarsa = personnel.kedaluwarsa || [];

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'A. Personil inti — sertifikat masih berlaku' }),
      el('.sub', { text: `Per ${fmt.date(personnel.as_of)}` }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Nama' }),
        el('th', { text: 'Jabatan' }),
        el('th', { text: 'Jenis' }),
        el('th', { text: 'Nama sertifikat' }),
        el('th', { text: 'Nomor' }),
        el('th', { text: 'Penerbit' }),
        el('th', { text: 'Berlaku s/d' }),
      ])),
      el('tbody', memenuhi.length
        ? memenuhi.map((row) => el('tr', [
          el('td', [
            el('div', { text: textOr(row.employee_name) }),
            el('.cell-sub.mono', { text: textOr(row.employee_code) }),
          ]),
          el('td', { text: textOr(row.position) }),
          el('td', { text: textOr(row.certificate_type_label || row.certificate_type) }),
          el('td', { text: textOr(row.certificate_name) }),
          el('td.mono', { text: textOr(row.number) }),
          el('td', { text: textOr(row.issuer) }),
          el('td', { text: row.expiry_date ? fmt.date(row.expiry_date) : 'Tidak kedaluwarsa' }),
        ]))
        : el('tr', el('td', {
          colspan: '7',
          class: 'muted',
          text: 'Belum ada personil bersertifikat yang masih berlaku pada tanggal ini.',
        }))),
    ])),
  ]));

  // Kartunya sendiri, bukan baris merah di tabel atas: yang berlaku dan yang
  // lewat tidak boleh berbagi satu daftar yang bisa disalin utuh ke lampiran.
  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Sertifikat kedaluwarsa — tidak didaftar sebagai kualifikasi' }),
      badge(String(kedaluwarsa.length), kedaluwarsa.length ? 'warn' : ''),
    ]),
    kedaluwarsa.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Nama' }),
          el('th', { text: 'Jenis' }),
          el('th', { text: 'Nama sertifikat' }),
          el('th', { text: 'Nomor' }),
          el('th', { text: 'Lewat sejak' }),
          el('th.right', { text: 'Hari lewat' }),
        ])),
        el('tbody', kedaluwarsa.map((row) => el('tr', { style: { color: 'var(--warning)' } }, [
          el('td', { text: textOr(row.employee_name) }),
          el('td', { text: textOr(row.certificate_type_label || row.certificate_type) }),
          el('td', { text: textOr(row.certificate_name) }),
          el('td.mono', { text: textOr(row.number) }),
          el('td', { text: row.expiry_date ? fmt.date(row.expiry_date) : RULED }),
          el('td.right.num', { text: row.days_to_expiry === null ? RULED : String(Math.abs(row.days_to_expiry)) }),
        ]))),
      ]))
      : el('.card-body', el('p.muted', { style: { margin: 0 }, text: 'Tidak ada sertifikat yang kedaluwarsa pada tanggal ini.' })),
    el('.card-body', el('p.muted', {
      style: { margin: 0, fontSize: '12px' },
      text: 'Baris di sini TIDAK tercetak pada F/SBD — sebuah lembar yang menyatakan seorang ahli bersedia '
        + 'ditugaskan tidak boleh berdiri di atas sertifikat yang sudah lewat. Ia berdiri di sini supaya ada '
        + 'yang sempat memperpanjangnya sebelum batas pemasukan, bukan supaya bisa diabaikan.',
    })),
  ]));

  const alat = (equipment && equipment.memenuhi) || [];
  const sewaHabis = (equipment && equipment.kedaluwarsa) || [];

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'B. Dukungan alat — milik sendiri dan sewa' }),
      el('.sub', { text: `Per ${fmt.date((equipment && equipment.as_of) || asOfInput.value)}` }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }),
        el('th', { text: 'Jenis / nama alat' }),
        el('th', { text: 'Merk' }),
        el('th', { text: 'Tipe' }),
        el('th', { text: 'No. seri' }),
        el('th', { text: 'Status' }),
        el('th', { text: 'Pemilik / lessor' }),
        el('th', { text: 'Sewa s/d' }),
      ])),
      el('tbody', alat.length
        ? alat.map((row) => el('tr', [
          el('td.mono', { text: textOr(row.code) }),
          el('td', { text: textOr(row.name) }),
          el('td', { text: textOr(row.brand) }),
          el('td', { text: textOr(row.model) }),
          el('td.mono', { text: textOr(row.serial_no) }),
          // Status berdiri di tengah tabel, bukan sebagai catatan kaki: inilah
          // persis kolom yang diperiksa panitia lelang.
          el('td', badge(textOr(row.ownership_label), row.rented ? 'blue' : '')),
          el('td', { text: textOr(row.lessor_name) }),
          el('td', { text: row.rental_end ? fmt.date(row.rental_end) : RULED }),
        ]))
        : el('tr', el('td', { colspan: '8', class: 'muted', text: 'Belum ada peralatan tercatat pada register aset.' }))),
    ])),
    el('.card-body', el('p.muted', {
      style: { margin: 0, fontSize: '12px' },
      text: 'Alat sewa boleh mendukung penawaran dan tercetak pada F/DA — SEBAGAI sewa, dengan lessornya. '
        + 'Yang tidak boleh adalah lembar yang membuatnya terbaca seperti milik sendiri.',
    })),
  ]));

  // Cermin kartu sertifikat kedaluwarsa, dan ada karena alasan yang sama:
  // tidak ada apa pun di modul Aset yang memindahkan status alat sewa ketika
  // masa sewanya habis, jadi tanpa kartu ini alat yang sudah kembali ke lessor
  // hilang dari layar tanpa jejak — atau, lebih buruk, ikut ke lampiran.
  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Sewa berakhir — tidak didaftar sebagai dukungan alat' }),
      badge(String(sewaHabis.length), sewaHabis.length ? 'warn' : ''),
    ]),
    sewaHabis.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kode' }),
          el('th', { text: 'Jenis / nama alat' }),
          el('th', { text: 'Pemilik / lessor' }),
          el('th', { text: 'Sewa berakhir' }),
          el('th.right', { text: 'Hari lewat' }),
        ])),
        el('tbody', sewaHabis.map((row) => el('tr', { style: { color: 'var(--warning)' } }, [
          el('td.mono', { text: textOr(row.code) }),
          el('td', { text: textOr(row.name) }),
          el('td', { text: textOr(row.lessor_name) }),
          el('td', { text: row.rental_end ? fmt.date(row.rental_end) : RULED }),
          el('td.right.num', {
            text: row.days_to_rental_end === null || row.days_to_rental_end === undefined
              ? RULED
              : String(Math.abs(row.days_to_rental_end)),
          }),
        ]))),
      ]))
      : el('.card-body', el('p.muted', { style: { margin: 0 }, text: 'Tidak ada alat sewa yang masa sewanya berakhir pada tanggal ini.' })),
    el('.card-body', el('p.muted', {
      style: { margin: 0, fontSize: '12px' },
      text: 'Baris di sini TIDAK tercetak pada F/DA — alat yang sudah kembali ke lessor bukan dukungan alat, '
        + 'dan status asetnya tetap "tersedia" karena tidak ada yang memindahkannya saat sewa habis. Ia berdiri '
        + 'di sini supaya sewanya sempat diperpanjang sebelum batas pemasukan, bukan supaya bisa diabaikan.',
    })),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'C. Daftar subkontraktor' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }),
        el('th', { text: 'Nama' }),
        el('th', { text: 'Badan hukum' }),
        el('th', { text: 'Klasifikasi' }),
        el('th', { text: 'NPWP' }),
        el('th', { text: 'Kota' }),
        el('th.right', { text: 'Rating' }),
      ])),
      el('tbody', (subcontractors || []).length
        ? subcontractors.map((row) => {
          const node = el('tr', { style: { cursor: 'pointer' } }, [
            el('td.mono', { text: textOr(row.code) }),
            el('td', { text: textOr(row.name) }),
            el('td', { text: textOr(row.legal_name) }),
            el('td', { text: textOr(row.classification) }),
            el('td.mono', { text: textOr(row.npwp) }),
            el('td', { text: textOr(row.city) }),
            el('td.right.num', { text: row.rating === null ? RULED : fmt.num(row.rating, 2) }),
          ]);
          node.addEventListener('click', () => navigate(`d/procurement/vendors/${row.vendor_id}`));
          return node;
        })
        : el('tr', el('td', { colspan: '7', class: 'muted', text: 'Belum ada vendor bertipe subkontraktor yang aktif.' }))),
    ])),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara membaca' })),
    el('.card-body', [
      el('p', { text: 'Tanggal acuan di kanan atas menentukan sertifikat mana yang masih berlaku dan sewa alat mana yang masih berjalan. Lembar kualifikasi bertanggal harus menjawab pertanyaan yang sama setiap kali dicetak ulang, jadi jawabannya "berlaku pada tanggal itu", bukan "berlaku hari ini".' }),
      el('p', { text: 'Tombol cetak F/SBD dan F/DA baru muncul setelah paket tender dipilih: judul paket, pemberi tugas, dan nomor lelang tidak diketahui satu baris sertifikat atau satu baris aset pun, dan lembar yang menggarisi ketiganya bukan lembar yang bisa dimasukkan ke sampul penawaran.' }),
      el('p', { text: 'F/SBD dan F/DA dijawab per BATAS PEMASUKAN paket yang dipilih — tanggal panitia menilai berkas yang dimasukkan, dan tanggal yang tercetak di kepala lembar. Paket yang belum mencatat batas pemasukan jatuh ke hari cetak. Tanggal acuan di layar ini bebas, jadi layar memperingatkan bila keduanya berbeda.' }),
      el('p', { text: 'Layar ini tidak menyimpan pilihan siapa pun. Tidak ada tabel "personil yang dinominasikan" — sebuah daftar nominasi yang tidak dirawat justru hal pertama yang diperiksa panitia.' }),
    ]),
  ]));
}
