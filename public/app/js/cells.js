/* Value rendering shared by list tables, detail panels and report tables. */

import { el, badge, pluck, progressBar } from './ui.js';
import * as fmt from './format.js';
import { enumLabel } from './enums.js';
import { labelFor } from './lookup.js';

function truncate(text, max) {
  const value = String(text ?? '');
  return max && value.length > max ? `${value.slice(0, max - 1)}…` : value;
}

/**
 * Render one column of one row into a DOM node.
 * `column.type` drives the formatting; see schema.js for the vocabulary.
 */
export function renderCell(row, column) {
  const raw = pluck(row, column.key);

  switch (column.type) {
    case 'code':
      return raw ? el('span.mono', { text: String(raw) }) : el('span.muted', { text: '—' });

    case 'currency':
      return el('span.num', {
        text: fmt.rupiah(raw),
        class: Number(raw) === 0 && column.toneZero ? 'muted' : '',
      });

    case 'currency2':
      return el('span.num', { text: fmt.rupiah(raw, { decimals: 2 }) });

    case 'qty':
      return el('span.num', {
        text: column.signed && Number(raw) > 0 ? `+${fmt.qty(raw)}` : fmt.qty(raw),
        class: column.signed ? (Number(raw) < 0 ? 'muted' : '') : '',
      });

    case 'number': {
      if (raw === null || raw === undefined || raw === '') return el('span.muted', { text: '—' });
      const text = `${column.prefix || ''}${fmt.num(raw, column.decimals || 0)}${column.suffix || ''}`;
      return el('span.num', { text, class: column.strong ? 'strong' : '' });
    }

    case 'percent': {
      const value = Number(raw);
      const node = el('span.num', {
        text: column.signed && value > 0 ? `+${fmt.percent(raw)}` : fmt.percent(raw),
      });
      if (column.signed && Number.isFinite(value) && value !== 0) {
        node.style.color = value < 0 ? 'var(--danger)' : 'var(--success)';
      }
      return node;
    }

    case 'date':
      return el('span', [
        el('span', { text: fmt.date(raw) }),
        column.withRelative && raw ? el('span.cell-sub', { text: fmt.relativeDays(raw) }) : null,
      ]);

    case 'datetime':
      return el('span.nowrap', { text: fmt.dateTime(raw) });

    case 'period':
      return el('span', { text: fmt.periodLabel(row.period_year, row.period_month) });

    case 'bool':
      return raw
        ? el('span', { text: '✓', style: { color: 'var(--success)', fontWeight: '700' } })
        : el('span.muted', { text: '–' });

    case 'flag':
      return badge(
        raw ? column.trueLabel || 'Ya' : column.falseLabel || 'Tidak',
        raw ? column.trueTone || 'green' : column.falseTone || '',
      );

    case 'status':
      return badge(row[`${column.key}_label`] || raw || '—', fmt.statusTone(raw));

    case 'priority': {
      const tone = { critical: 'red', high: 'amber', medium: 'blue', low: '' }[raw] || '';
      return badge(row.priority_label || enumLabel('ticketPriority', raw), tone);
    }

    case 'enum':
      return el('span', { text: row[`${column.key}_label`] || enumLabel(column.enum, raw) || '—' });

    case 'rel': {
      if (raw === null || raw === undefined || raw === '') return el('span.muted', { text: '—' });
      const label = labelFor(column.lookup, raw);
      return label ? el('span', { text: label }) : el('span.mono.muted', { text: `#${raw}` });
    }

    case 'progress': {
      const actual = Number(row.actual_progress_pct ?? raw ?? 0);
      const planned = Number(row.planned_progress_pct ?? 0);
      const behind = planned > 0 && actual < planned - 0.01;
      return el('div', { style: { minWidth: '130px' } }, [
        el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: '11.5px', marginBottom: '3px' } }, [
          el('span.num', { text: fmt.percent(actual) }),
          planned > 0 ? el('span.muted.num', { text: `rencana ${fmt.percent(planned)}` }) : null,
        ]),
        progressBar(actual, behind ? 'amber' : 'green'),
      ]);
    }

    case 'sla': {
      if (!raw) return el('span.muted', { text: '—' });
      const breached = column.breachKey ? row[column.breachKey] : false;
      return el('span', [
        el('span.nowrap', { text: fmt.dateTime(raw), style: breached ? { color: 'var(--danger)' } : {} }),
        breached ? el('span.cell-sub', { text: 'SLA terlampaui', style: { color: 'var(--danger)' } }) : null,
      ]);
    }

    case 'tags': {
      const list = Array.isArray(raw) ? raw : [];
      if (!list.length) return el('span.muted', { text: '—' });
      return el('span', { style: { display: 'inline-flex', gap: '4px', flexWrap: 'wrap' } },
        list.map((tag) => badge(String(tag), 'primary')));
    }

    case 'count':
      return el('span.num', { text: `${Array.isArray(raw) ? raw.length : Number(raw) || 0}${column.suffix || ''}` });

    case 'link':
      return el('a', { href: column.href(row), text: raw ?? '—' });

    default: {
      if (raw === null || raw === undefined || raw === '') return el('span.muted', { text: '—' });
      const main = el('span.cell-main', { text: truncate(raw, column.truncate) });
      const sub = column.sub ? pluck(row, column.sub) : null;
      if (!sub) return column.indentBy ? withIndent(row, column, main) : main;
      return el('span', [main, el('span.cell-sub', { text: truncate(sub, 60) })]);
    }
  }
}

/** COA-style indent: depth derived from the "1-1210" code shape. */
function withIndent(row, column, node) {
  const code = String(pluck(row, column.indentBy) || '');
  const digits = code.replace(/[^0-9]/g, '');
  let depth = 0;
  if (digits.length >= 5) {
    if (digits.slice(1) === '0000') depth = 0;
    else if (digits.slice(2) === '000') depth = 1;
    else depth = 2;
  }
  if (!depth) return el('span', { style: { fontWeight: '600' } }, node);
  return el('span', { style: { paddingLeft: `${depth * 16}px` } }, node);
}

/** Sum of a numeric column across rows. */
export function sumColumn(rows, key) {
  return rows.reduce((total, row) => total + (Number(pluck(row, key)) || 0), 0);
}
