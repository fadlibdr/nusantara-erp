/* Approval inbox — the bell in the header.
 *
 * Polled rather than pushed. The API is token-authenticated over plain HTTP
 * with no websocket or SSE endpoint, and adding one would mean a persistent
 * connection per open tab through nginx for a badge that changes a few times a
 * day. A poll on a slow interval, paused while the tab is hidden, costs one
 * cheap indexed count and is honest about what it is. */

import { api, session } from './api.js';
import { el, clear, button, icon, badge, toast, toastError, modal, closeModal, withBusy } from './ui.js';
import * as fmt from './format.js';

const POLL_MS = 90_000;

const EVENT_TONE = {
  'document.submitted': 'amber',
  'document.approved': 'green',
  'document.rejected': 'red',
};

const EVENT_LABEL = {
  'document.submitted': 'Menunggu',
  'document.approved': 'Disetujui',
  'document.rejected': 'Ditolak',
};

let bellButton = null;
let timer = null;
let unread = 0;

function paintBadge() {
  if (!bellButton) return;

  const existing = bellButton.querySelector('.bell-count');
  if (existing) existing.remove();

  if (unread > 0) {
    bellButton.appendChild(el('span.bell-count', { text: unread > 99 ? '99+' : String(unread) }));
  }

  bellButton.title = unread > 0 ? `${unread} pemberitahuan belum dibaca` : 'Pemberitahuan';
}

async function refresh() {
  try {
    const data = await api.get('core/notifications/unread-count');
    unread = Number(data.unread) || 0;
    paintBadge();
  } catch (error) {
    // A failed poll is not worth a toast — the next one will pick it up, and a
    // badge that shouts about its own plumbing is worse than a stale badge.
    //
    // A 401 is different: api.js has already cleared the session and shown the
    // login screen, and a timer that keeps firing re-renders that screen every
    // 90 seconds, wiping whatever the user has typed into it.
    if (error && error.status === 401) stopNotificationPolling();
  }
}

function row(notification, onChanged) {
  const unreadRow = !notification.read_at;

  return el(`.notif${unreadRow ? '.unread' : ''}`, [
    el('.notif-head', [
      badge(EVENT_LABEL[notification.event] || '—', EVENT_TONE[notification.event] || ''),
      el('.spacer'),
      el('.muted', { text: fmt.relativeDays(notification.created_at), style: { fontSize: '12px' } }),
    ]),
    el('.notif-title', { text: notification.title }),
    notification.body ? el('.notif-body', { text: notification.body }) : null,
    el('.row-actions', [
      notification.link
        ? button('Buka dokumen', {
          size: 'sm',
          onClick: async () => {
            if (unreadRow) await api.post('core/notifications/read', { ids: [notification.id] });
            // Close first: the router paints behind the overlay, so navigating
            // with the modal still open looks like the click did nothing.
            closeModal();
            window.location.hash = notification.link.replace(/^#/, '');
            onChanged();
          },
        })
        : null,
      unreadRow
        ? button('Tandai dibaca', {
          size: 'sm',
          variant: 'ghost',
          onClick: (event) => withBusy(event.currentTarget, async () => {
            await api.post('core/notifications/read', { ids: [notification.id] });
            onChanged();
          }),
        })
        : null,
    ]),
  ]);
}

function openInbox() {
  const body = el('div', el('p.muted', { text: 'Memuat…' }));

  const dialog = modal({
    title: 'Pemberitahuan',
    width: 'wide',
    body,
    footer: [
      button('Tandai semua dibaca', {
        variant: 'ghost',
        onClick: (event) => withBusy(event.currentTarget, async () => {
          try {
            const result = await api.post('core/notifications/read', { all: true });
            unread = Number(result.unread) || 0;
            paintBadge();
            load();
          } catch (error) {
            toastError(error);
          }
        }),
      }),
      button('Tutup', { onClick: () => dialog.close() }),
    ],
  });

  async function load() {
    clear(body).appendChild(el('p.muted', { text: 'Memuat…' }));

    try {
      const list = await api.get('core/notifications', { limit: 50 });
      clear(body);

      if (!list.length) {
        body.appendChild(el('p.muted', {
          text: 'Belum ada pemberitahuan. Dokumen yang diajukan untuk disetujui akan muncul di sini.',
        }));
        return;
      }

      list.forEach((notification) => body.appendChild(row(notification, () => { refresh(); load(); })));
    } catch (error) {
      clear(body).appendChild(el('.alert.error', { text: error.message || 'Gagal memuat pemberitahuan.' }));
    }
  }

  load();
  return dialog;
}

/** The bell, ready to drop into the header. */
export function notificationBell() {
  bellButton = button('', {
    variant: 'ghost',
    iconName: 'inbox',
    title: 'Pemberitahuan',
    onClick: () => openInbox(),
  });
  bellButton.classList.add('bell');

  return bellButton;
}

export function startNotificationPolling() {
  stopNotificationPolling();
  if (!session.token) return;

  refresh();
  timer = setInterval(() => {
    // Nothing changes for a tab nobody is looking at.
    if (document.visibilityState === 'visible') refresh();
  }, POLL_MS);

  document.addEventListener('visibilitychange', onVisible);
}

export function stopNotificationPolling() {
  if (timer) clearInterval(timer);
  timer = null;
  unread = 0;
  paintBadge();
  document.removeEventListener('visibilitychange', onVisible);
}

function onVisible() {
  if (document.visibilityState === 'visible') refresh();
}
