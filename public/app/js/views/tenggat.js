/* Tenggat — semua tanggal yang bisa lewat diam-diam, dikumpulkan satu layar.

   Pemberitahuan harian (erp:deadline-watch, 08:30 WIB) bisa dibaca hari Selasa
   dan terlupa hari Jumat; layar ini tidak bisa. Ia menjalankan pemindaian yang
   sama secara langsung, jadi tenggat yang belum beres tetap tampil walau
   pemberitahuannya sudah lama ditandai terbaca. Data demo menunjukkan harganya:
   dua PO senilai gabungan Rp 360,8 jt dijanjikan Maret 2026 dan tidak ada satu
   layar pun yang menagihnya selama 153 hari.

   Server hanya mengirim entri yang izinnya dipegang pemanggil — di sini tidak
   ada penyaringan izin lagi, dan "kosong" berarti benar-benar tidak ada
   tenggat pada modul yang boleh Anda lihat. */

import { api } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

const TIER = {
  lewat: ['Lewat', 'red'],
  menipis: ['Menipis', 'amber'],
  tanpa_tanggal: ['Tanpa tanggal', 'red'],
};

/** Umur tenggat memakai skala beban yang sama dengan antrean siap-tagih. */
function warnaUmur(item, tier) {
  if (tier === 'menipis') return 'var(--text)';
  const days = item.days === null ? 999 : Math.abs(item.days);
  if (days >= 60) return 'var(--danger)';
  if (days >= 30) return 'var(--warning)';
  return 'var(--text)';
}

function umur(item, tier) {
  if (tier === 'tanpa_tanggal' || item.days === null) return 'tidak tercatat';
  const days = Math.abs(item.days);
  if (tier === 'lewat') return days === 0 ? 'hari ini' : `${days} hari lalu`;
  return `${days} hari lagi`;
}

export async function renderTenggat(host) {
  clear(host);
  const reload = () => renderTenggat(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Tenggat' }),
      el('.desc', {
        text: 'Tanggal yang menipis atau sudah lewat di semua modul — penawaran, kontrak, '
          + 'PO, jaminan, sertifikat, PKWT — dihitung langsung, bukan dari kotak masuk.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(4, 5));

  let payload;
  try {
    payload = await api.list('core/deadlines');
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  const findings = payload.data || [];
  clear(body);

  if (!findings.length) {
    body.appendChild(el('.alert.info',
      'Tidak ada tenggat yang menipis atau lewat pada modul yang boleh Anda lihat. '
      + 'Baris muncul di sini begitu sebuah tanggal masuk jendela peringatannya — '
      + 'pemberitahuan hariannya terbit pukul 08.30 WIB.'));
    return;
  }

  const totalOf = (tier) => findings
    .filter((f) => f.tier === tier)
    .reduce((sum, f) => sum + f.count, 0);
  const lewat = totalOf('lewat') + totalOf('tanpa_tanggal');
  const menipis = totalOf('menipis');

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Sudah lewat / tanpa tanggal' }),
      el('.value.sm', { text: String(lewat), style: lewat ? { color: 'var(--danger)' } : {} }),
      el('.delta.down', { text: 'butuh tindakan sekarang' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Menipis' }),
      el('.value.sm', { text: String(menipis) }),
      el('.delta', { text: 'masih di dalam jendela peringatan' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Kelompok pengawasan' }),
      el('.value.sm', { text: String(findings.length) }),
      el('.delta', { text: `dipindai per ${fmt.date(payload.meta && payload.meta.today)}` }),
    ]),
  ]));

  findings.forEach((finding) => {
    const [label, tone] = TIER[finding.tier] || [finding.tier, ''];
    const withValue = finding.items.some((item) => item.value !== null);
    const shown = finding.items.length;

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: finding.title }),
        el('span', [
          badge(label, tone),
          el('span.cell-sub', {
            text: shown < finding.count
              ? ` ${finding.count} baris (${shown} ditampilkan)`
              : ` ${finding.count} baris`,
          }),
        ]),
      ]),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: finding.label }),
          el('th', { text: 'Tanggal' }),
          el('th.right', { text: 'Umur' }),
          withValue ? el('th.right', { text: 'Nilai' }) : null,
          el('th', { text: '' }),
        ])),
        el('tbody', finding.items.map((item) => el('tr', [
          el('td', el('span.cell-main', { text: item.code })),
          el('td', { text: item.date ? fmt.date(item.date) : '—' }),
          el('td.right.num.strong', {
            text: umur(item, finding.tier),
            style: { color: warnaUmur(item, finding.tier) },
          }),
          withValue ? el('td.right.num', { text: item.value === null ? '—' : fmt.rupiah(item.value) }) : null,
          el('td.right', button('Buka', {
            size: 'sm',
            onClick: () => navigate(finding.link),
          })),
        ]))),
      ])),
    ]));
  });

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', { text: 'Setiap pagi pukul 08.30 WIB pemegang izin yang bisa bertindak menerima pemberitahuan per kelompok — menipis diingatkan ulang paling cepat 7 hari, lewat paling cepat 3 hari, jadi kotak masuk tidak dibanjiri.' }),
      el('p', { text: 'Baris hilang dari layar ini begitu penyebabnya beres: penawaran di-won/lost, PO ditutup, jaminan dikembalikan, sertifikat diperpanjang, tanggal PKWT diisi.' }),
      el('p', { text: 'Yang tampil di sini disaring menurut izin Anda — kelompok milik modul lain dikirim ke pemegang izinnya masing-masing.' }),
    ]),
  ]));
}
