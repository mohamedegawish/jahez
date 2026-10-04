import React, { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { FilePlus2, Receipt } from 'lucide-react';
import { api } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useCatalogServices } from '../../hooks/useReference';
import { toApiQuery, useListParams } from '../../hooks/useListParams';
import { formatDate, formatMoneyOf } from '../../lib/format';
import { invoiceStatusLabel } from '../../lib/labels';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Card, KPICard } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { CardSkeleton } from '../ui/LoadingState';
import { Pagination } from '../ui/Pagination';
import { QueryBoundary } from '../ui/QueryBoundary';
import { InvoiceStatusBadge } from '../ui/StatusBadges';
import { UnavailableNotice } from '../ui/UnavailableNotice';
import { BillingConfigPanel } from './BillingConfigPanel';
import { InvoiceDetail, type BillingSide } from './InvoiceDetail';

const STATUSES = ['draft', 'issued', 'paid', 'refunded', 'cancelled'] as const;

const TITLES: Record<BillingSide, { title: string; subtitle: string }> = {
  factory: { title: 'الفواتير والمدفوعات', subtitle: 'الفواتير الصادرة بحقكم وسداد قيمتها عبر بوابة الدفع المعتمدة.' },
  provider: { title: 'الفواتير والمستحقات', subtitle: 'مسودات الفواتير الخاصة باتفاقياتكم وإصدارها عندما يكون المزود هو الجهة المُصدِرة.' },
  admin: { title: 'الماليات والفواتير', subtitle: 'متابعة الفواتير والمدفوعات وحالة السياسة المالية على مستوى المنظومة.' },
};

/**
 * Invoices for any of the three roles. Every money operation is gated by an owner decision that is still
 * open (OQ-15, OQ-16): the API answers `409 policy_not_configured`, and this view shows that answer with
 * the question that decides it. Nothing here calculates, issues or marks anything paid by itself.
 */
export const BillingWorkspace: React.FC<{ mySide: BillingSide }> = ({ mySide }) => {
  const [params, setParams] = useSearchParams();
  const list = useListParams(['status', 'agreement', 'service', 'from', 'to'] as const);
  const services = useCatalogServices();
  const selectedParam = Number.parseInt(params.get('invoice') ?? '', 10) || null;

  const invoices = useApiQuery(
    (signal) => api.invoices.list({ ...toApiQuery(list), signal }),
    [list.page, list.perPage, list.filters.status, list.filters.agreement, list.filters.service, list.filters.from, list.filters.to],
  );
  const selectedId = selectedParam ?? invoices.data?.data[0]?.id ?? null;

  const select = (id: number | null) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        if (id === null) next.delete('invoice');
        else next.set('invoice', String(id));
        return next;
      },
      { replace: true },
    );

  const { title, subtitle } = TITLES[mySide];

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">{title}</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">{subtitle}</p>
      </div>

      <BillingConfigPanel />
      {mySide !== 'admin' && (
        <UnavailableNotice kind="decision" title="الاستحقاق والتأخر والعمولات" decisionNeeded="OQ-15, OQ-16">
          يُحدَّد تاريخ استحقاق كل فاتورة عند إصدارها من شروط السداد المعتمدة، وتُعرض «متأخرة» بعده؛ ولا تُرسل تذكيرات استحقاق. لا تُصدر فواتير عمولة أو رسوم للمركز،
          ولا تُعلَّم فاتورة «مدفوعة» إلا بإثبات موثق من بوابة الدفع أو بقيد دفعة يدوية معتمد ومُدقَّق.
        </UnavailableNotice>
      )}
      {mySide === 'admin' && <StatusCounts />}
      {mySide !== 'factory' && <DraftInvoicePanel mySide={mySide} defaultAgreement={list.filters.agreement} onCreated={(id) => { invoices.refetch(); select(id); }} />}

      <Card className="p-4">
        <div className="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
          <select
            aria-label="حالة الفاتورة"
            value={list.filters.status}
            onChange={(e) => list.setFilter('status', e.target.value)}
            className="py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]"
          >
            <option value="">كافة الحالات</option>
            {STATUSES.map((status) => (
              <option key={status} value={status}>
                {invoiceStatusLabel(status)}
              </option>
            ))}
          </select>
          <select
            aria-label="الخدمة"
            value={list.filters.service}
            onChange={(e) => list.setFilter('service', e.target.value)}
            className="py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF] max-w-[16rem]"
          >
            <option value="">كل الخدمات</option>
            {(services.data ?? []).map((service) => (
              <option key={service.code} value={service.code}>
                {service.name_ar}
              </option>
            ))}
          </select>
          <label className="text-xs text-[#667085] inline-flex items-center gap-1.5">
            من
            <input type="date" value={list.filters.from} onChange={(e) => list.setFilter('from', e.target.value)} className="py-1.5 px-2 text-xs border border-[#E6EAF0] rounded-lg" />
          </label>
          <label className="text-xs text-[#667085] inline-flex items-center gap-1.5">
            إلى
            <input type="date" value={list.filters.to} onChange={(e) => list.setFilter('to', e.target.value)} className="py-1.5 px-2 text-xs border border-[#E6EAF0] rounded-lg" />
          </label>
          {list.filters.agreement && <span className="text-xs text-[#667085]">اتفاقية #{list.filters.agreement}</span>}
          <Button variant="outline" size="sm" onClick={list.reset} disabled={!list.hasActiveFilters}>
            إعادة ضبط الفلاتر
          </Button>
        </div>
      </Card>

      <QueryBoundary
        query={invoices}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          list.page > 1 ? (
            <EmptyState title="هذه الصفحة فارغة" description="رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة." actionText="العودة إلى الصفحة الأولى" onAction={() => list.setPage(1)} />
          ) : (
            <EmptyState
              icon={Receipt}
              title={list.hasActiveFilters ? 'لا توجد فواتير مطابقة' : 'لا توجد فواتير بعد'}
              description={
                list.hasActiveFilters
                  ? 'جرّب تغيير الفلاتر.'
                  : 'لم تُنشأ أي فاتورة. تتوقف الفوترة على قرارات مالية لم يعتمدها المركز بعد؛ تظهر حالة كل قاعدة في اللوحة أعلاه.'
              }
              actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : undefined}
              onAction={list.hasActiveFilters ? list.reset : undefined}
            />
          )
        }
      >
        {(page) => (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <div className="lg:col-span-4 space-y-3">
              <h3 className="text-sm font-bold text-[#172033] flex items-center justify-between">
                <span>الفواتير</span>
                <span className="text-xs text-[#5146A5] bg-[#EEEAFE] px-2 py-0.5 rounded-full font-bold">{page.meta.total}</span>
              </h3>
              <div className="space-y-2">
                {page.data.map((invoice) => (
                  <div
                    key={invoice.id}
                    data-invoice-id={invoice.id}
                    onClick={() => select(invoice.id)}
                    className={`p-3.5 rounded-2xl border transition-all cursor-pointer ${
                      invoice.id === selectedId ? 'bg-white border-[#5146A5] shadow-md ring-2 ring-[#EEEAFE]' : 'bg-white border-[#E6EAF0] hover:border-[#CCD5E2]'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <h4 className="font-bold text-xs text-[#172033]">{invoice.number ?? `مسودة #${invoice.id}`}</h4>
                      <InvoiceStatusBadge status={invoice.status} size="sm" />
                    </div>
                    <p className="text-[11px] text-[#667085] mt-1 truncate">
                      {invoice.parties?.service?.name_ar}
                      {invoice.parties ? ` · ${mySide === 'factory' ? invoice.parties.provider?.name ?? '' : mySide === 'provider' ? invoice.parties.factory?.name ?? '' : `${invoice.parties.factory?.name ?? ''} / ${invoice.parties.provider?.name ?? ''}`}` : ''}
                    </p>
                    <div className="flex items-center justify-between mt-3 pt-2 border-t border-[#F1F4F9] text-[11px]">
                      <span className="font-bold text-[#35B779]">{invoice.total === null ? formatMoneyOf(invoice.subtotal, invoice.currency) : formatMoneyOf(invoice.total, invoice.currency)}</span>
                      <span className="text-[#98A2B3]">اتفاقية #{invoice.agreement_id} · {formatDate(invoice.created_at)}</span>
                    </div>
                  </div>
                ))}
              </div>
              <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
            </div>

            <div className="lg:col-span-8">
              {selectedId !== null && (
                <InvoiceDetail key={selectedId} invoiceId={selectedId} mySide={mySide} onChanged={invoices.refetch} />
              )}
            </div>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};

/** Invoice counts per status, from the API's pagination totals (IMC overview). */
const StatusCounts: React.FC = () => {
  const counts = useApiQuery(async (signal) => {
    const totals = await Promise.all(STATUSES.map((status) => api.invoices.list({ per_page: 1, filter: { status }, signal })));
    return Object.fromEntries(STATUSES.map((status, i) => [status, totals[i].meta.total])) as Record<(typeof STATUSES)[number], number>;
  }, []);

  if (counts.status === 'error') return <ApiErrorState compact error={counts.error} onRetry={counts.refetch} />;
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        {STATUSES.map((status) => (
          <KPICard key={status} title={`فواتير ${invoiceStatusLabel(status)}`} value={counts.data ? counts.data[status] : '…'} icon={Receipt} accentColor={status === 'paid' ? 'success' : status === 'issued' ? 'blue' : 'indigo'} />
        ))}
      </div>
      <UnavailableNotice kind="decision" title="الإجماليات المالية والتقارير ومؤشرات الأداء" decisionNeeded="OQ-27">
        لا يوفر الخادم نقطة تجميع للمبالغ ولا تقارير مالية؛ تُعرض هنا أعداد الفواتير فقط. تُضاف الإجماليات والرسوم بعد اعتماد تعريف التقارير.
      </UnavailableNotice>
    </div>
  );
};

/**
 * The issuer drafts an invoice for an agreement. The request is always sent: the API alone knows whether
 * an issuer, numbering and tax are configured. While they are not, it answers 409 policy_not_configured
 * and that answer (with the open question) is shown.
 */
const DraftInvoicePanel: React.FC<{ mySide: BillingSide; defaultAgreement: string; onCreated: (invoiceId: number) => void }> = ({ mySide, defaultAgreement, onCreated }) => {
  const agreements = useApiQuery((signal) => api.agreements.list({ per_page: 100, signal }), []);
  const [chosen, setChosen] = useState(defaultAgreement);
  const agreementId = Number(chosen || agreements.data?.data[0]?.id || 0);

  const draft = useApiMutation(() => api.billing.draftInvoice(agreementId));
  const handleDraft = async () => {
    const result = await draft.run();
    if (result.ok) onCreated(result.data.id);
  };

  const list = agreements.data?.data ?? [];
  return (
    <Card title="إنشاء مسودة فاتورة لاتفاقية" subtitle={mySide === 'admin' ? 'متاح للمركز عندما يكون هو الجهة المُصدِرة' : 'متاح للمزود عندما يكون هو الجهة المُصدِرة'}>
      {agreements.status === 'error' ? (
        <ApiErrorState compact error={agreements.error} onRetry={agreements.refetch} />
      ) : list.length === 0 ? (
        <p className="text-xs text-[#667085]">لا توجد اتفاقيات لإصدار فواتير لها.</p>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-col sm:flex-row gap-3">
            <select
              aria-label="الاتفاقية"
              value={chosen || String(list[0].id)}
              onChange={(e) => setChosen(e.target.value)}
              className="flex-1 py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]"
            >
              {list.map((agreement) => (
                <option key={agreement.id} value={agreement.id}>
                  #{agreement.id} — {agreement.service?.name_ar} ({agreement.factory?.name})
                </option>
              ))}
            </select>
            <Button variant="primary" size="sm" icon={FilePlus2} data-action="draft-invoice" onClick={handleDraft} isLoading={draft.pending}>
              إنشاء مسودة فاتورة
            </Button>
          </div>
          {draft.error !== null && <ApiErrorState compact error={draft.error} />}
        </div>
      )}
    </Card>
  );
};
