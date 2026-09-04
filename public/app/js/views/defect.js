/* Register defect (punch list) — daftar temuan yang menahan serah terima kedua,
   dan satu-satunya bukti di balik uang yang ditahan pelanggan karenanya.

   Retensi 5% CTR/2026/I/0001 berjumlah Rp 2.425.000.000 dari nilai kontrak
   Rp 48,5 M. Uang itu ditahan sebagai jaminan atas cacat pekerjaan sepanjang
   masa pemeliharaan 12 bulan — sementara sampai layar ini ada, tidak satu pun
   cacat tercatat di mana pun dalam sistem. Prasyarat BAST II membaca register
   ini: satu temuan kritis atau mayor yang masih terbuka menolak persetujuan
   BAST II lengkap dengan daftar kodenya. Tanpa layar untuk mengisinya, register
   yang kosong lolos prasyarat itu tanpa perlawanan, dan "kosong" berarti belum
   ada yang memeriksa — bukan pekerjaannya bersih.

   Angka ringkasan datang dari server berikut tanggal posisinya (as_of), bukan
   dari jam browser: yang membaca layar ini sedang memutuskan pelepasan uang. */

import { api, session } from '../api.js';
import {
  el, clear, button, badge, icon, toast, toastError,
  errorState, emptyState, skeletonTable, confirmDialog,
} from '../ui.js';
import * as fmt from '../format.js';
import { ENUMS } from '../enums.js';
import { loadSource, optionsFor, rowFor } from '../lookup.js';
import { promptFields } from './form.js';
import { RESOURCES } from '../schema.js';
import { navigate } from '../router.js';

/* Dipertahankan antar navigasi, seperti layar daftar generik: seorang PM yang
   membuka satu temuan lalu kembali tidak kehilangan proyek dan saringannya. */
const state = {
  q: '',
  projectId: '',
  severity: '',
  source: '',
  status: '',
  scope: 'terbuka',
  page: 1,
};

const PER_PAGE = 20;

/* "Masih terbuka" dan "Lewat target" adalah pertanyaan, bukan status: keduanya
   hanya MEMBUANG closed dan waived, jadi menunggu-verifikasi tetap ikut
   terhitung — lihat DefectStatus::isOpen(). */
const SCOPES = [
  { value: 'terbuka', label: 'Masih terbuka' },
  { value: 'lewat', label: 'Lewat target perbaikan' },
  { value: 'semua', label: 'Semua temuan' },
];

/* Label pendek untuk badge; kalimat panjang dari server (severity_label) dipakai
   sebagai tooltip supaya artinya tetap terbaca utuh. */
const SEVERITY = {
  critical: ['Kritis', 'red'],
  major: ['Mayor', 'amber'],
  minor: ['Minor', ''],
};

/* Warna status datang dari ENUMS.defectStatus (enums.js) lewat
   fmt.statusTone(value, 'defectStatus'), bukan peta pribadi lagi: sebelumnya
   register ini merah untuk temuan terbuka sementara halaman detail temuan yang
   sama (detail.js) hijau (diukur 4 Sep 2026: DEF/2026/IX/0001). Alasan
   merahnya tertulis di enum itu. */

/** Umur temuan memakai skala beban yang sama dengan antrean siap-tagih. */
function warnaUmur(days) {
  if (days === null || days === undefined) return 'var(--text)';
  if (days >= 60) return 'var(--danger)';
  if (days >= 30) return 'var(--warning)';
  return 'var(--text)';
}

function statCard(label, value, hint, alarming = false) {
  return el('.stat', [
    el('.label', { text: label }),
    el('.value.sm', { text: value }),
    hint ? el(`.delta${alarming ? '.down' : ''}`, { text: hint }) : null,
  ]);
}

function enumSelect(placeholder, list, value, onChange) {
  const select = el('select.filter-w', { 'aria-label': placeholder, title: placeholder });
  select.appendChild(el('option', { value: '', text: placeholder }));
  list.forEach((option) => select.appendChild(el('option', { value: option.value, text: option.label })));
  select.value = value || '';
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

function projectSelect(projects, value, onChange) {
  const select = el('select.filter-w', { 'aria-label': 'Proyek', title: 'Proyek' });
  select.appendChild(el('option', { value: '', text: 'Semua proyek' }));
  optionsFor('projects', projects).forEach((option) =>
    select.appendChild(el('option', { value: option.value, text: option.label })));
  select.value = value || '';
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

/**
 * Isian satu temuan. `row` kosong berarti temuan baru.
 *
 * resolution_note sengaja tidak ada di sini walaupun API menerimanya: catatan
 * itu ditulis oleh Dispensasi dan Buka kembali, dan membiarkannya diketik ulang
 * lewat Ubah berarti alasan dispensasi atas retensi Rp 2,4 miliar bisa diganti
 * tanpa jejak — prj_defects memang tidak masuk daftar model yang diaudit.
 */
function isianTemuan(row) {
  const isEdit = Boolean(row);

  return [
    isEdit ? null : {
      key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', required: true,
      default: state.projectId ? Number(state.projectId) : undefined,
      help: 'Proyek tidak dapat dipindah setelah temuan tersimpan.',
    },
    {
      key: 'title', label: 'Temuan', type: 'text', required: true, maxlength: 200,
      default: isEdit ? row.title : undefined,
      help: 'Satu kalimat yang bisa dicari kembali, mis. "Lift barang tidak level di lantai 5".',
    },
    {
      key: 'severity', label: 'Keparahan', type: 'select', enum: 'defectSeverity', required: true,
      default: isEdit ? row.severity : undefined,
      help: 'Kritis dan Mayor menahan BAST II sampai diverifikasi selesai atau diberi dispensasi. Minor hanya memunculkan peringatan.',
    },
    {
      key: 'source', label: 'Sumber temuan', type: 'select', enum: 'defectSource', required: true,
      default: isEdit ? row.source : undefined,
    },
    {
      key: 'location', label: 'Lokasi', type: 'text', maxlength: 150,
      default: isEdit ? row.location : undefined,
      help: 'Mis. "Lantai 5 zona B" — yang dicari orang saat menyusuri punch list di lokasi.',
    },
    {
      key: 'responsible_employee_id', label: 'Penanggung jawab perbaikan', type: 'lookup', lookup: 'employees',
      default: isEdit ? row.responsible_employee_id : undefined,
    },
    {
      key: 'due_date', label: 'Target perbaikan', type: 'date',
      default: isEdit ? row.due_date : undefined,
      help: 'Lewat tanggal ini temuan dihitung terlambat, selama belum diverifikasi atau didispensasi.',
    },
    {
      key: 'reported_on', label: 'Tanggal temuan', type: 'date',
      default: isEdit ? row.reported_on : fmt.today(),
      help: 'Umur temuan dihitung dari tanggal ini.',
    },
    {
      key: 'description', label: 'Uraian', type: 'textarea', rows: 3, maxlength: 5000,
      default: isEdit ? row.description : undefined,
    },
  ].filter(Boolean);
}

export async function renderDefects(host) {
  clear(host);

  /* Daftar proyek dihangatkan SEBELUM satu elemen pun dipasang, bukan di
     tengah-tengah render. Menunggunya di tengah meninggalkan jendela kecil
     tempat tombol Muat ulang sudah bisa diklik sementara separuh isi layar —
     termasuk penangan tindakan barisnya — belum ada. Setelah baris ini seluruh
     penyusunan layar berjalan dalam satu tarikan napas. */
  const projects = await loadSource('projects').catch(() => []);

  const canCreate = session.can('prj.create');
  const canUpdate = session.can('prj.update');
  const canApprove = session.can('prj.approve');
  const canDelete = session.can('prj.delete');

  /* Kolom tindakan hanya ada bila akun ini memang bisa melakukan sesuatu.
     Menghitungnya dari izin, bukan dari isi baris, supaya kepala tabel tidak
     pernah punya satu kolom lebih banyak daripada barisnya. */
  const adaTindakan = canUpdate || canApprove || canDelete;

  /* Layar detail hanya ada bila register terdaftar di schema.js. Tanpa itu baris
     tetap terbaca lengkap di sini, hanya tidak bisa diklik ke halamannya
     sendiri — dan tautan dari pemberitahuan tidak akan mendarat. */
  const punyaDetail = Boolean(RESOURCES['projects/defects']);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Register Defect (Punch List)' }),
      el('.desc', {
        text: 'Daftar temuan pekerjaan beserta perbaikan dan penerimaannya. Temuan kritis dan '
          + 'mayor yang masih terbuka menahan BAST II — dan retensi yang menunggu di belakangnya.',
      }),
    ]),
    el('.actions', [
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => load() }),
      canCreate
        ? button('Catat temuan', { variant: 'primary', iconName: 'plus', onClick: () => catatTemuan() })
        : null,
    ]),
  ]));

  /* ------------------------------------------------------------- saringan */
  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });

  const searchInput = el('input', {
    type: 'text', value: state.q,
    placeholder: 'Cari kode, temuan, lokasi…', 'aria-label': 'Cari temuan',
  });
  let searchTimer;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      state.q = searchInput.value.trim();
      state.page = 1;
      load();
    }, 320);
  });

  const scopeInput = el('select.filter-w', { 'aria-label': 'Tampilkan', title: 'Tampilkan' });
  SCOPES.forEach((option) => scopeInput.appendChild(el('option', { value: option.value, text: option.label })));
  scopeInput.value = state.scope;
  scopeInput.addEventListener('change', () => {
    state.scope = scopeInput.value;
    state.page = 1;
    load();
  });

  const statusInput = enumSelect('Semua status', ENUMS.defectStatus, state.status, (value) => {
    state.status = value;
    /* Memilih "Selesai (terverifikasi)" atau "Dispensasi pelanggan" sambil
       bertahan pada tampilan "Masih terbuka" mengembalikan nol baris selamanya:
       open=1 di API justru MEMBUANG kedua status itu. Jadi memilih salah satunya
       melebarkan tampilan sekalian, dan selectnya ikut berubah supaya yang
       terjadi kelihatan. */
    if ((value === 'closed' || value === 'waived') && state.scope !== 'semua') {
      state.scope = 'semua';
      scopeInput.value = 'semua';
    }
    state.page = 1;
    load();
  });

  const severityInput = enumSelect('Semua keparahan', ENUMS.defectSeverity, state.severity, (value) => {
    state.severity = value;
    state.page = 1;
    load();
  });

  const sourceInput = enumSelect('Semua sumber', ENUMS.defectSource, state.source, (value) => {
    state.source = value;
    state.page = 1;
    load();
  });

  const projectInput = projectSelect(projects, state.projectId, (value) => {
    state.projectId = value;
    state.page = 1;
    load();
  });

  /* Saringan proyek bertahan antar navigasi, daftar proyeknya tidak selalu.
     Sebuah <select> yang tidak punya option dengan nilai itu diam-diam jatuh ke
     "Semua proyek", sementara state masih menyaring ke proyek yang hilang — dan
     layar lalu menampilkan satu proyek sambil mengaku menampilkan semuanya. */
  state.projectId = projectInput.value;

  controls.append(
    el('.search', [icon('search', 14), searchInput]),
    projectInput,
    scopeInput,
    severityInput,
    statusInput,
    sourceInput,
  );

  host.appendChild(controls);

  const body = el('div');
  host.appendChild(body);

  /* -------------------------------------------------------------- tindakan */
  /**
   * Satu pembungkus untuk semua tindakan baris: tampilkan kegagalan sebagai
   * toast, muat ulang hanya bila sesuatu benar-benar terjadi. `false` berarti
   * dialognya dibatalkan — memuat ulang tabel untuk sebuah pembatalan cuma
   * mengedipkan layar tanpa alasan.
   */
  function bungkus(kerja) {
    return async () => {
      try {
        if ((await kerja()) === false) return;
        load();
      } catch (error) {
        toastError(error);
      }
    };
  }

  const tandaiSelesai = (row) => bungkus(async () => {
    const values = await promptFields(`Selesai diperbaiki — ${row.code}`, [
      {
        key: 'fixed_at', label: 'Tanggal perbaikan selesai', type: 'date', default: fmt.today(),
        help: 'Temuan pindah ke "Menunggu verifikasi" dan MASIH dihitung terbuka: belum ada yang menerimanya.',
      },
    ], { submitLabel: 'Tandai selesai diperbaiki' });
    if (values === null) return false;

    await api.post(`projects/defects/${row.id}/fixed`, values);
    toast(`${row.code} menunggu verifikasi.`);
    return true;
  });

  const verifikasi = (row) => bungkus(async () => {
    const values = await promptFields(`Verifikasi selesai — ${row.code}`, [
      {
        key: 'verified_at', label: 'Tanggal diterima', type: 'date', default: fmt.today(),
        help: 'Ini penerimaan atas perbaikannya, dan baris inilah yang dihitung prasyarat BAST II.',
      },
    ], { submitLabel: 'Verifikasi selesai' });
    if (values === null) return false;

    await api.post(`projects/defects/${row.id}/verify`, values);
    toast(`${row.code} diverifikasi selesai.`);
    return true;
  });

  const dispensasi = (row) => bungkus(async () => {
    const values = await promptFields(`Dispensasi pelanggan — ${row.code}`, [
      {
        key: 'reason', label: 'Alasan dispensasi', type: 'textarea', rows: 3, required: true,
        help: 'Minimal 10 karakter. Ini satu-satunya jalan melewati blokir BAST II untuk temuan '
          + 'kritis/mayor, jadi tulis siapa yang menerima dan atas dasar apa.',
      },
      { key: 'waived_at', label: 'Tanggal dispensasi', type: 'date', default: fmt.today() },
    ], { submitLabel: 'Catat dispensasi' });
    if (values === null) return false;

    await api.post(`projects/defects/${row.id}/waive`, values);
    toast(`${row.code} diterima pelanggan apa adanya.`);
    return true;
  });

  const bukaKembali = (row) => bungkus(async () => {
    const values = await promptFields(`Buka kembali — ${row.code}`, [
      {
        key: 'reason', label: 'Alasan dibuka kembali', type: 'textarea', rows: 3, required: true,
        help: 'Minimal 10 karakter. Alasannya ditulis di ATAS catatan lama, bukan menggantinya: '
          + 'kenapa satu item kembali hanya terbaca di sebelah apa yang dulu diklaim tentangnya.',
      },
    ], { submitLabel: 'Buka kembali temuan' });
    if (values === null) return false;

    await api.post(`projects/defects/${row.id}/reopen`, values);
    toast(`${row.code} kembali ke perbaikan.`);
    return true;
  });

  const ubahTemuan = (row) => bungkus(async () => {
    const values = await promptFields(`Ubah ${row.code}`, isianTemuan(row), { submitLabel: 'Simpan perubahan' });
    if (values === null) return false;

    await api.put(`projects/defects/${row.id}`, values);
    toast(`${row.code} diperbarui.`);
    return true;
  });

  const hapusTemuan = (row) => async () => {
    await confirmDialog({
      title: `Hapus temuan ${row.code}`,
      message: `Hapus "${row.title}"? Hanya temuan yang belum ditindaklanjuti yang boleh dihapus. `
        + 'Bila pelanggan menerimanya apa adanya, pakai Dispensasi supaya alasannya tersimpan.',
      confirmLabel: 'Hapus',
      onConfirm: async () => {
        await api.del(`projects/defects/${row.id}`);
        toast(`Temuan ${row.code} dihapus.`);
        load();
      },
    });
  };

  /* Deklarasi fungsi, bukan const: tombol "Catat temuan" di kepala halaman sudah
     terpasang sebelum baris ini dibaca, dan sebuah const akan meninggalkannya
     menunjuk ke variabel yang belum ada. */
  async function catatTemuan() {
    try {
      const values = await promptFields('Catat temuan baru', isianTemuan(null), { submitLabel: 'Simpan temuan' });
      if (values === null) return;

      const saved = await api.post('projects/defects', values);
      toast(`Temuan ${saved.code} tercatat.`);
      state.page = 1;
      load();
    } catch (error) {
      toastError(error);
    }
  }

  /** Selalu mengembalikan satu <td>, walau kosong — lihat `adaTindakan`. */
  function tombolBaris(row) {
    const terminal = row.status === 'closed' || row.status === 'waived';
    const buttons = [];

    if (canUpdate && !terminal) {
      buttons.push(button('Selesai diperbaiki', { size: 'sm', onClick: tandaiSelesai(row) }));
    }
    if (canApprove && !terminal) {
      buttons.push(button('Verifikasi', {
        size: 'sm',
        // Menonjol hanya saat memang giliran verifikator. Pada temuan yang belum
        // dinyatakan diperbaiki tindakan ini tetap sah — MK memang sering
        // menutup item di tempat saat menyusuri punch list — tetapi bukan tombol
        // yang seharusnya menarik jari lebih dulu.
        variant: row.status === 'ready_for_review' ? 'primary' : '',
        onClick: verifikasi(row),
      }));
      buttons.push(button('Dispensasi', { size: 'sm', variant: 'ghost', onClick: dispensasi(row) }));
    }
    if (canApprove && terminal) {
      buttons.push(button('Buka kembali', { size: 'sm', onClick: bukaKembali(row) }));
    }
    if (canUpdate && !terminal) {
      buttons.push(button('', { size: 'sm', variant: 'ghost', iconName: 'edit', title: 'Ubah', onClick: ubahTemuan(row) }));
    }
    // Sama seperti servicenya: apa pun yang sudah diperbaiki, diterima atau
    // didispensasi adalah bukti pada proyek yang retensinya bergantung padanya.
    if (canDelete && row.status === 'open' && !row.fixed_at) {
      buttons.push(button('', { size: 'sm', variant: 'ghost', iconName: 'trash', title: 'Hapus', onClick: hapusTemuan(row) }));
    }

    // Satu stopPropagation untuk seluruh sel: barisnya bisa diklik menuju detail,
    // dan tanpa ini setiap tombol tindakan juga ikut memindahkan halaman.
    return el('td.right', { onclick: (event) => event.stopPropagation() },
      el('.row-actions', { style: { justifyContent: 'flex-end' } }, buttons));
  }

  /* ----------------------------------------------------------------- tabel */
  function baris(row, tampilkanProyek) {
    const [labelKeparahan, warnaKeparahan] = SEVERITY[row.severity] || [row.severity_label || row.severity, ''];
    const tempat = [
      row.location,
      row.wbs_task ? `${row.wbs_task.wbs_code} ${row.wbs_task.name}`.trim() : null,
    ].filter(Boolean).join(' · ');

    const tr = el(`tr${punyaDetail ? '.clickable' : ''}`, [
      el('td', el('span', [
        el('span.cell-main.mono', { text: row.code }),
        el('span.cell-sub', { text: row.source_label || row.source || '' }),
      ])),
      el('td', el('span', [
        el('span.cell-main', { text: row.title }),
        el('span.cell-sub', { text: tempat || 'lokasi tidak dicatat' }),
      ])),
      tampilkanProyek
        ? el('td', el('span', [
          el('span.cell-main.mono', { text: row.project ? row.project.code : '—' }),
          row.project ? el('span.cell-sub', { text: row.project.name }) : null,
        ]))
        : null,
      el('td', el('span', { title: row.severity_label || '' }, [
        badge(labelKeparahan, warnaKeparahan),
        row.blocks_handover ? el('.cell-sub', { text: 'menahan BAST II' }) : null,
      ])),
      el(`td${row.responsible_employee ? '' : '.muted'}`, {
        text: row.responsible_employee ? row.responsible_employee.name : 'belum ditunjuk',
      }),
      el('td', row.due_date
        ? el('span', [
          el('span', { text: fmt.date(row.due_date) }),
          // "Lewat target" datang dari server (is_overdue), bukan dari
          // perbandingan tanggal di browser: jam klien tidak dipakai untuk angka
          // yang jadi dasar orang mengambil keputusan.
          row.is_overdue ? el('div', badge('Lewat target', 'red')) : null,
        ])
        : el('span.muted', { text: 'tanpa target' })),
      el('td.right.num.strong', {
        text: row.days_open === null || row.days_open === undefined ? '—' : `${row.days_open} hari`,
        style: { color: warnaUmur(row.days_open) },
      }),
      el('td', badge(row.status_label || row.status, fmt.statusTone(row.status, 'defectStatus'))),
      adaTindakan ? tombolBaris(row) : null,
    ]);

    if (punyaDetail) {
      tr.addEventListener('click', () => navigate(`d/projects/defects/${row.id}`));
    }

    return tr;
  }

  function pager(meta) {
    const lastPage = meta.last_page || 1;
    const halaman = meta.current_page || state.page;

    return el('.pager', [
      el('span', {
        text: meta.total === undefined
          ? `Halaman ${halaman}`
          : `Menampilkan ${meta.from || 0}–${meta.to || 0} dari ${meta.total} temuan`,
      }),
      el('.spacer'),
      button('', {
        size: 'sm', iconName: 'back', title: 'Sebelumnya',
        disabled: halaman <= 1,
        onClick: () => { state.page = halaman - 1; load(); },
      }),
      el('span.num', { text: `${halaman} / ${lastPage}` }),
      button('', {
        size: 'sm', iconName: 'chevronRight', title: 'Berikutnya',
        disabled: halaman >= lastPage,
        onClick: () => { state.page = halaman + 1; load(); },
      }),
    ]);
  }

  function caraKerjanya() {
    return el('.card', [
      el('.card-head', el('h2', { text: 'Cara kerjanya' })),
      el('.card-body', [
        el('p', { text: 'Alurnya pendek dan tiap langkah berarti satu hal. "Selesai diperbaiki" dikatakan oleh yang mengerjakan (izin prj.update) dan belum menutup apa pun. "Verifikasi" dikatakan oleh yang menerima pekerjaan (izin prj.approve) — baris inilah yang dihitung prasyarat BAST II. "Dispensasi" berarti pelanggan menerima item itu apa adanya. "Buka kembali" untuk perbaikan yang tidak bertahan.' }),
        el('p', { text: '"Menunggu verifikasi" masih dihitung TERBUKA, termasuk oleh prasyarat BAST II. BAST II adalah penerimaan pelanggan, jadi item yang baru diklaim selesai belum diterima siapa pun.' }),
        el('p', { text: 'Kritis dan Mayor menahan BAST II: satu saja yang masih terbuka membuat persetujuan BAST II ditolak, lengkap dengan daftar kodenya. Minor hanya memunculkan peringatan, yang boleh dilewati dengan alasan tertulis minimal 20 karakter pada layar BAST.' }),
        el('p', { text: 'Dispensasi dan Buka kembali wajib disertai alasan minimal 10 karakter, dan alasannya tersimpan pada temuannya — bukan sebagai satu kalimat pada persetujuan BAST senilai miliaran rupiah. Temuan yang sudah ditindaklanjuti tidak bisa dihapus; kalau pelanggan menerimanya apa adanya, itulah gunanya Dispensasi.' }),
        el('p', { text: 'Temuan boleh dicatat pada proyek berstatus apa pun, termasuk yang sudah ditutup: klaim masa pemeliharaan datang setelah BAST I dan harus punya tempat mendarat. Temuan pada proyek yang sudah diserahterimakan — dan setiap temuan kritis di mana pun — langsung memberitahu pemegang izin prj.update, yaitu orang-orang yang akan membayar perbaikannya.' }),
      ]),
    ]);
  }

  /* ------------------------------------------------------------------ muat */
  async function load() {
    clear(body).appendChild(skeletonTable(6, 6));

    let payload;
    let summary;

    try {
      [payload, summary] = await Promise.all([
        api.list('projects/defects', {
          per_page: PER_PAGE,
          page: state.page,
          q: state.q || undefined,
          project_id: state.projectId || undefined,
          severity: state.severity || undefined,
          source: state.source || undefined,
          status: state.status || undefined,
          open: state.scope === 'terbuka' ? 1 : undefined,
          overdue: state.scope === 'lewat' ? 1 : undefined,
        }),
        api.get('projects/defects/summary', { project_id: state.projectId || undefined }),
      ]);
    } catch (error) {
      return clear(body).appendChild(errorState(error, load));
    }

    const rows = payload.data || [];
    const meta = payload.meta || {};

    /* Memverifikasi baris terakhir di halaman 3 pada tampilan "Masih terbuka"
       membuang baris itu dari hasil, dan halaman 3 lalu kosong tanpa alasan yang
       terlihat. Aman dari pengulangan: last_page tidak pernah kurang dari 1,
       jadi kunjungan berikutnya tidak lagi memenuhi syarat page > 1. */
    if (!rows.length && state.page > 1) {
      state.page = Math.max(1, meta.last_page || 1);
      return load();
    }

    clear(body);

    const proyek = state.projectId ? rowFor('projects', state.projectId) : null;
    const lingkup = proyek ? `proyek ${proyek.code}` : 'seluruh proyek';

    body.appendChild(el('.stat-row', [
      statCard('Temuan terbuka', String(summary.open_count),
        'termasuk yang menunggu verifikasi'),
      statCard('Menahan BAST II', String(summary.open_blocking_count),
        summary.open_blocking_count > 0
          ? 'kritis/mayor yang belum diterima pelanggan'
          : 'tidak ada yang menahan serah terima',
        summary.open_blocking_count > 0),
      statCard('Lewat target perbaikan', String(summary.overdue_count),
        summary.overdue_count > 0 ? 'target perbaikannya sudah lewat' : 'semuanya di dalam target',
        summary.overdue_count > 0),
      statCard('Terbuka terlama',
        summary.oldest_open_days === null || summary.oldest_open_days === undefined
          ? '—'
          : `${summary.oldest_open_days} hari`,
        summary.oldest_open_code || 'tidak ada temuan terbuka'),
      statCard('Posisi per', fmt.date(summary.as_of), `${summary.total} temuan tercatat`),
    ]));

    /* Ringkasan hanya mengikuti proyek, tidak mengikuti saringan lain — dan
       angka yang basisnya tidak tertulis akan dibaca sebagai jawaban atas
       pertanyaan yang sedang ada di layar. */
    body.appendChild(el('p.muted', {
      text: `Lima angka di atas dihitung server dari SELURUH temuan ${lingkup} per `
        + `${fmt.date(summary.as_of)}. Saringan di bawah hanya mengubah isi tabel, bukan angka-angka itu.`,
      style: { fontSize: '11.5px', margin: '0 0 16px' },
    }));

    if (summary.open_blocking_count > 0) {
      body.appendChild(el('.alert.warn', [
        icon('warn', 16),
        el('div', { style: { flex: '1' } }, [
          el('div', {
            text: `${summary.open_blocking_count} temuan kritis/mayor masih terbuka pada ${lingkup}. `
              + 'Persetujuan BAST II akan ditolak beserta daftar kodenya sampai semuanya diverifikasi '
              + 'selesai atau diberi dispensasi — dan retensi baru boleh ditagih setelah BAST II.',
          }),
        ]),
        button('Lihat BAST', { size: 'sm', onClick: () => navigate('r/projects/bast') }),
      ]));
    }

    /* Register kosong LOLOS blokir BAST II tanpa perlawanan. Itu informasi,
       bukan penegakan — dan justru di momen inilah orang melepas Rp 2,4 miliar. */
    if (summary.total === 0) {
      body.appendChild(el('.alert.info',
        `Belum ada satu pun temuan tercatat pada ${lingkup}. Prasyarat BAST II membaca register ini, `
        + 'jadi register yang kosong lolos begitu saja — dan itu berarti belum ada yang memeriksa, '
        + 'bukan berarti pekerjaannya bersih.'));
    }

    // Kolom proyek hanya berguna saat belum ada proyek yang dipilih; kalau sudah,
    // ia mengulang isi saringan di setiap baris.
    const tampilkanProyek = !state.projectId;

    const card = el('.card', el('.card-head', [
      el('h2', { text: 'Daftar temuan' }),
      el('.cell-sub', { text: 'kritis lalu mayor, yang paling lama tercatat di atas' }),
    ]));

    if (!rows.length) {
      card.appendChild(emptyState(
        summary.total === 0
          ? 'Belum ada temuan yang tercatat.'
          : 'Tidak ada temuan yang cocok dengan pencarian atau saringan di atas.',
        {
          title: 'Tidak ada baris',
          action: canCreate
            ? button('Catat temuan', { variant: 'primary', iconName: 'plus', onClick: () => catatTemuan() })
            : null,
        },
      ));
      body.append(card, caraKerjanya());
      return;
    }

    card.appendChild(el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }),
        el('th', { text: 'Temuan' }),
        tampilkanProyek ? el('th', { text: 'Proyek' }) : null,
        el('th', { text: 'Keparahan' }),
        el('th', { text: 'Penanggung jawab' }),
        el('th', { text: 'Target perbaikan' }),
        el('th.right', { text: 'Umur' }),
        el('th', { text: 'Status' }),
        adaTindakan ? el('th.right', { text: '', style: { width: '1%' } }) : null,
      ])),
      el('tbody', rows.map((row) => baris(row, tampilkanProyek))),
    ])));

    card.appendChild(pager(meta));
    body.append(card, caraKerjanya());

    body.appendChild(el('.row-actions', [
      button('Buka BAST proyek', { iconName: 'chevron', onClick: () => navigate('r/projects/bast') }),
    ]));
  }

  await load();
}
