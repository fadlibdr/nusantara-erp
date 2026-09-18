/* Hash router — keeps the SPA deployable as plain static files under /app/. */

const routes = [];
let notFound = null;
let current = '';

export function route(pattern, handler) {
  const keys = [];
  const source = pattern
    .replace(/[.+?^${}()|[\]\\]/g, '\\$&')
    .replace(/:(\w+)/g, (_, name) => {
      keys.push(name);
      return '([^/]+)';
    })
    .replace(/\*/g, '.*');
  routes.push({ regex: new RegExp(`^${source}$`), keys, handler });
}

export function fallback(handler) {
  notFound = handler;
}

export function currentPath() {
  return (location.hash || '#/dashboard').replace(/^#\/?/, '');
}

export function navigate(path, { replace = false } = {}) {
  const target = `#/${String(path).replace(/^\/?#?\/?/, '')}`;
  if (replace) location.replace(target);
  else location.hash = target;
}

/* Tulis ulang bagian query hash TANPA memicu hashchange, dan beri tahu router
 * bahwa itulah lokasi sekarang — untuk layar bertab yang berpindah tab di
 * dalam dirinya sendiri: tautan berikutnya ke '#/x?tab=a' (notifikasi "Buka
 * dokumen") berbeda dari hash sekarang, jadi hashchange sungguh berjalan, dan
 * muat ulang halaman kembali ke tab yang sedang dibuka, bukan tab dari query
 * lama. */
export function replacePath(path) {
  history.replaceState(null, '', `#/${String(path).replace(/^\/?#?\/?/, '')}`);
  current = currentPath();
}

export function back() {
  if (history.length > 1) history.back();
  else navigate('dashboard');
}

export function resolve() {
  const full = currentPath();
  current = full;

  // Bagian query dipisah SEBELUM pencocokan pola: '#/r/x?page=2' dulunya gagal
  // di lookup RESOURCES karena '?page=2' ikut terbawa ke path. Tidak ada pola
  // rute berisi '?', jadi rute lama tidak berubah sedikit pun. Query mentah
  // menjadi argumen ketiga handler — handler lama menerima (params, path) dan
  // mengabaikannya. `current` tetap menyimpan string PENUH, sehingga
  // back/forward antara URL berbeda query benar-benar me-resolve ulang — itulah
  // jalur pemulihan state daftar dari URL.
  const cut = full.indexOf('?');
  const path = cut === -1 ? full : full.slice(0, cut);
  const query = cut === -1 ? '' : full.slice(cut + 1);

  for (const entry of routes) {
    const match = entry.regex.exec(path);
    if (!match) continue;
    const params = {};
    entry.keys.forEach((key, index) => { params[key] = decodeURIComponent(match[index + 1]); });
    return entry.handler(params, path, query);
  }

  if (notFound) return notFound(path);
  return undefined;
}

/*
 * Penjaga meninggalkan rute (UI-4d).
 *
 * Formulir modal dijaga `dirty()` milik modal(): menutupnya menanyakan dulu.
 * Formulir HALAMAN tidak punya penutup — yang meninggalkannya adalah navigasi,
 * dan sampai sekarang router tidak punya cara menolaknya. Tanpa ini, satu klik
 * pada tautan sidebar membuang lima belas baris yang belum tersimpan tanpa satu
 * pun pertanyaan.
 *
 * Penjaganya ASINKRON karena pertanyaannya sebuah dialog. Selama ia terbuka,
 * bilah alamat sudah memuat hash yang baru; kalau jawabannya "tetap di sini",
 * hash lama ditulis kembali lewat history.replaceState — yang TIDAK memicu
 * hashchange, jadi tidak ada putaran yang perlu diredam dan `current` memang
 * masih bernilai hash lama.
 *
 * Izinnya sekali pakai: sesudah pengguna memilih meninggalkan, penjaganya
 * dilepas, sehingga navigasi yang sama tidak ditanyai dua kali.
 */
let leaveGuard = null;

export function setLeaveGuard(fn) {
  leaveGuard = fn;
}

/* Dilepas HANYA oleh pemasangnya sendiri: sebuah layar yang sudah digantikan
   tidak boleh mencabut penjaga milik layar penggantinya. */
export function clearLeaveGuard(fn) {
  if (leaveGuard === fn) leaveGuard = null;
}

export function start() {
  window.addEventListener('hashchange', async () => {
    if (currentPath() === current) return;

    if (leaveGuard) {
      const from = current;
      const guard = leaveGuard;
      let mayLeave = true;
      try {
        mayLeave = await guard();
      } catch {
        /* Penjaga yang melempar tidak boleh mengunci aplikasi di satu layar. */
        mayLeave = true;
      }
      if (!mayLeave) {
        history.replaceState(null, '', `#/${from}`);
        return;
      }
      clearLeaveGuard(guard);
    }

    resolve();
  });
  resolve();
}
