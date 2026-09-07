/*
 * Tab "Jadwal" — gantt BACA-SAJA atas WBS proyek (Fase 1 / P1-H).
 *
 * Gambarnya digambar `charts.js ganttChart()` yang sudah ada sejak P1-A; berkas
 * ini hanya menyusun BARISNYA. Yang membuatnya tidak sepele adalah dari mana
 * setiap medan baris itu datang.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * BASELINE DICOCOKKAN LEWAT `wbs_code`, BUKAN LEWAT `wbs_task_id`
 *
 * ROADMAP menyebutnya "bukan id — kolomnya nullable". Kolomnya memang nullable
 * dan memang tanpa FK, dan migrasinya menulis alasannya: baris beku harus
 * bertahan meski tugas hidupnya dihapus. Tetapi keadaannya lebih keras daripada
 * itu — DIUKUR pada berkas demo yang dikirim repositori ini: **0 dari 11**
 * `wbs_task_id` beku menunjuk baris `prj_wbs_tasks` yang masih ada (id beku
 * 12–22, id hidup 34–44), sementara **11 dari 11** `wbs_code`-nya cocok.
 * Sebabnya `ProjectService::generateWbsFromBoq`, yang MENGHAPUS seluruh WBS
 * lalu membuatnya ulang dengan id baru setiap kali "Buat WBS dari BOQ" ditekan.
 *
 * Jadi mencocokkan lewat id di sini bukan "kurang aman" — ia menghasilkan
 * gantt tanpa satu pun bar baseline pada data yang ada. Kode di bawah
 * mencocokkan lewat `wbs_code` saja, dan menyebutkan jumlah yang tidak cocok
 * alih-alih menampilkannya sebagai "tidak ada baseline".
 *
 * (Catatan untuk pembaca berikutnya: `EvmService::physicalProgress` mencoba id
 * DULU lalu jatuh ke kode. Keduanya memberi hasil yang sama pada data hari ini
 * justru karena setiap id meleset. Perbedaan itu disengaja dan ditulis di
 * docs/CONVENTIONS.md §20 dan docs/LAPORAN-PAKET-HM-P1-H.md §3; sejak P1-H,
 * ketidaksepakatan antara id dan kode TIDAK LAGI DIAM — laporan EVM memuat
 * peringatan yang menyebut kedua kodenya.)
 *
 * Angka 0-dari-11 di atas bukan hafalan: `JadwalGanttTest` mengukurnya ulang
 * terhadap `ProjectService::generateWbsFromBoq` yang sungguhan, dan harness
 * S26_gantt mengukur gambarnya di peramban (23 rect berdiri di x dan lebar yang
 * dihitung ulang dari tanggal muatan API-nya, 0 meleset > 0,05 px).
 * ────────────────────────────────────────────────────────────────────────────
 *
 * PROGRES DATANG DARI SISI HIDUP, dan hanya dari sana. Muatan baseline punya
 * `live_progress_pct`, tetapi ia `whenLoaded` atas relasi yang — karena
 * paragraf di atas — selalu null: Laravel memulangkan `null` tanpa pernah
 * memanggil closure-nya, jadi medan itu null untuk SETIAP baris pada data demo.
 * Maka dua endpoint diambil dan digabung di sini.
 *
 * SATUAN. `progress_pct` di kawat adalah 0..100 dan sebuah STRING ('60.0000');
 * `ganttChart` menerima 0..1 dan dengan sengaja TIDAK menormalkan nilai di luar
 * rentang — ia menjepit barnya lalu menulis "(di luar 0–100 %)" di <title>.
 * Membaginya 100 karena itu bukan kosmetik: tanpa itu setiap bar terbaca 100 %.
 */

import { api } from '../api.js';
import { el, clear, button, errorState, skeletonTable } from '../ui.js';
import { ganttChart } from '../charts.js';

const ZOOMS = [
  { key: 'week', label: 'Mingguan' },
  { key: 'month', label: 'Bulanan' },
];

/* Zoom bertahan antar kunjungan dalam satu sesi, seperti tab layar lain. */
const state = { zoom: 'week' };

/**
 * @param {HTMLElement} host
 * @param {{ id: number|string, project: object }} ctx
 */
export async function renderJadwal(host, { id, project }) {
  clear(host);
  host.appendChild(skeletonTable(6, 4));

  let tasks;
  let baseline;
  let cycles;
  /* "Hari ini" MENURUT SERVER (meta.as_of), tidak pernah menurut jam peramban —
     aturan yang sudah tertulis di EvmService ("an EVM report keyed off a skewed
     PC clock manufactures schedule variance out of nothing") dan dipakai
     evm.js, varian.js dan sertifikat.js. Garis "Hari ini" di gantt adalah
     pembacaan keterlambatan yang sama, hanya dengan mata: tanpa medan ini
     charts.js jatuh ke localToday() dan garisnya berpindah mengikuti timezone
     pembacanya (diukur 7 Sep 2026: Asia/Jakarta x=484,67, America/Los_Angeles
     x=483,27, pada berkas dan jam server yang sama). */
  let today;
  /* Kegagalan baseline BUKAN ketiadaan baseline. `.catch(() => null)` dulu
     menyamakan keduanya, dan layar mengumumkan "belum ada baseline beku" untuk
     proyek yang baru saja ia baca punya baseline disetujui — fakta yang
     dikarang tentang rencana beku, di layar yang justru ada untuk membandingkan
     rencana dengan kenyataan, dan PANDUAN §7.2 mengajarkan kalimat itu berarti
     "bukan galat". Ketiga keadaannya kini dibedakan (tidak ada / gagal dimuat /
     tidak boleh dibaca), dan yang gagal membawa tombol coba lagi. */
  let fault = null;

  try {
    /* DUA endpoint yang SUDAH ADA — P1-H tidak menambah satu pun. Baseline yang
       memang belum ada bukan galat: proyek yang belum dibekukan tetap punya
       jadwal, dan yang hilang hanyalah bar pembandingnya.

       `api.list` (amplop utuh), bukan `api.get`: endpoint pohon mengirim
       `meta.parent_cycles` ketika sebuah siklus parent_id memaksanya mengangkat
       baris menjadi akar. Barisnya sampai; tempatnya di pohon TIDAK, dan kaki
       kartu mengatakannya alih-alih menggambar jadwal yang tersusun ulang
       diam-diam. */
    const [live, current] = await Promise.all([
      api.list(`projects/${id}/wbs-tasks`),
      api.get('projects/baselines', { project_id: id, current: 1, per_page: 1 })
        .catch((error) => { fault = { stage: 'list', status: error.status || 0 }; return null; }),
    ]);

    tasks = (live && live.data) || [];
    cycles = (live && live.meta && live.meta.parent_cycles) || [];
    today = (live && live.meta && live.meta.as_of) || null;
    const head = Array.isArray(current) ? current[0] : null;

    if (head) {
      baseline = await api.get(`projects/baselines/${head.id}`)
        .catch((error) => {
          fault = { stage: 'show', status: error.status || 0, code: head.code || null };

          return null;
        });
    }
  } catch (error) {
    return clear(host).appendChild(errorState(error, () => renderJadwal(host, { id, project })));
  }

  clear(host);
  paint(host, { id, project, tasks, baseline, cycles, fault, today });
}

function paint(host, ctx) {
  const { id, project, tasks, baseline, cycles, fault, today } = ctx;
  const flat = flatten(tasks);

  if (!flat.length) {
    host.appendChild(el('.card', el('.card-body', el('p.muted', {
      text: 'Proyek ini belum punya WBS. Buat WBS dari BOQ pada tab Ringkasan, atau impor jadwalnya dari '
        + 'berkas MPP-XML — gantt menggambar tugas WBS, bukan laporan mingguan.',
      style: { margin: 0 },
    }))));
    return;
  }

  const { index, duplicates } = indexBaseline(baseline);
  const rows = flat.map((task) => {
    const frozen = index.get(task.wbs_code) || null;

    return {
      label: `${task.wbs_code} ${task.name}`,
      start: task.planned_start,
      end: task.planned_end,
      // 0..100 di kawat (dan sebuah string) → 0..1 di sini.
      progress: toFraction(task.progress_pct),
      baselineStart: frozen ? frozen.planned_start : null,
      baselineEnd: frozen ? frozen.planned_end : null,
      level: task.level,
    };
  });

  const matched = rows.filter((row) => row.baselineStart || row.baselineEnd).length;

  const chart = ganttChart({
    rows,
    zoom: state.zoom,
    weekends: true,
    // null → charts.js jatuh ke jam peramban; itu hanya terjadi bila server
    // benar-benar tidak mengirim as_of.
    today,
    ariaLabel: `Jadwal WBS ${project.code || ''}`.trim(),
    // Catatan sumber ikut TERCETAK (ia di dalam svg), dan di kertas bilah zoom
    // sudah disembunyikan blok cetak — jadi kalimat inilah yang memberi tahu
    // pembaca kertasnya apa yang sedang ia lihat.
    sourceNote: sourceNote(baseline, fault, matched, rows.length),
  });

  host.appendChild(el('.card.gantt-sheet', [
    el('.card-head', [
      // .card-head TIDAK disembunyikan blok cetak — inilah judul yang sampai
      // ke kertas, dan ia harus menyebut proyeknya.
      el('h2', { text: `Jadwal — ${project.code || ''} ${project.name || ''}`.trim() }),
      el('.spacer'),
      el('.filters', { style: { border: '0', margin: '0', padding: '0' } }, [
        ...ZOOMS.map((zoom) => button(zoom.label, {
          size: 'sm',
          variant: state.zoom === zoom.key ? 'primary' : 'ghost',
          onClick: () => {
            if (state.zoom === zoom.key) return;
            state.zoom = zoom.key;
            paint(clear(host), ctx);
          },
        })),
        button('Cetak', { size: 'sm', variant: 'ghost', iconName: 'print', onClick: () => window.print() }),
      ]),
    ]),
    // .chart-scroll: gantt lebarnya 900 px alami dan menggulir mendatar di
    // ponsel; blok cetak P1-A sudah menjadikannya `overflow: visible`.
    el('.card-body', el('.chart-scroll', chart)),
    el('.card-body', { style: { borderTop: '1px solid var(--border)', paddingTop: '10px' } }, [
      el('p.cell-sub', {
        /* ROADMAP: "ketergantungan gantt ditunda ke Fase 2 (kolomnya tidak ada
           — impor MPP-XML mengabaikan PredecessorLink, legenda mengatakannya)."
           Kalimat ini ADALAH legenda yang mengatakannya. */
        text: 'Ketergantungan antar tugas tidak digambar: kolomnya belum ada di basis data, dan impor MPP-XML '
          + 'mengabaikan PredecessorLink. Garis yang digambar dari kolom yang tidak ada akan menjadi jadwal '
          + 'karangan.',
        style: { margin: 0 },
      }),
      duplicates.length
        ? el('p.cell-sub', {
          text: `Kode WBS ganda pada baseline: ${duplicates.join(', ')} — yang dipakai baris terakhir. `
            + 'Kode WBS tidak dijamin unik per proyek oleh basis data.',
          style: { margin: '6px 0 0', color: 'var(--warning)' },
        })
        : null,
      /* Baseline yang GAGAL dibaca: kalimatnya sudah ada di dalam svg (ikut
         tercetak), tetapi kegagalan yang bisa dicoba lagi butuh pintu keluar —
         dan sebuah pintu keluar tidak bisa hidup di dalam svg yang tercetak. */
      fault
        ? el('p.cell-sub', { style: { margin: '6px 0 0', color: 'var(--warning)' } }, [
          el('span', { text: `${faultSentence(fault)} ` }),
          button('Coba lagi', {
            size: 'sm',
            variant: 'ghost',
            onClick: () => renderJadwal(host, { id, project }),
          }),
        ])
        : null,
      /* Siklus parent_id: barisnya tetap tergambar (server mengangkatnya menjadi
         akar), tetapi posisinya di pohon bukan posisi yang tersimpan — dan
         urutan serta indentasi gantt dibaca orang sebagai struktur. */
      (cycles || []).length
        ? el('p.cell-sub', {
          text: `Induk melingkar pada ${cycles.join(', ')}: baris ini menunjuk induk yang justru `
            + 'keturunannya sendiri, jadi ia digambar sebagai akar. Barisnya lengkap, letaknya di '
            + 'pohon tidak — perbaiki induknya di basis data.',
          style: { margin: '6px 0 0', color: 'var(--warning)' },
        })
        : null,
    ]),
  ]));
}

/**
 * Pohon bersarang → daftar rata DALAM URUTAN POHON, dengan `level` diturunkan
 * dari kedalamannya.
 *
 * Urutan penting dan tidak gratis: muatan baseline diurutkan
 * (sort_order, wbs_code), yang menyelang-nyeling induk dan anak dari cabang
 * berbeda (A, A.1, B.1, C.1, A.2, B, …). Menggambar dalam urutan muatan
 * menghasilkan gantt yang acak. Sisi HIDUP bersarang, jadi urutan pohonnya
 * diambil dari sarangnya sendiri.
 *
 * `prj_wbs_tasks` tidak punya kolom level/depth/path — hierarkinya `parent_id`
 * plus konvensi kode bertitik. Kedalaman karena itu dihitung di sini.
 */
function flatten(tasks, level = 0, out = []) {
  (tasks || []).forEach((task) => {
    out.push({ ...task, level });
    if (Array.isArray(task.children) && task.children.length) flatten(task.children, level + 1, out);
  });

  return out;
}

/**
 * Baris baseline diindeks per `wbs_code`.
 *
 * Basis data TIDAK menjamin `wbs_code` unik per proyek (indeks
 * (project_id, wbs_code) bukan unique, dan aturan validasinya hanya
 * required/string/max:20). Sebuah Map diam-diam menyimpan yang terakhir; di
 * sini tabrakannya DIHITUNG dan disebutkan di bawah gantt — angka yang salah
 * yang mengaku dirinya salah lebih baik daripada angka yang salah dan diam.
 */
function indexBaseline(baseline) {
  const index = new Map();
  const duplicates = [];

  ((baseline && baseline.tasks) || []).forEach((task) => {
    if (!task || !task.wbs_code) return;
    if (index.has(task.wbs_code) && !duplicates.includes(task.wbs_code)) duplicates.push(task.wbs_code);
    index.set(task.wbs_code, task);
  });

  return { index, duplicates };
}

/** '60.0000' (0..100) → 0.6 (0..1); null tetap null, bukan 0. */
function toFraction(value) {
  if (value === null || value === undefined || value === '') return null;

  const number = Number(value);

  return Number.isFinite(number) ? number / 100 : null;
}

/**
 * Kenapa bar pembanding tidak ada, dalam kalimat yang membedakan "sudah
 * ditanya, jawabannya tidak ada" dari "tidak bisa ditanya".
 *
 * `stage: 'list'` — daftar baselinenya sendiri gagal: layar ini TIDAK TAHU
 * apakah proyek ini punya rencana beku, dan mengatakan "belum ada" di situ
 * adalah mengarang fakta tentang rencana yang disepakati.
 * `stage: 'show'` — daftarnya menjawab, isinya yang gagal: keberadaan
 * baselinenya justru diketahui, lengkap dengan kodenya.
 */
function faultSentence(fault) {
  const how = fault.status === 403
    ? 'tidak boleh dibaca oleh peran Anda'
    : fault.status
      ? `gagal dimuat (galat HTTP ${fault.status})`
      : 'gagal dimuat (sambungan ke server terputus)';

  return fault.stage === 'show'
    ? `Baseline ${fault.code || 'yang berlaku'} ada, tetapi isinya ${how} — bar pembanding tidak digambar.`
    : `Daftar baseline ${how}, jadi layar ini tidak tahu apakah proyek ini punya rencana beku — `
      + 'bar pembanding tidak digambar.';
}

/** Kalimat di dalam svg — ikut tercetak, dan menyebut apa yang TIDAK cocok. */
function sourceNote(baseline, fault, matched, total) {
  const zoom = state.zoom === 'month' ? 'bulanan' : 'mingguan';
  const scale = `Skala ${zoom}`;

  if (!baseline) {
    return fault
      ? `${scale} · ${faultSentence(fault)} Yang tergambar hanya rencana WBS yang berlaku sekarang.`
      : `${scale} · belum ada baseline beku, jadi tidak ada bar pembanding — yang tergambar hanya rencana `
        + 'WBS yang berlaku sekarang.';
  }

  const missing = total - matched;

  return `${scale} · baseline ${baseline.code || ''} (${matched} dari ${total} tugas cocok`
    + (missing ? `, ${missing} tanpa pasangan` : '')
    + '), dicocokkan menurut kode WBS.';
}
