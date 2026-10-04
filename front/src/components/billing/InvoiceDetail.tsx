import React, { useRef, useState } from 'react';
import { CheckCircle2, CreditCard, ExternalLink, Plus, Printer, RefreshCw, Trash2 } from 'lucide-react';
import { api, fieldMessages, isNetworkError } from '../../api';
import type { Invoice, Payment } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatAmount, formatDate, formatDateTime, formatMoneyOf } from '../../lib/format';
import { businessToday } from '../../lib/financialPolicies';
import { fieldErrorClass } from '../../lib/forms';
import { decisionLabel, invoiceIssuerLabel } from '../../lib/labels';
import { printInvoice } from '../../lib/printInvoice';
import { safeExternalUrl } from '../../lib/url';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { ConfirmModal } from '../ui/ConfirmModal';
import { FieldError } from '../ui/FieldError';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReasonModal } from '../ui/ReasonModal';
import { InvoiceStatusBadge, PaymentStatusBadge } from '../ui/StatusBadges';

export type BillingSide = 'factory' | 'provider' | 'admin';

/** A line's unit amount is a decimal string with at most two places, exactly as the API stores it. */
const AMOUNT = /^\d{1,12}(\.\d{1,2})?$/;

const newKey = () => `web-${crypto.randomUUID()}`;

interface InvoiceDetailProps {
  invoiceId: number;
  mySide: BillingSide;
  onChanged: () => void;
}

/**
 * One invoice. Who may act is decided by the API (the configured issuer edits, issues and cancels;
 * the factory pays). The UI offers the actions that match the viewer and the invoice's status, and
 * every refusal (403, 409, 409 policy_not_configured, 422, 429) is shown rather than hidden.
 */
export const InvoiceDetail: React.FC<InvoiceDetailProps> = ({ invoiceId, mySide, onChanged }) => {
  const { hasPermission } = useAuth();
  const invoice = useApiQuery((signal) => api.invoices.get(invoiceId, signal), [invoiceId]);
  const payments = useApiQuery((signal) => api.invoices.payments.list(invoiceId, signal), [invoiceId]);
  const [dialog, setDialog] = useState<'issue' | 'cancel' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const reload = () => {
    invoice.refetch();
    payments.refetch();
    onChanged();
  };

  return (
    <QueryBoundary query={invoice} loading={<CardSkeleton />}>
      {(inv) => {
        const isIssuer =
          (mySide === 'provider' && inv.issuer === 'service_provider') || (mySide === 'admin' && inv.issuer === 'imc' && hasPermission('invoices.manage'));
        const canEdit = isIssuer && inv.status === 'draft';
        const payable = inv.status === 'issued' || inv.status === 'partially_paid';
        const canPay = mySide === 'factory' && payable;
        const canRecord = mySide === 'admin' && payable && hasPermission('payments.record');

        return (
          <div className="space-y-5" data-testid="invoice-detail">
            {notice && (
              <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center justify-between">
                <span className="flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4" />
                  {notice}
                </span>
                <button onClick={() => setNotice(null)} className="hover:underline cursor-pointer">إغلاق</button>
              </div>
            )}

            <Card
              title={inv.number ? `فاتورة ${inv.number}` : `مسودة فاتورة #${inv.id}`}
              subtitle={`اتفاقية #${inv.agreement_id} · الجهة المُصدِرة: ${invoiceIssuerLabel(inv.issuer)} · أُنشئت ${formatDateTime(inv.created_at)}`}
              action={
                <div className="flex items-center gap-2">
                  {inv.policy_basis === 'legacy' && <Badge size="sm" variant="neutral">سجل سابق — بلا إصدار سياسة</Badge>}
                  {inv.is_overdue && <Badge size="sm" variant="error">متأخرة السداد</Badge>}
                  <InvoiceStatusBadge status={inv.status} />
                  <Button variant="outline" size="sm" icon={Printer} onClick={() => { if (!printInvoice(inv)) setNotice('سمحوا للمتصفح بفتح نافذة جديدة لطباعة الفاتورة.'); }}>
                    طباعة / PDF
                  </Button>
                </div>
              }
              accent="purple"
            >
              <dl className="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-2 text-xs p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                <Row label="المصنع" value={inv.parties?.factory?.name ?? '—'} />
                <Row label="المزود" value={inv.parties?.provider?.name ?? '—'} />
                <Row label="الخدمة" value={inv.parties?.service?.name_ar ?? '—'} />
                <Row label="نوع الفاتورة" value="فاتورة خدمة متفق عليها" />
                <Row label="تاريخ الاستحقاق" value={inv.due_date ? formatDate(inv.due_date) : inv.status === 'draft' ? 'يُحدَّد عند الإصدار من شروط السداد المعتمدة' : 'غير محدد'} />
                <Row label="الجهة الملزَمة بالسداد" value={inv.payer === 'factory' ? 'المنشأة الصناعية' : '—'} />
                <Row label="مرجع الدفع" value="يظهر في سجل المدفوعات أدناه" />
              </dl>
              <LinesTable invoice={inv} canEdit={canEdit} onChanged={() => { setNotice(null); reload(); }} />

              <dl className="mt-4 pt-4 border-t border-[#E6EAF0] grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-xs">
                <Row label="المجموع الفرعي" value={formatMoneyOf(inv.subtotal, inv.currency)} />
                {inv.fees !== null && Number(inv.fees) > 0 && <Row label="الرسوم" value={formatMoneyOf(inv.fees, inv.currency)} />}
                <Row label="الضريبة" value={inv.tax ? `${inv.tax.rate_percent}% — ${formatMoneyOf(inv.tax.amount, inv.currency)}` : 'تُحتسب عند الإصدار'} />
                <Row label="الإجمالي" value={inv.total === null ? 'يُحدَّد عند الإصدار' : formatMoneyOf(inv.total, inv.currency)} strong testId="invoice-total" />
                <Row
                  label="اقتسام الإيراد"
                  value={
                    inv.revenue_share.status === 'calculated'
                      ? `${inv.revenue_share.rate_percent}% — ${formatMoneyOf(inv.revenue_share.amount, inv.currency)} (للاطلاع فقط)`
                      : `غير محسوب — بانتظار قرار: ${decisionLabel(inv.revenue_share.decision_needed)}`
                  }
                />
                {inv.total !== null && <Row label="المدفوع" value={formatMoneyOf(inv.amount_paid, inv.currency)} />}
                {inv.outstanding !== null && <Row label="المتبقي" value={formatMoneyOf(inv.outstanding, inv.currency)} />}
                <Row label="تاريخ الإصدار" value={inv.issued_at ? formatDateTime(inv.issued_at) : '—'} />
                <Row label="تاريخ السداد" value={inv.paid_at ? formatDateTime(inv.paid_at) : '—'} />
              </dl>
              {inv.calculation && <CalculationBreakdown invoice={inv} />}
              {inv.status_reason && (
                <p className="mt-3 p-2.5 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-xs text-[#667085]" dir="auto">السبب: {inv.status_reason}</p>
              )}

              {isIssuer && inv.status === 'draft' && (
                <div className="mt-4 pt-4 border-t border-[#E6EAF0] flex flex-wrap items-center gap-2">
                  <Button variant="primary" size="sm" data-action="issue-invoice" onClick={() => setDialog('issue')}>
                    إصدار الفاتورة
                  </Button>
                  <Button variant="outline" size="sm" data-action="cancel-invoice" onClick={() => setDialog('cancel')}>
                    إلغاء المسودة
                  </Button>
                </div>
              )}
            </Card>

            <PaymentsCard
              invoice={inv}
              payments={payments}
              canPay={canPay}
              canRecord={canRecord}
              onRecorded={() => {
                setNotice('سُجّلت الدفعة اليدوية.');
                reload();
              }}
              onStarted={() => {
                setNotice('بدأت عملية الدفع. أكمل الدفع من صفحة البوابة، ثم حدّث الحالة.');
                reload();
              }}
            />

            {dialog === 'issue' && (
              <ConfirmModal
                title="إصدار الفاتورة"
                subtitle={`مسودة #${inv.id}`}
                description="بعد الإصدار تُرقَّم الفاتورة وتُحتسب الضرائب والرسوم وتاريخ الاستحقاق على الخادم من السياسات المعتمدة السارية اليوم، ويُحفظ إصدار كل سياسة مع الفاتورة ولا يُعاد حسابها لاحقًا، ويُقفَل تعديلها. إن نقصت سياسة معتمدة يرفض الخادم الإصدار ويذكر السبب."
                confirmLabel="تأكيد الإصدار"
                onConfirm={() => api.invoices.issue(inv.id)}
                onDone={() => {
                  setDialog(null);
                  setNotice('تم إصدار الفاتورة.');
                  reload();
                }}
                onClose={() => setDialog(null)}
              />
            )}
            {dialog === 'cancel' && (
              <ReasonModal
                title="إلغاء مسودة الفاتورة"
                subtitle={`مسودة #${inv.id}`}
                description="يمكن إلغاء المسودات فقط؛ لا تُلغى الفاتورة بعد إصدارها لأن الإشعارات الدائنة لم تُعتمد بعد."
                confirmLabel="تأكيد الإلغاء"
                variant="danger"
                onConfirm={(reason) => api.invoices.cancel(inv.id, reason || null)}
                onDone={() => {
                  setDialog(null);
                  setNotice('تم إلغاء المسودة.');
                  reload();
                }}
                onClose={() => setDialog(null)}
              />
            )}
          </div>
        );
      }}
    </QueryBoundary>
  );
};

const Row: React.FC<{ label: string; value: string; strong?: boolean; testId?: string }> = ({ label, value, strong, testId }) => (
  <div className="flex items-center justify-between gap-3">
    <dt className="text-[#667085]">{label}</dt>
    <dd className={strong ? 'font-extrabold text-[#35B779] text-sm' : 'font-semibold text-[#172033]'} data-testid={testId}>{value}</dd>
  </div>
);

/* ───────────────────────── Lines ───────────────────────── */

const LinesTable: React.FC<{ invoice: Invoice; canEdit: boolean; onChanged: () => void }> = ({ invoice, canEdit, onChanged }) => {
  const [description, setDescription] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [unit, setUnit] = useState('');
  const add = useApiMutation(() => api.invoices.addLine(invoice.id, { description: description.trim(), quantity: Number(quantity), unit_amount: unit.trim() }));
  const remove = useApiMutation((lineId: number) => api.invoices.removeLine(invoice.id, lineId));

  const handleAdd = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await add.run();
    if (result.ok) {
      setDescription('');
      setUnit('');
      setQuantity('1');
      onChanged();
    }
  };

  const descErrors = fieldMessages(add.error, 'description');
  const qtyErrors = fieldMessages(add.error, 'quantity');
  const unitErrors = fieldMessages(add.error, 'unit_amount');
  const hasFieldErrors = descErrors.length + qtyErrors.length + unitErrors.length > 0;

  return (
    <div className="space-y-3">
      <div className="overflow-x-auto">
        <table className="w-full text-right border-collapse text-xs">
          <thead>
            <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
              <th className="py-2.5 px-3">#</th>
              <th className="py-2.5 px-3">البند</th>
              <th className="py-2.5 px-3">الكمية</th>
              <th className="py-2.5 px-3">سعر الوحدة</th>
              <th className="py-2.5 px-3">المبلغ</th>
              {canEdit && <th className="py-2.5 px-3" />}
            </tr>
          </thead>
          <tbody className="divide-y divide-[#F1F4F9]">
            {invoice.lines.map((line) => (
              <tr key={line.id} data-line-id={line.id}>
                <td className="py-2.5 px-3 text-[#98A2B3]">{line.position}</td>
                <td className="py-2.5 px-3 font-semibold text-[#172033]" dir="auto">{line.description}</td>
                <td className="py-2.5 px-3 text-[#667085]">{line.quantity}</td>
                <td className="py-2.5 px-3 text-[#667085]">{formatAmount(line.unit_amount)}</td>
                <td className="py-2.5 px-3 font-bold text-[#172033]">{formatAmount(line.line_amount)}</td>
                {canEdit && (
                  <td className="py-2.5 px-3 text-left">
                    <button
                      type="button"
                      aria-label="حذف البند"
                      data-action="remove-line"
                      onClick={async () => {
                        const result = await remove.run(line.id);
                        if (result.ok) onChanged();
                      }}
                      className="p-1.5 rounded-lg text-[#B82B3B] hover:bg-[#FDECEE] cursor-pointer"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </td>
                )}
              </tr>
            ))}
            {invoice.lines.length === 0 && (
              <tr>
                <td colSpan={canEdit ? 6 : 5} className="py-4 px-3 text-center text-[#98A2B3]">لا توجد بنود.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      {remove.error !== null && <ApiErrorState compact error={remove.error} />}

      {canEdit && (
        <form onSubmit={handleAdd} className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] space-y-2 text-xs" noValidate data-testid="line-form">
          <div className="grid grid-cols-1 sm:grid-cols-6 gap-2">
            <div className="sm:col-span-3">
              <input aria-label="وصف البند" placeholder="وصف البند" value={description} maxLength={500} onChange={(e) => setDescription(e.target.value)} className={`w-full p-2 rounded-lg border border-[#E6EAF0] bg-white ${fieldErrorClass(descErrors.length > 0)}`} />
              <FieldError messages={descErrors} />
            </div>
            <div>
              <input aria-label="الكمية" type="number" min={1} value={quantity} onChange={(e) => setQuantity(e.target.value)} className={`w-full p-2 rounded-lg border border-[#E6EAF0] bg-white ${fieldErrorClass(qtyErrors.length > 0)}`} dir="ltr" />
              <FieldError messages={qtyErrors} />
            </div>
            <div>
              <input aria-label="سعر الوحدة" placeholder="0.00" inputMode="decimal" value={unit} onChange={(e) => setUnit(e.target.value)} className={`w-full p-2 rounded-lg border border-[#E6EAF0] bg-white ${fieldErrorClass(unitErrors.length > 0 || (unit !== '' && !AMOUNT.test(unit.trim())))}`} dir="ltr" />
              <FieldError messages={unitErrors} />
            </div>
            <Button type="submit" variant="secondary" size="sm" icon={Plus} isLoading={add.pending} disabled={description.trim() === '' || !AMOUNT.test(unit.trim()) || Number(quantity) < 1}>
              إضافة
            </Button>
          </div>
          {add.error !== null && !hasFieldErrors && <ApiErrorState compact error={add.error} />}
        </form>
      )}
    </div>
  );
};

/* ───────────────────────── Payments ───────────────────────── */

const PaymentsCard: React.FC<{
  invoice: Invoice;
  payments: ReturnType<typeof useApiQuery<Payment[]>>;
  canPay: boolean;
  canRecord: boolean;
  onStarted: () => void;
  onRecorded: () => void;
}> = ({ invoice, payments, canPay, canRecord, onStarted, onRecorded }) => {
  // One Idempotency-Key per payment ATTEMPT: kept when the answer was lost (network), so a retry returns
  // the same payment instead of starting another; replaced after any definitive answer.
  const keyRef = useRef<string | null>(null);
  const start = useApiMutation(async () => {
    keyRef.current ??= newKey();
    try {
      const payment = await api.invoices.payments.start(invoice.id, keyRef.current);
      keyRef.current = null;
      return payment;
    } catch (error) {
      if (!isNetworkError(error)) keyRef.current = null;
      throw error;
    }
  });

  const handleStart = async () => {
    const result = await start.run();
    if (result.ok) onStarted();
  };

  const list = payments.data ?? [];

  return (
    <Card
      title="المدفوعات"
      subtitle="تصبح الدفعة «ناجحة» فقط بإثبات موثّق من بوابة الدفع، ولا يغيّرها المتصفح"
      accent="blue"
      action={
        <Button variant="ghost" size="sm" icon={RefreshCw} onClick={payments.refetch}>
          تحديث الحالة
        </Button>
      }
    >
      {canPay && (
        <div className="mb-4 space-y-2">
          <Button variant="primary" size="md" icon={CreditCard} data-action="pay-invoice" onClick={handleStart} isLoading={start.pending}>
            دفع الفاتورة ({formatMoneyOf(invoice.outstanding ?? invoice.total, invoice.currency)})
          </Button>
          {start.error !== null && <ApiErrorState compact error={start.error} />}
        </div>
      )}
      {!canPay && (invoice.status === 'issued' || invoice.status === 'partially_paid') && <p className="mb-3 text-[11px] text-[#98A2B3]">الدفع من صلاحيات المصنع المخاطَب بالفاتورة.</p>}
      {canRecord && <ManualPaymentForm invoice={invoice} onRecorded={onRecorded} />}

      <QueryBoundary
        query={payments}
        loading={<CardSkeleton />}
        isEmpty={() => list.length === 0}
        empty={<p className="text-xs text-[#667085] text-center py-4">لا توجد دفعات لهذه الفاتورة.</p>}
      >
        {() => (
          <ul className="space-y-2.5" data-testid="payments">
            {list.map((payment) => (
              <li key={payment.id} data-payment-id={payment.id} className="p-3 rounded-xl border border-[#E6EAF0] text-xs space-y-1.5">
                <div className="flex items-center justify-between">
                  <span className="font-bold text-[#172033]">{formatMoneyOf(payment.amount, payment.currency)}</span>
                  <PaymentStatusBadge status={payment.status} size="sm" />
                </div>
                <div className="text-[11px] text-[#98A2B3]">
                  {payment.method === 'manual' ? `دفعة يدوية موثقة · مرجع ${payment.gateway_reference ?? '—'} · استُلمت ${formatDate(payment.received_on)}` : payment.gateway} · {formatDateTime(payment.created_at)}
                  {payment.failure_reason && <span dir="auto"> · {payment.failure_reason}</span>}
                </div>
                {payment.status === 'pending' && safeExternalUrl(payment.checkout_url) && (
                  <a href={safeExternalUrl(payment.checkout_url) ?? undefined} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-bold text-[#0A6EB0] hover:underline">
                    إكمال الدفع في صفحة البوابة <ExternalLink className="w-3 h-3" />
                  </a>
                )}
              </li>
            ))}
          </ul>
        )}
      </QueryBoundary>
    </Card>
  );
};

/* ───────────────────────── Calculation (ADR-023) ───────────────────────── */

const POLICY_NAMES: Record<string, string> = { invoicing: 'الفوترة', tax: 'الضرائب والرسوم', payment_terms: 'شروط السداد', revenue_share: 'حصة الوزارة' };

/** The server's calculation frozen at issue, with the policy versions it used. */
const CalculationBreakdown: React.FC<{ invoice: Invoice }> = ({ invoice }) => {
  const c = invoice.calculation;
  if (!c) return null;
  return (
    <div className="mt-4 p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-xs space-y-2" data-testid="invoice-calculation">
      <h4 className="font-bold text-[#172033]">تفاصيل الاحتساب (محسوبة على الخادم عند الإصدار)</h4>
      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
        <Row label={c.prices_include_tax ? 'صافي الخدمة (الأسعار شاملة الضريبة)' : 'صافي الخدمة'} value={formatMoneyOf(c.net_service_amount, invoice.currency)} />
        {c.fees.map((fee) => <Row key={fee.code} label={`رسم: ${fee.name_ar}${fee.taxable ? ' (خاضع للضريبة)' : ''}`} value={formatMoneyOf(fee.amount, invoice.currency)} />)}
        {c.taxes.map((tax) => <Row key={tax.code} label={`${tax.name_ar} ${tax.rate_percent}% على ${formatMoneyOf(tax.base, invoice.currency)}`} value={formatMoneyOf(tax.amount, invoice.currency)} />)}
        {c.taxes.length === 0 && <Row label="الضرائب" value="لا توجد ضرائب وفق السياسة المعتمدة" />}
        <Row label="الإجمالي" value={formatMoneyOf(c.total, invoice.currency)} strong />
      </dl>
      <p className="text-[11px] text-[#667085]">
        السياسات المطبقة:{' '}
        {Object.entries(invoice.policy_versions)
          .map(([kind, ref]) => `${POLICY_NAMES[kind] ?? kind}: ${ref && 'version' in ref && ref.version ? `الإصدار ${ref.version}` : 'لا ينطبق'}`)
          .join(' · ')}
      </p>
    </div>
  );
};

/* ───────────────────────── Manual payment entry (ADR-023) ───────────────────────── */

/**
 * An authorised administrator records money received outside the platform. The server checks the
 * amount against what is outstanding and the payment terms, and refuses a duplicate reference.
 */
const ManualPaymentForm: React.FC<{ invoice: Invoice; onRecorded: () => void }> = ({ invoice, onRecorded }) => {
  const [amount, setAmount] = useState(invoice.outstanding ?? '');
  const [receivedOn, setReceivedOn] = useState(businessToday());
  const [reference, setReference] = useState('');
  const [note, setNote] = useState('');
  // One Idempotency-Key per entry attempt, kept only when the answer was lost.
  const keyRef = useRef<string | null>(null);
  const record = useApiMutation(async () => {
    keyRef.current ??= newKey();
    try {
      const payment = await api.invoices.payments.recordManual(
        invoice.id,
        { amount: amount.trim(), received_on: receivedOn, reference: reference.trim(), evidence_note: note.trim() || null },
        keyRef.current,
      );
      keyRef.current = null;
      return payment;
    } catch (error) {
      if (!isNetworkError(error)) keyRef.current = null;
      throw error;
    }
  });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await record.run();
    if (result.ok) onRecorded();
  };

  const amountErrors = fieldMessages(record.error, 'amount');
  const dateErrors = fieldMessages(record.error, 'received_on');
  const invalid = !AMOUNT.test(amount.trim()) || reference.trim() === '' || receivedOn === '';
  const input = 'w-full p-2 rounded-lg border border-[#E6EAF0] bg-white';

  return (
    <form onSubmit={submit} noValidate className="mb-4 p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] space-y-2 text-xs" data-testid="manual-payment-form">
      <h4 className="font-bold text-[#172033]">تسجيل دفعة مستلمة خارج المنصة</h4>
      <p className="text-[11px] text-[#667085]">
        يُسجَّل في سجل التدقيق باسمك. لا تُسجَّل الفاتورة مدفوعة إلا بدفعة موثقة؛ ويرفض الخادم أي مبلغ يتجاوز المتبقي أو سدادًا جزئيًا لا تسمح به شروط السداد.
      </p>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
        <label className="space-y-1">
          <span className="text-[#667085]">المبلغ</span>
          <input aria-label="المبلغ" value={amount} onChange={(e) => setAmount(e.target.value)} dir="ltr" inputMode="decimal" className={`${input} ${fieldErrorClass(amountErrors.length > 0)}`} />
          <FieldError messages={amountErrors} />
        </label>
        <label className="space-y-1">
          <span className="text-[#667085]">تاريخ الاستلام</span>
          <input aria-label="تاريخ الاستلام" type="date" max={businessToday()} value={receivedOn} onChange={(e) => setReceivedOn(e.target.value)} dir="ltr" className={input} />
          <FieldError messages={dateErrors} />
        </label>
        <label className="space-y-1">
          <span className="text-[#667085]">مرجع التحويل</span>
          <input aria-label="مرجع التحويل" value={reference} onChange={(e) => setReference(e.target.value)} dir="ltr" maxLength={100} className={input} />
          <FieldError messages={fieldMessages(record.error, 'reference')} />
        </label>
      </div>
      <textarea aria-label="ملاحظة الإثبات" value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={2000} placeholder="ملاحظة الإثبات (اختيارية)" className={input} />
      <Button type="submit" variant="primary" size="sm" isLoading={record.pending} disabled={invalid} data-action="record-manual-payment">
        تسجيل الدفعة
      </Button>
      {record.error !== null && amountErrors.length + dateErrors.length === 0 && <ApiErrorState compact error={record.error} />}
    </form>
  );
};
