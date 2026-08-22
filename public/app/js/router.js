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

export function start() {
  window.addEventListener('hashchange', () => {
    if (currentPath() !== current) resolve();
  });
  resolve();
}
