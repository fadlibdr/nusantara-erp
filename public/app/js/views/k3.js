/* Laporan K3 (SMK3) — the monthly safety report a project owes its client.
   Read-only over the incident register; nothing here writes. */

import { api } from '../api.js';
import { el, clear, button, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor } from '../lookup.js';

const state = {
  projectId: '',
  from: firstOfThisMonth(),
  to: today(),
};

function today() {
  return new Date().toISOString().slice(0, 10);
}

function firstOfThisMonth() {
  return `${today().slice(0, 7)}-01`;
}

/**
 * A rate the report could not compute reads as "—", never as 0,00.
 *
 * A site that filed no daily reports has an UNKNOWN frequency rate, not a
 * perfect one, and a zero on a client's report is a lie with a decimal point.
 */
function rate(value) {
  return value === null || value === undefined ? '—' : fmt.num(value, 2);
}

function statCard(label, value, hint, alarming = false) {
  return el('.stat', [
    el('.label', { text: label }),
    el('.value.sm', { text: value }),
    hint ? el(`.delta${alarming ? '.down' : ''}`, { text: hint }) : null,
  ]);
}

function tallyTable(title, rows, emptyText) {
  if (!rows.length) {
    return el('.card', [
      el('.card-head', el('h2', { text: title })),
      el('.card-body', el('p.muted', { text: emptyText })),
    ]);
  }

  const total = rows.reduce((sum, row) => sum + row.count, 0);

  return el('.card', [
    el('.card-head', el('h2', { text: title })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [el('th', { text: 'Kategori' }), el('th.right', { text: 'Jumlah' }), el('th.right', { text: 'Porsi' })])),
      el('tbody', rows.map((row) => el('tr', [
        el('td', { text: row.label }),
        el('td.right.num', { text: String(row.count) }),
        el('td.right.num', { text: `${fmt.num(row.count * 100 / total, 0)}%` }),
      ]))),
    ])),
  ]);
}

async function projectSelect(onChange, value) {
  const select = el('select.filter-w', { 'aria-label': 'Proyek' });
  select.appendChild(el('option', { value: '', text: 'Semua proyek' }));
  const rows = await loadSource('projects').catch(() => []);
  optionsFor('projects', rows).forEach((option) =>
    select.appendChild(el('option', { value: option.value, text: option.label })));
  select.value = value || '';
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

function dateInput(label, value, onChange) {
  const input = el('input.filter-w', { type: 'date', value, title: label, 'aria-label': label });
  input.addEventListener('change', () => onChange(input.value));
  return input;
}

export async function renderK3(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Laporan K3' }),
      el('.desc', { text: 'Statistik kecelakaan kerja dari register SMK3, sesuai PP 50/2012 dan Permen PUPR 10/2021.' }),
    ]),
    el('.actions', [button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() })]),
  ]));

  const filters = el('.filters');
  filters.appendChild(await projectSelect((value) => { state.projectId = value; load(); }, state.projectId));
  filters.appendChild(dateInput('Dari tanggal', state.from, (value) => { state.from = value; load(); }));
  filters.appendChild(dateInput('Sampai tanggal', state.to, (value) => { state.to = value; load(); }));
  host.appendChild(filters);

  const body = el('div');
  host.appendChild(body);

  async function load() {
    clear(body).appendChild(skeletonTable(4));

    let report;
    try {
      report = await api.get('projects/safety-incidents/statistics', {
        project_id: state.projectId || undefined,
        from: state.from,
        to: state.to,
      });
    } catch (error) {
      return clear(body).appendChild(errorState(error, load));
    }

    clear(body);

    body.appendChild(el('.stat-row', [
      statCard('Total insiden', String(report.incident_count), 'termasuk near miss'),
      statCard('Tercatat (recordable)', String(report.recordable_count), 'dasar frequency rate'),
      statCard('Hari kerja hilang', String(report.lost_days)),
      statCard('Fatal', String(report.fatalities),
        report.fatalities > 0 ? 'wajib dilaporkan ke Disnaker' : null, true),
    ]));

    body.appendChild(el('.stat-row', [
      statCard('Frequency rate', rate(report.frequency_rate), 'per 1 juta jam kerja'),
      statCard('Severity rate', rate(report.severity_rate), 'hari hilang per 1 juta jam'),
      statCard('Jam kerja orang', fmt.num(report.man_hours, 0),
        `${fmt.num(report.man_hours_basis.man_days, 0)} man-days × ${report.man_hours_basis.hours_per_day} jam`),
      statCard('Hari sejak insiden terakhir',
        report.days_since_last_recordable === null ? '—' : String(report.days_since_last_recordable),
        'kecelakaan tercatat'),
    ]));

    // Where the rate comes from, said plainly. A reader who does not know the
    // denominator rests on daily reports having been filed will over-trust it.
    if (report.man_hours === 0) {
      body.appendChild(el('.alert.warn',
        'Belum ada laporan harian pada periode ini, sehingga jam kerja orang tidak diketahui dan '
        + 'frequency/severity rate tidak dapat dihitung. Insiden tetap tercatat.'));
    } else {
      body.appendChild(el('.alert.info',
        `Jam kerja orang dihitung dari laporan harian (${report.man_hours_basis.source}): `
        + `${fmt.num(report.man_hours_basis.man_days, 0)} man-days × ${report.man_hours_basis.hours_per_day} jam per hari. `
        + 'Laporan harian yang belum diisi membuat rate tampak lebih buruk daripada seharusnya.'));
    }

    if (report.open_count > 0 || report.overdue_count > 0) {
      body.appendChild(el('.stat-row', [
        statCard('Tindak lanjut terbuka', String(report.open_count)),
        statCard('Tindak lanjut lewat target', String(report.overdue_count),
          report.overdue_count > 0 ? 'melewati target selesai' : null, true),
        statCard('Orang terlibat', String(report.people_involved)),
      ]));
    }

    body.appendChild(tallyTable('Menurut keparahan', report.by_severity, 'Tidak ada insiden pada periode ini.'));
    body.appendChild(tallyTable('Menurut jenis kejadian', report.by_category, 'Tidak ada insiden pada periode ini.'));

    body.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Cara membaca' })),
      el('.card-body', [
        el('p', { text: 'Frequency rate (FR) = kecelakaan tercatat × 1.000.000 ÷ jam kerja orang.' }),
        el('p', { text: 'Severity rate (SR) = hari kerja hilang × 1.000.000 ÷ jam kerja orang.' }),
        el('p', {
          text: 'Near miss dan P3K sengaja tidak masuk hitungan FR: keduanya wajib dicatat, '
            + 'tetapi menghitungnya akan membuat lokasi yang jujur melapor tampak lebih buruk '
            + 'daripada lokasi yang tidak melapor sama sekali.',
        }),
      ]),
    ]));

    body.appendChild(el('.row-actions', [
      button('Buka register insiden', {
        iconName: 'chevron',
        onClick: () => { window.location.hash = '#/r/projects/safety-incidents'; },
      }),
    ]));
  }

  await load();
}
