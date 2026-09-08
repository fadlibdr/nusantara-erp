/*
 * Kartu Aktivitas CRM untuk layar prospek, penawaran dan pelanggan (F-3 / T3.2).
 *
 * Kawatnya sama dengan kartu Lampiran: satu baris di renderDetail, dan
 * KEANGGOTAANNYA diputuskan cermin registri di dalam kartu ini sendiri
 * (ACTIVITY_DOCUMENTS ⇄ Modules\Crm\Support\ActivityDocuments, dijaga
 * tests/Feature/Crm/ActivityRegistryTest.php — slug yang hanya ada di satu sisi
 * adalah kartu yang selalu 422, atau dokumen yang diam-diam tidak bisa mencatat
 * satu pun aktivitas).
 *
 * EMPAT ATURAN KEJUJURAN YANG MEMBENTUK BERKAS INI.
 *
 *  1. KARTU KOSONG MENGATAKAN DIRINYA KOSONG — "Belum ada aktivitas dicatat",
 *     bukan "0 aktivitas". Angka nol yang dipajang sebagai hasil pengukuran
 *     adalah kebohongan kecil yang paling sering dipercaya: yang membacanya
 *     mengira ada yang sudah memeriksa dan menemukan tidak ada apa-apa.
 *  2. LEWAT TANGGAL DIHITUNG SERVER (`is_overdue`). Jam peramban yang meleset
 *     dua hari akan mewarnai baris yang salah, dan warna itulah yang dipakai
 *     orang untuk memilih pekerjaan hari ini.
 *  3. KARTU PROSPEK MENYEBUT DARI MANA TANGGAL TINDAK LANJUTNYA DATANG. Kolom
 *     itu turunan sejak F-3 (T3.3) dan tidak bisa lagi diketik di formulir;
 *     tanpa kalimat ini, satu-satunya penjelasannya adalah 422 yang baru muncul
 *     setelah orangnya terlanjur mengetik.
 *  4. YANG DIPOTONG DIAKUI, DAN JUMLAHNYA DATANG DARI SERVER (verifikasi F-3,
 *     8 Sep 2026). Kartu ini mengambil paling banyak PER_CARD baris; sampai
 *     hari itu ia memakai `api.get`, yang membuang amplopnya, lalu menghitung
 *     ringkasannya dari baris yang kebetulan termuat — "100 terbuka." pada
 *     dokumen berisi 110, tanpa satu kalimat pun yang mengakui sisanya. Kini
 *     `api.list` membawa `meta.total`, kalimat "N dari M digambar" muncul
 *     seperti di papan, dan pada kartu yang terpotong angka terbuka/lewat
 *     tanggal ditanyakan lagi ke server alih-alih ditaksir dari yang terlihat.
 */

import { api, session } from '../api.js';
import { el, clear, button, badge, confirmDialog, errorState, toast, toastError, withBusy } from '../ui.js';
import * as fmt from '../format.js';
import { promptFields } from './form.js';

/**
 * Slug layar → jenis dokumen di server. Cermin ActivityDocuments::DOCUMENTS.
 */
export const ACTIVITY_DOCUMENTS = {
  'crm/leads': 'lead',
  'crm/quotations': 'quotation',
  'crm/customers': 'customer',
};

/** Cermin Modules\Crm\Enums\ActivityType. */
const TYPES = [
  { value: 'call', label: 'Telepon' },
  { value: 'meeting', label: 'Rapat' },
  { value: 'email', label: 'Email' },
  { value: 'visit', label: 'Kunjungan' },
  { value: 'note', label: 'Catatan' },
];

const FIELDS = [
  { key: 'type', label: 'Jenis', type: 'select', options: TYPES, default: 'call', required: true },
  { key: 'subject', label: 'Kegiatan', type: 'text', required: true, span: 2 },
  {
    key: 'due_at', label: 'Jatuh tempo', type: 'date',
    help: 'Boleh kosong untuk catatan atas sesuatu yang sudah terjadi. Tanggal, bukan jam — '
      + '"hubungi lagi Senin depan" adalah sebuah hari.',
  },
  {
    key: 'owner_user_id', label: 'Pemilik', type: 'lookup', lookup: 'users',
    help: 'Boleh kosong. Yang kosong berbunyi "Belum ditugaskan" — tidak ditebak dari siapa yang mengetiknya.',
  },
  { key: 'notes', label: 'Catatan', type: 'textarea', span: 2 },
];

/** Satu baris aktivitas. */
function activityRow(activity, { canEdit, onChanged }) {
  const done = !activity.is_open;

  const meta = [
    activity.type_label,
    done
      ? `Selesai ${fmt.dateTime(activity.done_at)}${activity.done_by_name ? ` oleh ${activity.done_by_name}` : ''}`
      : (activity.due_at ? `Jatuh tempo ${fmt.date(activity.due_at)} · ${fmt.relativeDays(activity.due_at)}` : 'Tanpa tanggal'),
    activity.owner_user_name,
  ].filter(Boolean).join(' · ');

  return el('.attachment', [
    el('.attachment-main', [
      el('.attachment-name', { style: { display: 'flex', gap: '6px', alignItems: 'center', flexWrap: 'wrap' } }, [
        el('span', { text: activity.subject }),
        // Warna datang dari server (is_overdue): jam peramban tidak pernah
        // memutuskan pekerjaan siapa yang terlambat.
        activity.is_overdue ? badge('Lewat tanggal', 'red') : null,
        done ? badge('Selesai', 'green') : null,
      ]),
      el('.cell-sub', { text: meta }),
      activity.notes ? el('.cell-sub', { text: activity.notes }) : null,
    ]),
    canEdit
      ? el('.row-actions', [
        done
          ? button('Buka kembali', {
            size: 'sm', variant: 'ghost', iconName: 'refresh',
            title: 'Batalkan cap selesai — mis. satu klik pada baris yang salah',
            onClick: (event) => withBusy(event.currentTarget, async () => {
              try {
                await api.post(`crm/activities/${activity.id}/reopen`);
                toast(`"${activity.subject}" dibuka kembali.`);
                onChanged();
              } catch (error) { toastError(error); }
            }),
          })
          : button('Selesai', {
            size: 'sm', variant: 'ghost', iconName: 'check',
            title: 'Tandai selesai — waktunya dicap server atas nama Anda',
            onClick: (event) => withBusy(event.currentTarget, async () => {
              try {
                await api.post(`crm/activities/${activity.id}/done`);
                toast(`"${activity.subject}" ditandai selesai.`);
                onChanged();
              } catch (error) { toastError(error); }
            }),
          }),
        button('Hapus', {
          size: 'sm', variant: 'ghost', iconName: 'trash',
          onClick: () => confirmDialog({
            title: `Hapus aktivitas "${activity.subject}"?`,
            message: 'Barisnya hilang dari kartu ini. Bila aktivitas ini yang menentukan tanggal tindak lanjut '
              + 'prospeknya, tanggal itu ikut bergeser ke aktivitas terbuka berikutnya.',
            tone: 'danger',
            confirmLabel: 'Hapus',
            onConfirm: async () => {
              await api.del(`crm/activities/${activity.id}`);
              toast('Aktivitas dihapus.');
              onChanged();
            },
          }),
        }),
      ])
      : null,
  ]);
}

/**
 * @param {string} slug   kunci resource, mis. 'crm/leads'
 * @param {number} id     id dokumen
 * @param {string} module awalan izin modul pemiliknya
 */
export function activitiesCard(slug, id, module) {
  const documentType = ACTIVITY_DOCUMENTS[slug];
  if (!documentType || !session.can(`${module}.view`)) return null;

  const canEdit = session.can(`${module}.update`);
  const canCreate = session.can(`${module}.create`);
  const body = el('.card-body');
  const card = el('.card', [
    el('.card-head', [el('h2', { text: 'Aktivitas' }), el('.spacer')]),
    body,
  ]);

  /** Baris paling banyak yang digambar kartu ini. Papan memakai angka yang sama. */
  const PER_CARD = 100;

  async function load() {
    clear(body).appendChild(el('p.muted', { text: 'Memuat…', style: { margin: 0 } }));

    let payload;
    try {
      /* api.list, BUKAN api.get: `get` membuang amplopnya dan hanya memulangkan
         `data`, jadi `meta.total` tidak pernah sampai ke sini dan kartunya
         menghitung ringkasannya dari baris yang KEBETULAN termuat. Diukur
         8 Sep 2026 pada satu prospek berisi 110 pekerjaan terbuka: kartu
         berbunyi "100 terbuka." sementara kartu papan untuk prospek yang sama
         persis menyebut 110 (server withCount) — dua layar di satu paket, satu
         di antaranya berbohong tanpa satu kalimat pun yang mengaku. */
      payload = await api.list('crm/activities',
        { document_type: documentType, document_id: id, per_page: PER_CARD });
    } catch (error) {
      return clear(body).appendChild(errorState(error, load));
    }

    const rows = (payload && payload.data) || [];
    const meta = (payload && payload.meta) || {};
    const total = typeof meta.total === 'number' ? meta.total : rows.length;
    const hidden = Math.max(0, total - rows.length);

    /* Yang TIDAK digambar tetap harus dihitung, dan satu-satunya yang bisa
       menghitungnya adalah server. Dua permintaan tambahan HANYA pada kartu
       yang terpotong (per_page tercapai) — bukan pada setiap kartu di
       aplikasi: yang normal tidak membayar apa pun. `state=open` sekaligus
       memulangkan aktivitas terbuka PALING AWAL (urutannya due_at menaik,
       yang tanpa tanggal di bawah), yaitu baris yang menjelaskan tanggal
       tindak lanjut prospek — tanpa ini kalimat turunannya ikut dihitung dari
       100 baris yang termuat dan bisa menyebut aktivitas yang salah. */
    let counted = null;
    if (hidden) {
      try {
        const [openPage, overduePage] = await Promise.all([
          api.list('crm/activities', { document_type: documentType, document_id: id, state: 'open', per_page: 1 }),
          api.list('crm/activities', { document_type: documentType, document_id: id, state: 'overdue', per_page: 1 }),
        ]);
        counted = {
          open: openPage.meta.total,
          overdue: overduePage.meta.total,
          earliest: (openPage.data || [])[0] || null,
        };
      } catch {
        // Gagal = tidak ada angka tambahan, bukan angka karangan.
        counted = null;
      }
    }

    clear(body);

    const open = rows.filter((row) => row.is_open);
    const done = rows.filter((row) => !row.is_open);
    const overdue = open.filter((row) => row.is_overdue);

    const openCount = counted ? counted.open : open.length;
    const overdueCount = counted ? counted.overdue : overdue.length;
    const doneCount = counted ? total - counted.open : done.length;

    /* Kalimat pembuka: apa yang terbuka, berapa yang lewat tanggal. Sebuah
       kartu tanpa satu baris pun MENGATAKANNYA — "Belum ada aktivitas
       dicatat" adalah keadaan yang berbeda dari "sudah diperiksa, nol". */
    if (!rows.length) {
      body.appendChild(el('p.muted', {
        text: 'Belum ada aktivitas dicatat untuk dokumen ini.',
        style: { margin: '0 0 10px' },
      }));
    } else {
      body.appendChild(el('.cell-sub', {
        text: `${openCount} terbuka${overdueCount ? `, ${overdueCount} lewat tanggal` : ''}`
          + `${doneCount ? ` · ${doneCount} selesai` : ''}.`,
        style: { margin: '0 0 8px' },
      }));
    }

    /* PEMOTONGAN DIAKUI, dengan kalimat yang sama bentuknya dengan papan
       ("25 dari 31 digambar"): sebuah daftar yang berhenti diam-diam adalah
       cara orang mengira ia sudah melihat semuanya. */
    if (hidden) {
      body.appendChild(el('.cell-sub', {
        text: `${rows.length} dari ${total} digambar — sisanya (yang jatuh temponya paling akhir, `
          + 'dan yang tanpa tanggal) ada di layar Aktivitas CRM.',
        style: { margin: '0 0 8px' },
      }));
    }

    /* Kartu PROSPEK menyebut asal tanggal tindak lanjutnya — kolom turunan
       (T3.3) yang tidak bisa lagi diketik di formulir. */
    if (documentType === 'lead') {
      const drawnEarliest = open.filter((row) => row.due_at).sort((a, b) => a.due_at.localeCompare(b.due_at))[0];
      const fromServer = counted && counted.earliest && counted.earliest.due_at ? counted.earliest : null;
      const earliest = counted ? fromServer : drawnEarliest;
      body.appendChild(el('.cell-sub', {
        text: earliest
          ? `Tindak lanjut berikutnya ${fmt.date(earliest.due_at)} — diturunkan dari aktivitas terbuka paling awal ("${earliest.subject}").`
          : 'Tanggal tindak lanjut prospek ini kosong: tidak ada aktivitas terbuka yang bertanggal. '
            + 'Tanggalnya diturunkan dari aktivitas, tidak diketik.',
        style: { margin: '0 0 10px' },
      }));
    }

    [...open, ...done].forEach((activity) => body.appendChild(activityRow(activity, { canEdit, onChanged: load })));

    if (canCreate) {
      body.appendChild(el('.attachment-add', [
        button('Tambah aktivitas', {
          size: 'sm',
          iconName: 'plus',
          onClick: async () => {
            const values = await promptFields('Tambah aktivitas', FIELDS, { submitLabel: 'Simpan' });
            if (values === null) return;
            try {
              await api.post('crm/activities', { ...values, document_type: documentType, document_id: id });
              toast('Aktivitas dicatat.');
              load();
            } catch (error) { toastError(error); }
          },
        }),
      ]));
    }
  }

  load();
  return card;
}
