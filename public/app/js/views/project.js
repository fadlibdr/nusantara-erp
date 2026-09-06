/* Project workspace: dashboard tiles, kurva-S, WBS tree, and the project's
   documents pulled from the other modules. */

import { api, session } from '../api.js';
import { el, clear, button, badge, progressBar, errorState, emptyState, toast, toastError, icon, modal, withBusy } from '../ui.js';
import * as fmt from '../format.js';
import { attachmentsCard } from './attachments.js';
import { evmCard, baselineCard } from './evm.js';
import { preload } from '../lookup.js';
import { openForm } from './form.js';
import { promptFields, buildInput } from './form.js';
import { navigate, back } from '../router.js';
import { openPrintable } from '../print.js';
import { RESOURCES } from '../schema.js';
import { lineChart } from '../charts.js';
import { openTutupProyek } from './tutupproyek.js';
import { renderJadwal } from './jadwal.js';

/*
 * Tab yang sedang dibuka (P1-H). DI LINGKUP MODUL, bukan di dalam
 * renderProject: `reload` menggambar ulang SELURUH layar dan dipasang di enam
 * tempat (simpan Ubah, Buat WBS dari BOQ, impor MPP-XML, Tutup proyek, simpan
 * catatan minggu, simpan progres daun WBS). Sebuah variabel tab yang hidup di
 * dalam fungsi render akan kembali ke Ringkasan setiap kali salah satu dari
 * keenamnya menyimpan — yaitu justru saat orangnya sedang bekerja di Jadwal.
 */
const tabState = { tab: 'ringkasan' };

const PROJECT_TABS = [
  { key: 'ringkasan', label: 'Ringkasan' },
  { key: 'jadwal', label: 'Jadwal' },
];

/**
 * Kurva-S rencana vs aktual — `charts.js lineChart` sejak P1-E.
 *
 * Sampai P1-D grafik ini adalah ~95 baris SVG tangan di berkas ini: kisi, sumbu,
 * penjarangan label, path area, tiga garis dan titik ber-<title>, semuanya
 * ditulis sendiri dan diwarnai lewat kelas `.plan/.act/.base` di app.css.
 * `charts.js` (P1-A) ditulis untuk menampungnya — `yMin/yMax/yStep`,
 * `series.dash/token/area/dots`, `points[].title` — dan sekarang yang tersisa
 * di sini hanya DATA-nya.
 *
 * Yang BERUBAH, dan disengaja:
 *  • Warna datang dari token kategorikal `--chart-n`, bukan `--muted/--primary/
 *    --text-2`. "Aktual" tetap biru yang sama persis (`--chart-1` = `--primary`
 *    = #1a56db); dua garis rencana yang dulu abu-abu tua dan abu-abu muda —
 *    hampir tak terbedakan kecuali dari pola putusnya — kini hijau dan batu
 *    tulis, pola putusnya dipertahankan ('5 3' mingguan, '2 4' baseline).
 *  • Nilai di LUAR 0–100 tidak lagi dijepit diam-diam. Kode lama menulis
 *    `Math.min(100, value)`, jadi minggu ber-105 % tergambar persis di garis
 *    100 % dan tidak ada yang tahu; `charts.js` menempelkannya di tepi plot
 *    dengan `data-outside` dan menambahkan "(di luar sumbu)" pada <title>-nya.
 *  • Legenda pindah KE DALAM svg (ikut tercetak, ikut ter-skala), jadi blok
 *    `.legend` di pemanggil dibuang — bukan disembunyikan.
 *
 * `baselinePoints` opsional: kurva baseline beku digambar sebagai seri ketiga,
 * DISAMPEL pada tanggal milik setiap minggu, bukan pada indeksnya — titik
 * baseline bulanan dan minggu mingguan, dan memplot yang satu di posisi yang
 * lain menggeser kurvanya berminggu-minggu. Interpolasinya tidak berubah satu
 * baris pun; yang berubah hanya siapa yang menggambar hasilnya.
 */
export function sCurveChart(weeks, baselinePoints) {
  /* `Number(null)` adalah 0, dan `Number('')` juga — keduanya BERHINGGA,
     jadi penjaga yang hanya memeriksa Number.isFinite mengembalikan 0 untuk
     nilai yang tidak diketahui dan menggambar titiknya persis di garis 0 %
     dengan tooltip yang berbunyi "rencana —, aktual —" (verifikasi P1-E).
     Bentuk yang benar adalah bentuk yang dipakai evm.js value() dan
     charts.js finite(): null/undefined/'' adalah CELAH, bukan nol. */
  const pct = (value) => {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  };

  /* Satu kalimat <title> untuk KEDUA seri di minggu yang sama — bentuk yang
     sama dengan grafik tangan: pembaca yang menyentuh titik aktual ingin tahu
     rencananya juga, bukan salah satunya saja. */
  const titleOf = (week) => `Minggu ${week.week_no} — rencana ${fmt.percent(week.planned_pct)}, `
    + `aktual ${fmt.percent(week.actual_pct)}`;

  const series = [
    {
      label: 'Rencana (laporan mingguan)',
      token: '--chart-3',
      dash: '5 3',
      dots: false,
      points: weeks.map((week) => ({ y: pct(week.planned_pct), title: titleOf(week) })),
    },
    {
      label: 'Aktual',
      token: '--chart-1',
      area: true,
      points: weeks.map((week) => ({ y: pct(week.actual_pct), title: titleOf(week) })),
    },
  ];

  if (baselinePoints && baselinePoints.length) {
    const samples = baselinePoints
      .filter((point) => point && point.period_end)
      .map((point) => ({ at: Date.parse(point.period_end), pct: Number(point.planned_pct) || 0 }))
      .filter((point) => Number.isFinite(point.at))
      .sort((a, b) => a.at - b.at);

    const pctAt = (time) => {
      if (!samples.length || !Number.isFinite(time)) return null;
      // Sebelum sampel pertama kurvanya TIDAK DIKETAHUI, bukan nol. Garis putus
      // mulai di tempat datanya mulai, alih-alih menarik garis nol mendatar
      // yang mengarang selisih lebih besar daripada yang sebenarnya ada.
      if (time < samples[0].at) return null;
      if (time === samples[0].at) return samples[0].pct;
      if (time >= samples[samples.length - 1].at) return samples[samples.length - 1].pct;
      for (let i = 1; i < samples.length; i++) {
        if (time > samples[i].at) continue;
        const previous = samples[i - 1];
        const next = samples[i];
        const span = next.at - previous.at;
        return span === 0 ? next.pct : previous.pct + ((time - previous.at) / span) * (next.pct - previous.pct);
      }
      return null;
    };

    series.push({
      label: 'Rencana baseline (kurva beku)',
      token: '--chart-8',
      dash: '2 4',
      dots: false,
      // y null = CELAH: minggu sebelum sampel pertama memang tidak punya nilai.
      points: weeks.map((week) => ({ y: pctAt(Date.parse(week.period_end)) })),
    });
  }

  return lineChart({
    series,
    xLabels: weeks.map((week) => `M${week.week_no}`),
    // Sumbu 0–100 dipaksa: kurva-S adalah persen kumulatif, dan sumbu yang
    // menyesuaikan diri ke puncak data membuat dua proyek tidak bisa
    // dibandingkan sekilas.
    yMin: 0,
    yMax: 100,
    yStep: 25,
    /* fmt.percent, bukan `${value}%`: yFormat dipakai charts.js untuk label
       sumbu DAN untuk <title> bawaan setiap titik tanpa judul sendiri, jadi
       bentuk template mencetak titik desimal Inggris ('4.5%') di aplikasi yang
       menulis '4,5%' di mana pun (verifikasi P1-E). Label sumbunya bilangan
       bulat, jadi teksnya tidak berubah. */
    yFormat: (value) => fmt.percent(value, { decimals: 1 }),
    ariaLabel: 'Kurva-S rencana vs aktual',
  });
}

/**
 * The WBS endpoint answers a nested tree (roots with `children`), so walk the
 * nesting rather than reconstructing it from parent_id.
 */
function wbsTree(tasks, { canUpdate, onProgress }) {
  if (!tasks.length) {
    return el('.card-body', el('p.muted', { text: 'WBS belum dibuat. Gunakan tombol "Buat WBS dari BOQ".', style: { margin: 0 } }));
  }

  const rows = [];
  const walk = (nodes, depth) => {
    for (const task of [...nodes].sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0))) {
      const children = task.children || [];
      const isLeaf = children.length === 0;

      rows.push(el('tr', [
        el('td.code', { text: task.wbs_code || '' }),
        el('td', el('span', { style: { paddingLeft: `${depth * 18}px`, fontWeight: isLeaf ? '400' : '600' }, text: task.name })),
        el('td.right.num', { text: fmt.percent(task.weight_pct) }),
        el('td', { style: { minWidth: '150px' } }, el('div', [
          el('div.num', { text: fmt.percent(task.progress_pct), style: { fontSize: '11.5px', marginBottom: '3px' } }),
          progressBar(task.progress_pct, Number(task.progress_pct) >= 100 ? 'green' : ''),
        ])),
        el('td.right', isLeaf && canUpdate
          ? button('Perbarui', { size: 'sm', onClick: () => onProgress(task) })
          // Baris induk tidak bisa diisi manual — progresnya agregat berbobot
          // dari sub-tugasnya; label ini menjelaskan kenapa tombolnya absen.
          : el('span.muted', { text: isLeaf ? '' : 'agregat dari sub-tugas' })),
      ]));

      if (!isLeaf) walk(children, depth + 1);
    }
  };
  walk(tasks, 0);

  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', [
      el('th', { text: 'Kode' }), el('th', { text: 'Uraian' }),
      el('th.right', { text: 'Bobot' }), el('th', { text: 'Progres' }), el('th', { text: '' }),
    ])),
    el('tbody', rows),
  ]));
}

/*
 * P0-C — tiga izin lapangan adalah DOKUMEN sekarang, bukan pad cetak kosong.
 *
 * Kartu blank-pad yang dulu di sini — tombol `core/print/forms/<izin>/<id
 * PROYEK>` plus kalimat "dicetak KOSONG" — hilang BERSAMA perilakunya
 * (aturan kejujuran: tidak ada tombol yatim yang kini 404 karena composer
 * menuntut id izin). Penggantinya register masing-masing: entri RESOURCES
 * `projects/work-permits` / `overtime-permits` / `gate-passes` di schema.js,
 * dengan tombol cetak per BARIS izin (printForms) dan siklus
 * ajukan/setujui/periksa-nya. Kartu ini tinggal papan penunjuk arah.
 */
const IZIN_REGISTERS = [
  { route: '#/r/projects/work-permits', label: 'Izin Kerja (IKL)', code: 'Form F/IK', note: 'izin satu shift — bahaya & APD, disetujui prj.approve' },
  { route: '#/r/projects/overtime-permits', label: 'Izin Lembur (ILB)', code: 'Form F/IL', note: 'jam per pekerja mengalir ke rekap payroll saat disetujui' },
  { route: '#/r/projects/gate-passes', label: 'Izin Material (IMK)', code: 'Form F/IM', note: 'disetujui manajemen, lalu diperiksa security di gerbang' },
];

function izinLapanganCard() {
  return el('.card', [
    el('.card-head', el('h2', { text: 'Izin lapangan (IKL / ILB / IMK)' })),
    el('.card-body', [
      el('p.muted', {
        text: 'Ketiga izin kini dicatat sebagai dokumen bernomor dan lembarnya dicetak DARI datanya '
          + '(baris kosong tetap bergaris untuk diisi tangan). Buat dan cetak dari registernya:',
        style: { margin: '0 0 12px', fontSize: '12.5px' },
      }),
      el('div', { style: { display: 'flex', flexDirection: 'column', gap: '9px' } }, IZIN_REGISTERS.map((izin) => el('div', [
        el('a', { href: izin.route, text: izin.label }),
        el('.cell-sub', { text: `${izin.code} · ${izin.note}`, style: { marginTop: '2px' } }),
      ]))),
    ]),
  ]);
}

/* P8 — impor MPP-XML (kriteria #8): jadwal MS Project → pohon WBS + baseline,
   dari halaman proyek — bersebelahan dengan "Buat WBS dari BOQ" karena
   keduanya adalah dua sumber untuk satu pohon. Yang dibaca berkasnya hanyalah
   hierarki outline dan tanggal per tugas; bobot daun dihitung dari porsi
   durasi (konvensi terdokumentasi MppXmlImportService, bukan tebakan per
   sel). Dialog TIDAK memeriksa apa pun sendiri: proyek yang sudah ber-WBS,
   XML yang bukan MS Project, baseline tanpa RAP/BAC — semuanya dijawab 422
   server dengan kalimat bernama, dan toastError menampilkannya utuh. */
function openMppXmlImport(project, { onImported } = {}) {
  const picker = el('input', {
    type: 'file',
    accept: '.xml,text/xml,application/xml',
    'aria-label': 'Berkas XML MS Project',
  });

  const makeBaseline = el('input', { type: 'checkbox', checked: true });
  const bac = buildInput({ type: 'currency' }, '');

  const body = el('div', { style: { display: 'flex', flexDirection: 'column', gap: '12px' } }, [
    el('p', {
      style: { margin: '0', fontSize: '13px', color: 'var(--text-2)' },
      text: 'Ekspor jadwal dari Microsoft Project sebagai XML (File > Save As > XML Format) — '
        + 'bukan .mpp biner. Hierarki outline menjadi pohon WBS, tanggal mulai/selesai menjadi '
        + 'tanggal tugas; bobot daun dihitung dari porsi durasinya. Maksimal 5 MB.',
    }),
    el('p', {
      style: { margin: '0', fontSize: '13px', color: 'var(--text-2)' },
      text: 'Impor hanya menata proyek yang belum ber-WBS — proyek yang sudah punya tugas ditolak '
        + 'dengan menyebut apa yang ada, bukan ditimpa.',
    }),
    el('.field', [el('label', { text: 'Berkas XML' }), picker]),
    el('label', { style: { display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px' } }, [
      makeBaseline,
      el('span', { text: 'Bekukan baseline (kurva S) dari jadwal ini' }),
    ]),
    el('.field', [
      el('label', { text: 'BAC — nilai anggaran baseline (opsional)' }),
      bac.node,
      el('.help', {
        text: 'Kosongkan untuk memakai RAP proyek. Tanpa RAP dan tanpa nilai di sini, pembekuan '
          + 'baseline ditolak server dengan kalimatnya sendiri dan seluruh impor batal.',
      }),
    ]),
  ]);

  const submit = button('Impor', {
    variant: 'primary',
    onClick: (event) => runImport(event.currentTarget),
  });

  async function runImport(trigger) {
    const file = picker.files && picker.files[0];
    if (!file) {
      toast('Pilih berkas XML-nya dulu.', { tone: 'err' });
      return;
    }

    await withBusy(trigger, async () => {
      const dataUrl = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(new Error('Berkas tidak dapat dibaca.'));
        reader.readAsDataURL(file);
      });

      // Server mendekode base64 KETAT (base64_decode(..., true)); awalan
      // dataURL FileReader ("data:…;base64,") harus dibuang di sini.
      const content = dataUrl.split(',').pop();
      const bacValue = bac.read();

      let result;
      try {
        result = await api.post(`projects/${project.id}/import-mpp-xml`, {
          filename: file.name,
          content,
          buat_baseline: makeBaseline.checked,
          ...(bacValue ? { bac_override: bacValue } : {}),
        });
      } catch (error) {
        toastError(error);
        return;
      }

      toast(
        `${result.tasks} tugas WBS diimpor dari ${file.name}.`
        + (result.baseline_code
          ? ` Baseline ${result.baseline_code} dibekukan (${result.baseline_points} titik kurva S).`
          : ' Baseline tidak dibekukan.'),
      );
      handle.close();
      if (onImported) onImported();
    });
  }

  const handle = modal({
    title: `Impor Jadwal MPP-XML — ${project.code}`,
    body,
    footer: [
      button('Batal', { variant: 'ghost', onClick: () => handle.requestClose() }),
      submit,
    ],
    dirty: () => Boolean(picker.files && picker.files.length),
  });
}

const safe = (path, params) => api.get(path, params).then((rows) => rows || []).catch(() => []);

export async function renderProject(host, { id }) {
  clear(host);
  host.appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '18px', width: '35%' } }))));

  let project;
  let dashboard;
  let sCurve;
  let evm;
  try {
    [project, dashboard, sCurve, evm] = await Promise.all([
      api.get(`projects/${id}`),
      api.get(`projects/${id}/dashboard`).catch(() => null),
      api.get(`projects/${id}/s-curve`).catch(() => null),
      // Fetched here so the kurva-S can draw the frozen baseline alongside the
      // weekly plan; evmCard reuses this response rather than asking twice.
      api.get(`projects/${id}/evm`).catch(() => null),
    ]);
  } catch (error) {
    clear(host);
    host.append(
      el('.page-head', [button('Kembali', { iconName: 'back', onClick: () => back() })]),
      errorState(error, () => renderProject(host, { id })),
    );
    return;
  }

  const def = RESOURCES.projects;
  const reload = () => renderProject(host, { id });
  const canUpdate = session.can('prj.update');

  clear(host);

  // Remah roti digambar router dengan "#id" sebelum rekaman tiba; diisi kode
  // di sini seperti detail.js/custom.js — Terakhir dibuka (T2.5) membaca
  // judulnya dari sini, dan tanpa baris ini tercatat sebagai "Proyek #1".
  const crumb = document.querySelector('#crumbs b');
  if (crumb) crumb.textContent = project.code || project.name;

  host.appendChild(el('.page-head', [
    el('div', [
      el('div', { style: { display: 'flex', alignItems: 'center', gap: '9px', flexWrap: 'wrap' } }, [
        el('h1', { text: project.name }),
        badge(project.status_label || project.status, fmt.statusTone(project.status)),
      ]),
      el('.desc', { text: `${project.code} · ${project.location || project.city || '—'} · ${project.type_label || ''}` }),
    ]),
    el('.actions', [
      button('', { iconName: 'back', title: 'Kembali', onClick: () => back() }),
      button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() }),
      /* Galeri foto progres (Temuan 16): semua foto ber-mime image lintas
         laporan harian/BAST/defect/GRN/SPK proyek ini, terurut tanggal ambil,
         dengan lencana geotag — layar galeri-proyek/:id. */
      button('Galeri Foto', { onClick: () => navigate(`galeri-proyek/${id}`) }),
      /* Formulir rumah: lembar data proyek dalam format formulir perusahaan —
         pita empat pihak (PEMILIK / KONSULTAN MK / PROYEK / KONTRAKTOR), blok
         identitas SPK, dan tiga kolom tanda tangan. Dibuka di tab baru lalu
         dicetak browser (bukan dompdf), lihat print.js openPrintable. */
      button('Cetak Data Proyek', {
        iconName: 'print',
        title: 'Cetak lembar data proyek dalam format formulir perusahaan',
        onClick: (event) => openPrintable(`core/print/forms/data-proyek/${id}`, event.currentTarget),
      }),
      canUpdate ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'projects', row: project, onSaved: reload }) }) : null,
      canUpdate && project.status !== 'closed'
        ? button('Buat WBS dari BOQ', {
          onClick: async () => {
            try {
              await api.post(`projects/${id}/generate-wbs`, {});
              toast('WBS dibuat ulang dari BOQ.');
              reload();
            } catch (error) {
              toastError(error);
            }
          },
        })
        : null,
      /* P8 — sumber kedua untuk pohon yang sama: jadwal MS Project (XML).
         Tombolnya tetap digambar untuk proyek ber-WBS — penolakannya urusan
         server, dan kalimat 422-nya (menyebut tugas yang sudah ada) lebih
         mendidik daripada tombol yang diam-diam hilang. */
      canUpdate && project.status !== 'closed'
        ? button('Impor Jadwal (MPP-XML)', {
          onClick: () => openMppXmlImport(project, { onImported: reload }),
        })
        : null,
      /* Aksi eksplisit di balik status 'Ditutup' (Temuan 47): checklist item
         terbuka dibaca dulu, baru tombolnya. Server menolak closed dari form
         status biasa, jadi tanpa tombol ini menutup proyek tidak ada pintunya. */
      session.can('prj.approve') && project.status !== 'closed'
        ? button('Tutup proyek', {
          variant: 'danger',
          onClick: () => openTutupProyek(project, { onClosed: reload }),
        })
        : null,
    ]),
  ]));

  const actual = Number(project.actual_progress_pct || 0);
  const planned = Number(project.planned_progress_pct || 0);
  const deviation = Number(project.deviation_pct ?? actual - planned);

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Nilai kontrak' }), el('.value.sm', { text: fmt.rupiah(project.contract_value) })]),
    el('.stat', [
      el('.label', { text: 'Progres aktual' }), el('.value', { text: fmt.percent(actual) }),
      el(`.delta${deviation < 0 ? '.down' : '.up'}`, { text: `${deviation >= 0 ? '+' : ''}${fmt.percent(deviation)} vs rencana` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Tenaga kerja hari ini' }),
      el('.value', { text: dashboard ? String(dashboard.manpower_today) : '—' }),
      dashboard && dashboard.latest_daily_report
        ? el('.delta', { text: `Laporan terakhir ${fmt.date(dashboard.latest_daily_report.report_date)}` })
        : null,
    ]),
    el('.stat', [
      el('.label', { text: 'Milestone terlambat' }),
      el('.value', { text: dashboard ? String(dashboard.milestones.overdue_count) : '—' }),
      dashboard && dashboard.milestones.next
        ? el('.delta', { text: `Berikutnya: ${dashboard.milestones.next.name.slice(0, 34)}` })
        : null,
    ]),
    el('.stat', [
      el('.label', { text: 'PO terbuka' }),
      el('.value', { text: dashboard ? String(dashboard.open_po_count) : '—' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Retensi ditahan' }),
      el('.value.sm', { text: fmt.rupiah(project.retention_amount) }),
      el('.delta', { text: `${fmt.percent(project.retention_pct)} dari nilai kontrak` }),
    ]),
  ]));

  /* Bilah tab (P1-H) — pola paintTabs() views/evm.js, satu-satunya bentuk tab
     di SPA ini. Kepala halaman dan baris ubin di atas tetap terlihat pada
     kedua tab: keduanya menjawab "proyek mana ini", pertanyaan yang tidak
     berubah karena orang membuka jadwalnya. */
  const tabs = el('.tabs');
  const pane = el('div');
  host.append(tabs, pane);

  const main = el('div');
  const side = el('div');

  /* ------------------------------------------------------------ kurva-S */
  const weeks = (sCurve && sCurve.weeks) || [];
  const baselineCurvePoints = (evm && evm.curve && evm.curve.points) || null;
  main.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Kurva-S (progres kumulatif)' }),
      el('.spacer'),
      session.can('prj.create')
        ? button('Catat minggu', {
          size: 'sm',
          onClick: () => openForm({
            def: RESOURCES['projects/weekly-progress'],
            key: 'projects/weekly-progress',
            row: null,
            onSaved: reload,
          }),
        })
        : null,
    ]),
    el('.card-body', weeks.length
      ? el('div', [
        // Legendanya ada DI DALAM svg sejak P1-E — blok .legend lama dibuang,
        // bukan disembunyikan: dua legenda untuk satu grafik adalah dua tempat
        // yang bisa berselisih tentang nama garis yang sama.
        sCurveChart(weeks, baselineCurvePoints),
      ])
      : el('p.muted', { text: 'Belum ada data progres mingguan.', style: { margin: 0 } })),
  ]));

  /* ------------------------------------------------------------ EVM */
  main.appendChild(await evmCard(id, evm));
  side.appendChild(await baselineCard(id, reload));

  /* ------------------------------------------------- izin lapangan (P0-C)
     Papan penunjuk ke tiga register izin — tetap di sini karena kerani
     lokasi mencarinya dari halaman proyek, tetapi tombol cetaknya kini per
     BARIS izin di register masing-masing. */
  side.appendChild(izinLapanganCard());

  /* ---------------------------------------------------------------- WBS */
  const tasks = await safe(`projects/${id}/wbs-tasks`, { per_page: 300 });
  const leafWeight = dashboard ? dashboard.wbs.leaf_weight_total : null;

  main.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Struktur WBS' }),
      el('.spacer'),
      leafWeight !== null
        ? badge(`Bobot daun ${fmt.percent(leafWeight)}`, Math.abs(leafWeight - 100) < 0.01 ? 'green' : 'amber')
        : null,
    ]),
    wbsTree(tasks, {
      canUpdate,
      onProgress: async (task) => {
        const values = await promptFields(`Perbarui progres — ${task.name}`, [
          { key: 'progress_pct', label: 'Progres (%)', type: 'percent', required: true, default: task.progress_pct },
          { key: 'actual_start', label: 'Aktual mulai', type: 'date' },
          { key: 'actual_end', label: 'Aktual selesai', type: 'date' },
        ], { submitLabel: 'Simpan' });
        if (!values) return;
        try {
          await api.put(`projects/wbs-tasks/${task.id}/progress`, values);
          toast('Progres diperbarui; progres induk dihitung ulang.');
          reload();
        } catch (error) {
          toastError(error);
        }
      },
    }),
  ]));

  /* ------------------------------------------------------ related lists */
  const [dailyReports, milestones, manpower, bast, defects] = await Promise.all([
    safe('projects/daily-reports', { project_id: id, per_page: 5 }),
    safe('projects/milestones', { project_id: id, per_page: 20 }),
    safe('projects/manpower-assignments', { project_id: id, per_page: 20 }),
    safe('projects/bast', { project_id: id, per_page: 10 }),
    // Hanya yang masih terbuka (open=1 ikut menghitung "menunggu verifikasi"):
    // kartu ini menjawab "apa yang menahan BAST II?", dan temuan yang sudah
    // diverifikasi atau didispensasi tidak menahan apa-apa.
    safe('projects/defects', { project_id: id, open: 1, per_page: 10 }),
  ]);

  const listCard = (title, rows, render, route) => el('.card', [
    el('.card-head', [
      el('h2', { text: title }),
      el('.spacer'),
      route ? button('Semua', { size: 'sm', variant: 'ghost', onClick: () => navigate(route) }) : null,
    ]),
    rows.length
      ? el('div', { style: { padding: '4px 16px 12px' } }, rows.map(render))
      : el('.card-body', el('p.muted', { text: 'Belum ada data.', style: { margin: 0, fontSize: '13px' } })),
  ]);

  side.appendChild(listCard('Milestone', milestones, (row) => el('div', {
    style: { display: 'flex', gap: '10px', alignItems: 'flex-start', padding: '7px 0', borderBottom: '1px solid var(--border)' },
  }, [
    el('div', { style: { flex: '1', minWidth: '0' } }, [
      el('div', { text: row.name, style: { fontSize: '13px' } }),
      el('.cell-sub', { text: `${fmt.date(row.due_date)} · ${fmt.relativeDays(row.due_date)}` }),
    ]),
    row.is_achieved ? badge('Tercapai', 'green') : (row.is_overdue ? badge('Terlambat', 'red') : badge('Berjalan')),
  ])));

  side.appendChild(listCard('Personel di proyek', manpower, (row) => el('div', {
    style: { display: 'flex', justifyContent: 'space-between', gap: '10px', padding: '7px 0', borderBottom: '1px solid var(--border)', fontSize: '13px' },
  }, [
    el('span', { text: row.role_on_project }),
    el('span.muted', { text: `sejak ${fmt.date(row.assigned_from)}` }),
  ])));

  side.appendChild(listCard('BAST', bast, (row) => el('div', {
    style: { display: 'flex', justifyContent: 'space-between', gap: '10px', padding: '7px 0', borderBottom: '1px solid var(--border)', fontSize: '13px' },
  }, [
    el('span.mono', { text: row.code }),
    badge(row.status_label || row.status, fmt.statusTone(row.status)),
  ]), 'r/projects/bast'));

  /* Punch list terbuka, tepat di bawah kartu BAST yang ditahannya. Warna
     keparahan mengikuti views/defect.js, bukan statusTone: temuan yang terbuka
     adalah pekerjaan yang belum selesai pada proyek yang retensinya belum cair.
     Baris hanya bisa diklik bila registernya terdaftar di schema.js — tanpa
     itu kartunya tetap terbaca utuh, hanya tidak mengantar ke mana-mana. */
  const DEFECT_SEVERITY = { critical: ['Kritis', 'red'], major: ['Mayor', 'amber'], minor: ['Minor', ''] };
  const defectDetailOk = Boolean(RESOURCES['projects/defects']);
  side.appendChild(listCard('Punch list (register defect)', defects, (row) => {
    const [sevLabel, sevTone] = DEFECT_SEVERITY[row.severity] || [row.severity_label || row.severity, ''];
    const node = el('div', {
      style: {
        padding: '7px 0', borderBottom: '1px solid var(--border)',
        cursor: defectDetailOk ? 'pointer' : 'default',
      },
    }, [
      el('div', { style: { display: 'flex', gap: '7px', alignItems: 'center', flexWrap: 'wrap' } }, [
        el('span.mono', { text: row.code, style: { fontSize: '12px' } }),
        badge(sevLabel, sevTone),
        row.is_overdue ? badge('Lewat target', 'red') : null,
      ]),
      el('div', { text: row.title, style: { fontSize: '13px', marginTop: '2px' } }),
      el('.cell-sub', {
        text: [
          row.responsible_employee ? row.responsible_employee.name : 'belum ditunjuk',
          row.due_date ? `target ${fmt.date(row.due_date)}` : 'tanpa target',
        ].join(' · '),
      }),
    ]);
    if (defectDetailOk) node.addEventListener('click', () => navigate(`d/projects/defects/${row.id}`));
    return node;
  }, 'defects'));

  main.appendChild(listCard('Laporan harian terakhir', dailyReports, (row) => el('div', {
    style: { padding: '9px 0', borderBottom: '1px solid var(--border)' },
  }, [
    el('div', { style: { display: 'flex', gap: '10px', alignItems: 'center' } }, [
      el('b', { text: fmt.date(row.report_date), style: { fontSize: '13px' } }),
      el('span.muted', { text: `${row.manpower_count} orang`, style: { fontSize: '12px' } }),
      row.weather_am_label ? badge(`${row.weather_am_label} / ${row.weather_pm_label || '—'}`) : null,
    ]),
    el('div', { text: row.activities, style: { fontSize: '12.5px', color: 'var(--text-2)', marginTop: '3px' } }),
  ]), 'r/projects/daily-reports'));

  const attachments = attachmentsCard('projects/projects', Number(id), 'prj');
  if (attachments) side.appendChild(attachments);

  const paintPane = () => {
    clear(pane);

    if (tabState.tab === 'jadwal') {
      // Gantt baca-saja; ia mengambil datanya sendiri (dua endpoint yang sudah
      // ada) supaya tab Ringkasan tidak membayar permintaan yang tidak
      // dilihatnya.
      renderJadwal(pane, { id, project });

      return;
    }

    pane.appendChild(el('.detail-grid', [main, side]));
  };

  const paintTabs = () => {
    clear(tabs);
    PROJECT_TABS.forEach((tab) => tabs.appendChild(el(`button${tab.key === tabState.tab ? '.active' : ''}`, {
      text: tab.label,
      onclick: () => {
        if (tabState.tab === tab.key) return;
        tabState.tab = tab.key;
        paintTabs();
        paintPane();
      },
    })));
  };

  paintTabs();
  paintPane();
}
