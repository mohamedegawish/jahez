import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCheck } from 'lucide-react';
import { api } from '../api';
import type { AppNotification } from '../api';
import { useApiMutation } from '../hooks/useApiMutation';
import { useApiQuery } from '../hooks/useApiQuery';
import { toApiQuery, useListParams } from '../hooks/useListParams';
import { formatDateTime } from '../lib/format';
import { ApiErrorState } from '../components/ui/ApiErrorState';
import { Button } from '../components/ui/Button';
import { Card } from '../components/ui/Card';
import { EmptyState } from '../components/ui/EmptyState';
import { CardSkeleton } from '../components/ui/LoadingState';
import { Pagination } from '../components/ui/Pagination';
import { QueryBoundary } from '../components/ui/QueryBoundary';

/** Every in-app notification of the signed-in account, newest first (all roles). */
export const NotificationsPage: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(['unread'] as const);
  const query = useApiQuery(
    (signal) => api.notifications.list({ ...toApiQuery(list), filter: list.filters.unread === '1' ? { unread: true } : undefined, signal }),
    [list.page, list.perPage, list.filters.unread],
  );
  const readAll = useApiMutation(() => api.notifications.readAll());

  const open = async (notification: AppNotification) => {
    if (notification.read_at === null) {
      try {
        await api.notifications.read(notification.id);
      } catch {
        // The record still opens; the read mark is retried the next time.
      }
    }
    if (notification.link) navigate(notification.link);
    else query.refetch();
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">الإشعارات</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">أحداث الطلبات والتفاوض والاتفاقيات والفواتير وقرارات المركز المتعلقة بحسابكم.</p>
        </div>
        <Button
          variant="outline"
          size="sm"
          icon={CheckCheck}
          isLoading={readAll.pending}
          disabled={(query.data?.meta.unread_count ?? 0) === 0}
          onClick={async () => {
            const result = await readAll.run();
            if (result.ok) query.refetch();
          }}
        >
          تعليم الكل كمقروء
        </Button>
      </div>
      {readAll.error !== null && <ApiErrorState compact error={readAll.error} />}

      <Card className="p-4">
        <label className="inline-flex items-center gap-2 text-xs font-semibold text-[#172033] cursor-pointer">
          <input type="checkbox" className="accent-[#5146A5]" checked={list.filters.unread === '1'} onChange={(e) => list.setFilter('unread', e.target.checked ? '1' : '')} />
          غير المقروءة فقط {query.data ? `(${query.data.meta.unread_count})` : ''}
        </label>
      </Card>

      <QueryBoundary
        query={query}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={<EmptyState icon={Bell} title="لا توجد إشعارات" description={list.filters.unread === '1' ? 'قرأتم كل الإشعارات.' : 'تصلكم هنا إشعارات الطلبات والرسائل والقرارات.'} />}
      >
        {(page) => (
          <div className="jahez-card overflow-hidden">
            <ul className="divide-y divide-[#F1F4F9]">
              {page.data.map((notification) => (
                <li key={notification.id}>
                  <button
                    type="button"
                    onClick={() => void open(notification)}
                    className={`w-full text-right p-4 hover:bg-[#F7F9FC] cursor-pointer flex items-start gap-3 ${notification.read_at === null ? 'bg-[#EEEAFE]/30' : ''}`}
                  >
                    <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${notification.read_at === null ? 'bg-[#5146A5]' : 'bg-[#E6EAF0]'}`} />
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-bold text-[#172033]">{notification.title}</span>
                      <span className="block text-xs text-[#667085] mt-0.5 leading-relaxed" dir="auto">{notification.body}</span>
                    </span>
                    <span className="text-[11px] text-[#98A2B3] shrink-0">{formatDateTime(notification.created_at)}</span>
                  </button>
                </li>
              ))}
            </ul>
            <div className="p-3 border-t border-[#E6EAF0] bg-[#F7F9FC]">
              <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
            </div>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};
