/* Timesheet & Lembur dari Absensi (F-5) — turunan, bukan putusan.
 *
 * Layar ini membaca jam masuk/pulang yang ditulis F-4 dan menghitung: jam
 * kerja, keterlambatan, dan jam lembur menurut kebijakan yang berlaku hari itu.
 * Ia TIDAK menyimpan apa pun dan TIDAK menulis rekap: ILB (Izin Lembur) tetap
 * otoritatif atas jam lembur yang dibayar, dan yang ditampilkan di sini adalah
 * USULAN dan PEMBANDING.
 *
 * TIGA HAL YANG MEMBENTUK SELURUH TAMPILAN INI.
 *
 * 1. KOSONG BUKAN NOL, DAN NOL BUKAN KOSONG. Produksi memegang nol baris
 *    absensi pada hari paket ini ditulis, jadi layar ini pertama kali dilihat
 *    orang dalam keadaan kosong. Periode tanpa satu pun catatan menampilkan
 *    KALIMAT, bukan tabel berisi "0 jam" untuk delapan orang — angka nol di
 *    sebelah nama seseorang adalah tuduhan. Sebaliknya, hari yang diukur penuh
 *    dan memang tidak berlembur menampilkan 0 yang sungguhan.
 *
 * 2. SEL KOSONG UNTUK YANG TIDAK DIUKUR (pelajaran Fase 1, dan aturan ekspor
 *    rumah ini): sel bergaris '—' dengan title yang menyebut sebabnya, tidak
 *    pernah 0. Di CSV ia benar-benar kosong.
 *
 * 3. DUA BATAS KEJUJURAN DICETAK DI ATAS ANGKANYA, dari server: sistem ini
 *    tidak punya kalender hari libur nasional (policy.holidays_known), dan
 *    tarif akhir pekan/hari libur Kepmenaker (2x/3x/4x) tidak dibangun — jam
 *    pada hari non-kerja ditampilkan tetapi tidak pernah diusulkan sebagai
 *    lembur hari kerja.
 */

import { api } from '../api.js';
import { el, clear, button, badge, field, errorState, emptyState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { toCsv, downloadCsv, csvFilename } from '../csv.js';

const today = new Date();
const state = { year: today.getFullYear(), month: today.getMonth() + 1, employeeId: null };

/* ------------------------------------------------------------------ format */

/** Menit menjadi "7j 30m". null → null, dan pemanggilnya yang memutuskan tampilan. */
function hoursText(minutes) {
  if (minutes === null || minutes === undefined) return null;
  const total = Math.round(Number(minutes));
  const sign = total < 0 ? '-' : '';
  const abs = Math.abs(total);
  const h = Math.floor(abs / 60);
  const m = abs % 60;
  if (!h) return `${sign}${m}m`;
  return m ? `${sign}${h}j ${m}m` : `${sign}${h}j`;
}

/** Jam desimal menjadi "3 jam" / "2,5 jam". */
function hoursDecimal(value) {
  if (value === null || value === undefined) return null;
  return `${fmt.num(value, 2)} jam`;
}

/**
 * Sel yang BOLEH kosong. `reason` menjadi title-nya, jadi sebuah garis selalu
 * bisa ditanyai kenapa ia garis.
 */
function cell(text, reason, extraClass = '') {
  if (text === null || text === undefined || text === '') {
    return el(`td${extraClass}.right`, { 'data-empty': 'true', title: reason || undefined },
      el('span.muted', { text: '—' }));
  }
  return el(`td${extraClass}.right.num`, { text, title: reason || undefined });
}

/* -------------------------------------------------------------- kebijakan */

function policyCard(policy) {
  const rules = [
    `Jam kerja normal ${policy.normal_hours_per_day} jam/hari, mulai ${policy.day_start}.`,
    /* Kalimat ini menjawab pertanyaan pertama yang layar ini akan terima:
       kenapa 08:00–17:00 bukan sembilan jam kerja. Potongan istirahat yang
       tidak disebutkan adalah potongan yang tidak bisa diperiksa siapa pun. */
    policy.break_minutes
      ? `Istirahat ${policy.break_minutes} menit TIDAK dihitung jam kerja (UU 13/2003 Ps. 79) — `
        + `dipotong dari rentang masuk→pulang sejauh rentang itu melewati ${Math.round(policy.break_after_minutes / 60)} jam, `
        + 'sebelum lembur dihitung.'
      : 'Istirahat tidak dipotong sama sekali (setelannya kosong): seluruh rentang masuk→pulang '
        + 'dihitung jam kerja, jadi hari kerja 08:00–17:00 menghasilkan satu jam lembur.',
    `Terlambat dihitung sesudah toleransi ${policy.late_tolerance_minutes} menit.`,
    `Lembur dibulatkan ke ${policy.rounding_minutes} menit terdekat, minimum ${policy.overtime_minimum_minutes} menit.`,
    `Batas Kepmenaker ${policy.overtime_daily_cap_hours} jam/hari dan ${policy.overtime_weekly_cap_hours} jam/pekan — `
      + 'dilampaui berarti DITANDAI, bukan dipotong.',
    /* SYARATNYA ikut, bukan hanya tarifnya. Tarif jam berikutnya hanya menyala
       bila total rincian harian sama persis dengan rekap bulanan yang dibayar —
       dan pada alur ILB, yang layar ini sendiri sebut otoritatif, keduanya
       jarang sama. Mencetak "2x jam berikutnya" sebagai fakta di layar yang
       dibuka untuk tukang berarti menjanjikan tarif yang tidak akan ia terima. */
    `Tarif: ${fmt.num(policy.overtime_first_hour_pct / 100, 2)}x jam pertama tiap hari lembur, `
      + `${fmt.num(policy.overtime_next_hours_pct / 100, 2)}x jam berikutnya — TETAPI hanya bila total `
      + 'lembur turunan di layar ini sama persis dengan rekap bulanan yang dibayar. Bila berbeda '
      + `(mis. rekap mengikuti ILB), SELURUH lembur periode itu dibayar ${fmt.num(policy.overtime_first_hour_pct / 100, 2)}x `
      + 'dan slipnya menyebutkan sebabnya.',
  ];

  /* Dua batas yang harus terbaca SEBELUM angkanya, bukan sesudah. Layar yang
     menampilkan jam lembur tanpa menyebut bahwa hari libur tidak dihitung akan
     dibaca sebagai "inilah seluruh lembur bulan ini". */
  const limits = [
    'Tarif akhir pekan/hari libur Kepmenaker (2x/3x/4x sejak jam pertama) BELUM dibangun. '
      + 'Jam pada hari non-kerja tetap dihitung dan ditampilkan, tetapi tidak pernah diusulkan '
      + 'sebagai lembur hari kerja — memakai tarif hari kerja untuknya berarti membayar kurang.',
    policy.holidays_known
      ? null
      : 'Sistem ini tidak punya kalender hari libur nasional. Hari non-kerja ditentukan pola pekan '
        + `(${policy.workweek_days} hari kerja), jadi hari libur nasional terbaca sebagai hari kerja biasa.`,
    /* BATAS KETIGA, ditambahkan pada putaran verifikasi: sistem ini hanya punya
       SATU jam mulai kerja untuk seluruh perusahaan, jadi shift malam tidak bisa
       dinilai keterlambatannya sama sekali. Sebelumnya pekerja shift malam yang
       datang tepat waktu dibaca "Terlambat 13j 50m" setiap hari — di layar yang
       dibuat supaya ia bisa membantah. */
    `Sistem ini hanya punya SATU jam mulai kerja (${policy.day_start}) untuk seluruh perusahaan. `
      + 'Hari yang jam masuknya jatuh jauh di luar jendela itu — shift malam, misalnya — '
      + 'keterlambatannya TIDAK diukur sama sekali; jam kerja dan lemburnya tetap terukur.',
    'Angka di layar ini adalah USULAN dari register absensi. ILB (Izin Lembur) tetap otoritatif '
      + 'atas jam lembur yang dibayar; rekap bulanan tetap dokumen yang diperiksa dan disimpan HR.',
  ].filter(Boolean);

  return el('.card.timesheet-policy', [
    el('.card-head', [el('h2', { text: 'Kebijakan yang dipakai menghitung' }), el('.spacer')]),
    el('.card-body', [
      el('ul.timesheet-rules', { style: { margin: '0 0 12px', paddingLeft: '18px' } },
        rules.map((text) => el('li.cell-sub', { text }))),
      ...limits.map((text) => el('p.cell-sub.timesheet-limit', {
        style: { margin: '0 0 8px', color: 'var(--warning)' }, text,
      })),
    ]),
  ]);
}

function postedBanner(period) {
  if (!period.payroll_posted) return null;

  /* MAJU-SAJA, dikatakan sebelum orang mencoba: payroll periode ini sudah
     disetujui/ditutup, jadi apa pun yang diterapkan ke rekapnya sekarang tidak
     akan mengubah slip yang sudah terbit. */
  return el('.alert.warn.timesheet-posted', {
    text: `Payroll ${period.label} sudah disetujui atau ditutup. Slip yang sudah terbit tidak `
      + 'berubah oleh angka di layar ini, dan mengubah rekapnya sekarang tidak akan menghitung '
      + 'ulang gaji yang sudah dibayarkan.',
  });
}

/* ------------------------------------------------------ tabel per pegawai */

const PERIOD_COLUMNS = [
  { key: 'employee_code', label: 'Kode' },
  { key: 'employee_name', label: 'Karyawan' },
  { key: 'measured_days', label: 'Hari terukur' },
  { key: 'half_measured_days', label: 'Hari setengah terukur' },
  { key: 'unrecorded_days', label: 'Hari tanpa cap jam' },
  /* TIGA kolom, bukan satu: rentang yang benar-benar terukur, istirahat yang
     dipotong darinya, dan jam kerja yang tersisa. Satu kolom saja memaksa
     pembaca Excel menebak yang mana — dan yang menjadi lembur adalah yang
     ketiga. */
  { key: 'worked_minutes', label: 'Menit masuk→pulang' },
  { key: 'break_minutes', label: 'Menit istirahat' },
  { key: 'net_worked_minutes', label: 'Menit kerja' },
  { key: 'late_minutes', label: 'Menit terlambat' },
  { key: 'overtime_hours', label: 'Lembur turunan (jam)' },
  { key: 'permit_hours', label: 'ILB disetujui (jam)' },
  { key: 'delta_hours', label: 'Selisih (jam)' },
  { key: 'non_working_measured_minutes', label: 'Menit kerja pada hari non-kerja' },
];

function deltaCell(row) {
  if (row.delta_hours === null || row.delta_hours === undefined) {
    return cell(null, row.permit_hours === null
      ? 'Tidak ada ILB yang disetujui pada periode ini, jadi tidak ada yang bisa dibandingkan.'
      : 'Tidak ada satu hari pun yang terukur, jadi tidak ada turunan yang bisa dibandingkan.');
  }

  const value = Number(row.delta_hours);
  if (value === 0) {
    return el('td.right.num', { text: '0', title: 'Turunan absensi dan ILB berjumlah sama.' });
  }

  /* Berapa DAN ke arah mana — dan tidak lebih dari itu. Siapa yang menang
     bukan keputusan layar ini. */
  return el('td.right.num.timesheet-delta', {
    'data-direction': value > 0 ? 'lebih' : 'kurang',
    style: { color: 'var(--warning)' },
    title: value > 0
      ? 'Absensi mengukur LEBIH banyak daripada jam ILB yang disetujui.'
      : 'Absensi mengukur LEBIH SEDIKIT daripada jam ILB yang disetujui.',
    text: `${value > 0 ? '+' : ''}${fmt.num(value, 2)}`,
  });
}

/* Lencana pekan yang melewati batas — DENGAN keterangan pekan mana dan berapa
   menitnya jatuh di bulan sebelah. Pekan ISO yang terbelah antara dua bulan
   dihitung UTUH (TimesheetService::weeksOverCap), jadi angkanya tidak akan
   cocok dengan tabel harian di layar ini, yang hanya memuat hari bulan ini —
   dan selisih yang tidak dijelaskan akan dibaca sebagai kesalahan. */
function weeklyCapBadge(row, policy) {
  const weeks = row.weeks_over_weekly_cap;
  if (!weeks.length) return null;

  const node = badge(`${weeks.length} pekan > ${policy.overtime_weekly_cap_hours} jam`, 'amber');
  node.title = weeks
    .map((week) => `${week.week}: ${hoursText(week.minutes)}`
      + (week.spans_periods
        ? ` (termasuk ${hoursText(week.minutes_outside_period)} pada bulan sebelah — pekan ISO ini terbelah)`
        : ''))
    .join(' · ');

  return node;
}

function periodTable(payload, onPick) {
  return el('.table-wrap', el('table.data.timesheet', [
    el('thead', el('tr', [
      el('th', { text: 'Karyawan' }),
      el('th.right', { text: 'Hari terukur' }),
      el('th.right', { text: 'Jam kerja' }),
      el('th.right', { text: 'Terlambat' }),
      el('th.right', { text: 'Lembur turunan' }),
      el('th.right', { text: 'ILB disetujui' }),
      el('th.right', { text: 'Selisih' }),
      el('th', { text: '' }),
    ])),
    el('tbody', payload.rows.map((row) => el('tr', { 'data-employee': row.employee_code }, [
      el('td', [
        el('span.cell-main', { text: row.employee_name }),
        el('span.cell-sub.mono', { text: row.employee_code }),
        row.half_measured_days
          ? el('span.cell-sub.timesheet-half', {
            style: { display: 'block', color: 'var(--warning)', whiteSpace: 'normal' },
            text: `${row.half_measured_days} hari hanya punya satu cap jam — belum terukur, bukan nol. `
              + 'Lengkapi lewat Absensi Harian → Koreksi.',
          })
          : null,
        row.non_working_measured_minutes
          ? el('span.cell-sub.timesheet-holiday', {
            style: { display: 'block', whiteSpace: 'normal' },
            text: `${hoursText(row.non_working_measured_minutes)} jam kerja tercatat pada hari non-kerja, `
              + 'tidak diusulkan sebagai lembur.',
          })
          : null,
      ]),
      el('td.right.num', { text: String(row.measured_days) }),
      cell(hoursText(row.net_worked_minutes), 'Tidak ada satu hari pun dengan dua cap jam pada periode ini.'),
      cell(hoursText(row.late_minutes), 'Tidak ada satu hari pun dengan cap jam masuk pada periode ini.'),
      el('td.right.num.timesheet-ot', {
        'data-empty': String(row.overtime_hours === null),
        'data-over-cap': String(Boolean(row.days_over_daily_cap || row.weeks_over_weekly_cap.length)),
      }, [
        row.overtime_hours === null
          ? el('span.muted', { text: '—', title: 'Belum ada hari terukur, jadi tidak ada lembur yang bisa diturunkan.' })
          : el('span', { text: hoursDecimal(row.overtime_hours) }),
        row.days_over_daily_cap
          ? badge(`${row.days_over_daily_cap} hari > ${payload.policy.overtime_daily_cap_hours} jam`, 'amber')
          : null,
        weeklyCapBadge(row, payload.policy),
      ]),
      cell(hoursDecimal(row.permit_hours), 'Tidak ada ILB yang disetujui untuk orang ini pada periode ini.'),
      deltaCell(row),
      el('td', button('Rincian harian', { size: 'sm', iconName: 'list', onClick: () => onPick(row.employee_id) })),
    ]))),
  ]));
}

/* -------------------------------------------------------- rincian per hari */

const STATE_TONE = {
  terukur: '',
  setengah_terukur: 'amber',
  tidak_tercatat: '',
  non_kerja: '',
  // Putaran penutup (V-2): tanggal yang belum terjadi. Nada yang sama dengan
  // hari non-kerja — tidak ada yang hilang, jadi tidak ada yang perlu
  // ditandai. Yang salah sebelumnya bukan warnanya melainkan namanya: ia
  // terhitung "tidak tercatat", dan itu tuduhan.
  belum_tiba: '',
};

function dayTable(payload) {
  return el('.table-wrap', el('table.data.timesheet-days', [
    el('thead', el('tr', [
      el('th', { text: 'Tanggal' }),
      el('th', { text: 'Keadaan' }),
      el('th', { text: 'Masuk' }),
      el('th', { text: 'Pulang' }),
      el('th.right', { text: 'Jam kerja' }),
      el('th.right', { text: 'Terlambat' }),
      el('th.right', { text: 'Lembur' }),
      el('th.right', { text: 'ILB' }),
      el('th', { text: 'Catatan' }),
    ])),
    el('tbody', payload.days.map((day) => el('tr', {
      'data-date': day.date,
      'data-state': day.state,
      'data-non-working': String(day.non_working_day),
    }, [
      el('td', [
        el('span.cell-main', { text: fmt.date(day.date) }),
        day.non_working_day ? el('span.cell-sub', { text: 'hari non-kerja' }) : null,
      ]),
      el('td', [
        badge(day.state_label, STATE_TONE[day.state] || ''),
        day.attendance_status && day.state !== 'terukur'
          ? el('span.cell-sub', { text: `kerani: ${day.attendance_status}` })
          : null,
      ]),
      el('td.mono', { text: day.check_in_at ? day.check_in_at.slice(11, 16) : '' }),
      el('td.mono', { text: day.check_out_at ? day.check_out_at.slice(11, 16) : '' }),
      /* Jam KERJA, bukan rentang di lokasi: istirahat sudah dipotong, dan
         title-nya menyebut berapa — supaya selisih antara cap jam di dua kolom
         sebelah kiri dan angka ini tidak perlu ditebak. */
      cell(
        hoursText(day.net_worked_minutes),
        day.break_minutes
          ? `Rentang masuk→pulang ${hoursText(day.worked_minutes)}, dikurangi istirahat `
            + `${day.break_minutes} menit yang tidak dihitung jam kerja.`
          : day.note,
      ),
      cell(hoursText(day.late_minutes), day.note),
      el('td.right.num', { 'data-empty': String(day.overtime_minutes === null), 'data-over-cap': String(Boolean(day.over_daily_cap)) },
        day.overtime_minutes === null
          ? el('span.muted', { text: '—', title: day.note || undefined })
          : el('span', {
            text: hoursText(day.overtime_minutes),
            style: day.over_daily_cap ? { color: 'var(--warning)' } : {},
            title: day.over_daily_cap ? 'Melewati batas harian — dicatat penuh, tidak dipotong.' : undefined,
          })),
      cell(hoursDecimal(day.permit_hours), 'Tidak ada ILB yang disetujui untuk hari ini.'),
      el('td.cell-sub', { style: { whiteSpace: 'normal' }, text: day.note || '' }),
    ]))),
  ]));
}

/* -------------------------------------------------------------- ekspor CSV */

/**
 * Sel KOSONG, bukan 0 — aturan ekspor rumah ini. Sebuah 0 di kolom "Menit
 * kerja" pada baris orang yang tidak pernah diukur akan dibuka di Excel oleh
 * seseorang yang tidak pernah melihat layar ini.
 */
function periodCsv(payload) {
  const rows = payload.rows.map((row) => PERIOD_COLUMNS.map((column) => {
    const value = row[column.key];
    if (value === null || value === undefined) return '';
    return typeof value === 'number' ? String(value).replace('.', ',') : String(value);
  }));

  return toCsv(PERIOD_COLUMNS.map((column) => column.label), rows);
}

/* ------------------------------------------------------------------ layar */

function periodControls(onChange) {
  const yearInput = el('input', { type: 'number', min: '2000', max: '2100', value: String(state.year) });
  const monthSelect = el('select', fmt.MONTHS.map((label, index) => el('option', {
    value: String(index + 1), text: label,
  })));
  monthSelect.value = String(state.month);

  yearInput.addEventListener('change', () => { state.year = Number(yearInput.value) || state.year; onChange(); });
  monthSelect.addEventListener('change', () => { state.month = Number(monthSelect.value); onChange(); });

  return el('.card', el('.card-body', {
    style: { display: 'flex', gap: '12px', flexWrap: 'wrap', alignItems: 'flex-end' },
  }, [field('Tahun', yearInput), field('Bulan', monthSelect)]));
}

export async function renderTimesheet(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Timesheet & Lembur' }),
      el('.desc', {
        text: 'Jam kerja, keterlambatan dan lembur yang DITURUNKAN dari jam masuk/pulang di register '
          + 'absensi, disandingkan dengan jam ILB yang disetujui. Layar ini tidak menyimpan apa pun: '
          + 'ILB tetap otoritatif, dan rekap bulanan tetap dokumen yang Anda periksa dan simpan.',
      }),
    ]),
  ]));

  const body = el('div');
  const detail = el('div');
  host.append(periodControls(() => load()), body, detail);

  /* Nomor urut muatan — idiom usulanrekap.js/absensi.js: dua penggantian bulan
     beruntun mengirim dua permintaan, dan yang berangkat lebih dulu boleh
     mendarat belakangan. */
  let loadToken = 0;

  async function loadDetail(employeeId) {
    const token = loadToken;
    state.employeeId = employeeId;
    clear(detail).appendChild(skeletonTable(6, 9));

    let payload;
    try {
      payload = await api.get(`hr/timesheet/${employeeId}`, {
        period_year: state.year, period_month: state.month,
      });
    } catch (error) {
      if (token !== loadToken) return;
      clear(detail).appendChild(errorState(error, () => loadDetail(employeeId)));
      return;
    }

    if (token !== loadToken) return;
    clear(detail).appendChild(el('.card.timesheet-detail', [
      el('.card-head', [
        el('h2', { text: `Rincian harian — ${payload.summary.employee_name}` }),
        el('.spacer'),
        button('Tutup', { size: 'sm', onClick: () => { state.employeeId = null; clear(detail); } }),
      ]),
      dayTable(payload),
    ]));
  }

  async function load() {
    const token = ++loadToken;
    clear(detail);
    clear(body).appendChild(skeletonTable(6, 8));

    let payload;
    try {
      payload = await api.get('hr/timesheet', { period_year: state.year, period_month: state.month });
    } catch (error) {
      if (token !== loadToken) return;
      clear(body).appendChild(errorState(error, load));
      return;
    }

    if (token !== loadToken) return;
    clear(body);

    const banner = postedBanner(payload.period);
    if (banner) body.appendChild(banner);
    body.appendChild(policyCard(payload.policy));

    if (!payload.rows.length) {
      /* NOL BARIS BUKAN "semua orang bekerja nol jam": tidak ada satu pun
         catatan pada periode itu. Kalimatnya harus mengatakan yang kedua. */
      body.appendChild(emptyState(
        `Belum ada satu pun absensi, izin lembur yang disetujui, atau rekap pada ${payload.period.label}. `
        + 'Register memang belum berisi untuk bulan ini — bukan berarti tidak ada yang masuk kerja. '
        + 'Timesheet mulai terisi sendiri begitu orang menekan Absen Masuk dan Absen Pulang di layar '
        + 'Absensi Saya.',
        { title: 'Register bulan ini kosong' },
      ));
      return;
    }

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: `Timesheet ${payload.period.label}` }),
        el('.spacer'),
        el('span.muted', { text: `${payload.rows.length} karyawan` }),
        button('Unduh CSV', {
          variant: 'primary',
          iconName: 'download',
          onClick: () => downloadCsv(
            csvFilename(`timesheet ${payload.period.year}-${String(payload.period.month).padStart(2, '0')}`),
            periodCsv(payload),
          ),
        }),
      ]),
      periodTable(payload, loadDetail),
    ]));
  }

  await load();
}

/**
 * "Timesheet Saya" — pintu `me`, tanpa izin hr.*.
 *
 * Layar yang sama tanpa daftar orang lain: tukang, teknisi dan pengemudi tidak
 * memegang satu pun izin HR, dan sebuah timesheet yang hanya bisa dilihat HR
 * adalah timesheet yang orangnya tidak pernah bisa membantah.
 */
export async function renderTimesheetSaya(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Timesheet Saya' }),
      el('.desc', {
        text: 'Jam kerja, keterlambatan dan lembur Anda, diturunkan dari absen masuk/pulang Anda '
          + 'sendiri. Angka di sini adalah usulan dari register — jam lembur yang dibayar tetap '
          + 'mengikuti Izin Lembur (ILB) yang disetujui.',
      }),
    ]),
  ]));

  const body = el('div');
  host.append(periodControls(() => load()), body);

  let loadToken = 0;

  async function load() {
    const token = ++loadToken;
    clear(body).appendChild(skeletonTable(6, 9));

    let payload;
    try {
      payload = await api.get('hr/timesheet/me', { period_year: state.year, period_month: state.month });
    } catch (error) {
      if (token !== loadToken) return;
      clear(body).appendChild(errorState(error, load));
      return;
    }

    if (token !== loadToken) return;
    clear(body);

    if (!payload.linked) {
      /* Kalimatnya dari SERVER: akun integrasi, admin, dan orang yang datanya
         belum ditautkan HR semuanya sah, dan ketiganya bukan galat. */
      body.appendChild(emptyState(
        payload.notice,
        { title: 'Belum ada kartu karyawan' },
      ));
      return;
    }

    const banner = postedBanner(payload.period);
    if (banner) body.appendChild(banner);
    body.appendChild(policyCard(payload.policy));

    const summary = payload.summary;
    body.appendChild(el('.stat-row', [
      el('.stat', [
        el('.label', { text: 'Hari terukur' }),
        el('.value', { text: String(summary.measured_days) }),
        /* EMPAT kalimat, bukan tiga, dan pujiannya PALING AKHIR.
           Terukur di peramban (S42m, 14 Sep 2026): bulan yang tidak punya satu
           pun catatan berbunyi "0 · setiap hari bercap jam lengkap" — sebuah
           pujian tentang orang yang tidak pernah menekan tombolnya sama sekali.
           Perbaikan pertama hanya menutup measured_days === 0, dan meninggalkan
           lubang yang bentuknya sama persis: satu hari terukur dari 26 juga
           berbunyi "setiap hari bercap jam lengkap", karena hari yang TIDAK
           TERCATAT SAMA SEKALI tidak masuk half_measured_days. `unrecorded_days`
           sudah ada di muatan sejak awal dan tidak pernah dipakai.
           Pujian sekarang menuntut ketiganya: ada yang terukur, tidak ada yang
           setengah terukur, DAN tidak ada hari yang lewat tanpa cap jam. */
        el('.delta', {
          text: summary.measured_days === 0
            ? 'belum ada satu hari pun dengan cap jam masuk dan pulang'
            : (summary.half_measured_days
              ? `${summary.half_measured_days} hari hanya satu cap jam — belum terukur`
              : (summary.unrecorded_days
                ? `${summary.unrecorded_days} hari kerja lewat tanpa cap jam sama sekali`
                : 'setiap hari bercap jam lengkap')),
        }),
      ]),
      el('.stat', [
        el('.label', { text: 'Jam kerja' }),
        el('.value.sm', { text: hoursText(summary.net_worked_minutes) ?? '—' }),
        el('.delta', {
          text: summary.net_worked_minutes === null
            ? 'belum ada yang terukur bulan ini'
            : (summary.break_minutes
              ? `sesudah istirahat ${hoursText(summary.break_minutes)} yang tidak dihitung jam kerja`
              : ''),
        }),
      ]),
      el('.stat', [
        el('.label', { text: 'Lembur turunan' }),
        el('.value.sm', { text: hoursDecimal(summary.overtime_hours) ?? '—' }),
        el('.delta', { text: summary.permit_hours === null ? 'tanpa ILB disetujui' : `ILB ${hoursDecimal(summary.permit_hours)}` }),
      ]),
    ]));

    body.appendChild(el('.card.timesheet-detail', [
      el('.card-head', [el('h2', { text: `Rincian harian ${payload.period.label}` }), el('.spacer')]),
      dayTable(payload),
    ]));
  }

  await load();
}
