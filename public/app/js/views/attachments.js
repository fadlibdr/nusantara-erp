/* Attachments card for document detail screens.
 *
 * Files go up as base64 inside the normal JSON body — api.js authenticates on a
 * header and serialises every request as JSON, so multipart would mean a second
 * transport for one card. The 33% base64 overhead is why the server's raw cap
 * is well under php-fpm's post limit.
 *
 * Downloads go through fetch() rather than a plain <a href>, for the same
 * reason: a link carries no token header, so it would be a 401. The response
 * becomes a blob and the blob becomes a click. */

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
  'projects/projects', 'projects/daily-reports', 'projects/bast', 'projects/defects',
  'procurement/purchase-requisitions', 'procurement/purchase-orders', 'procurement/vendors',
  'procurement/vendor-documents',
  'inventory/goods-receipts', 'inventory/stock-adjustments',
  'subcontract/subcontracts', 'subcontract/progress-claims',
  'finance/ar-invoices', 'finance/ap-bills', 'finance/payments', 'finance/journals',
  'finance/petty-cash-vouchers', 'finance/kasbon',
  'hr/employees', 'hr/certificates', 'hr/leave-requests',
  'servicedesk/tickets', 'servicedesk/field-reports',
  'assets/assets',
]);

const MAX_BYTES = 5 * 1024 * 1024;

function sizeLabel(bytes) {
  return bytes >= 1024 * 1024
    ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} kB`;
}

function readAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('Berkas tidak dapat dibaca.'));
    reader.readAsDataURL(file);
  });
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
    accept: '.pdf,.jpg,.jpeg,.png,.webp,.gif,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt',
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

    if (file.size > MAX_BYTES) {
      toastError(new Error(`Berkas ${sizeLabel(file.size)} melebihi batas ${sizeLabel(MAX_BYTES)}.`));
      input.value = '';
      return;
    }

    await withBusy(pick, async () => {
      try {
        await api.post('core/attachments', {
          document_type: slug,
          document_id: id,
          filename: file.name,
          content: await readAsBase64(file),
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
    pick,
    input,
    el('.help', {
      text: `PDF, gambar, Word, Excel, CSV atau teks — maksimal ${sizeLabel(MAX_BYTES)}. `
        + 'Isi berkas diperiksa, jadi berkas yang isinya tidak sesuai namanya akan ditolak.',
    }),
  ]);
}
