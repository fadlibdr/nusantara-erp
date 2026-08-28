/* Persetujuan Eksternal (MK/Owner) card for document detail screens — P0-F.
 *
 * One row of the list is one mandate: a one-time link issued to a named
 * MK/Owner person, or a signed physical sheet recorded after the fact. The
 * server shows the plaintext link EXACTLY ONCE in the issue response and
 * stores only its hash, so the success dialog here is the last place the URL
 * exists — it is shown with a copy button and a warning, and never asked for
 * again (there is no endpoint to ask).
 *
 * Recording a physical sheet requires the scan to already be attached to THIS
 * document (attachments card, same screen); the dialog only offers this
 * document's attachments, mirroring the server rule instead of discovering it
 * as a 422. */

import { api, session } from '../api.js';
import { el, clear, button, badge, modal, confirmDialog, errorState, toast, toastError } from '../ui.js';
import * as fmt from '../format.js';
import { promptFields } from './form.js';

/* Kept in step with Modules\Core\Support\ExternalApprovableDocuments by
 * tests/Feature/Core/ExternalApprovalRegistryTest.php, which reads both and
 * fails if they diverge — a slug on only one side is either a card that 422s
 * or a document whose links nobody can issue. */
export const EXTERNAL_APPROVABLE = new Set([
  'projects/daily-reports',
  'crm/contract-change-orders',
  'projects/work-permits',
]);

/* Both vocabularies are pinned to their PHP sources (ExternalApproval::PARTIES,
 * ExternalDecision) by the same registry test. */
const PARTY_LABELS = {
  mk: 'MK',
  owner: 'Pemilik',
};

const DECISION_LABELS = {
  approved: 'Setuju',
  approved_with_notes: 'Setuju dengan catatan',
  rejected: 'Tolak',
};

const DEFAULT_VALIDITY_DAYS = 7; // ExternalApprovalService::DEFAULT_VALIDITY_DAYS

function isExpired(row) {
  // Mirrors ExternalApproval::isExpired(): expires_at = now is already expired.
  return row.expires_at && new Date(row.expires_at) <= new Date();
}

function statusChip(row) {
  if (row.decided_at) return badge('Diputuskan', row.decision === 'rejected' ? 'red' : 'green');
  if (row.revoked_at) return badge('Dicabut', '');
  if (isExpired(row)) return badge('Kedaluwarsa', '');
  return badge('Terbit', 'amber');
}

/** 'YYYY-MM-DD HH:MM:00' local time, `days` days from now — what the API's
 *  `expires_at` (after:now) expects when the issuer picked a validity. */
function expiresAtFromDays(days) {
  const date = new Date(Date.now() + days * 86_400_000);
  const pad = (part) => String(part).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} `
    + `${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
}

function approvalRow(row, { canApprove, onChanged }) {
  const viaLabel = row.decided_via === 'physical' ? 'lembar fisik' : 'tautan';

  const subLines = [];

  // The issuedBy/revokedBy relations serialise onto the same snake_case keys
  // as the raw columns, so issued_by/revoked_by are {id, name} objects here.
  const issuer = row.issued_by && row.issued_by.name ? row.issued_by.name : null;
  const revoker = row.revoked_by && row.revoked_by.name ? row.revoked_by.name : null;

  if (row.decided_at) {
    subLines.push(`${DECISION_LABELS[row.decision] || row.decision || '—'}`
      + `${row.decision_notes ? ` — ${row.decision_notes}` : ''}`
      + ` · via ${viaLabel} · ${fmt.dateTime(row.decided_at)}`);
  } else if (row.revoked_at) {
    subLines.push(`Dicabut ${fmt.dateTime(row.revoked_at)}${revoker ? ` oleh ${revoker}` : ''}`);
  }

  subLines.push(`Diterbitkan ${fmt.relativeDays(row.created_at)}`
    + `${issuer ? ` oleh ${issuer}` : ''}`
    + `${row.expires_at && !row.decided_at ? ` · berlaku s/d ${fmt.dateTime(row.expires_at)}` : ''}`);

  if (row.attachment) subLines.push(`Lembar fisik: ${row.attachment.original_name}`);

  const revocable = canApprove && !row.decided_at && !row.revoked_at && !isExpired(row);

  return el('.attachment', [
    el('.attachment-main', [
      el('.attachment-name', {
        text: `${PARTY_LABELS[row.party] || row.party} — ${row.name}`
          + `${row.organization ? ` (${row.organization})` : ''}`,
      }),
      ...subLines.map((line) => el('.cell-sub', { text: line })),
    ]),
    el('.row-actions', [
      statusChip(row),
      revocable
        ? button('Cabut', {
          size: 'sm',
          variant: 'ghost',
          iconName: 'trash',
          onClick: () => confirmDialog({
            title: `Cabut tautan untuk ${row.name}?`,
            message: 'Tautan yang dicabut tidak bisa dipakai memutuskan. Keputusan yang sudah tercatat tidak dapat dicabut.',
            tone: 'danger',
            onConfirm: async () => {
              await api.post(`core/external-approvals/${row.id}/revoke`);
              toast(`Tautan untuk ${row.name} dicabut.`);
              onChanged();
            },
          }),
        })
        : null,
    ]),
  ]);
}

/* --------------------------------------------------------------- dialogs */

const PARTY_OPTIONS = Object.entries(PARTY_LABELS).map(([value, label]) => ({ value, label }));
const DECISION_OPTIONS = Object.entries(DECISION_LABELS).map(([value, label]) => ({ value, label }));

/** The one and only screen the plaintext URL ever appears on. */
function showIssuedUrl(url, name) {
  const input = el('input', { type: 'text' });
  input.value = url;
  input.readOnly = true;
  input.addEventListener('focus', () => input.select());

  const copy = button('Salin', {
    variant: 'primary',
    onClick: async () => {
      try {
        await navigator.clipboard.writeText(url);
      } catch {
        // Clipboard API needs a secure context; the selection fallback works
        // over the plain-HTTP deployments DEPLOYMENT.md still allows.
        input.select();
        document.execCommand('copy');
      }
      toast('Tautan disalin.');
    },
  });

  const dialog = modal({
    title: 'Tautan persetujuan diterbitkan',
    width: 'narrow',
    body: el('div', [
      el('p', { text: `Kirim tautan ini kepada ${name} lewat saluran Anda sendiri (WhatsApp/e-mail).`, style: { marginTop: '0' } }),
      el('.attachment-add', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, [input, copy]),
      el('.help', {
        text: 'Salin sekarang — tautan hanya ditampilkan sekali dan tidak dapat dilihat lagi. '
          + 'Bila hilang: cabut tautan ini, lalu terbitkan yang baru.',
      }),
    ]),
    footer: [button('Tutup', { onClick: () => dialog.close() })],
  });
}

async function issueLink(slug, id, onChanged) {
  const values = await promptFields('Terbitkan Tautan Persetujuan', [
    { key: 'party', label: 'Pihak', type: 'select', options: PARTY_OPTIONS, required: true },
    { key: 'name', label: 'Nama pemutus', required: true },
    { key: 'organization', label: 'Organisasi' },
    { key: 'email', label: 'E-mail', help: 'Opsional, arsip untuk siapa tautan diterbitkan — sistem tidak mengirim e-mail.' },
    {
      key: 'days', label: 'Masa berlaku (hari)', type: 'number', min: 1,
      help: `Kosongkan untuk ${DEFAULT_VALIDITY_DAYS} hari.`,
    },
  ], { submitLabel: 'Terbitkan' });
  if (values === null) return;

  const payload = {
    document_type: slug,
    document_id: id,
    party: values.party,
    name: values.name,
    organization: values.organization,
    email: values.email,
  };
  if (values.days) payload.expires_at = expiresAtFromDays(values.days);

  try {
    const issued = await api.post('core/external-approvals', payload);
    onChanged();
    showIssuedUrl(issued.url, values.name);
  } catch (error) {
    toastError(error);
  }
}

async function recordPhysical(slug, id, onChanged) {
  let attachments = [];
  try {
    attachments = await api.get('core/attachments', { document_type: slug, document_id: id });
  } catch (error) {
    toastError(error);
    return;
  }

  if (!attachments.length) {
    // The server would refuse anyway; saying it before the form is honest.
    toastError(new Error('Lampirkan dulu scan lembar bertanda tangan pada dokumen ini (kartu Lampiran), baru catat keputusannya.'));
    return;
  }

  const values = await promptFields('Catat Tanda Tangan Fisik', [
    {
      key: 'attachment_id', label: 'Scan lembar bertanda tangan', type: 'select', required: true,
      options: attachments.map((attachment) => ({ value: attachment.id, label: attachment.original_name })),
      help: 'Hanya lampiran dokumen ini yang bisa dipilih — scan lembar dokumen lain ditolak server.',
    },
    { key: 'party', label: 'Pihak', type: 'select', options: PARTY_OPTIONS, required: true },
    { key: 'name', label: 'Nama penanda tangan', required: true },
    { key: 'organization', label: 'Organisasi' },
    { key: 'decision', label: 'Keputusan', type: 'select', options: DECISION_OPTIONS, required: true },
    { key: 'decision_notes', label: 'Catatan keputusan', type: 'textarea' },
    { key: 'decided_at', label: 'Tanggal keputusan', type: 'date', help: 'Kosongkan untuk hari ini.' },
  ], { submitLabel: 'Catat Keputusan' });
  if (values === null) return;

  try {
    await api.post('core/external-approvals/record-physical', { document_type: slug, document_id: id, ...values });
    toast(`Keputusan ${DECISION_LABELS[values.decision] || values.decision} dari ${values.name} tercatat dari lembar fisik.`);
    onChanged();
  } catch (error) {
    toastError(error);
  }
}

/* ------------------------------------------------------------------ card */

/**
 * @param {string} slug   resource key, e.g. 'projects/daily-reports'
 * @param {number} id     document id
 * @param {string} module permission prefix of the owning module
 */
export function externalApprovalsCard(slug, id, module) {
  if (!EXTERNAL_APPROVABLE.has(slug) || !session.can(`${module}.view`)) return null;

  // Issuing a decision link is approve-level power (ExternalApprovalController),
  // so the write buttons follow {prefix}.approve, not update.
  const canApprove = session.can(`${module}.approve`);
  const body = el('.card-body');
  const card = el('.card', [
    el('.card-head', [el('h2', { text: 'Persetujuan Eksternal (MK/Owner)' }), el('.spacer')]),
    body,
  ]);

  async function load() {
    clear(body).appendChild(el('p.muted', { text: 'Memuat…', style: { margin: 0 } }));

    try {
      const list = await api.get('core/external-approvals', { document_type: slug, document_id: id });
      clear(body);

      if (!list.length) {
        body.appendChild(el('p.muted', {
          text: 'Belum ada tautan atau keputusan eksternal.',
          style: { margin: '0 0 10px' },
        }));
      } else {
        list.forEach((row) => body.appendChild(approvalRow(row, { canApprove, onChanged: load })));
      }

      if (canApprove) {
        body.appendChild(el('.attachment-add', [
          el('.row-actions', [
            button('Terbitkan Tautan', {
              size: 'sm',
              iconName: 'plus',
              onClick: () => issueLink(slug, id, load),
            }),
            button('Catat Tanda Tangan Fisik', {
              size: 'sm',
              variant: 'ghost',
              iconName: 'edit',
              onClick: () => recordPhysical(slug, id, load),
            }),
          ]),
          el('.help', {
            text: 'Tautan berlaku sekali pakai dan URL-nya hanya tampil saat diterbitkan. '
              + 'Keputusan dari lembar fisik butuh scan lembarnya terlampir pada dokumen ini.',
          }),
        ]));
      }
    } catch (error) {
      // errorState keeps a retry button; a bare alert leaves the card dead.
      clear(body).appendChild(errorState(error, load));
    }
  }

  load();
  return card;
}
