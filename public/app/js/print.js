/*
 * Downloading a generated document.
 *
 * The obvious implementation — an <a href> to the print endpoint — cannot work:
 * every API call carries the session token in an X-Api-Token header (the
 * Authorization header collides with the nginx Basic auth gate), and a plain
 * link sends no headers at all. So the PDF is fetched as a blob and handed to
 * the browser through an object URL, the same way an attachment is.
 *
 * It downloads rather than opening a tab on purpose: window.open() after an
 * await is a popup by every browser's reckoning and gets blocked. A file in the
 * downloads folder is one click from the system's own PDF viewer, which is
 * where somebody printing a document was heading anyway.
 *
 * openPrintable(), at the foot of this file, is the exception that proves it:
 * a formulir rumah is a page to look at and print, not a file to keep, so it
 * must open a tab — and pays for that by opening the tab BEFORE the fetch,
 * while the click is still on the stack.
 *
 * A fetched blob carries no name, so the server's Content-Disposition filename
 * is lost and the caller supplies one.
 */

import { api } from './api.js';
import { el, toast, toastError, withBusy } from './ui.js';

/**
 * A filename any browser on any OS will save without argument — document codes
 * carry slashes (PO/2026/VII/0001), which a filename cannot.
 */
function safeName(prefix, code, ext) {
  const slug = String(code || '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  return `${prefix}-${slug || 'dokumen'}.${ext}`;
}

export function pdfName(prefix, code) {
  return safeName(prefix, code, 'pdf');
}

/* P8 — ekspor XLSX formulir rumah. Nama berkasnya dihitung di sini karena
   blob hasil fetch tidak membawa Content-Disposition server (lihat komentar
   pembuka berkas ini). */
export function xlsxName(prefix, code) {
  return safeName(prefix, code, 'xlsx');
}

export async function downloadPdf(path, filename, trigger) {
  const run = async () => {
    const blob = await api.blob(path);
    const url = URL.createObjectURL(blob);
    const link = el('a', { href: url, download: filename, style: { display: 'none' } });
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  try {
    if (trigger) await withBusy(trigger, run);
    else await run();
  } catch (error) {
    toastError(error);
  }
}

/* --------------------------------------------------------------- formulir */

/*
 * Formulir rumah — the owner's own construction forms.
 *
 * These are not PDFs. The endpoint returns a standalone HTML sheet and the
 * BROWSER prints it, because the weekly schedule is a landscape grid that
 * dompdf (the whole of the path above) cannot lay out and whose page box is
 * portrait-only. So this one has to open a tab rather than save a file: the
 * user is going to look at it, set the orientation, and print.
 *
 * The auth problem is exactly the one the top of this file describes — the
 * session token rides in an X-Api-Token header, so a plain <a href> or a
 * window.open(url) sends nothing and lands on a 401 page. The sheet is fetched
 * with the header, re-blobbed as text/html and handed to the tab as an object
 * URL.
 *
 * Which leaves the popup problem this file already documents for downloadPdf:
 * a window.open() issued AFTER an await has lost the user gesture and is
 * blocked. The tab is therefore opened synchronously, on the click, and
 * navigated once the fetch lands.
 */
export async function openPrintable(path, trigger) {
  // Before the first await — the click is still on the stack here, and this is
  // the only line in the function for which that is true.
  const tab = openPrintTab();

  if (!tab) return;

  await showPrintable(tab, path, trigger);
}

/*
 * The synchronous half of openPrintable(), on its own for an action that has
 * to POST before it can print — "Cetak surat penagihan ke-N" (T3.7, actions.js
 * printForm): the level moves on the server first, and the sheet for that
 * level only renders afterwards, so the tab is opened on the click and
 * navigated once BOTH the POST and the fetch have landed. Null when the
 * browser blocked it; the toast has already said so.
 */
export function openPrintTab() {
  const tab = window.open('', '_blank');

  if (!tab) {
    toast('Popup diblokir browser. Izinkan popup untuk situs ini, lalu cetak lagi.', { tone: 'err' });
    return null;
  }

  // A blank white tab for the second the fetch takes reads as a broken button.
  tab.document.write('<!DOCTYPE html><meta charset="utf-8"><title>Menyiapkan formulir…</title>'
    + '<body style="font:14px system-ui,sans-serif;padding:24px;color:#455">Menyiapkan formulir…</body>');
  tab.document.close();

  return tab;
}

/* The asynchronous half: fetch the sheet with the session header into a tab
   openPrintTab() already holds, and print it once it has laid out. */
export async function showPrintable(tab, path, trigger) {
  const run = async () => {
    const fetched = await api.blob(path);
    // Re-typed rather than trusted: a blob's own type is what decides whether
    // an object URL renders or downloads, and a response blob that arrived as
    // application/octet-stream would save the form instead of showing it.
    const url = URL.createObjectURL(new Blob([await fetched.text()], { type: 'text/html' }));

    tab.location.replace(url);
    printWhenLoaded(tab, url);
  };

  try {
    if (trigger) await withBusy(trigger, run);
    else await run();
  } catch (error) {
    // The tab is ours and it only ever held the placeholder; leaving it open
    // would strand the user on "Menyiapkan formulir…" forever while the toast
    // explaining the refusal appears on the page behind it.
    tab.close();
    toastError(error);
  }
}

const PRINT_POLL_MS = 120;

/* ~7 detik. Past that the sheet is not coming and the timer must not run on. */
const PRINT_POLL_LIMIT = 60;

/*
 * Polled, not listened for.
 *
 * A load listener registered on the tab before the navigation does not survive
 * it (the document is replaced), and one registered after it races the load.
 * Polling is same-origin legal — a blob: URL created here inherits this
 * origin — and cannot fire twice.
 *
 * readyState alone is not the test: about:blank is already 'complete', so the
 * placeholder would print instead of the form. The layout's own .lembar
 * wrapper is the proof that the sheet, and not the placeholder, is what is on
 * screen.
 */
function printWhenLoaded(tab, url, ticks = 0) {
  if (tab.closed) {
    URL.revokeObjectURL(url);
    return;
  }

  let ready = false;
  try {
    ready = tab.document.readyState === 'complete' && !!tab.document.querySelector('.lembar');
  } catch {
    ready = false;
  }

  if (ready) {
    tab.focus();
    tab.print();
    // Safe here: revoking an object URL does not unload a document already
    // parsed from it. The one thing it costs is reloading that tab, which
    // would then be blank — printing again from the dialog still works.
    URL.revokeObjectURL(url);
    return;
  }

  if (ticks >= PRINT_POLL_LIMIT) {
    URL.revokeObjectURL(url);
    return;
  }

  setTimeout(() => printWhenLoaded(tab, url, ticks + 1), PRINT_POLL_MS);
}
