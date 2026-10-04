import type { Invoice } from '../api';
import { formatDate, formatDateTime, formatMoneyOf } from './format';
import { invoiceIssuerLabel, invoiceStatusLabel } from './labels';

const escape = (value: string | number | null | undefined): string =>
  String(value ?? '—').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch] as string);

/**
 * A printable copy of an invoice exactly as the API returned it, opened in a new window for the
 * browser's print dialog (save as PDF). No server document generation exists; nothing here is computed
 * beyond the formatting of the stored amounts. A draft is marked as a draft.
 */
export function printInvoice(invoice: Invoice): boolean {
  const win = window.open('', '_blank', 'noopener=no,width=820,height=1000');
  if (!win) return false;

  const rows = invoice.lines
    .map(
      (line) =>
        `<tr><td>${escape(line.position)}</td><td>${escape(line.description)}</td><td>${escape(line.quantity)}</td><td>${escape(formatMoneyOf(line.unit_amount, invoice.currency))}</td><td>${escape(formatMoneyOf(line.line_amount, invoice.currency))}</td></tr>`,
    )
    .join('');

  win.document.write(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${escape(invoice.number ?? `مسودة فاتورة ${invoice.id}`)}</title>
<style>
body{font-family:system-ui,'Segoe UI',Tahoma,sans-serif;color:#172033;margin:32px;font-size:13px}
h1{font-size:20px;margin:0 0 4px}.muted{color:#667085}.draft{display:inline-block;padding:2px 8px;border:1px solid #A66F0B;color:#A66F0B;border-radius:6px;font-weight:700}
table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border:1px solid #E6EAF0;padding:8px;text-align:right}th{background:#F7F9FC}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;margin-top:16px}.total{font-weight:800;font-size:15px}
footer{margin-top:24px;font-size:11px;color:#667085}
</style></head><body>
<h1>${invoice.number ? `فاتورة رقم ${escape(invoice.number)}` : `مسودة فاتورة #${escape(invoice.id)}`}</h1>
<div class="muted">منصة جاهز · الحالة: ${escape(invoiceStatusLabel(invoice.status))} ${invoice.status === 'draft' ? '<span class="draft">مسودة غير صادرة</span>' : ''}</div>
<div class="grid">
<div><strong>المصنع:</strong> ${escape(invoice.parties?.factory?.name)}</div>
<div><strong>المزود:</strong> ${escape(invoice.parties?.provider?.name)}</div>
<div><strong>الخدمة:</strong> ${escape(invoice.parties?.service?.name_ar)}</div>
<div><strong>الاتفاقية:</strong> #${escape(invoice.agreement_id)}</div>
<div><strong>الجهة المُصدِرة:</strong> ${escape(invoiceIssuerLabel(invoice.issuer))}</div>
<div><strong>تاريخ الإصدار:</strong> ${escape(invoice.issued_at ? formatDateTime(invoice.issued_at) : null)}</div>
<div><strong>تاريخ الاستحقاق:</strong> ${escape(invoice.due_date ? formatDate(invoice.due_date) : 'يُحدَّد عند الإصدار')}</div>
<div><strong>تاريخ السداد:</strong> ${escape(invoice.paid_at ? formatDateTime(invoice.paid_at) : null)}</div>
</div>
<table><thead><tr><th>#</th><th>البند</th><th>الكمية</th><th>سعر الوحدة</th><th>القيمة</th></tr></thead><tbody>${rows}</tbody></table>
<div class="grid">
<div>المجموع الفرعي: ${escape(formatMoneyOf(invoice.subtotal, invoice.currency))}</div>
${invoice.fees !== null && Number(invoice.fees) > 0 ? `<div>الرسوم: ${escape(formatMoneyOf(invoice.fees, invoice.currency))}</div>` : ''}
<div>الضريبة: ${invoice.tax ? `${escape(invoice.tax.rate_percent)}% — ${escape(formatMoneyOf(invoice.tax.amount, invoice.currency))}` : 'تُحتسب عند الإصدار'}</div>
<div class="total">الإجمالي: ${escape(invoice.total === null ? 'يُحدَّد عند الإصدار' : formatMoneyOf(invoice.total, invoice.currency))}</div>
</div>
<footer>نسخة مطبوعة من بيانات المنصة كما هي. لا تُضاف إليها رسوم أو عمولات غير مسجلة في الفاتورة.${invoice.policy_basis === 'legacy' ? ' سجل سابق لإدارة السياسات المالية.' : ''} النسخة المطبوعة ليست مستندًا ضريبيًا معتمدًا ما لم يُعتمد ذلك.</footer>
<script>window.onload=function(){window.print();}</script>
</body></html>`);
  win.document.close();
  return true;
}
