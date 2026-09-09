/* Attachments card for document detail screens.
 *
 * Files go up through api.uploadFile(), which picks the transport by size: up
 * to 5 MB as base64 inside the normal JSON body, above that (the 25 MB
 * engineering-drawing class, P0-D) as multipart — 25 MB of base64 is ~33 MB of
 * JSON, more than post_max_size on any deployment. Both routes land on the
 * same server-side checks.
 *
 * Downloads go through fetch() rather than a plain <a href>: a link carries no
 * token header, so it would be a 401. The response becomes a blob and the blob
 * becomes a click. */

import { api, session } from '../api.js';
import { el, clear, button, icon, badge, field, modal, confirmDialog, errorState, toast, toastError, withBusy } from '../ui.js';
import * as fmt from '../format.js';

/* Kept in step with Modules\Core\Support\AttachableDocuments by
 * tests/Feature/Core/AttachmentRegistryTest.php, which reads both and fails if
 * they diverge — a slug that exists on only one side is either a card that
 * 422s or a document that silently cannot hold files. */
export const ATTACHABLE = new Set([
  /* P7: pustaka metode kerja — inilah dokumen yang kebijakan pptx/docx P0-D
   * dipaku untuknya; metode pelaksanaan datang sebagai dek slide atau Word.
   * Lampirannya menempel pada VERSI, bukan pada metodenya. */
  'core/method-library',
  'crm/quotations', 'crm/contracts', 'crm/guarantees',
  /* P7: kertas milik PEMBERI TUGAS — dokumen pemilihan, tiap addendum, dan
   * BA aanwijzing yang ditandatangani. Lembar TKDN & RKK sengaja tidak:
   * keduanya disusun dari baris yang dimiliki ERP ini. */
  'crm/tender-packages',
  'estimation/boqs', 'estimation/cost-budgets',
  /* P1-ENG: lembar gambar (dwg/dxf, kebijakan P0-D) pada submittal gambar;
   * brosur & mill certificate pada submittal material. IPP sengaja tidak. */
  'engineering/drawing-submittals', 'engineering/material-submittals',
  /* P1-QC: foto inspeksi mutu (rebar terbuka, hasil uji slump) menumpang lembar
   * QCI — buktinya. NCR & benda uji sengaja tidak. */
  'quality/inspections',
  'projects/projects', 'projects/daily-reports', 'projects/bast', 'projects/defects',
  /* P3: foto opname (bekisting, hasil cor, meteran di atas galian) menumpang
   * lembar OPN — buktinya volume yang diukur. BAPP zona & register variasi
   * kontrak sengaja tidak. */
  'projects/progress-measurements',
  /* P6: foto kejadian menempel pada INSIDENNYA (temuan panduan §7.7) — bukan
   * lagi dititipkan ke laporan harian dengan nomor insiden di keterangan. */
  'projects/safety-incidents',
  /* P0-C: foto izin kerja (kondisi area, APD) & foto muatan izin gerbang. */
  'projects/work-permits', 'projects/gate-passes',
  'procurement/purchase-requisitions', 'procurement/purchase-orders', 'procurement/vendors',
  'procurement/vendor-documents',
  /* P2: daftar hadir menumpang BA Negosiasi (BAN) — buktinya. */
  'procurement/negotiation-minutes',
  'inventory/goods-receipts', 'inventory/stock-adjustments',
  'subcontract/subcontracts', 'subcontract/progress-claims',
  'finance/ar-invoices', 'finance/ap-bills', 'finance/payments', 'finance/journals',
  'finance/petty-cash-vouchers', 'finance/kasbon',
  /* F-4: selfie absen masuk/pulang menempel pada BARIS ABSENSINYA. Kartu
   * lampirannya muncul di panel satu hari pada layar Absensi Harian — dan
   * hanya di sana, karena melihat foto seseorang tidak boleh lebih mudah
   * daripada melihat baris absensinya (izin hr). */
  'hr/attendances',
  'hr/employees', 'hr/certificates', 'hr/leave-requests',
  'servicedesk/tickets', 'servicedesk/field-reports',
  'assets/assets',
]);

/* Mirrors AttachmentService::MAX_BYTES / SIZE_LIMITS, drift caught by
 * AttachmentSpaPolicyTest. The server enforces the real limits; checking here
 * only saves reading and shipping a file the API would refuse anyway. */
const MAX_BYTES = 5 * 1024 * 1024;
const SIZE_LIMITS = {
  dwg: 25 * 1024 * 1024,
  dxf: 25 * 1024 * 1024,
  mpp: 25 * 1024 * 1024,
};

/** The cap for one file, by its extension. */
function sizeLimit(name) {
  const dot = name.lastIndexOf('.');
  const extension = dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
  return SIZE_LIMITS[extension] || MAX_BYTES;
}

function sizeLabel(bytes) {
  return bytes >= 1024 * 1024
    ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} kB`;
}

async function download(attachment) {
  try {
    const blob = await api.blob(`core/attachments/${attachment.id}/download`);
    const url = URL.createObjectURL(blob);
    const link = el('a', { href: url, download: attachment.original_name, style: { display: 'none' } });
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    toastError(error);
  }
}

/* MASA BERLAKU: EMPAT KEADAAN, DAN YANG PERTAMA ADALAH KEADAAN NORMAL (F-8).
 *
 * `attachment.validity` datang DARI SERVER (Modules\Core\Models\Attachment):
 * state + sisa hari + jendela peringatannya. Tidak dihitung ulang di sini, dan
 * itu disengaja — pengawas tenggat 08.30, layar Tenggat dan kartu ini harus
 * mustahil menyimpulkan berbeda tentang berkas yang sama. Aturan yang sama
 * dihitung dua kali adalah cara F-3 (aktivitas CRM) dan F-7 (servis alat)
 * masing-masing melahirkan satu kontradiksi yang harus dibayar belakangan.
 *
 * 'tanpa_masa_berlaku' SENGAJA BUKAN LENCANA. Hampir setiap lampiran di sistem
 * ini adalah foto lapangan, nota atau gambar kerja yang tidak punya — dan tidak
 * akan pernah punya — masa berlaku. Menandainya kuning, merah, atau bahkan
 * memberinya lencana abu-abu akan menaruh satu peringatan visual pada setiap
 * baris di setiap kartu lampiran di seluruh aplikasi. Ia ditulis sebagai
 * keterangan biasa, satu baris dengan ukuran berkas dan nama pengunggah. */
export function validityNode(attachment, { hideWhenNone = false } = {}) {
  const validity = attachment.validity;
  // Respons tanpa blok validity (server lama): diam, bukan menebak.
  if (!validity) return null;

  if (validity.state === 'tanpa_masa_berlaku') {
    // hideWhenNone dipakai strip Foto lapangan: di sana SETIAP baris ada di
    // keadaan ini, jadi menuliskannya adalah kebisingan. Di kartu ini ia
    // ditulis, karena kartu inilah yang punya tombol untuk mengubahnya.
    return hideWhenNone ? null : el('span', { text: 'Tanpa masa berlaku' });
  }

  const sampai = `Berlaku s/d ${fmt.date(attachment.valid_until)}`;
  const days = Math.abs(validity.days === null ? 0 : validity.days);

  if (validity.state === 'menipis') {
    return badge(`${sampai} · ${days === 0 ? 'hari ini' : `${days} hari lagi`}`, 'amber');
  }
  if (validity.state === 'kedaluwarsa') {
    return badge(`Kedaluwarsa ${fmt.date(attachment.valid_until)} · ${days} hari lalu`, 'red');
  }

  /* 'berlaku' — dan keadaan apa pun yang belum dikenal versi klien ini.
     Cabang bawaan sengaja yang PALING TENANG: sebuah keadaan baru yang jatuh
     ke sini terbaca sebagai keterangan, bukan sebagai peringatan kuning pada
     berkas yang tidak bersalah. */
  return el('span', { text: sampai });
}

/** Satu kolom, satu dialog — lihat AttachmentController::update. */
function expiryModal(attachment, onSaved) {
  const input = el('input', { type: 'date', value: attachment.valid_until || '' });
  const save = button('Simpan', { variant: 'primary' });
  const clearIt = button('Kosongkan');

  /* Jendela peringatannya DIBACA dari barisnya — `validity.lead_days` ikut di
     setiap lampiran justru untuk ini. Sebuah kalimat yang menyalin angkanya
     tetap berkata "30 hari" pada hari jendelanya diubah, sementara lencana
     kuning di kartu yang sama menyala di ambang yang lain. */
  const lead = attachment.validity ? attachment.validity.lead_days : null;

  const dialog = modal({
    title: `Masa berlaku ${attachment.original_name}`,
    width: 'narrow',
    body: el('.form-grid', [
      field('Berlaku sampai dengan', input, {
        help: 'Kosongkan bila berkas ini memang tidak punya masa berlaku — itu keadaan biasa untuk '
          + 'foto lapangan, nota dan gambar kerja. Hari terakhirnya masih dihitung berlaku'
          + (lead === null ? '.' : `, dan peringatan mulai ${lead} hari sebelumnya.`),
      }),
    ]),
    footer: [button('Batal', { onClick: () => dialog.close() }), clearIt, save],
  });

  const write = async (value) => {
    try {
      await api.patch(`core/attachments/${attachment.id}`, { valid_until: value });
      toast(value === null ? 'Masa berlaku dikosongkan.' : 'Masa berlaku disimpan.');
      dialog.close();
      onSaved();
    } catch (error) {
      toastError(error);
    }
  };

  save.addEventListener('click', () => withBusy(save, () => write(input.value || null)));
  clearIt.addEventListener('click', () => withBusy(clearIt, () => write(null)));
}

function attachmentRow(attachment, { canEdit, onChanged }) {
  const validity = validityNode(attachment);

  return el('.attachment', [
    el('.attachment-main', [
      el('.attachment-name', { text: attachment.original_name }),
      el('.cell-sub', {
        text: [
          sizeLabel(attachment.size_bytes),
          attachment.uploader ? attachment.uploader.name : null,
          fmt.relativeDays(attachment.created_at),
        ].filter(Boolean).join(' · '),
      }),
      validity ? el('.cell-sub.attachment-validity', [validity]) : null,
      attachment.caption ? el('.cell-sub', { text: attachment.caption }) : null,
    ]),
    el('.row-actions', [
      canEdit
        // Tanpa iconName: PATHS di ui.js tidak punya ikon kalender, dan nama
        // yang tidak dikenal menghasilkan kotak kosong tanpa satu pun galat.
        ? button('Masa berlaku', {
          size: 'sm',
          variant: 'ghost',
          title: 'Atur masa berlaku berkas ini',
          onClick: () => expiryModal(attachment, onChanged),
        })
        : null,
      button('Unduh', { size: 'sm', variant: 'ghost', iconName: 'download', onClick: () => download(attachment) }),
      canEdit
        ? button('Hapus', {
          size: 'sm',
          variant: 'ghost',
          iconName: 'trash',
          onClick: () => confirmDialog({
            title: `Hapus ${attachment.original_name}?`,
            message: 'Berkasnya dihapus dari penyimpanan dan tidak dapat dikembalikan.',
            onConfirm: async () => {
              await api.del(`core/attachments/${attachment.id}`);
              toast('Lampiran dihapus.');
              onChanged();
            },
          }),
        })
        : null,
    ]),
  ]);
}

/**
 * @param {string} slug   resource key, e.g. 'finance/ap-bills'
 * @param {number} id     document id
 * @param {string} module permission prefix of the owning module
 */
export function attachmentsCard(slug, id, module) {
  if (!ATTACHABLE.has(slug) || !session.can(`${module}.view`)) return null;

  const canEdit = session.can(`${module}.update`);
  const body = el('.card-body');
  const card = el('.card', [
    el('.card-head', [el('h2', { text: 'Lampiran' }), el('.spacer')]),
    body,
  ]);

  async function load() {
    clear(body).appendChild(el('p.muted', { text: 'Memuat…', style: { margin: 0 } }));

    try {
      const list = await api.get('core/attachments', { document_type: slug, document_id: id });
      clear(body);

      if (!list.length) {
        body.appendChild(el('p.muted', { text: 'Belum ada lampiran.', style: { margin: '0 0 10px' } }));
      } else {
        list.forEach((attachment) => body.appendChild(attachmentRow(attachment, { canEdit, onChanged: load })));
      }

      if (uploadBox) body.appendChild(uploadBox);
    } catch (error) {
      // errorState keeps a retry button; a bare alert leaves the card dead.
      clear(body).appendChild(errorState(error, load));
    }
  }

  /* DIBANGUN SEKALI, dipasang ulang setiap kali kartu digambar ulang.
     Sebuah unggahan yang sukses memanggil load(), dan load() mengosongkan
     body — jadi uploader() yang dipanggil ulang di sana akan melahirkan
     <input type="date"> yang BARU dan kosong setiap kali. Orang yang mengetik
     satu masa berlaku lalu melampirkan lima lembar polis dari set yang sama
     kemudian menyimpan satu berkas bertanggal dan empat tanpa tanggal, tanpa
     satu pun pesan — dan keempatnya adalah berkas yang tidak akan pernah
     ditagih pengawas kedaluwarsa. Node yang sama dipakai kembali, jadi
     nilainya bertahan persis seperti yang dijanjikan kotaknya. */
  const uploadBox = canEdit ? uploader(slug, id, load) : null;

  load();
  return card;
}

function uploader(slug, id, onUploaded) {
  const input = el('input', {
    type: 'file',
    // Kept in step with AttachmentService::ALLOWED by AttachmentSpaPolicyTest.
    accept: '.pdf,.jpg,.jpeg,.png,.webp,.gif,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt,.dwg,.dxf,.mpp,.xml,.pptx,.ppt',
    style: { display: 'none' },
  });

  const pick = button('Tambah lampiran', {
    size: 'sm',
    iconName: 'plus',
    onClick: () => input.click(),
  });

  /* Masa berlaku BOLEH diisi sebelum memilih berkas, dan kosong adalah bawaan
     yang benar: sebagian besar berkas yang naik lewat kartu ini tidak punya
     masa berlaku, dan sebuah kotak yang menuntut diisi hanya akan membuat orang
     mengarang tanggal. Nilainya ikut ke KEDUA transport lewat api.uploadFile()
     dan BERTAHAN antar-unggahan — orang yang melampirkan lima lembar polis yang
     sama berlakunya tidak boleh mengetiknya lima kali. Yang membuatnya bertahan
     bukan baris di sini melainkan attachmentsCard, yang membangun kotak ini
     sekali saja dan memasangnya kembali; hanya kotak BERKAS yang dikosongkan
     (input.value = '') supaya berkas yang sama bisa dipilih dua kali. */
  const validUntil = el('input', { type: 'date' });

  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) return;

    const limit = sizeLimit(file.name);
    if (file.size > limit) {
      toastError(new Error(`Berkas ${sizeLabel(file.size)} melebihi batas ${sizeLabel(limit)}.`));
      input.value = '';
      return;
    }

    await withBusy(pick, async () => {
      try {
        await api.uploadFile(file, {
          document_type: slug,
          document_id: id,
          valid_until: validUntil.value || null,
        });
        toast(`${file.name} dilampirkan.`);
        onUploaded();
      } catch (error) {
        toastError(error);
      } finally {
        input.value = '';
      }
    });
  });

  return el('.attachment-add', [
    el('.attachment-add-row', [
      pick,
      el('label.attachment-expiry', [
        el('span', { text: 'Masa berlaku (opsional)' }),
        validUntil,
      ]),
    ]),
    input,
    el('.help', {
      text: 'PDF, gambar, Word, Excel, PowerPoint, CSV/teks, XML, gambar teknik (DWG/DXF) atau jadwal (MPP) — '
        + `maksimal ${sizeLabel(MAX_BYTES)}, khusus DWG, DXF dan MPP ${sizeLabel(SIZE_LIMITS.dwg)}. `
        + 'Isi berkas diperiksa, jadi berkas yang isinya tidak sesuai namanya akan ditolak.',
    }),
  ]);
}
