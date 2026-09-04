/* Thin client over the Sanctum-token JSON API.
   Every list endpoint answers { data: [...], meta: {...} } and every error
   answers { message, errors? }, so both shapes are normalised here once. */

const BASE = new URL('../../api/', new URL('.', import.meta.url)).pathname.replace(/\/$/, '');
const TOKEN_KEY = 'nusantara_erp_token';
const USER_KEY = 'nusantara_erp_user';

export class ApiError extends Error {
  constructor(status, message, errors) {
    super(message);
    this.status = status;
    this.errors = errors || null;
  }

  /** Flattened "field: first message" lines, for toasts. */
  get details() {
    if (!this.errors) return [];
    return Object.entries(this.errors).map(([field, msgs]) => `${field}: ${[].concat(msgs)[0]}`);
  }
}

export const session = {
  get token() {
    return localStorage.getItem(TOKEN_KEY);
  },
  get user() {
    try {
      return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
    } catch {
      return null;
    }
  },
  start(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  },
  setUser(user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  },
  clear() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  },
  /** An array means "any of these" — a screen several modules can reach. */
  can(permission) {
    if (!permission) return true;
    const user = this.user;
    if (!user) return false;
    const held = user.permissions || [];
    return Array.isArray(permission)
      ? permission.some((one) => held.includes(one))
      : held.includes(permission);
  },
  hasRole(role) {
    return ((this.user || {}).roles || []).includes(role);
  },
};

/** Callback invoked when the API rejects our token, so the shell can bail out. */
let onUnauthorized = () => {};
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn;
}

function buildUrl(path, params) {
  const url = `${BASE}/${String(path).replace(/^\//, '')}`;
  if (!params) return url;

  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    query.append(key, value);
  }
  const qs = query.toString();
  return qs ? `${url}?${qs}` : url;
}

async function request(method, path, { body, params, raw = false } = {}) {
  const headers = { Accept: 'application/json' };
  const token = session.token;

  // X-Api-Token, not Authorization: a deployment may sit behind an HTTP-level
  // gate (Basic auth, an authenticating proxy) that owns Authorization. Setting
  // it here would REPLACE the browser's gate credential rather than accompany
  // it, so the gate would reject every API call and the 401 would read as an
  // expired session. The API accepts either header — see IamServiceProvider.
  if (token) headers['X-Api-Token'] = token;

  // A FormData body passes through untouched, and its Content-Type must NOT be
  // set here: the browser writes multipart/form-data with the boundary it
  // chose, and a hand-set header would omit that boundary, leaving the server
  // an unparseable body.
  const multipart = body instanceof FormData;
  if (body !== undefined && !multipart) headers['Content-Type'] = 'application/json';

  let response;
  try {
    response = await fetch(buildUrl(path, params), {
      method,
      headers,
      body: body === undefined || multipart ? body : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'Tidak dapat terhubung ke server.');
  }

  return settle(response.status, await response.text(), raw);
}

/**
 * The response half of request(), shared with the one XHR path below so the
 * two transports can never disagree about what a 204, a 401 or a 422 means:
 * one place clears the session, one place picks the Indonesian fallback.
 */
function settle(status, text, raw = false) {
  if (status === 204) return null;

  let payload = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (status < 200 || status >= 300) {
    if (status === 401) {
      session.clear();
      onUnauthorized();
    }
    const message =
      (payload && payload.message) ||
      { 403: 'Anda tidak punya akses untuk tindakan ini.', 404: 'Data tidak ditemukan.', 429: 'Terlalu banyak permintaan — coba lagi sebentar lagi.' }[status] ||
      `Terjadi kesalahan (HTTP ${status}).`;
    throw new ApiError(status, message, payload && payload.errors);
  }

  if (raw) return payload;
  return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
}

/*
 * The one XMLHttpRequest in this client — because fetch() cannot report upload
 * progress, and a site photo is the one request whose body is the slow part.
 * It travels as base64 JSON (POST core/attachments: 5 MB raw is ~7 M
 * characters), and on the 4G a slab has that is tens of seconds during which,
 * until T2.9, the screen showed a spinning button and nothing else
 * (ASESMEN-UX §4.3). Measured 4 Sep 2026 (harness S15, Chromium upload
 * throttled to 200 kB/s): a 1 MB photo raises 70 upload.progress events over
 * 7 s on this path; on loopback the same body raises one, at 100 %.
 *
 * Everything else stays on fetch. Same headers as request(), same settle(), so
 * the only difference between the two transports is the progress callback —
 * { loaded, total } in bytes, total null when the browser cannot know it.
 */
function requestWithProgress(method, path, body, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open(method, buildUrl(path));
    xhr.setRequestHeader('Accept', 'application/json');
    if (session.token) xhr.setRequestHeader('X-Api-Token', session.token);

    const multipart = body instanceof FormData;
    if (!multipart) xhr.setRequestHeader('Content-Type', 'application/json');

    if (onProgress) {
      xhr.upload.addEventListener('progress', (event) => {
        onProgress({ loaded: event.loaded, total: event.lengthComputable ? event.total : null });
      });
    }

    // A dropped connection, an aborted request and a timeout all land here
    // with status 0 — the same "Tidak dapat terhubung" that fetch() throws.
    const failed = () => reject(new ApiError(0, 'Tidak dapat terhubung ke server.'));
    xhr.addEventListener('error', failed);
    xhr.addEventListener('abort', failed);
    xhr.addEventListener('timeout', failed);

    xhr.addEventListener('load', () => {
      try {
        resolve(settle(xhr.status, xhr.responseText));
      } catch (error) {
        reject(error);
      }
    });

    xhr.send(multipart ? body : JSON.stringify(body));
  });
}

/**
 * A binary response — an attachment download.
 *
 * Separate from request() because that one reads the body as text and parses
 * JSON, which corrupts a PDF. And a plain <a href> cannot be used at all: it
 * carries no token header, so it would come back 401.
 */
async function requestBlob(path) {
  // Accept JSON even though the success body is a PDF: without it an expired
  // session answers 302 to the login page and the 401 only arrives after a
  // second round trip. The print and download endpoints ignore Accept.
  const headers = { Accept: 'application/json' };
  if (session.token) headers['X-Api-Token'] = session.token;

  let response;
  try {
    response = await fetch(buildUrl(path), { method: 'GET', headers });
  } catch {
    throw new ApiError(0, 'Tidak dapat terhubung ke server.');
  }

  if (!response.ok) {
    if (response.status === 401) {
      session.clear();
      onUnauthorized();
    }

    // The error body IS json even though the success body is not.
    let message = `Terjadi kesalahan (HTTP ${response.status}).`;
    try {
      const payload = JSON.parse(await response.text());
      if (payload && payload.message) message = payload.message;
    } catch { /* keep the generic message */ }

    throw new ApiError(response.status, message);
  }

  return response.blob();
}

/**
 * The largest file the JSON attachment route can carry, mirroring
 * AttachmentService::MAX_BYTES (drift caught by AttachmentSpaPolicyTest).
 * Base64 inflates by a third, so 5 MB raw is ~6.99 M characters — just inside
 * the server's 7 000 000-char content rule. One byte more and the JSON route
 * would refuse it, which is what the multipart route below is for.
 */
const JSON_UPLOAD_MAX_BYTES = 5 * 1024 * 1024;

function readAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    // reader.result is a data: URI; the server strips the prefix itself.
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('Berkas tidak dapat dibaca.'));
    reader.readAsDataURL(file);
  });
}

/**
 * One file onto one document, on whichever transport can carry it.
 *
 * Up to 5 MB goes as base64 inside the ordinary JSON body (POST
 * core/attachments) — the route this client has always used. Bigger files (the
 * 25 MB engineering-drawing class, P0-D) travel raw as multipart form data to
 * core/attachments/upload, because 25 MB of base64 is ~33 MB of JSON — beyond
 * post_max_size on any deployment. Same fields, same server-side checks, same
 * response shape: the two routes land on one policy in AttachmentService.
 *
 * @param {File} file
 * @param {object} fields document_type + document_id, optionally caption,
 *                        latitude, longitude, accuracy_m
 */
async function uploadFile(file, fields) {
  if (file.size <= JSON_UPLOAD_MAX_BYTES) {
    return request('POST', 'core/attachments', {
      body: { ...fields, filename: file.name, content: await readAsBase64(file) },
    });
  }

  const form = new FormData();
  form.append('file', file, file.name);
  for (const [key, value] of Object.entries(fields)) {
    if (value !== undefined && value !== null) form.append(key, value);
  }
  return request('POST', 'core/attachments/upload', { body: form });
}

export const api = {
  get: (path, params) => request('GET', path, { params }),
  blob: (path) => requestBlob(path),
  /** List + pagination meta together. */
  list: (path, params) => request('GET', path, { params, raw: true }),
  post: (path, body) => request('POST', path, { body }),
  put: (path, body) => request('PUT', path, { body }),
  del: (path) => request('DELETE', path),
  uploadFile,
  /** POST with upload progress — the XHR path; onProgress({ loaded, total }). */
  upload: (path, body, onProgress) => requestWithProgress('POST', path, body, onProgress),
};

export async function login(email, password) {
  const data = await request('POST', 'iam/auth/login', { body: { email, password } });
  session.start(data.token, data.user);
  return data.user;
}

export async function logout() {
  try {
    await request('POST', 'iam/auth/logout');
  } catch {
    /* token may already be gone server-side — clearing locally is enough */
  }
  session.clear();
}

export async function refreshMe() {
  const user = await request('GET', 'iam/auth/me');
  session.setUser(user);
  return user;
}
