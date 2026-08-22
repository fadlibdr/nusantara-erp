/* Ekspor CSV sisi klien — modul generik seperti combobox.js: tidak tahu apa-apa
 * tentang resource schema.js, hanya menerima kolom dan baris.
 *
 * Format ditujukan untuk Excel dengan pengaturan regional Indonesia (konsumen
 * yang disebut: KAP): pemisah ';' dengan CRLF, angka MENTAH berkoma desimal
 * tanpa pemisah ribuan (regional id-ID membaca ',' sebagai desimal; ';' sebagai
 * pemisah kolomlah yang membuat koma desimal aman), tanggal yyyy-mm-dd, boolean
 * Ya/Tidak. Kompromi yang diterima dan disengaja: Excel berlokal en-US akan
 * membaca nilai berkoma desimal sebagai teks.
 *
 * Unduhan dibangun dari Blob (pola taxexport.js), bukan tautan <a href> polos:
 * API mengautentikasi lewat header X-Api-Token, jadi tautan telanjang tidak
 * pernah bisa membawa kredensial. */

import { el, toast, pluck } from './ui.js';
import { enumLabel } from './enums.js';
import { labelFor } from './lookup.js';
import { toDateInput, toDateTimeInput, periodLabel, today } from './format.js';

/** Angka mentah untuk Excel-ID: koma desimal, tanpa pemisah ribuan. */
function numberValue(raw) {
  if (raw === null || raw === undefined || raw === '') return '';
  const n = Number(raw);
  if (!Number.isFinite(n)) return '';
  return String(n).replace('.', ',');
}

/**
 * Nilai satu sel untuk ekspor, menurut tipe kolomnya (kosakata schema.js,
 * semantik sama dengan renderCell di cells.js — hanya saja "layak ekspor":
 * angka mentah, bukan string berformat rupiah).
 */
export function csvValue(row, column) {
  const raw = pluck(row, column.key);

  switch (column.type) {
    case 'currency':
    case 'currency2':
    case 'qty':
    case 'percent':
    case 'number':
      return numberValue(raw);

    case 'count':
      return numberValue(Array.isArray(raw) ? raw.length : raw);

    case 'progress':
      // Angka mentahnya, sejalan dengan cells.js yang membaca actual_progress_pct.
      return numberValue(row.actual_progress_pct ?? raw);

    case 'date':
      return raw ? toDateInput(raw) : '';

    case 'datetime':
    case 'sla':
      // 'yyyy-mm-dd hh:mm' — dikenali Excel-ID dan tetap terurut sebagai teks.
      return raw ? toDateTimeInput(raw).replace('T', ' ') : '';

    case 'bool':
    case 'flag':
      return raw ? 'Ya' : 'Tidak';

    case 'enum':
      return row[`${column.key}_label`] || enumLabel(column.enum, raw) || '';

    case 'status':
      return row[`${column.key}_label`] || (raw === null || raw === undefined ? '' : String(raw));

    case 'priority':
      return row.priority_label || enumLabel('ticketPriority', raw) || '';

    case 'rel': {
      // Baris hanya membawa foreign key (semantik cells.js); labelnya hidup di
      // cache lookup. '#id' sebagai cadangan ketika cache tidak mengenalnya.
      if (raw === null || raw === undefined || raw === '') return '';
      return labelFor(column.lookup, raw) || `#${raw}`;
    }

    case 'period':
      return row.period_year && row.period_month ? periodLabel(row.period_year, row.period_month) : '';

    case 'tags':
      return Array.isArray(raw) ? raw.join(', ') : '';

    default:
      return raw === null || raw === undefined ? '' : String(raw);
  }
}

/** Kutip gaya RFC: hanya bila mengandung ; " atau baris baru; gandakan kutip dalam. */
function escapeCell(value) {
  const text = value === null || value === undefined ? '' : String(value);
  return /[";\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * Teks CSV dari satu baris judul + baris-baris nilai (nilai sudah string,
 * biasanya hasil csvValue). Pemisah ';' dan akhiran CRLF — lihat kepala berkas.
 */
export function toCsv(headers, rows) {
  return [headers, ...rows]
    .map((cells) => cells.map(escapeCell).join(';'))
    .join('\r\n') + '\r\n';
}

/**
 * Unduh teks CSV sebagai berkas — pola taxexport.js apa adanya: Blob ber-BOM
 * supaya Excel membukanya sebagai UTF-8, <a download> tersembunyi, klik, rapikan.
 */
export function downloadCsv(filename, csvText) {
  const blob = new Blob(['﻿', csvText], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = el('a', { href: url, download: filename, style: { display: 'none' } });
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
  toast(`${filename} diunduh.`);
}

/** Nama berkas: slug(label) + '_' + yyyy-mm-dd + '.csv', mis. invoice-termin-ar_2026-08-02.csv */
export function csvFilename(label) {
  const slug = String(label || 'ekspor')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'ekspor';
  return `${slug}_${today()}.csv`;
}
