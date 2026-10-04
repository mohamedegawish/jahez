import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, MessageSquare } from 'lucide-react';
import { api } from '../../api';
import type { ProviderRequest } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { formatDate, formatDateTime } from '../../lib/format';
import { pendingActionOf } from '../../lib/lifecycle';
import { providerRequestStatusLabel } from '../../lib/labels';
import { LifecycleBadge } from '../../components/marketplace/LifecycleBadge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { TableSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';

const STATUSES = ['pending', 'accepted', 'agreed', 'declined', 'withdrawn', 'closed'] as const;
const SORTS = [
  { value: '', label: 'الأحدث أولًا' },
  { value: 'oldest', label: 'الأقدم أولًا' },
  { value: 'recent_activity', label: 'آخر تغيير في الحالة' },
] as const;

const select = 'py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * The provider's inbox: each factory request sent to this provider, independent of the other providers
 * the factory chose. Search, filters, sort and pagination run on the API; each row opens the request's
 * own page with its negotiation.
 */
export const ProviderRequests: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(['status', 'unread', 'service'] as const);
  const search = useSearchBox(list.search, list.setSearch);

  const query = useApiQuery(
    (signal) =>
      api.providerRequests.list({
        ...toApiQuery(list),
        filter: { status: list.filters.status || undefined, unread: list.filters.unread === '1' || undefined, service: list.filters.service || undefined },
        signal,
      }),
    [list.page, list.perPage, list.search, list.sort, list.filters.status, list.filters.unread, list.filters.service],
  );

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">طلبات المصانع</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          كل طلب يخص شركتكم وحدها: قراركم فيه لا يؤثر على المزودين الآخرين. افتحوا الطلب للرد عليه والتفاوض مع المصنع داخله.
        </p>
      </div>

      <Card className="p-4 space-y-3">
        <div className="flex flex-col lg:flex-row gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="ابحث بعنوان الطلب أو اسم المصنع..." className="flex-1" />
          <select aria-label="حالة الطلب" value={list.filters.status} onChange={(e) => list.setFilter('status', e.target.value)} className={select}>
            <option value="">كافة الحالات</option>
            {STATUSES.map((status) => (
              <option key={status} value={status}>
                {providerRequestStatusLabel(status)}
              </option>
            ))}
          </select>
          <select aria-label="الترتيب" value={list.sort} onChange={(e) => list.setSort(e.target.value)} className={select}>
            {SORTS.map((sort) => (
              <option key={sort.value} value={sort.value}>
                {sort.label}
              </option>
            ))}
          </select>
          <label className="inline-flex items-center gap-2 text-xs font-semibold text-[#172033] cursor-pointer shrink-0">
            <input type="checkbox" className="accent-[#5146A5]" checked={list.filters.unread === '1'} onChange={(e) => list.setFilter('unread', e.target.checked ? '1' : '')} />
            برسائل غير مقروءة
          </label>
          <Button variant="outline" size="sm" onClick={list.reset} disabled={!list.hasActiveFilters}>
            إعادة ضبط
          </Button>
        </div>
      </Card>

      <QueryBoundary
        query={query}
        loading={<TableSkeleton rows={5} cols={6} />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          list.page > 1 ? (
            <EmptyState title="هذه الصفحة فارغة" description="رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة." actionText="العودة إلى الصفحة الأولى" onAction={() => list.setPage(1)} />
          ) : (
            <EmptyState
              title={list.hasActiveFilters ? 'لا توجد طلبات مطابقة' : 'لا توجد طلبات واردة'}
              description={list.hasActiveFilters ? 'جرّبوا تغيير البحث أو الفلاتر.' : 'تظهر هنا الطلبات التي ترسلها إليكم المصانع المؤهلة بعد اعتماد ملفكم.'}
              actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : undefined}
              onAction={list.hasActiveFilters ? list.reset : undefined}
            />
          )
        }
      >
        {(page) => (
          <div className="jahez-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-right border-collapse text-xs">
                <thead>
                  <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
                    <th className="py-3 px-4">رقم</th>
                    <th className="py-3 px-4">المصنع</th>
                    <th className="py-3 px-4">الطلب والخدمة</th>
                    <th className="py-3 px-4">تاريخ الطلب</th>
                    <th className="py-3 px-4">آخر نشاط</th>
                    <th className="py-3 px-4">المرحلة</th>
                    <th className="py-3 px-4">المطلوب منكم</th>
                    <th className="py-3 px-4" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EAF0]">
                  {page.data.map((thread) => (
                    <Row key={thread.id} thread={thread} onOpen={() => navigate(`/provider/requests/${thread.id}`)} />
                  ))}
                </tbody>
              </table>
            </div>
            <div className="p-3 border-t border-[#E6EAF0] bg-[#F7F9FC]">
              <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
            </div>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};

const Row: React.FC<{ thread: ProviderRequest; onOpen: () => void }> = ({ thread, onOpen }) => {
  const action = pendingActionOf(thread, 'provider');
  const unread = thread.unread_messages_count ?? 0;
  const lastActivity = [thread.status_changed_at, thread.last_message_at].filter(Boolean).sort().at(-1) ?? thread.created_at;

  return (
    <tr data-thread-id={thread.id} className="hover:bg-[#F7F9FC] cursor-pointer" onClick={onOpen}>
      <td className="py-3.5 px-4 font-mono font-bold text-[#5146A5]">#{thread.id}</td>
      <td className="py-3.5 px-4 font-bold text-[#172033]">{thread.service_request?.factory?.name ?? '—'}</td>
      <td className="py-3.5 px-4 max-w-xs">
        <div className="font-semibold text-[#172033] truncate">{thread.service_request?.title}</div>
        <div className="text-[11px] text-[#667085] truncate">{thread.service_request?.service?.name_ar}</div>
      </td>
      <td className="py-3.5 px-4 text-[#667085]">{formatDate(thread.created_at)}</td>
      <td className="py-3.5 px-4 text-[#667085]">
        <div>{formatDateTime(lastActivity)}</div>
        {unread > 0 && (
          <span className="inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-full bg-[#E45B6A] text-white text-[10px] font-bold" data-testid="unread">
            <MessageSquare className="w-3 h-3" /> {unread} غير مقروءة
          </span>
        )}
      </td>
      <td className="py-3.5 px-4">
        <LifecycleBadge thread={thread} />
      </td>
      <td className="py-3.5 px-4 text-[11px] font-semibold text-[#A66F0B]">{action ?? <span className="text-[#98A2B3] font-normal">—</span>}</td>
      <td className="py-3.5 px-4">
        <Button size="sm" variant="secondary" icon={ArrowLeft} iconPosition="left" onClick={(e) => { e.stopPropagation(); onOpen(); }}>
          فتح
        </Button>
      </td>
    </tr>
  );
};
