/* Register Sertifikat & PKWT — dua daftar tanggal SDM yang dibaca pengawas
   tenggat (erp:deadline-watch, 08.30 WIB) dari satu layar kerja.

   Harga telatnya bukan teori. SKK yang kedaluwarsa menaikkan PPh final
   pelaksanaan konstruksi dari 2,65% (bersertifikat) ke 4,00% — selisih 1,35
   poin dari setiap tagihan (lihat fin_taxes). PKWT yang lewat tanggalnya demi
   hukum menjadi PKWTT (PP 35/2021). Data demo hari pertama membacanya
   telanjang: register sertifikat kosong dan dua karyawan kontrak (EMP-0007,
   EMP-0008) tanpa tanggal akhir PKWT tercatat.

   Umur TIDAK dihitung dari jam klien: sertifikat memakai days_to_expiry yang
   dihitung server, PKWT memakai meta.today dari api/core/deadlines. Jam klien
   yang mundur sehari sudah cukup untuk menyembunyikan baris LEWAT tepat pada
   pagi pengawasnya menagih. */

import { api, session } from '../api.js';
import {
  el, clear, button, badge, toast, toastError, errorState, skeletonTable,
  emptyState, confirmDialog,
} from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';
import { RESOURCES } from '../schema.js';
import { openForm, promptFields } from './form.js';

/* Sama dengan lead_days entri certificate_expiry dan pkwt_end di
   Modules/Core/Support/WatchedDeadlines.php — kalau angka di sana berubah,
   layar ini dan pemberitahuan hariannya harus tetap satu suara. */
const LEAD_DAYS = 60;

const DAY_MS = 86400000;

const TIER_BADGE = {
  lewat: ['Lewat', 'red'],
  menipis: ['Menipis', 'amber'],
};

/* 'YYYY-MM-DD' di-parse sebagai tengah malam UTC oleh spesifikasi, jadi selisih
   dua tanggal telanjang selalu kelipatan bulat satu hari — tidak ada zona waktu
   klien yang ikut campur. slice(0, 10) menjaga dari bentuk simpanan
   'YYYY-MM-DD 00:00:00' seandainya sebuah endpoint lupa memformat. */
function selisihHari(dateStr, todayStr) {
  if (!dateStr) return null;
  return Math.round((Date.parse(String(dateStr).slice(0, 10)) - Date.parse(todayStr)) / DAY_MS);
}

/* Hari-H sudah LEWAT, bukan menipis — mengikuti batas separuh-terbuka si
   pengawas ("< besok menangkap hari ini"). */
function tingkat(days) {
  if (days === null) return null;
  if (days <= 0) return 'lewat';
  if (days <= LEAD_DAYS) return 'menipis';
  return 'aman';
}

function umur(days, nullText) {
  if (days === null) return nullText;
  if (days === 0) return 'hari ini';
  return days < 0 ? `${Math.abs(days)} hari lalu` : `${days} hari lagi`;
}

/** Skala beban yang sama dengan layar tenggat: lewat merah, menipis kuning. */
function warnaUmur(days) {
  if (days === null) return 'var(--text-2)';
  if (days <= 0) return 'var(--danger)';
  if (days <= LEAD_DAYS) return 'var(--warning)';
  return 'var(--text)';
}

export async function renderSertifikat(host) {
  clear(host);
  const reload = () => renderSertifikat(host);

  /* Formulir tambah/ubah dipinjam dari definisi generik 'hr/certificates',
     bukan ditulis ulang di sini. Dicek dulu, tidak diasumsikan: sehabis deploy,
     modul SPA bisa basi sebelah — schema.js lama tersangkut cache sementara
     file ini sudah baru — dan tanpa definisi itu register harus tetap terbaca,
     hanya tombol formulirnya yang turun. */
  const certDef = RESOURCES['hr/certificates'] || null;
  const canCreate = Boolean(certDef) && session.can('hr.create');
  const canUpdate = session.can('hr.update');
  const canDelete = session.can('hr.delete');

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Register Sertifikat & PKWT' }),
      el('.desc', {
        text: 'Sertifikat keahlian (SKK, K3, principal) dan tanggal akhir PKWT karyawan '
          + 'kontrak — register yang dipindai pengawas tenggat setiap pagi pukul 08.30 WIB.',
      }),
    ]),
    el('.actions', [
      canCreate ? button('Tambah Sertifikat', {
        variant: 'primary', iconName: 'plus',
        onClick: () => openForm({ def: certDef, key: 'hr/certificates', onSaved: reload }),
      }) : null,
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload }),
    ]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(4, 6));

  let certPayload;
  let empPayload;
  let serverToday;
  try {
    /* core/deadlines ikut diminta hanya demi meta.today-nya: umur PKWT tidak
       punya padanan days_to_expiry dari server, dan jam klien tidak dipercaya.
       Kalau panggilan itu gagal, tanggal klien menjadi cadangan yang diakui —
       tanggal PKWT-nya sendiri tetap dicetak apa adanya. */
    let deadlinePayload = null;
    [certPayload, empPayload, deadlinePayload] = await Promise.all([
      api.list('hr/certificates', { per_page: 200 }),
      api.list('hr/employees', { employment_type: 'kontrak', status: 'active', per_page: 200 }),
      api.list('core/deadlines').catch(() => null),
    ]);
    serverToday = (deadlinePayload && deadlinePayload.meta && deadlinePayload.meta.today) || fmt.today();
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  const certs = certPayload.data || [];
  const certTotal = (certPayload.meta || {}).total ?? certs.length;
  const kontrak = (empPayload.data || []).map((row) => ({
    ...row,
    pkwt_days: selisihHari(row.pkwt_end_date, serverToday),
  }));

  /* Alarm data di atas, lalu yang paling dekat berakhir: baris tanpa tanggal
     adalah satu-satunya yang tidak bisa diurut oleh tanggal — dan justru yang
     paling perlu dilihat. */
  kontrak.sort((a, b) =>
    (a.pkwt_end_date ? 1 : 0) - (b.pkwt_end_date ? 1 : 0)
    || String(a.pkwt_end_date || '').localeCompare(String(b.pkwt_end_date || ''))
    || String(a.code || '').localeCompare(String(b.code || '')));

  clear(body);

  const certLewat = certs.filter((row) => tingkat(row.days_to_expiry) === 'lewat').length;
  const certMenipis = certs.filter((row) => tingkat(row.days_to_expiry) === 'menipis').length;
  const pkwtLewat = kontrak.filter((row) => tingkat(row.pkwt_days) === 'lewat').length;
  const pkwtMenipis = kontrak.filter((row) => tingkat(row.pkwt_days) === 'menipis').length;
  // Dasar selesainya-pekerjaan sah tanpa tanggal (PP 35/2021 Pasal 9) — hanya
  // jangka-waktu (atau dasar yang belum dicatat) yang berutang tanggal.
  const pkwtTanpaTanggal = kontrak.filter((row) => !row.pkwt_end_date
    && row.pkwt_basis !== 'selesainya_pekerjaan').length;

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Sertifikat lewat / menipis' }),
      el('.value.sm', {
        text: String(certLewat + certMenipis),
        style: certLewat ? { color: 'var(--danger)' } : {},
      }),
      certs.length
        ? el(certLewat + certMenipis ? '.delta.down' : '.delta', {
          text: certLewat + certMenipis
            ? `${certLewat} lewat · ${certMenipis} menipis (≤ ${LEAD_DAYS} hari)`
            : `semua dari ${certs.length} sertifikat masih aman`,
        })
        : el('.delta.down', { text: 'register masih kosong — mulai catat' }),
    ]),
    el('.stat', [
      el('.label', { text: 'PKWT lewat / menipis' }),
      el('.value.sm', {
        text: String(pkwtLewat + pkwtMenipis),
        style: pkwtLewat ? { color: 'var(--danger)' } : {},
      }),
      el(pkwtLewat + pkwtMenipis ? '.delta.down' : '.delta', {
        text: pkwtLewat + pkwtMenipis
          ? `${pkwtLewat} lewat · ${pkwtMenipis} berakhir ≤ ${LEAD_DAYS} hari`
          : `${kontrak.length} karyawan kontrak aktif terpantau`,
      }),
    ]),
    el('.stat', [
      el('.label', { text: 'PKWT tanpa tanggal' }),
      el('.value.sm', {
        text: String(pkwtTanpaTanggal),
        style: pkwtTanpaTanggal ? { color: 'var(--danger)' } : {},
      }),
      el(pkwtTanpaTanggal ? '.delta.down' : '.delta', {
        text: pkwtTanpaTanggal
          ? 'alarm data — ditagih ulang tiap 7 hari sampai diisi'
          : 'semua kontrak punya tanggal akhir',
      }),
    ]),
  ]));

  const perpanjang = (row) => async (event) => {
    event.stopPropagation();
    const values = await promptFields(`Perpanjang ${row.name}`, [
      {
        key: 'expiry_date', label: 'Tanggal kedaluwarsa baru', type: 'date', required: true,
        default: row.expiry_date,
        help: row.issued_date
          ? `Harus setelah tanggal terbit (${fmt.date(row.issued_date)}).`
          : 'Perpanjangan cukup mengganti tanggal ini — tidak perlu baris baru.',
      },
    ], { submitLabel: 'Simpan perpanjangan' });

    if (values === null) return;

    try {
      /* PUT parsial satu field memang jalur resmi perpanjangan — identitas
         sertifikatnya tidak ikut dikirim, jadi tidak bisa berubah tak sengaja. */
      await api.put(`hr/certificates/${row.id}`, values);
      toast('Tanggal kedaluwarsa diperbarui — pengingatnya berhenti.');
      reload();
    } catch (error) {
      toastError(error);
    }
  };

  const ubahSertifikat = (row) => (event) => {
    event.stopPropagation();
    openForm({ def: certDef, key: 'hr/certificates', row, onSaved: reload });
  };

  const hapusSertifikat = (row) => async (event) => {
    event.stopPropagation();
    await confirmDialog({
      title: 'Hapus sertifikat',
      message: `Hapus "${row.name}" dari register? Pengingat kedaluwarsanya ikut berhenti; `
        + 'barisnya disimpan sebagai soft delete untuk jejak audit.',
      confirmLabel: 'Hapus',
      onConfirm: async () => {
        await api.del(`hr/certificates/${row.id}`);
        toast('Sertifikat dihapus dari register.');
        reload();
      },
    });
  };

  const isiPkwt = (row) => async (event) => {
    event.stopPropagation();
    const values = await promptFields(`Akhir PKWT — ${row.name}`, [
      {
        key: 'pkwt_basis', label: 'Dasar PKWT', type: 'select', required: true,
        default: row.pkwt_basis || 'jangka_waktu',
        options: [
          { value: 'jangka_waktu', label: 'Jangka waktu tertentu' },
          { value: 'selesainya_pekerjaan', label: 'Selesainya pekerjaan tertentu' },
        ],
        help: 'PKWT selesainya pekerjaan sah tanpa tanggal akhir (PP 35/2021 Pasal 9) — '
          + 'jangan mengarang tanggal hanya untuk mendiamkan pengingat.',
      },
      {
        // Tidak required: dasar selesainya-pekerjaan memang tanpa tanggal.
        // Server yang menegakkan pasangan sahnya, bukan dialog ini.
        key: 'pkwt_end_date', label: 'Tanggal akhir PKWT', type: 'date',
        default: row.pkwt_end_date,
        help: `Wajib untuk jangka waktu; harus setelah tanggal masuk (${fmt.date(row.join_date)}). `
          + 'PKWT yang lewat tanggalnya demi hukum menjadi PKWTT — PP 35/2021.',
      },
    ], { submitLabel: 'Simpan' });

    if (values === null) return;

    try {
      await api.put(`hr/employees/${row.id}`, values);
      toast('PKWT tersimpan.');
      reload();
    } catch (error) {
      toastError(error);
    }
  };

  /* ------------------------------------------------------ kartu sertifikat */
  const certCard = el('.card', [
    el('.card-head', [
      el('h2', { text: 'Sertifikat keahlian' }),
      el('.cell-sub', {
        text: certs.length < certTotal
          ? `${certs.length} dari ${certTotal} ditampilkan · paling cepat kedaluwarsa di atas`
          : `${certs.length} sertifikat · paling cepat kedaluwarsa di atas`,
      }),
    ]),
  ]);

  if (!certs.length) {
    certCard.appendChild(emptyState(
      'SKK Konstruksi, Sertifikat K3, dan sertifikasi principal dicatat di sini supaya '
      + `kedaluwarsanya diingatkan ${LEAD_DAYS} hari di muka. SKK yang lewat menaikkan PPh `
      + 'final pelaksanaan dari 2,65% ke 4,00% — selisih 1,35 poin dari setiap tagihan.',
      {
        title: 'Register masih kosong',
        action: canCreate
          ? button('Tambah Sertifikat', {
            variant: 'primary', iconName: 'plus',
            onClick: () => openForm({ def: certDef, key: 'hr/certificates', onSaved: reload }),
          })
          : null,
      },
    ));
  } else {
    certCard.appendChild(el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Karyawan' }),
        el('th', { text: 'Sertifikat' }),
        el('th', { text: 'Jenis' }),
        el('th', { text: 'Kedaluwarsa' }),
        el('th.right', { text: 'Sisa' }),
        el('th', { text: '' }),
      ])),
      /* Server sudah mengurutkan yang paling cepat kedaluwarsa di atas dan yang
         tanpa tanggal di bawah; jangan diurut ulang di sini. */
      el('tbody', certs.map((row) => {
        const days = row.days_to_expiry;
        const tier = tingkat(days);
        const [tierLabel, tierTone] = TIER_BADGE[tier] || [];
        const employee = row.employee || {};
        const sub = [row.number, row.issuer].filter(Boolean).join(' · ');

        const tr = el(`tr${certDef ? '.clickable' : ''}`, [
          el('td', el('span', [
            el('span.cell-main', { text: employee.name || '—' }),
            employee.code ? el('span.cell-sub.mono', { text: employee.code }) : null,
          ])),
          el('td', el('span', [
            el('span.cell-main', { text: row.name }),
            sub ? el('span.cell-sub', { text: sub }) : null,
          ])),
          el('td', { text: row.certificate_type_label || row.certificate_type || '—' }),
          el('td', row.expiry_date
            ? el('span', [
              el('span', { text: fmt.date(row.expiry_date) }),
              tierLabel ? el('div', badge(tierLabel, tierTone)) : null,
            ])
            : el('span.muted', {
              text: 'Tidak kedaluwarsa',
              title: 'Tanpa tanggal kedaluwarsa — tidak pernah diingatkan',
            })),
          el('td.right.num.strong', {
            text: umur(days, '—'),
            style: { color: warnaUmur(days) },
          }),
          el('td.right', el('div', { style: { display: 'flex', gap: '4px', justifyContent: 'flex-end' } }, [
            canUpdate ? button('Perpanjang', {
              size: 'sm',
              variant: tier === 'lewat' || tier === 'menipis' ? 'primary' : 'ghost',
              onClick: perpanjang(row),
            }) : null,
            canUpdate && certDef
              ? button('', { size: 'sm', variant: 'ghost', iconName: 'edit', title: 'Ubah', onClick: ubahSertifikat(row) })
              : null,
            canDelete
              ? button('', { size: 'sm', variant: 'ghost', iconName: 'trash', title: 'Hapus', onClick: hapusSertifikat(row) })
              : null,
          ])),
        ]);

        if (certDef) {
          /* Halaman detailnya adalah tempat lampiran hidup — pindaian SKK/K3
             (PDF) ditempel di sana, bukan di register ini. */
          tr.addEventListener('click', () => navigate(`d/hr/certificates/${row.id}`));
        }
        return tr;
      })),
    ])));
  }
  body.appendChild(certCard);

  /* ------------------------------------------------------------ kartu PKWT */
  const pkwtCard = el('.card', [
    el('.card-head', [
      el('h2', { text: 'PKWT karyawan kontrak' }),
      el('.cell-sub', { text: `${kontrak.length} karyawan kontrak aktif` }),
    ]),
  ]);

  if (!kontrak.length) {
    pkwtCard.appendChild(el('.card-body', el('.alert.info',
      'Tidak ada karyawan kontrak aktif. Tanggal akhir PKWT hanya berlaku untuk status '
      + 'kerja kontrak — karyawan tetap dan harian tidak diawasi.')));
  } else {
    pkwtCard.appendChild(el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Karyawan' }),
        el('th', { text: 'Jabatan' }),
        el('th', { text: 'Masuk' }),
        el('th', { text: 'Akhir PKWT' }),
        el('th.right', { text: 'Sisa' }),
        el('th', { text: '' }),
      ])),
      el('tbody', kontrak.map((row) => {
        const days = row.pkwt_days;
        const tier = tingkat(days);
        const [tierLabel, tierTone] = TIER_BADGE[tier] || [];

        const tr = el('tr.clickable', [
          el('td', el('span', [
            el('span.cell-main', { text: row.name }),
            row.code ? el('span.cell-sub.mono', { text: row.code }) : null,
          ])),
          el('td', { text: row.position || '—' }),
          el('td', { text: fmt.date(row.join_date) }),
          el('td', row.pkwt_end_date
            ? el('span', [
              el('span', { text: fmt.date(row.pkwt_end_date) }),
              tierLabel ? el('div', badge(tierLabel, tierTone)) : null,
            ])
            // Selesainya pekerjaan sah tanpa tanggal — abu-abu, bukan alarm.
            : (row.pkwt_basis === 'selesainya_pekerjaan'
              ? el('span', badge('Selesainya pekerjaan', ''))
              : el('span', badge('Tanpa tanggal', 'red')))),
          el('td.right.num.strong', {
            text: row.pkwt_end_date ? umur(days, '—')
              : (row.pkwt_basis === 'selesainya_pekerjaan' ? '—' : 'tidak tercatat'),
            style: {
              color: row.pkwt_end_date ? warnaUmur(days)
                : (row.pkwt_basis === 'selesainya_pekerjaan' ? 'var(--text)' : 'var(--danger)'),
            },
          }),
          el('td.right', canUpdate
            ? button(row.pkwt_end_date || row.pkwt_basis === 'selesainya_pekerjaan' ? 'Ubah PKWT' : 'Isi tanggal', {
              size: 'sm',
              variant: (row.pkwt_end_date && tier === 'aman') || (!row.pkwt_end_date && row.pkwt_basis === 'selesainya_pekerjaan')
                ? 'ghost' : 'primary',
              onClick: isiPkwt(row),
            })
            : null),
        ]);

        tr.addEventListener('click', () => navigate(`d/hr/employees/${row.id}`));
        return tr;
      })),
    ])));
  }
  body.appendChild(pkwtCard);

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', {
        text: `Setiap pagi pukul 08.30 WIB pengawas tenggat memindai register ini: tanggal yang tersisa ${LEAD_DAYS} hari atau kurang masuk MENIPIS, yang sudah lewat masuk LEWAT, dan karyawan kontrak tanpa tanggal akhir PKWT dihitung alarm data. Pemberitahuannya dikirim ke pemegang izin ubah-SDM — menipis diingatkan ulang paling cepat 7 hari, lewat paling cepat 3 hari.`,
      }),
      el('p', {
        text: 'Perpanjangan = ubah tanggal kedaluwarsa sertifikat; sertifikat yang tidak dipertahankan = hapus dari register. Keduanya seketika menghentikan pengingatnya. Sertifikat tanpa tanggal kedaluwarsa tidak pernah diingatkan.',
      }),
      el('p', {
        text: 'Tanggal akhir PKWT hanya untuk karyawan kontrak — pindah ke tetap/harian otomatis mengosongkan tanggalnya. PKWT yang dibiarkan lewat demi hukum menjadi PKWTT (PP 35/2021), dan SKK yang kedaluwarsa menaikkan PPh final pelaksanaan dari 2,65% ke 4,00%.',
      }),
    ]),
  ]));

  body.appendChild(el('.row-actions', [
    button('Buka daftar karyawan', { iconName: 'chevron', onClick: () => navigate('r/hr/employees') }),
    button('Lihat semua tenggat', { iconName: 'chevron', onClick: () => navigate('tenggat') }),
  ]));
}
