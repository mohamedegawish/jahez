import React from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ChevronLeft, FileText } from 'lucide-react';
import { api } from '../../api';
import type { LegalField } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { FactoryChangeQueue } from '../../components/admin/FactoryChangeQueue';
import { Button } from '../../components/ui/Button';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { Tabs } from '../../components/ui/Tabs';

const LABELS: Record<LegalField, string> = {
  legal_name: 'الاسم القانوني',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
};

/**
 * Requests to change verified legal information (ADR-019 providers, ADR-020 factories). The verified
 * value stays in force until IMC approves; approving applies the new value, rejecting keeps the old
 * one, and either way the decision, reviewer and time are recorded and the owner is notified.
 */
export const ChangeRequests: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const type = params.get('type') === 'factory' ? 'factory' : 'provider';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const navigate = useNavigate();

  const providerQueue = useApiQuery((signal) => api.providerChangeRequests.list({ page, per_page: 12, signal }), [page], { enabled: type === 'provider' });
  const counts = useApiQuery((signal) => api.reviewSummary(signal), []);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">طلبات تعديل البيانات الحساسة</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5 max-w-3xl leading-relaxed">
          تبقى القيمة المعتمدة سارية حتى قراركم. عند الاعتماد تُطبَّق القيمة الجديدة ومستنداتها، وعند الرفض تبقى البيانات كما هي ويُبلَّغ صاحب الحساب
          بالسبب. يُسجَّل المراجع والقرار ووقته في سجل التدقيق.
        </p>
      </div>

      <Tabs
        tabs={[
          { id: 'provider', label: 'مزودو الخدمات', count: counts.data?.change_requests.providers },
          { id: 'factory', label: 'المنشآت الصناعية', count: counts.data?.change_requests.factories },
        ]}
        activeTab={type}
        onChange={(id) => setParams({ type: id }, { replace: true })}
      />

      {type === 'factory' ? (
        <FactoryChangeQueue showEmpty onDecided={counts.refetch} />
      ) : (
        <QueryBoundary
          query={providerQueue}
          loading={<CardSkeleton />}
          isEmpty={(result) => result.data.length === 0}
          empty={<EmptyState title="لا توجد طلبات" description="لا توجد طلبات تعديل بيانات قانونية لمزودين بانتظار القرار." />}
        >
          {(result) => (
            <>
              <div className="grid gap-4 grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
                {result.data.map((request) => (
                  <article key={request.id} className="jahez-card p-4 flex flex-col gap-3 text-xs" data-change-request-id={request.id}>
                    <div className="flex items-start justify-between gap-2">
                      <h3 className="font-bold text-sm text-[#172033]">{request.service_provider?.name ?? `مزود #${request.service_provider_id}`}</h3>
                      <span className="text-[10px] text-[#98A2B3] shrink-0">{formatDateTime(request.created_at)}</span>
                    </div>
                    <dl className="space-y-1.5">
                      {(Object.keys(request.changes) as LegalField[]).map((field) => (
                        <div key={field}>
                          <dt className="text-[#98A2B3] text-[10px]">{LABELS[field]}</dt>
                          <dd className="text-[#172033]" dir="auto">
                            <span className="line-through text-[#98A2B3]">{request.service_provider?.[field] ?? '—'}</span> ← <strong>{request.changes[field] ?? '— حذف —'}</strong>
                          </dd>
                        </div>
                      ))}
                    </dl>
                    {(request.documents ?? []).length > 0 && (
                      <p className="text-[#667085] flex items-center gap-1">
                        <FileText className="w-3.5 h-3.5 text-[#5146A5]" /> {(request.documents ?? []).length} مستند داعم
                      </p>
                    )}
                    {request.requested_by && <p className="text-[10px] text-[#98A2B3]">مقدّم الطلب: {request.requested_by.name}</p>}
                    <Button
                      variant="secondary"
                      size="sm"
                      icon={ChevronLeft}
                      className="mt-auto"
                      onClick={() => navigate(`/admin/approvals/providers/${request.service_provider_id}`)}
                    >
                      مراجعة الطلب والمستندات
                    </Button>
                  </article>
                ))}
              </div>
              <Pagination meta={result.meta} onPage={(n) => setParams({ type, page: String(n) }, { replace: true })} />
            </>
          )}
        </QueryBoundary>
      )}
    </div>
  );
};
