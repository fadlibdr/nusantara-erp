/* Tiket yang sudah melewati SLA.

   The endpoint has existed since the ServiceDesk module was written and nothing
   ever called it, so the one question a service manager asks every morning —
   "what have we already broken our promise on?" — had no screen. The ticket list
   shows an SLA column, but finding the breaches meant reading every page. */

import { api } from '../api.js';
import { el, clear, badge, button, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

function rows(payload) {
  return Array.isArray(payload) ? payload : (payload.data || []);
}

/** How far past due, in whole hours — the number that decides who gets called. */
function overdueBy(dueAt) {
  if (!dueAt) return null;
  const hours = (Date.now() - new Date(dueAt).getTime()) / 3_600_000;
  return hours <= 0 ? null : Math.floor(hours);
}

function overdueLabel(hours) {
  if (hours === null) return '—';
  if (hours < 24) return `${hours} jam`;
  return `${Math.floor(hours / 24)} hari ${hours % 24} jam`;
}

export async function renderSlaBreaches(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Tiket Lewat SLA' }),
      el('.desc', { text: 'Tiket yang belum selesai dan sudah melewati batas waktu penyelesaian menurut kontrak layanannya.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderSlaBreaches(host) })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(5, 6));

  let tickets;
  try {
    tickets = rows(await api.list('servicedesk/tickets-sla-breaches', { per_page: 100 }));
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => renderSlaBreaches(host)));
  }

  clear(body);

  if (!tickets.length) {
    body.appendChild(el('.alert.info', 'Tidak ada tiket yang melewati SLA. '
      + 'Daftar ini hanya memuat tiket yang belum selesai dan sudah lewat batas waktu.'));
    return;
  }

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Tiket lewat SLA' }),
      el('.value.sm', { text: String(tickets.length) }),
      el('.delta.down', { text: 'sudah melewati janji ke pelanggan' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Terlama' }),
      el('.value.sm', {
        text: overdueLabel(Math.max(...tickets.map((ticket) => overdueBy(ticket.resolution_due_at) ?? 0))),
      }),
    ]),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Daftar tiket' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }),
        el('th', { text: 'Judul' }),
        el('th', { text: 'Pelanggan' }),
        el('th', { text: 'Prioritas' }),
        el('th', { text: 'Batas selesai' }),
        el('th.right', { text: 'Terlambat' }),
        el('th', { text: 'Ditugaskan ke' }),
      ])),
      el('tbody', tickets.map((ticket) => {
        const hours = overdueBy(ticket.resolution_due_at);
        const node = el('tr', { style: { cursor: 'pointer' } }, [
          el('td.mono', { text: ticket.code }),
          el('td', { text: ticket.title || '—' }),
          el('td', { text: ticket.customer?.name || ticket.customer_name || '—' }),
          el('td', badge(ticket.priority_label || ticket.priority, fmt.statusTone(ticket.priority))),
          el('td', { text: fmt.dateTime(ticket.resolution_due_at) }),
          el('td.right.num', { text: overdueLabel(hours), style: { color: 'var(--danger)' } }),
          // assignee_name is what TicketResource emits — there is no `assignee`
          // object on the wire, so reading it left the column permanently '—'
          // and the morning triage list never showed who already owns a breach.
          el('td', { text: ticket.assignee_name || '—' }),
        ]);

        node.addEventListener('click', () => navigate(`d/servicedesk/tickets/${ticket.id}`));
        return node;
      })),
    ])),
  ]));
}
