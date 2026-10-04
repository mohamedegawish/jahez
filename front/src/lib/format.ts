import type { Money } from '../api/types';

const CURRENCY_LABEL: Record<string, string> = { EGP: 'ج.م' };

/**
 * Format a decimal STRING amount for display ("250000.5" -> "250,000.50"). The digits are
 * handled as text on purpose: API money is a string and must never pass through a float.
 */
export function formatAmount(amount: string | null | undefined): string {
  if (amount === null || amount === undefined || amount === '') return '-';
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(amount.trim());
  if (!match) return amount;
  const [, sign, whole, fraction = ''] = match;
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${sign}${grouped}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

/** `{ amount: "250000.00", currency: "EGP" }` -> "250,000.00 ج.م" */
export function formatMoney(money: Money | null | undefined): string {
  if (!money) return '-';
  return `${formatAmount(money.amount)} ${CURRENCY_LABEL[money.currency] ?? money.currency}`;
}

/** An amount that sits next to a separately known currency (invoice lines, totals). */
export function formatMoneyOf(amount: string | null | undefined, currency: string = 'EGP'): string {
  if (amount === null || amount === undefined) return '-';
  return `${formatAmount(amount)} ${CURRENCY_LABEL[currency] ?? currency}`;
}

const pad = (n: number) => String(n).padStart(2, '0');

/** ISO timestamp or `YYYY-MM-DD` -> `YYYY-MM-DD` (local time for timestamps). */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '-';
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** ISO timestamp -> `YYYY-MM-DD HH:mm` (local time). */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return `${formatDate(value)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Today as `YYYY-MM-DD` in local time (for `evaluated_on` / `valid_until` inputs). */
export function today(): string {
  const now = new Date();
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
