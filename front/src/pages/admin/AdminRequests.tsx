import React, { useState } from 'react';
import { ChevronDown, ChevronUp, Lock } from 'lucide-react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { useCatalogServices } from '../../hooks/useReference';
import { formatDate, formatDateTime } from '../../lib/format';
import { providerRequestStatusLabel, serviceRequestStatusLabel } from '../../lib/labels';
import { Badge } from '../../components/ui/Badge';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton, TableSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';
import { UnavailableNotice } from '../../components/ui/UnavailableNotice';

const FILTER_KEYS = ['status', 'service', 'thread_status'] as const;
const STATUS_VARIANT = { open: 'blue', awarded: 'success', cancelled: 'neutral' } as const;
const selectClass = 'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * IMC oversight of factory service requests (ADR-015). Within the approved staff visibility rule
 * (OQ-39, PROPOSED): IMC sees each request and the status of every provider thread, never the
 * negotiation messages or offer terms, which stay between the two parties.
 */
export const AdminRequests: React.FC = () => {
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const services = useCatalogServices();
  const [openId, setOpenId] = useState<number | null>(null);

  const query = useApiQuery(
    (signal) => api.serviceRequests.list({ ...toApiQuery(list), signal }),
    [list.page, list.perPage, list.search, list.sort, list.filters.status, list.filters.service, list.filters.thread_status],
  );

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">طلبات الخدمة والتفاوض</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">متابعة طلبات المصانع وحالة رد كل مزود عليها.</p>
      </div>

      <UnavailableNotice kind="decision" title="حدود اطلاع المركز" decisionNeeded="OQ-39">
        يعرض المركز الطلبات وحالات الردود فقط. رسائل التفاوض وشروط العروض وأسعارها خاصة بالمصنع والمزود ولا تظهر هنا، إلى أن يُعتمد قرار مختلف
        بشأن اطلاع موظفي الوزارة.
      </UnavailableNotice>

      <Card className="p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث بعنوان الطلب أو اسم المزود..." className="w-full lg:col-span-2" />
          <select aria-label="حالة الطلب" value={list.filters.status} onChange={(e) => list.setFilter('status', e.target.value)} className={selectClass}>
            <option value="">كل الحالات</option>
            {(['open', 'awarded', 'cancelled'] as const).map((status) => (
              <option key={status} value={status}>
                {serviceRequestStatusLabel(status)}
              </option>
            ))}
          </select>
          <select aria-label="الخدمة" value={list.filters.service} onChange={(e) => list.setFilter('service', e.target.value)} className={selectClass}>
            <option value="">كل الخدمات</option>
            {(services.data ?? []).map((service) => (
              <option key={service.code} value={service.code}>
                {service.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="الترتيب" value={list.sort} onChange={(e) => list.setSort(e.target.value)} className={selectClass}>
            <option value="">الأحدث أولًا</option>
            <option value="oldest">الأقدم أولًا</option>
          </select>
        </div>
      </Card>

      <QueryBoundary
        query={query}
        loading={<TableSkeleton rows={6} cols={5} />}
        isEmpty={(page) => page.data.length === 0}
        empty={<EmptyState title="لا توجد طلبات" description="لا توجد طلبات خدمة تطابق البحث أو الفلاتر." />}
      >
        {(page) => (
          <div className="space-y-3">
            {page.data.map((request) => (
              <div key={request.id} className="jahez-card overflow-hidden" data-service-request-id={request.id}>
                <button
                  type="button"
                  onClick={() => setOpenId(openId === request.id ? null : request.id)}
                  className="w-full p-4 flex flex-col sm:flex-row sm:items-center gap-3 text-right cursor-pointer hover:bg-[#F7F9FC]"
                >
                  <div className="flex-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-bold text-sm text-[#172033]">{request.title}</span>
                      <Badge size="sm" variant={STATUS_VARIANT[request.status] ?? 'neutral'}>
                        {serviceRequestStatusLabel(request.status)}
                      </Badge>
                    </div>
                    <div className="text-[11px] text-[#667085] mt-1">
                      {request.factory?.name ?? '—'} · {request.service?.name_ar ?? '—'} · {formatDate(request.created_at)}
                    </div>
                  </div>
                  {openId === request.id ? <ChevronUp className="w-4 h-4 text-[#667085]" /> : <ChevronDown className="w-4 h-4 text-[#667085]" />}
                </button>
                {openId === request.id && <RequestThreads id={request.id} />}
              </div>
            ))}
            <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};

const RequestThreads: React.FC<{ id: number }> = ({ id }) => {
  const detail = useApiQuery((signal) => api.serviceRequests.get(id, signal), [id]);
  return (
    <div className="border-t border-[#E6EAF0] p-4 bg-[#FBFCFE]">
      <QueryBoundary query={detail} loading={<CardSkeleton />}>
        {(request) => (
          <div className="space-y-3 text-xs">
            <p className="text-[#667085] whitespace-pre-line" dir="auto">
              <span className="font-bold text-[#172033]">الاحتياج: </span>
              {request.need}
            </p>
            <table className="w-full text-right">
              <thead>
                <tr className="text-[#98A2B3] text-[11px]">
                  <th className="py-1.5">المزود</th>
                  <th className="py-1.5">حالة الرد</th>
                  <th className="py-1.5">آخر تحديث</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#F1F4F9]">
                {(request.provider_requests ?? []).map((thread) => (
                  <tr key={thread.id}>
                    <td className="py-2 font-semibold text-[#172033]">{thread.provider?.name ?? `#${thread.id}`}</td>
                    <td className="py-2">{providerRequestStatusLabel(thread.status)}</td>
                    <td className="py-2 text-[#667085]">{formatDateTime(thread.status_changed_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="text-[10px] text-[#98A2B3] flex items-center gap-1">
              <Lock className="w-3 h-3" /> محتوى التفاوض والعروض غير متاح للمركز.
            </p>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};
