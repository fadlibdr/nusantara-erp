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
import { el, clear, button, icon, confirmDialog, errorState, toast, toastError, withBusy } from '../ui.js';
import * as fmt from '../format.js';

/* Kept in step with Modules\Core\Support\AttachableDocuments by
 * tests/Feature/Core/AttachmentRegistryTest.php, which reads both and fails if
 * they diverge — a slug that exists on only one side is either a card that
 * 422s or a document that silently cannot hold files. */
export const ATTACHABLE = new Set([
  'crm/quotations', 'crm/contracts', 'crm/guarantees',
  'estimation/boqs', 'estimation/cost-budgets',
  /* P1-ENG: lembar gambar (dwg/dxf, kebijakan P0-D) pada submittal gambar;
   * brosur & mill certificate pada submittal material. IPP sengaja tidak. */
  'engineering/drawing-submittals', 'engineering/material-submittals',
  /* P1-QC: foto inspeksi mutu (rebar terbuka, hasil uji slump) menumpang lembar
   * QCI — buktinya. NCR & benda uji sengaja tidak. */
  'quality/inspections',
  'projects/projects', 'projects/daily-reports', 'projects/bast', 'projects/defects',
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

function attachmentRow(attachment, { canEdit, onChanged }) {
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
      attachment.caption ? el('.cell-sub', { text: attachment.caption }) : null,
    ]),
    el('.row-actions', [
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

      if (canEdit) body.appendChild(uploader(slug, id, load));
    } catch (error) {
      // errorState keeps a retry button; a bare alert leaves the card dead.
      clear(body).appendChild(errorState(error, load));
    }
  }

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
        await api.uploadFile(file, { document_type: slug, document_id: id });
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
    pick,
    input,
    el('.help', {
      text: 'PDF, gambar, Word, Excel, PowerPoint, CSV/teks, XML, gambar teknik (DWG/DXF) atau jadwal (MPP) — '
        + `maksimal ${sizeLabel(MAX_BYTES)}, khusus DWG, DXF dan MPP ${sizeLabel(SIZE_LIMITS.dwg)}. `
        + 'Isi berkas diperiksa, jadi berkas yang isinya tidak sesuai namanya akan ditolak.',
    }),
  ]);
}
