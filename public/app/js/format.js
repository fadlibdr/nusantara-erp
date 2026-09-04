/* Display formatting — Indonesian locale throughout (id-ID, IDR, WIB). */

import { enumTone } from './enums.js';

const money0 = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const money2 = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qtyFmt = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
const pctFmt = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

export const MONTHS = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

export const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

export function num(value, decimals = 0) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return decimals === 2 ? money2.format(n) : money0.format(n);
}

/** Rupiah. Amounts are stored as decimal strings, so coerce before formatting. */
export function rupiah(value, { decimals = 0, blank = '—' } = {}) {
  if (value === null || value === undefined || value === '') return blank;
  const n = Number(value);
  if (!Number.isFinite(n)) return blank;
  // `+ 0` collapses -0 so a zero balance never prints as "Rp -0".
  return `Rp ${decimals === 2 ? money2.format(n + 0) : money0.format(Math.round(n) + 0)}`;
}

/** Compact rupiah for dashboard tiles: Rp 48,5 M / Rp 232,5 jt. */
export function rupiahShort(value) {
  const n = Number(value);
  if (!Number.isFinite(n) || n === 0) return 'Rp 0';
  const abs = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  if (abs >= 1e12) return `${sign}Rp ${pctFmt.format(abs / 1e12)} T`;
  if (abs >= 1e9) return `${sign}Rp ${pctFmt.format(abs / 1e9)} M`;
  if (abs >= 1e6) return `${sign}Rp ${pctFmt.format(abs / 1e6)} jt`;
  if (abs >= 1e3) return `${sign}Rp ${pctFmt.format(abs / 1e3)} rb`;
  return `${sign}Rp ${money0.format(abs)}`;
}

export function qty(value, unit) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return unit ? `${qtyFmt.format(n)} ${unit}` : qtyFmt.format(n);
}

export function percent(value, { decimals = 2 } = {}) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: decimals }).format(n)}%`;
}

function parseDate(value) {
  if (!value) return null;
  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function date(value) {
  const d = parseDate(value);
  if (!d) return '—';
  return `${String(d.getDate()).padStart(2, '0')} ${MONTHS_SHORT[d.getMonth()]} ${d.getFullYear()}`;
}

export function dateLong(value) {
  const d = parseDate(value);
  if (!d) return '—';
  return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}

export function dateTime(value) {
  const d = parseDate(value);
  if (!d) return '—';
  return `${date(d)} ${String(d.getHours()).padStart(2, '0')}.${String(d.getMinutes()).padStart(2, '0')}`;
}

/** "3 hari lagi" / "2 hari lalu" / "hari ini", relative to today. */
export function relativeDays(value) {
  const d = parseDate(value);
  if (!d) return '';
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const target = new Date(d);
  target.setHours(0, 0, 0, 0);
  const days = Math.round((target - today) / 86400000);
  if (days === 0) return 'hari ini';
  if (days === 1) return 'besok';
  if (days === -1) return 'kemarin';
  return days > 0 ? `${days} hari lagi` : `${Math.abs(days)} hari lalu`;
}

/** yyyy-mm-dd for <input type=date>. */
export function toDateInput(value) {
  const d = parseDate(value);
  if (!d) return '';
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function toDateTimeInput(value) {
  const d = parseDate(value);
  if (!d) return '';
  return `${toDateInput(d)}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function today() {
  return toDateInput(new Date());
}

export function periodLabel(year, month) {
  if (!year || !month) return '—';
  return `${MONTHS[Number(month) - 1]} ${year}`;
}

export function initials(name) {
  return String(name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join('');
}

/**
 * Status value -> badge colour. Shared by every module's lifecycle enums.
 *
 * Peta kata di bawah tidak tahu modul: 'open' hijau karena tiket layanan yang
 * baru dibuka memang keadaan normal. Kata yang sama pada NCR, insiden K3 dan
 * defect berarti kebalikannya — pekerjaan yang menahan BAST — dan lencananya
 * ikut hijau (diukur 4 Sep 2026: NCR/2026/IX/0002, K3/2026/IX/003 dan
 * DEF/2026/IX/0001 semua "Terbuka → green" di kepala halaman detail). Maka
 * pemanggil yang tahu enumnya menyebut namanya; enum yang menetapkan tone-nya
 * sendiri (enums.js) menang atas peta kata, termasuk '' untuk netral.
 */
export function statusTone(value, enumName) {
  if (enumName) {
    const own = enumTone(enumName, String(value));
    if (own !== undefined) return own;
  }

  switch (String(value)) {
    case 'approved':
    case 'active':
    case 'posted':
    case 'received':
    case 'won':
    case 'resolved':
    case 'acknowledged':
    case 'open':
      return 'green';
    case 'rejected':
    case 'cancelled':
    // Pembayaran yang dibalik: uangnya sudah dikembalikan lewat jurnal
    // pembalik, jadi lencananya harus terbaca sekeras 'dibatalkan'.
    case 'reversed':
    case 'lost':
    case 'terminated':
    case 'inactive':
    case 'resigned':
    case 'disposed':
    case 'claimed':
      return 'red';
    case 'submitted':
    case 'in_transit':
    case 'pending_customer':
    case 'on_hold':
    case 'maintenance':
    case 'expired':
      return 'amber';
    case 'in_progress':
    case 'assigned':
    case 'deployed':
    case 'warranty':
    case 'finishing':
    case 'qualified':
    case 'proposal':
      return 'blue';
    default:
      return '';
  }
}
