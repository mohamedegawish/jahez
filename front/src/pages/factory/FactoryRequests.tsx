import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, MessageSquare, Send } from 'lucide-react';
import { api } from '../../api';
import type { ServiceRequest } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { formatDate, formatDateTime } from '../../lib/format';
import { pendingActionOf } from '../../lib/lifecycle';
import { providerRequestStatusLabel, serviceRequestStatusLabel } from '../../lib/labels';
import { LifecycleBadge } from '../../components/marketplace/LifecycleBadge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';
import { ServiceRequestStatusBadge } from '../../components/ui/StatusBadges';

const REQUEST_STATUSES = ['open', 'awarded', 'cancelled'] as const;
const THREAD_STATUSES = ['pending', 'accepted', 'agreed', 'declined', 'withdrawn', 'closed'] as const;
const select = 'py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * "My services": every service the factory requested, with each provider's independent thread and its
 * stage from request to negotiation, agreement, IMC approval and contract draft. A provider's positive
 * answer is shown as negotiation, never as an active subscription.
 */
export const FactoryRequests: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(['status', 'thread_status'] as const);
  const search = useSearchBox(list.search, list.setSearch);

  const query = useApiQuery(
    (signal) => api.serviceRequests.list({ ...toApiQuery(list), per_page: 10, signal }),
    [list.page, list.search, list.sort, list.filters.status, list.filters.thread_status],
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">خدماتي</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
            الخدمات التي طلبتموها وتفاوضتم عليها، ومرحلة كل مزود: الطلب، التفاوض، الاتفاق، اعتماد المركز، ثم مسودة العقد.
          </p>
        </div>
        <Button variant="primary" size="sm" icon={Send} onClick={() => navigate('/factory/services')}>
          طلب خدمة جديدة
        </Button>
      </div>

      <Card className="p-4">
        <div className="flex flex-col lg:flex-row gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="ابحث بعنوان الطلب أو اسم المزود..." className="flex-1" />
          <select aria-label="حالة الطلب" value={list.filters.status} onChange={(e) => list.setFilter('status', e.target.value)} className={select}>
            <option value="">كل الطلبات</option>
            {REQUEST_STATUSES.map((status) => (
              <option key={status} value={status}>
                {serviceRequestStatusLabel(status)}
              </option>
            ))}
          </select>
          <select aria-label="مرحلة المزود" value={list.filters.thread_status} onChange={(e) => list.setFilter('thread_status', e.target.value)} className={select}>
            <option value="">كل مراحل المزودين</option>
            {THREAD_STATUSES.map((status) => (
              <option key={status} value={status}>
                {providerRequestStatusLabel(status)}
              </option>
            ))}
          </select>
          <select aria-label="الترتيب" value={list.sort} onChange={(e) => list.setSort(e.target.value)} className={select}>
            <option value="">الأحدث أولًا</option>
            <option value="oldest">الأقدم أولًا</option>
          </select>
          <Button variant="outline" size="sm" onClick={list.reset} disabled={!list.hasActiveFilters}>
            إعادة ضبط
          </Button>
        </div>
      </Card>

      <QueryBoundary
        query={query}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          list.page > 1 ? (
            <EmptyState title="هذه الصفحة فارغة" description="رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة." actionText="العودة إلى الصفحة الأولى" onAction={() => list.setPage(1)} />
          ) : (
            <EmptyState
              title={list.hasActiveFilters ? 'لا توجد خدمات مطابقة' : 'لم تطلبوا أي خدمة بعد'}
              description={list.hasActiveFilters ? 'جرّبوا تغيير البحث أو الفلاتر.' : 'تصفحوا الخدمات المتاحة لمنشأتكم واطلبوا ما يناسبكم من المزودين المعتمدين.'}
              actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : 'تصفح الخدمات'}
              onAction={list.hasActiveFilters ? list.reset : () => navigate('/factory/services')}
            />
          )
        }
      >
        {(page) => (
          <div className="space-y-4">
            {page.data.map((request) => (
              <RequestCard key={request.id} request={request} onOpen={(thread) => navigate(`/factory/requests/${request.id}${thread ? `?thread=${thread}` : ''}`)} />
            ))}
            <Pagination meta={page.meta} onPage={list.setPage} />
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};

const RequestCard: React.FC<{ request: ServiceRequest; onOpen: (threadId?: number) => void }> = ({ request, onOpen }) => {
  const threads = request.provider_requests ?? [];
  const unread = threads.reduce((sum, thread) => sum + (thread.unread_messages_count ?? 0), 0);
  const lastActivity =
    [request.status_changed_at, ...threads.flatMap((thread) => [thread.status_changed_at, thread.last_message_at])].filter(Boolean).sort().at(-1) ??
    request.created_at;

  return (
    <div className="jahez-card p-5 space-y-4" data-request-id={request.id}>
      <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2 flex-wrap mb-1">
            <span className="font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-[#EEEAFE] text-[#5146A5]">طلب #{request.id}</span>
            <ServiceRequestStatusBadge status={request.status} size="sm" />
            {unread > 0 && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#E45B6A] text-white text-[10px] font-bold">
                <MessageSquare className="w-3 h-3" /> {unread} رسالة غير مقروءة
              </span>
            )}
          </div>
          <h3 className="text-sm font-bold text-[#172033] truncate">{request.service?.name_ar}</h3>
          <p className="text-xs text-[#667085] truncate">{request.title}</p>
        </div>
        <div className="text-[11px] text-[#98A2B3] sm:text-left shrink-0">
          <div>طُلب في {formatDate(request.created_at)}</div>
          <div>آخر نشاط {formatDateTime(lastActivity)}</div>
        </div>
      </div>

      <div className="space-y-2">
        {threads.length === 0 && <p className="text-xs text-[#98A2B3]">لا يوجد مزودون على هذا الطلب.</p>}
        {threads.map((thread) => {
          const action = pendingActionOf(thread, 'factory');
          return (
            <button
              key={thread.id}
              type="button"
              onClick={() => onOpen(thread.id)}
              className="w-full text-right p-3 rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] hover:border-[#9B8AFB] hover:bg-white transition-all cursor-pointer flex flex-col sm:flex-row sm:items-center gap-2 justify-between"
            >
              <span className="flex items-center gap-2 min-w-0">
                <span className="font-bold text-xs text-[#172033] truncate">{thread.provider?.name ?? '—'}</span>
                {thread.latest_offer_version ? <span className="text-[10px] text-[#667085]">آخر عرض: النسخة {thread.latest_offer_version}</span> : null}
              </span>
              <span className="flex items-center gap-2 flex-wrap">
                {(thread.unread_messages_count ?? 0) > 0 && (
                  <span className="px-2 py-0.5 rounded-full bg-[#E45B6A] text-white text-[10px] font-bold">{thread.unread_messages_count} جديدة</span>
                )}
                {action && <span className="text-[10px] font-semibold text-[#A66F0B]">{action}</span>}
                <LifecycleBadge thread={thread} />
                {thread.agreement && <span className="text-[10px] text-[#5146A5] font-semibold">اتفاقية #{thread.agreement.id}</span>}
              </span>
            </button>
          );
        })}
      </div>

      <div className="flex items-center justify-between pt-3 border-t border-[#F1F4F9]">
        <span className="text-[11px] text-[#667085]">
          {threads.length} مزود · {request.active_provider_count ?? 0} قيد الرد أو التفاوض
        </span>
        <Button size="sm" variant="secondary" icon={ArrowLeft} iconPosition="left" onClick={() => onOpen()}>
          تفاصيل الطلب
        </Button>
      </div>
    </div>
  );
};
