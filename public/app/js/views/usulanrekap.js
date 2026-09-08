/* Usulan Rekap Absensi (F-4) — angka register bulan itu, ditawarkan kepada HR.
 *
 * Layar ini TIDAK menyimpan apa pun. Ia membaca hitungan dari register dan
 * menyodorkannya sebagai isian awal formulir rekap yang sudah ada; yang menekan
 * Simpan tetap manusia, lewat izin hr.create yang sudah ada, di endpoint yang
 * sudah ada.
 *
 * Alasannya bukan kehati-hatian yang samar. Register absensi boleh dikoreksi
 * kapan saja — F-4 justru menambah pintu koreksinya — sedangkan payroll yang
 * sudah disetujui sudah membukukan jurnal dan membayar orang. Jalur otomatis
 * dari yang pertama ke yang kedua berarti mengetik ulang absen bulan lalu
 * menggerakkan uang yang sudah keluar.
 *
 * Kolom yang TIDAK diusulkan ditulis di layar beserta alasannya, bukan
 * dikosongkan diam-diam: sakit dan cuti hidup di pengajuan cuti dengan
 * persetujuannya sendiri, hari kerja butuh kalender yang register tidak punya,
 * lembur bukan kehadiran, dan rekap bulanan tidak punya kolom setengah hari
 * sama sekali. Mengisi keempatnya dengan 0 akan terlihat seperti jawaban dan
 * terbawa ke slip gaji sebagai hak yang hilang.
 */

import { api, session } from '../api.js';
import { el, clear, button, badge, errorState, emptyState, skeletonTable, toast, field } from '../ui.js';
import * as fmt from '../format.js';
import { RESOURCES } from '../schema.js';
import { openForm } from './form.js';

const today = new Date();
const state = { year: today.getFullYear(), month: today.getMonth() + 1 };

export async function renderUsulanRekap(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Usulan Rekap Absensi' }),
      el('.desc', {
        text: 'Hitungan dari register absensi harian, ditawarkan sebagai isian awal rekap bulanan. '
          + 'Layar ini tidak menyimpan apa pun sendiri — rekap tetap dokumen yang Anda periksa dan simpan.',
      }),
    ]),
  ]));

  const yearInput = el('input', { type: 'number', min: '2000', max: '2100', value: String(state.year) });
  const monthSelect = el('select', fmt.MONTHS.map((label, index) => el('option', {
    value: String(index + 1), text: label,
  })));
  monthSelect.value = String(state.month);

  const controls = el('.card', el('.card-body', {
    style: { display: 'flex', gap: '12px', flexWrap: 'wrap', alignItems: 'flex-end' },
  }, [field('Tahun', yearInput), field('Bulan', monthSelect)]));

  const body = el('div');
  host.append(controls, body);

  yearInput.addEventListener('change', () => { state.year = Number(yearInput.value) || state.year; load(); });
  monthSelect.addEventListener('change', () => { state.month = Number(monthSelect.value); load(); });

  /* Nomor urut muatan: dua penggantian bulan beruntun mengirim dua permintaan,
     dan yang berangkat lebih dulu boleh mendarat belakangan — tabel bulan lama
     lalu menimpa bulan yang tertulis di kotak. Idiom yang sama dengan
     absensi.js. */
  let loadToken = 0;

  async function load() {
    const token = ++loadToken;
    clear(body).appendChild(skeletonTable(6, 6));

    let payload;
    try {
      payload = await api.get('hr/attendance-recaps/proposal', {
        period_year: state.year,
        period_month: state.month,
      });
    } catch (error) {
      if (token !== loadToken) return;
      clear(body).appendChild(errorState(error, load));
      return;
    }

    if (token !== loadToken) return;
    clear(body);

    body.appendChild(el('.card', [
      el('.card-head', [el('h2', { text: 'Yang tidak diusulkan — dan kenapa' }), el('.spacer')]),
      /* Label datang dari server bersama alasannya. Layar yang menyusun
         labelnya sendiri dari nama kolom akan menampilkan "sick_days" kepada
         orang yang sedang memutuskan gaji seseorang. */
      el('.card-body', payload.not_proposed.map((entry) => el('.cell-sub', {
        text: `${entry.label}: ${entry.why}`,
      }))),
    ]));

    if (!payload.rows.length) {
      /* Nol baris BUKAN "semua orang absen": tidak ada satu pun catatan bulan
         itu. Kalimatnya harus mengatakan yang kedua, karena yang pertama akan
         mendorong orang membuat rekap nol untuk seluruh karyawan. */
      body.appendChild(emptyState(
        `Belum ada satu pun absensi tercatat pada ${payload.period.label}. `
        + 'Itu berarti registernya kosong untuk bulan ini — bukan berarti tidak ada yang masuk kerja.',
        { title: 'Register bulan ini kosong' },
      ));
      return;
    }

    const canCreate = session.can('hr.create');
    const def = RESOURCES['hr/attendance-recaps'];

    const table = el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Karyawan' }),
        el('th', { text: 'Tercatat' }),
        el('th', { text: 'Hadir' }),
        el('th', { text: '½ hari' }),
        el('th', { text: 'Absen' }),
        el('th', { text: 'Absen ponsel' }),
        el('th', { text: '' }),
      ])),
      el('tbody', payload.rows.map((row) => el('tr', [
        el('td', [
          el('span.cell-main', { text: row.employee_name }),
          el('span.cell-sub.mono', { text: row.employee_code }),
        ]),
        el('td', { text: String(row.recorded_days) }),
        el('td', { text: String(row.present_days) }),
        el('td', { text: String(row.half_days) }),
        el('td', { text: String(row.absent_days) }),
        el('td', [
          el('span.cell-main', { text: `${row.clocked_days} hari` }),
          /* Dua angka yang TIDAK boleh dijumlahkan: "di luar lokasi" hanya
             untuk hari yang jaraknya benar-benar terukur, "tak terukur" untuk
             hari yang tidak. Baris kerani murni tidak masuk keduanya. */
          el('span.cell-sub', {
            /* "semua di dalam radius" untuk NOL absen ponsel adalah kalimat
               yang membaca seperti pujian tentang orang yang tidak pernah
               menekan tombolnya sama sekali (terukur 8 Sep 2026: baris kerani
               murni berbunyi "0 hari · semua di dalam radius"). */
            text: row.clocked_days === 0
              ? 'tidak ada absen dari ponsel bulan ini'
              : ([
                row.outside_days ? `${row.outside_days} di luar lokasi` : null,
                row.unmeasured_days ? `${row.unmeasured_days} tanpa jarak terukur` : null,
              ].filter(Boolean).join(' · ') || 'semuanya di dalam radius'),
          }),
        ]),
        el('td', row.has_recap
          ? badge('Rekap sudah ada', 'green')
          : (canCreate ? button('Buat rekap', {
            size: 'sm',
            iconName: 'plus',
            onClick: () => openForm({
              def,
              key: 'hr/attendance-recaps',
              /* Hanya yang register benar-benar tahu. Kolom lain sengaja
                 dibiarkan kosong supaya orang mengisinya — nol yang
                 disodorkan formulir akan disimpan sebagai nol yang diputuskan. */
              prefill: {
                employee_id: row.employee_id,
                period_year: payload.period.year,
                period_month: payload.period.month,
                present_days: row.present_days,
                alpha_days: row.absent_days,
              },
              onSaved: () => { toast('Rekap tersimpan.'); load(); },
            }),
          }) : el('span.muted', { text: '—' }))),
      ]))),
    ]);

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: `Register ${payload.period.label}` }),
        el('.spacer'),
        el('span.muted', { text: `${payload.rows.length} karyawan tercatat` }),
      ]),
      el('.table-wrap', table),
      canCreate ? null : el('.card-body', el('p.muted', {
        text: 'Anda tidak memiliki izin hr.create — layar ini hanya membaca.',
        style: { margin: 0 },
      })),
    ].filter(Boolean)));
  }

  await load();
}
