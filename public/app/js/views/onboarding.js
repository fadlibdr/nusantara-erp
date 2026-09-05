/* Panduan onboarding per peran — pop-up saat masuk.
 *
 * Permintaan pemilik 5 Sep 2026: "on boarding is not working, make it pop-up
 * every user is logged in and create a button to skip the onboarding process
 * also make the choice is remembered". "Not working" harfiah: dua belas
 * panduan itu hanya ada sebagai docs/ONBOARDING/<peran>.md, diserahkan dengan
 * tangan (README §"Cara menyerahkan") — aplikasi tidak pernah menampilkannya.
 *
 * Satu modal, tujuh langkah (kerangka README §"Kerangka yang sama": Siapa
 * Anda · Hari pertama · Pekerjaan Anda · Yang akan menolak · Formulir ·
 * Daftar periksa · Bila tersangkut). Isinya datang dari server sudah berupa
 * HTML yang disaring (GET iam/me/onboarding, tag mentah dibuang) — berkas
 * markdown tetap satu-satunya sumber, jadi yang dicetak atasan dan yang tampil
 * di layar tidak pernah berbeda.
 *
 * Keputusan Lewati/Selesai DISIMPAN DI SERVER (PUT iam/me/onboarding), bukan
 * localStorage: tablet kantor lapangan dipakai bergantian, dan pilihan
 * seorang pengawas bukan urusan kasir yang masuk sesudahnya — sebaliknya,
 * pilihan yang dibuat di laptop harus berlaku juga di tablet. Yang menentukan
 * pop-up di app.js adalah `onboarding_status` pada auth/me: null berarti
 * belum pernah memutuskan, dan panduan muncul lagi di setiap masuk sampai
 * orangnya menekan salah satu tombol. */
import { api, session } from '../api.js';
import { el, clear, button, modal, toast, toastError, withBusy } from '../ui.js';

const ENDPOINT = 'iam/me/onboarding';

/* Kalimat yang sama dengan `message` server — toast tetap tampil walau
   respons berhasil datang tanpa kalimat. */
const COPY = {
  skipped: 'Panduan onboarding dilewati — bisa dibuka lagi dari menu akun.',
  completed: 'Panduan onboarding selesai.',
  reset: 'Panduan onboarding akan tampil lagi saat Anda masuk berikutnya.',
};

/* Centang daftar periksa hidup selama tab ini terbuka saja — tidak ada
   kolomnya di server, dan panduan menyebut itu apa adanya di bawah daftar.
   Disimpan per modul, bukan per modal, supaya menutup lalu membuka lagi dari
   menu akun tidak menghapus centang yang baru dibuat. Kunci: id bagian +
   urutan kotak. */
const ticks = new Map();

/**
 * Membuka panduan untuk pemanggil. `auto` = dibuka sendiri saat masuk:
 * menutup lewat Esc/backdrop dicatat sebagai Lewati supaya tidak mengganggu
 * lagi besok; peran tanpa panduan (404) diam saja. Dari menu akun
 * (`auto: false`) tidak ada yang dicatat kecuali orangnya menekan Lewati atau
 * Selesai, dan galat ditampilkan.
 */
export async function openOnboarding({ auto = false } = {}) {
  let guide;
  try {
    guide = await api.get(ENDPOINT);
  } catch (error) {
    /* Peran khusus tanpa berkas panduan: satu GET kecil per masuk, dan bila
       panduannya ditulis kemudian orangnya masih akan melihatnya — mencatat
       "dilewati" diam-diam di sini akan menghilangkan itu selamanya. */
    if (!auto) toastError(error);
    return null;
  }

  /* Balapan dengan keputusan di perangkat lain: session.user dibaca dari
     localStorage sebelum refreshMe() selesai, sedangkan jawaban ini baru
     dari server. Server yang menang. */
  if (auto && guide.status) {
    syncStanding(guide.status, guide.seen_at);
    return null;
  }

  const user = session.user || {};
  const sections = guide.sections || [];
  const total = sections.length;
  if (!total) {
    if (!auto) toast('Panduan untuk peran Anda belum memuat satu bagian pun.', { tone: 'warn' });
    return null;
  }

  let index = 0;
  /* Hanya pop-up otomatis yang mencatat penutupan tanpa tombol; dimatikan
     begitu Lewati/Selesai sendiri yang mencatat, supaya close() sesudahnya
     tidak mencatat "dilewati" di atas "selesai". */
  let recordOnClose = auto;

  const counter = el('span.onboarding-counter');
  const guideLink = el('a', {
    href: guide.guide_url, target: '_blank', rel: 'noopener',
    title: guide.guide_path, text: 'Buka panduan lengkap',
  });
  const head = el('.onboarding-head', [counter, el('.spacer'), guideLink]);

  /* "Tampilkan lagi": status kembali null, jadi pop-up muncul saat masuk
     berikutnya — untuk orang yang dulu menekan Lewati lalu berubah pikiran. */
  if (guide.status) {
    head.insertBefore(button('Tampilkan lagi saat masuk berikutnya', {
      variant: 'ghost', size: 'sm',
      onClick: (event) => withBusy(event.currentTarget, async () => {
        try {
          session.setUser(await api.put(ENDPOINT, { status: null }));
          toast(COPY.reset);
        } catch (error) {
          toastError(error);
        }
      }),
    }), guideLink);
  }

  const steps = sections.map((section, i) => button('', {
    onClick: () => go(i),
    title: section.heading,
  }));
  steps.forEach((step, i) => {
    step.className = 'onboarding-step';
    step.append(
      el('span.n', { text: String(i + 1) }),
      el('span.t', { text: sections[i].heading.replace(/^\d+\.\s*/, '') }),
    );
  });
  const strip = el('ol.onboarding-steps', steps.map((step) => el('li', step)));

  const body = el('.onboarding-body', { tabindex: '-1' });

  const back = button('Kembali', { iconName: 'back', onClick: () => go(index - 1) });
  const next = button('Lanjut', { variant: 'primary', onClick: () => go(index + 1) });
  const finish = button('Selesai', { variant: 'primary', iconName: 'check', onClick: (event) => decide('completed', event.currentTarget) });
  /* Lewati tampak di SETIAP langkah — orang yang sudah tahu pekerjaannya
     tidak dipaksa menekan Lanjut enam kali. Sesudah memutuskan, tombol kirinya
     Tutup: tidak ada yang dicatat lagi kecuali Selesai ditekan. */
  const leave = guide.status
    ? button('Tutup', { variant: 'ghost', onClick: () => dialog.close() })
    : button('Lewati', { variant: 'ghost', onClick: (event) => decide('skipped', event.currentTarget) });

  const dialog = modal({
    title: `Selamat datang, ${user.name || 'rekan'} — ${guide.title}`,
    width: 'onboarding',
    body: el('.onboarding', [head, strip, body]),
    footer: [leave, el('.spacer'), back, next, finish],
    onClose: () => {
      if (!recordOnClose) return;
      recordOnClose = false;
      /* Esc atau klik backdrop pada pop-up otomatis = Lewati: tidak ada tombol
         yang ditekan, tapi orangnya sudah memilih untuk tidak membacanya
         sekarang, dan panduan yang muncul lagi di setiap masuk adalah
         gangguan, bukan bantuan. Gagal mencatat hanya berarti panduan tampil
         lagi besok — tidak perlu galat. */
      api.put(ENDPOINT, { status: 'skipped' })
        .then((fresh) => { session.setUser(fresh); toast(COPY.skipped); })
        .catch(() => {});
    },
  });

  async function decide(status, node) {
    await withBusy(node, async () => {
      try {
        const fresh = await api.put(ENDPOINT, { status });
        session.setUser(fresh);
        recordOnClose = false;
        dialog.close();
        toast(COPY[status]);
      } catch (error) {
        toastError(error);
      }
    });
  }

  function go(target) {
    index = Math.max(0, Math.min(total - 1, target));
    const section = sections[index];
    counter.textContent = `${index + 1} dari ${total}`;
    steps.forEach((step, i) => {
      step.classList.toggle('current', i === index);
      if (i === index) step.setAttribute('aria-current', 'step');
      else step.removeAttribute('aria-current');
    });

    clear(body);
    body.appendChild(el('h3.onboarding-heading', { text: section.heading }));
    body.appendChild(sectionContent(section, guide));
    body.scrollTop = 0;

    back.disabled = index === 0;
    next.hidden = index === total - 1;
    finish.hidden = index !== total - 1;
  }

  go(0);
  return dialog;
}

/**
 * HTML bagian dari server, dirapikan untuk hidup di dalam modal: tautan
 * relatif panduan (finance.md, ../PANDUAN-PENGGUNA.md) diarahkan ke berkas
 * di repositori dan dibuka di tab baru — di dalam SPA, href relatif akan
 * menimpa halaman aplikasi; tabel lebar menggulir di dalam kotaknya sendiri;
 * kotak centang daftar periksa dihidupkan (GFM merender `disabled`).
 */
function sectionContent(section, guide) {
  const content = el('.onboarding-content', { html: section.html });

  content.querySelectorAll('a[href]').forEach((anchor) => {
    const href = anchor.getAttribute('href') || '';
    if (href.startsWith('#')) {
      anchor.removeAttribute('href'); // sasaran di dalam berkas — tidak ada di sini
      return;
    }
    if (!/^(https?:|mailto:)/i.test(href)) {
      try {
        anchor.href = new URL(href, guide.guide_url).href;
      } catch {
        anchor.removeAttribute('href');
        return;
      }
    }
    anchor.target = '_blank';
    anchor.rel = 'noopener';
  });

  content.querySelectorAll('table').forEach((table) => {
    const wrap = el('.onboarding-table');
    table.replaceWith(wrap);
    wrap.appendChild(table);
  });

  const boxes = content.querySelectorAll('input[type="checkbox"]');
  boxes.forEach((box, i) => {
    const key = `${section.id}:${i}`;
    box.disabled = false;
    box.checked = ticks.get(key) === true;
    box.addEventListener('change', () => ticks.set(key, box.checked));
  });
  if (boxes.length) {
    content.appendChild(el('.onboarding-note.muted', {
      text: 'Centang hanya untuk sesi ini — tidak tersimpan, hilang saat halaman dimuat ulang.',
    }));
  }

  return content;
}

/* Salinan sesi mengikuti jawaban server tanpa menunggu refreshMe(). */
function syncStanding(status, seenAt) {
  const user = session.user;
  if (!user) return;
  session.setUser({ ...user, onboarding_status: status, onboarding_seen_at: seenAt });
}
