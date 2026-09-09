/* Ambang — setiap angka yang punya BATAS, dan keadaan batas itu (F-2 / T2.1).

   Saudara layar Tenggat. Yang itu menjawab "tanggal apa yang lewat"; yang ini
   menjawab "angka apa yang mendekati atau melewati batasnya".

   SATU ATURAN MENGATUR SELURUH LAYAR INI: sebuah sel yang tidak punya jawaban
   TIDAK PERNAH digambar sebagai 0 %. Ada dua sebab berbeda kenapa sebuah baris
   tidak punya angka, dan keduanya dicetak sebagai kalimatnya sendiri —
   "Batas belum disetel" (yang diukur ada, batasnya tidak pernah dipasang) dan
   "Belum ada yang diukur" (yang diukur sendiri belum ada). Satu batang abu-abu
   0 % untuk keduanya adalah cara termurah membuat layar anggaran berbohong.

   Server hanya mengirim entri yang izinnya dipegang pemanggil; di sini tidak
   ada penyaringan izin lagi. */

import { api } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

/** "tahun buku" -> "Tahun buku". Kapital di awal, sisanya apa adanya. */
function titleCase(word) {
  const text = String(word || 'Subjek');
  return text.charAt(0).toUpperCase() + text.slice(1);
}

const STATE = {
  lampau: ['Melampaui batas', 'red'],
  mendekati: ['Mendekati batas', 'amber'],
  aman: ['Aman', 'green'],
  /* Dianggarkan Rp 0 dan belum dibelanjakan — batasnya DISETEL, dan besarnya
     nol. Berbeda sebab dan berbeda jalan keluarnya dari "belum disetel". */
  tanpa_anggaran: ['Tidak dianggarkan', ''],
  tanpa_batas: ['Batas belum disetel', ''],
  tidak_terukur: ['Belum ada yang diukur', ''],
};

/* Warna angka mengikuti keadaan, dan HANYA keadaan — tidak ada baris tanpa
   angka yang diwarnai, karena tidak ada yang diketahui tentangnya. */
function warnaKeadaan(state) {
  if (state === 'lampau') return 'var(--danger)';
  if (state === 'mendekati') return 'var(--warning)';
  return 'var(--text)';
}

/** Sel persen: angka bila ada, ATURAN bila tidak — tidak pernah "0 %". */
function selPersen(row) {
  if (row.pct === null || row.pct === undefined) {
    const [label] = STATE[row.state] || [row.state];
    return el('td.right', el('span.cell-sub', { text: label }));
  }

  return el('td.right.num.strong', {
    text: fmt.percent(row.pct, { decimals: 1 }),
    style: { color: warnaKeadaan(row.state) },
  });
}

/* Sel angka yang boleh kosong — digaris, tidak dinolkan — DAN DALAM SATUANNYA
   SENDIRI. Setiap entri registri sudah mendeklarasikan `unit` sejak F-2 dan
   layar ini tidak pernah membacanya: entri berjam pertama (F-7, servis alat)
   mencetak "Rp 3.375,50" untuk 3.375,5 JAM. Satuan yang dideklarasikan sebuah
   entri tidak boleh hilang di tabel yang menampilkannya — aturan yang sama
   yang sudah dipegang kolom pertama tabel ini untuk `subject_word`. */
function selUkuran(value, unit) {
  if (value === null || value === undefined) return el('td.right', el('span.cell-sub', { text: '—' }));
  return el('td.right.num', { text: unit === 'rupiah' ? fmt.rupiah(value) : fmt.qty(value, unit) });
}

/* Kolom ketiga sebuah entri yang TIDAK proporsional: sisa menuju batas, bukan
   persentase. Meter kumulatif tidak pernah mulai dari nol pada servis
   terakhir, jadi "96 %" di sana mengukur umur alat, bukan sisa jatah
   servisnya — dan sebuah alat 12.000 jam bertarget 12.250 akan berbunyi
   "97,9 %" sejak 1.225 jam sebelum jatuh tempo. Yang menolong adalah "124,5
   jam lagi". Baris tanpa angka tetap mencetak ATURAN-nya, sama seperti sisi
   persen. */
function selSisa(row, unit) {
  if (row.remaining === null || row.remaining === undefined) {
    const [label] = STATE[row.state] || [row.state];
    return el('td.right', el('span.cell-sub', { text: label }));
  }

  /* Sisa NEGATIF dikatakan sebagai apa adanya: "40 jam lewat", bukan
     "-40 jam lagi" — sebuah minus yang harus dibaca dua kali di kolom yang
     seluruh tugasnya memberi tahu berapa lama lagi. */
  return el('td.right.num.strong', {
    text: row.remaining < 0
      ? `${fmt.qty(Math.abs(row.remaining), unit)} lewat`
      : `${fmt.qty(row.remaining, unit)} lagi`,
    style: { color: warnaKeadaan(row.state) },
  });
}

export async function renderAmbang(host) {
  clear(host);
  const reload = () => renderAmbang(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Ambang & Batas' }),
      el('.desc', {
        text: 'Angka yang mendekati atau melewati batasnya — anggaran proyek terhadap RAP, RAP terhadap '
          + 'nilai kontrak, realisasi overhead terhadap OVB, jam alat terhadap target servis berikutnya — '
          + 'dihitung langsung dari data hari ini.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(4, 5));

  let payload;
  try {
    payload = await api.list('core/thresholds');
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  const measures = payload.data || [];
  const meta = payload.meta || {};
  clear(body);

  if (!measures.length) {
    body.appendChild(el('.alert.info',
      'Tidak ada ukuran ambang pada modul yang boleh Anda lihat. '
      + `Registri memindai ${meta.checked || 0} ukuran; ${meta.skipped || 0} dilewati karena modulnya belum memasok angkanya.`));
    return;
  }

  const jumlah = (key) => measures.reduce((sum, m) => sum + ((m.counts && m.counts[key]) || 0), 0);

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Melampaui batas' }),
      el('.value.sm', { text: String(jumlah('lampau')), style: jumlah('lampau') ? { color: 'var(--danger)' } : {} }),
      el('.delta.down', { text: 'butuh keputusan sekarang' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Mendekati batas' }),
      el('.value.sm', { text: String(jumlah('mendekati')) }),
      el('.delta', { text: 'di ambang peringatan, belum lewat' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Tanpa batas / belum terukur' }),
      el('.value.sm', { text: String(jumlah('tanpa_anggaran') + jumlah('tanpa_batas') + jumlah('tidak_terukur')) }),
      el('.delta', { text: 'aturan, bukan nol' }),
    ]),
  ]));

  measures.forEach((measure) => {
    const rows = measure.rows || [];
    const shown = rows.length;

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: measure.label }),
        el('span', [
          /* Ambang sebuah entri dinyatakan dalam bahasanya sendiri: persen
             untuk sisi rupiah, JARAK dalam satuannya untuk entri yang tidak
             proporsional ("Peringatan 50 jam sebelum batas"). Mencetak "≥ 90 %"
             di atas tabel yang tidak punya satu persentase pun adalah lencana
             yang membantah barisnya sendiri. */
          badge(measure.warn_margin === null || measure.warn_margin === undefined
            ? `Peringatan ≥ ${fmt.percent(measure.warn_pct, { decimals: 0 })}`
            : `Peringatan ${fmt.qty(measure.warn_margin, measure.unit)} sebelum batas`, 'amber'),
          el('span.cell-sub', {
            text: shown < measure.total ? ` ${measure.total} baris (${shown} ditampilkan)` : ` ${measure.total} baris`,
          }),
        ]),
      ]),
      el('.card-body', [
        el('p.help', { text: `Yang diukur: ${measure.measures}.` }),
        el('p.help', { text: `Batasnya dari: ${measure.limit_source}.` }),
      ]),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          /* Kata yang DIDEKLARASIKAN entrinya, bukan dua pilihan yang dipatok
             layar: entri overhead menyebut subject_word 'tahun buku', dan
             tabelnya dulu berjudul "SUBJEK" di atas baris berisi "2026"
             (verifikasi F-2). Registri yang mendeklarasikan satuannya sendiri
             tidak boleh kehilangannya di tabel yang menampilkannya. */
          el('th', { text: titleCase(measure.subject_word) }),
          el('th.right', { text: 'Aktual' }),
          el('th.right', { text: 'Batas' }),
          el('th.right', { text: measure.proportional === false ? 'Sisa' : 'Terpakai' }),
          el('th', { text: 'Keadaan' }),
          el('th', { text: '' }),
        ])),
        el('tbody', rows.map((row) => {
          const [label, tone] = STATE[row.state] || [row.state, ''];

          return el('tr', [
            el('td', [
              el('span.cell-main', { text: row.subject }),
              row.name ? el('span.cell-sub', { text: row.name }) : null,
            ]),
            selUkuran(row.actual, measure.unit),
            selUkuran(row.limit, measure.unit),
            measure.proportional === false ? selSisa(row, measure.unit) : selPersen(row),
            el('td', [
              badge(label, tone),
              row.note ? el('span.cell-sub', { text: row.note }) : null,
            ]),
            el('td.right', button('Buka', { size: 'sm', onClick: () => navigate(row.link) })),
          ]);
        })),
      ])),
    ]));
  });

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara membacanya' })),
    el('.card-body', [
      el('p', { text: 'Pada ukuran yang berbentuk PERSENTASE, sebuah baris berubah menjadi "Mendekati batas" saat terpakai mencapai ambang peringatan, dan menjadi "Melampaui batas" tepat pada 100 % — anggaran yang habis persis sudah tidak menyisakan apa pun untuk dokumen berikutnya. Ukuran yang tidak berbentuk persentase (servis alat) memakai jarak: peringatannya mulai sekian jam sebelum target, dan "Melampaui batas" tepat saat pembacaan mencapai targetnya.' }),
      el('p', { text: '"Batas belum disetel" berarti angkanya ada tetapi batasnya tidak pernah dipasang (mis. nilai kontrak belum dicatat pada master proyek). Itu sebuah aturan yang dicetak apa adanya, bukan 0 % dan bukan taksiran.' }),
      el('p', { text: '"Tidak dianggarkan" berarti batasnya justru DISETEL — sebuah dokumen yang disetujui menyebut sisi ini dan menyebutnya Rp 0 — dan belum ada yang dibelanjakan di sana. Begitu ada rupiah yang keluar di sisi itu, barisnya menjadi "Melampaui batas": nol adalah batas yang paling mudah dilampaui, bukan batas yang hilang.' }),
      el('p', { text: '"Belum ada yang diukur" berarti yang diukurnya sendiri belum ada (mis. proyek belum punya RAP yang disetujui, atau sebuah alat belum punya satu pun pembacaan hour meter). Catatan pada barisnya menyebut kedua sisi yang hilang — dan, pada alat, menyebut MENGAPA belum ada pembacaan: belum pernah dimobilisasi, mobilisasinya belum punya log, atau lognya ada tetapi tanpa angka hour meter.' }),
      el('p', { text: 'Tidak semua ukuran punya persentase. Servis alat diukur dengan meter kumulatif yang tidak pernah mulai dari nol pada servis terakhir, jadi "96 % terpakai" di sana mengukur umur alat dan bukan sisa jatah servisnya; kolomnya mencetak SISA jamnya, dan peringatannya dinyatakan dalam jam sebelum target — sama panjang untuk alat baru maupun alat tua.' }),
      el('p', { text: 'Servis alat punya DUA pemicu yang berdiri sendiri-sendiri: jam operasi (tabel ini) dan tanggal (layar Tenggat). Yang mana pun tercapai lebih dulu, servisnya jatuh tempo — catatan tiap baris di sini menyebut tanggal jatuh temponya juga.' }),
      el('p', { text: 'Layar ini TIDAK menolak dokumen apa pun. Yang menolak PO/SPK yang menjebol RAP adalah gerbang anggaran pada pengajuannya, dengan kalimat dan angkanya sendiri.' }),
      meta.skipped
        ? el('p.help', { text: `${meta.skipped} ukuran dilewati karena tabel atau modul pemasoknya belum ada — dilaporkan, bukan disembunyikan sebagai "aman".` })
        : null,
    ]),
  ]));
}
