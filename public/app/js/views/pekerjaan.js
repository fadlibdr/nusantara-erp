/* Pekerjaan Saya (UX-F2, ERRATA D-3 + E-9) — dokumen YANG ANDA AJUKAN.
 *
 * Persetujuan menjawab "apa yang menunggu keputusan saya"; layar ini menjawab
 * kebalikannya: "dokumen saya sendiri sedang di mana". Dua tab saja (D-3):
 *
 *   Perlu diperbaiki     — ditolak penyetuju; milik Anda untuk dibetulkan dan
 *                          diajukan lagi (bucket needs_fix);
 *   Menunggu orang lain  — diajukan dan belum diputuskan (bucket waiting).
 *
 * Tidak ada tab "Draf saya" dan tidak ada tab "disetujui, menunggu dokumen
 * lanjutan": 24 dari 29 tabel dokumen tidak mencatat pembuatnya, dan tidak ada
 * API yang tahu dokumen lanjutan itu sudah ada (ERRATA D-3, E-7).
 *
 * SATU permintaan: GET core/my-work (UX-F1). Kalimat keadaan datang dari
 * statusStrip() halaman dokumen — satu sumber kalimat (E-3 butir 4) — diberi
 * baris jejak yang disusun dari baris my-work; petunjuk satu klausa per
 * (enum, nilai) dari enums.js (E-3 butir 5). Nama dan tanggal datang dari
 * server, tidak pernah dari sini. Sumber yang gagal tidak pernah tampak
 * sebagai "kosong": `meta.failed` menulis kalimatnya dan jumlah tab memakai
 * "+" (CONVENTIONS §14, §16). */
import { api } from '../api.js';
import { RESOURCES } from '../schema.js';
import { enumHint } from '../enums.js';
import { navigate } from '../router.js';
import * as fmt from '../format.js';
import { el, clear, button, icon, pageHeader, tabs, statusBadge, emptyState, errorState, skeletonTable } from '../ui.js';
import { statusStrip } from './detail.js';

const BUCKETS = [
  { id: 'needs_fix', label: 'Perlu diperbaiki' },
  { id: 'waiting', label: 'Menunggu orang lain' },
];

const EMPTY = {
  needs_fix: { title: 'Tidak ada yang perlu diperbaiki', text: 'Tidak ada dokumen Anda yang ditolak dan menunggu perbaikan.' },
  waiting: { title: 'Tidak ada yang menunggu orang lain', text: 'Semua dokumen yang Anda ajukan sudah diputuskan.' },
};

/** Satu baris jejak palsu agar statusStrip() menulis kalimat yang sama persis dengan halaman dokumen. */
function traceRecord(row) {
  if (row.bucket === 'needs_fix') {
    return {
      status: row.status,
      approvals: [{
        action: 'rejected', created_at: row.decided_at, note: row.note || null,
        user: row.decided_by ? { name: row.decided_by } : null,
      }],
    };
  }
  return { status: row.status, approvals: [{ action: 'submitted', created_at: row.submitted_at, user: { name: 'Anda' } }] };
}

function workRow(row) {
  const def = RESOURCES[row.resource] || { labelOne: row.label, columns: [] };
  // Hanya `submitted` dan `rejected` sampai ke layar ini (MyWork); keduanya ada di documentStatus dan
  // paymentStatus, jadi kolom status tanpa enum (PR, PO, ...) memakai documentStatus — tanpa itu lencananya
  // menulis kata mentah "rejected".
  const statusEnum = ((def.columns || []).find((column) => column.key === 'status') || {}).enum || 'documentStatus';
  const strip = statusStrip(def, traceRecord(row), false);
  const hint = enumHint(statusEnum, row.status);
  const waited = row.bucket === 'waiting' && row.days_waiting !== null && row.days_waiting !== undefined
    ? ` Sudah ${row.days_waiting} hari.` : '';
  const fix = row.bucket === 'needs_fix';

  const cta = el(`a.btn.sm${fix ? '.primary' : ''}`, { href: row.link }, [
    icon(fix ? 'pencil' : 'arrow-right', 13),
    el('span', { text: fix ? 'Perbaiki' : 'Lihat' }),
  ]);
  const li = el('li.work-row', { dataset: { bucket: row.bucket } }, [
    el('.work-main', [
      el('span.work-code.mono', { text: row.code }),
      el('b.work-title', { text: row.title || row.label }),
      el('span.work-type', { text: row.label }),
    ]),
    el('.work-state', [
      statusBadge(row.status, statusEnum),
      el('p.work-next', { text: [strip ? strip.text : null, hint, waited.trim() || null].filter(Boolean).join(' ') }),
    ]),
    el('.work-side', [
      el('span.work-amount.mono.num', { text: row.amount === null || row.amount === undefined ? '—' : fmt.rupiah(row.amount) }),
      cta,
    ]),
  ]);
  // Seluruh baris bisa diklik (pola tugas.js); tautan di dalamnya tetap satu-satunya perhentian Tab.
  li.addEventListener('click', (event) => {
    if (event.target.closest('a, button')) return;
    navigate(row.link.replace(/^#\//, ''));
  });
  return li;
}

export async function renderPekerjaan(host) {
  clear(host);
  host.appendChild(pageHeader({
    title: 'Pekerjaan Saya',
    description: 'Dokumen yang Anda ajukan: yang dikembalikan untuk diperbaiki dan yang masih menunggu keputusan orang lain.',
    actions: [button('Muat ulang', { iconName: 'refresh', onClick: () => renderPekerjaan(host) })],
  }));

  let rows = [];
  let failed = [];
  let active = BUCKETS[0].id;
  const panel = el('.card', { id: 'pekerjaan-panel', role: 'tabpanel' });
  const tabBar = tabs(BUCKETS.map((bucket) => ({ ...bucket, count: null, panelId: 'pekerjaan-panel' })), {
    active,
    onChange: (id) => { active = id; paint(); },
  });
  host.appendChild(tabBar);
  host.appendChild(panel);
  panel.appendChild(skeletonTable(4, 4));

  let payload;
  try {
    payload = await api.list('core/my-work');
  } catch (error) {
    // Jumlah tab tetap "—": sumbernya tidak menjawab, bukan nol.
    clear(panel).appendChild(el('.card-body', errorState(error, () => renderPekerjaan(host))));
    return;
  }
  rows = payload.data || [];
  failed = ((payload.meta || {}).failed) || [];

  const countOf = (id) => rows.filter((row) => row.bucket === id).length;
  // Daftar yang belum lengkap tidak boleh tampak sebagai jumlah pasti: "3+".
  BUCKETS.forEach((bucket) => tabBar.setCount(bucket.id, failed.length ? `${countOf(bucket.id)}+` : countOf(bucket.id)));
  // Buka tab pertama yang berisi; kalau keduanya kosong, tetap "Perlu diperbaiki".
  active = BUCKETS.find((bucket) => countOf(bucket.id) > 0)?.id || BUCKETS[0].id;
  tabBar.select(active);
  paint();

  function paint() {
    clear(panel);
    const shown = rows.filter((row) => row.bucket === active);
    if (failed.length) {
      panel.appendChild(el('.work-banner', el('.alert.warn', `Gagal dimuat: ${failed.join(', ')}. Daftar ini belum lengkap.`)));
    }
    if (!shown.length) {
      // Gambar galat saat sumbernya gagal, centang saat memang kosong (CONVENTIONS §14).
      panel.appendChild(failed.length
        ? emptyState('Tidak ada dokumen yang dapat ditampilkan.', { title: 'Daftar belum lengkap', kind: 'error' })
        : emptyState(EMPTY[active].text, { title: EMPTY[active].title, kind: 'done' }));
      return;
    }
    panel.appendChild(el('ul.work-list', shown.map(workRow)));
  }
}
