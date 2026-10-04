import React from 'react';
import { useSearchParams } from 'react-router-dom';
import { Eye } from 'lucide-react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate, formatDateTime } from '../../lib/format';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { TableSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ContractStatusBadge } from '../../components/ui/StatusBadges';
import { Tabs } from '../../components/ui/Tabs';
import { UnavailableNotice } from '../../components/ui/UnavailableNotice';
import { ReviewStatusBadge } from '../../components/marketplace/LifecycleBadge';
import { ReviewQueue } from '../../components/admin/AgreementReviewQueue';

const TABS = [
  { id: 'review', label: 'بانتظار اعتماد المركز' },
  { id: 'agreements', label: 'الاتفاقيات' },
  { id: 'contracts', label: 'مسودات العقود' },
];

/**
 * IMC oversight of agreements and contract drafts, and the review queue where IMC approves or rejects
 * each agreement before any contract draft or invoice (ADR-020). Reviewers see the agreed terms and price;
 * negotiation messages and contract training plans stay with the parties (OQ-39).
 */
export const ContractsManagement: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const tabParam = params.get('tab');
  const tab = tabParam === 'contracts' || tabParam === 'agreements' ? tabParam : 'review';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);

  const set = (changes: Record<string, string | null>) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        for (const [key, value] of Object.entries(changes)) {
          if (value === null) next.delete(key);
          else next.set(key, value);
        }
        return next;
      },
      { replace: true },
    );

  const agreements = useApiQuery((signal) => api.agreements.list({ page, per_page: 15, signal }), [page], { enabled: tab === 'agreements' });
  const contracts = useApiQuery((signal) => api.contracts.list({ page, per_page: 15, signal }), [page], { enabled: tab === 'contracts' });

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">الاتفاقيات ومسودات العقود</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">متابعة وجود الاتفاقيات والمسودات وحالتها على مستوى المنظومة.</p>
      </div>

      <UnavailableNotice kind="decision" title="حدود اطلاع المركز" decisionNeeded="OQ-39">
        يرى المراجعون شروط الاتفاقية وسعرها لاتخاذ قرار الاعتماد، ولا يطّلع المركز على رسائل التفاوض ولا على خطة التدريب في مسودات العقود.
      </UnavailableNotice>

      <Tabs tabs={TABS} activeTab={tab} onChange={(id) => set({ tab: id, page: null })} />

      {tab === 'review' ? (
        <ReviewQueue />
      ) : tab === 'agreements' ? (
        <QueryBoundary
          query={agreements}
          loading={<TableSkeleton rows={5} cols={5} />}
          isEmpty={(result) => result.data.length === 0}
          empty={<EmptyState title="لا توجد اتفاقيات" description="لم يقبل أي مصنع عرضًا حتى الآن." />}
        >
          {(result) => (
            <Card className="p-0 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-right border-collapse text-xs">
                  <thead>
                    <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
                      <th className="py-3 px-4">رقم</th>
                      <th className="py-3 px-4">الخدمة</th>
                      <th className="py-3 px-4">المصنع</th>
                      <th className="py-3 px-4">المزود</th>
                      <th className="py-3 px-4">تاريخ الإبرام</th>
                      <th className="py-3 px-4">اعتماد المركز</th>
                      <th className="py-3 px-4">مسودة العقد</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#E6EAF0]">
                    {result.data.map((agreement) => (
                      <tr key={agreement.id} data-agreement-id={agreement.id} className="hover:bg-[#F7F9FC]">
                        <td className="py-3.5 px-4 font-mono font-bold text-[#5146A5]">#{agreement.id}</td>
                        <td className="py-3.5 px-4 font-bold text-[#172033]">{agreement.service?.name_ar}</td>
                        <td className="py-3.5 px-4 text-[#667085]">{agreement.factory?.name}</td>
                        <td className="py-3.5 px-4 text-[#667085]">{agreement.provider?.name}</td>
                        <td className="py-3.5 px-4 text-[#667085]">{formatDate(agreement.concluded_at)}</td>
                        <td className="py-3.5 px-4">
                          <ReviewStatusBadge status={agreement.imc_review.status} />
                        </td>
                        <td className="py-3.5 px-4">
                          {agreement.contract ? (
                            <span className="inline-flex items-center gap-1.5">
                              <ContractStatusBadge status={agreement.contract.status} size="sm" />
                              <span className="text-[#98A2B3]">نسخة {agreement.contract.version}</span>
                            </span>
                          ) : (
                            <span className="text-[#98A2B3]">لا توجد</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="p-3 border-t border-[#E6EAF0] bg-[#F7F9FC]">
                <Pagination meta={result.meta} onPage={(n) => set({ page: String(n) })} />
              </div>
            </Card>
          )}
        </QueryBoundary>
      ) : (
        <QueryBoundary
          query={contracts}
          loading={<TableSkeleton rows={5} cols={5} />}
          isEmpty={(result) => result.data.length === 0}
          empty={<EmptyState title="لا توجد مسودات عقود" description="لم يُعدّ أي طرف مسودة عقد حتى الآن." />}
        >
          {(result) => (
            <Card className="p-0 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-right border-collapse text-xs">
                  <thead>
                    <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
                      <th className="py-3 px-4">رقم</th>
                      <th className="py-3 px-4">الاتفاقية</th>
                      <th className="py-3 px-4">النسخة</th>
                      <th className="py-3 px-4">الحالة</th>
                      <th className="py-3 px-4">الوضع القانوني</th>
                      <th className="py-3 px-4">آخر تغيير</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#E6EAF0]">
                    {result.data.map((contract) => (
                      <tr key={contract.id} data-contract-id={contract.id} className="hover:bg-[#F7F9FC]">
                        <td className="py-3.5 px-4 font-mono font-bold text-[#5146A5]">#{contract.id}</td>
                        <td className="py-3.5 px-4 text-[#667085]">اتفاقية #{contract.agreement_id}</td>
                        <td className="py-3.5 px-4 text-[#667085]">{contract.version}</td>
                        <td className="py-3.5 px-4">
                          <ContractStatusBadge status={contract.status} size="sm" />
                        </td>
                        <td className="py-3.5 px-4 text-[#667085] inline-flex items-center gap-1">
                          <Eye className="w-3.5 h-3.5" /> مسودة غير ملزمة
                        </td>
                        <td className="py-3.5 px-4 text-[#667085]">{formatDateTime(contract.status_changed_at ?? contract.created_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="p-3 border-t border-[#E6EAF0] bg-[#F7F9FC]">
                <Pagination meta={result.meta} onPage={(n) => set({ page: String(n) })} />
              </div>
            </Card>
          )}
        </QueryBoundary>
      )}
    </div>
  );
};
