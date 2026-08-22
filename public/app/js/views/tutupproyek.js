/* Tutup proyek — aksi eksplisit di balik status 'Ditutup' (Temuan 47).

   'Ditutup' dulunya satu pilihan dropdown: siapa pun ber-prj.update bisa
   menutup PRJ-2026-001 sementara termin 4 "BAST 15%" (Rp 7,275 M) dan termin 5
   retensi (Rp 2,425 M) belum ditagih — Rp 9,7 miliar hak tagih lewat tanpa satu
   layar pun bertanya. Sekarang server menolak status itu dari form biasa, dan
   pintunya adalah dialog ini: ringkasan item terbuka dibaca DULU, baru tombolnya.

   Dialog ini hanya menggambar apa yang dijawab GET projects/{id}/closure.
   Aturannya — mana yang memblokir, mana yang cukup dengan alasan — hidup di
   ProjectClosureService; kalau kalimat penolakan berubah di server, layar ini
   tidak perlu ikut diubah. */

import { api } from '../api.js';
import { el, button, badge, modal, toast, toastError, withBusy } from '../ui.js';

const LEVEL = {
  block: ['Wajib dibereskan', 'red'],
  warning: ['Perlu alasan', 'amber'],
  info: ['Info', ''],
};

/** Satu baris checklist: lolos = centang hijau, gagal = label levelnya. */
function checkRow(check) {
  const [label, tone] = LEVEL[check.level] || [check.level, ''];

  return el('div', {
    style: { display: 'flex', gap: '10px', alignItems: 'flex-start', padding: '8px 0', borderBottom: '1px solid var(--border)' },
  }, [
    el('div', { style: { minWidth: '110px' } },
      check.passed ? badge('Beres', 'green') : badge(label, tone)),
    el('div', { style: { flex: '1', minWidth: '0' } }, [
      el('div', { text: check.label, style: { fontSize: '13px', fontWeight: check.passed ? '400' : '600' } }),
      el('.cell-sub', { text: check.detail }),
    ]),
  ]);
}

/**
 * Buka dialog Tutup proyek untuk satu proyek. `onClosed` dipanggil setelah
 * server benar-benar menutup — pemanggil biasanya memuat ulang halamannya.
 */
export async function openTutupProyek(project, { onClosed } = {}) {
  let summary;
  try {
    summary = await api.get(`projects/${project.id}/closure`);
  } catch (error) {
    toastError(error);
    return;
  }

  if (!summary) {
    toast('Server tidak mengirimkan ringkasan penutupan.', { tone: 'warn' });
    return;
  }

  const body = el('div');

  body.appendChild(el('p', {
    style: { margin: '0 0 10px', fontSize: '13px', color: 'var(--text-2)' },
    text: `Menutup ${project.code} — ${project.name}. Setelah ditutup, laporan harian dan progres tidak bisa dientri lagi; membuka kembali dilakukan lewat ubah status.`,
  }));

  (summary.checks || []).forEach((check) => body.appendChild(checkRow(check)));

  /* Alasan hanya digambar bila memang ada peringatan untuk dilewati: kotak
     alasan yang selalu tampil akan diisi orang secara refleks, dan bel yang
     selalu berbunyi tidak lagi didengar siapa pun. */
  let reasonInput = null;
  if (summary.needs_override) {
    reasonInput = el('textarea', {
      rows: '3',
      placeholder: 'Mengapa proyek tetap ditutup dengan item terbuka di atas? (minimal 20 karakter)',
      style: { width: '100%', marginTop: '10px' },
    });
    body.appendChild(reasonInput);
  }

  if (!summary.can_close) {
    body.appendChild(el('.alert.warn', {
      style: { marginTop: '10px' },
      text: 'Ada item yang wajib dibereskan dulu — alasan tidak bisa melewatinya. Bereskan itemnya, lalu buka dialog ini lagi.',
    }));
  }

  const submit = button('Tutup proyek', {
    variant: 'danger',
    disabled: !summary.can_close,
    onClick: () => withBusy(submit, async () => {
      try {
        await api.post(`projects/${project.id}/close`, {
          override_reason: reasonInput && reasonInput.value.trim() !== '' ? reasonInput.value.trim() : undefined,
        });
      } catch (error) {
        // 422 dari server memuat kalimat Indonesia yang menyebut item
        // penghalangnya — itulah antarmukanya, jangan ditelan.
        toastError(error);
        return;
      }
      toast(`Proyek ${project.code} ditutup.`);
      handle.close();
      if (onClosed) onClosed();
    }),
  });

  const handle = modal({
    title: 'Tutup proyek',
    body,
    footer: [
      button('Batal', { variant: 'ghost', onClick: () => handle.requestClose() }),
      submit,
    ],
    dirty: () => Boolean(reasonInput && reasonInput.value.trim() !== ''),
  });
}
