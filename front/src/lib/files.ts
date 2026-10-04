import type { DocumentType } from '../api/types';

export const documentTypeLabel: Record<DocumentType, string> = {
  logo: 'الشعار',
  commercial_registration: 'مستند السجل التجاري',
  tax_registration: 'مستند التسجيل الضريبي',
};

/** Technical upload limits mirrored from the API (jahez.documents); the server checks them again. */
export const LOGO_ACCEPT = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';
export const LEGAL_ACCEPT = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';
export const LOGO_MAX_KB = 2048;
export const LEGAL_MAX_KB = 5120;

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * A quick check before uploading, so an obviously wrong file is reported without a round trip.
 * The API checks the content type and size itself and stays the authority.
 */
export function localFileProblem(file: File, accept: string, maxKb: number): string | null {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
  const allowed = accept.split(',').filter((entry) => entry.startsWith('.')).map((entry) => entry.slice(1));
  if (!allowed.includes(extension)) return `نوع الملف غير مسموح. الأنواع المسموح بها: ${allowed.join('، ')}.`;
  if (file.size > maxKb * 1024) return `حجم الملف أكبر من الحد المسموح (${formatBytes(maxKb * 1024)}).`;
  return null;
}

/** Save a private file the API returned, under its original name. */
export function saveBlob(blob: Blob, fileName: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** A fresh key for one submission attempt (Idempotency-Key: 8–100 of A-Za-z0-9_-). */
export function newIdempotencyKey(prefix: string): string {
  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID().replace(/-/g, '')
    : `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
  return `${prefix}-${random}`;
}
