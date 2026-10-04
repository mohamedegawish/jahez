import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCheck } from 'lucide-react';
import { api } from '../../api';
import type { AppNotification } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { ApiErrorState } from '../ui/ApiErrorState';
import { CardSkeleton } from '../ui/LoadingState';

/** How often the bell re-reads the unread count while the tab is visible (no push channel exists). */
const POLL_MS = 60_000;

/**
 * The account's in-app notifications (stored by the API, ADR-020). The count is polled; opening the
 * menu loads the latest ones. Following a notification marks it read and opens the record it links to.
 */
export const NotificationBell: React.FC = () => {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [tick, setTick] = useState(0);

  const latest = useApiQuery((signal) => api.notifications.list({ per_page: 8, signal }), [tick]);
  const unread = latest.data?.meta.unread_count ?? 0;

  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') setTick((n) => n + 1);
    }, POLL_MS);
    return () => window.clearInterval(timer);
  }, []);

  const readAll = useApiMutation(() => api.notifications.readAll());

  const follow = async (notification: AppNotification) => {
    setOpen(false);
    if (notification.read_at === null) {
      try {
        await api.notifications.read(notification.id);
      } catch {
        // Opening the record matters more than the read mark; the count refreshes on the next poll.
      }
      setTick((n) => n + 1);
    }
    if (notification.link) navigate(notification.link);
  };

  return (
    <div className="relative">
      <button
        onClick={() => {
          setOpen(!open);
          if (!open) setTick((n) => n + 1);
        }}
        className="relative p-2 rounded-xl text-[#667085] hover:bg-[#F1F4F9] hover:text-[#172033] transition-colors cursor-pointer"
        title="الإشعارات"
        aria-label={unread > 0 ? `الإشعارات (${unread} غير مقروء)` : 'الإشعارات'}
      >
        <Bell className="w-4 h-4" />
        {unread > 0 && (
          <span className="absolute -top-0.5 -left-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-[#E45B6A] text-white text-[10px] font-bold flex items-center justify-center" data-testid="unread-count">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute left-0 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] rounded-2xl bg-white border border-[#E6EAF0] shadow-xl z-50 animate-in fade-in duration-150 overflow-hidden">
            <div className="px-4 py-3 border-b border-[#F1F4F9] flex items-center justify-between">
              <span className="text-xs font-bold text-[#172033]">الإشعارات {unread > 0 ? `(${unread} غير مقروء)` : ''}</span>
              <button
                type="button"
                disabled={unread === 0 || readAll.pending}
                onClick={async () => {
                  const result = await readAll.run();
                  if (result.ok) setTick((n) => n + 1);
                }}
                className="inline-flex items-center gap-1 text-[11px] font-semibold text-[#0A6EB0] hover:underline disabled:opacity-40 disabled:no-underline cursor-pointer"
              >
                <CheckCheck className="w-3.5 h-3.5" /> تعليم الكل كمقروء
              </button>
            </div>
            <div className="max-h-96 overflow-y-auto">
              {latest.status === 'loading' && <div className="p-3"><CardSkeleton /></div>}
              {latest.status === 'error' && <div className="p-3"><ApiErrorState compact error={latest.error} onRetry={latest.refetch} /></div>}
              {readAll.error !== null && <div className="p-3"><ApiErrorState compact error={readAll.error} /></div>}
              {latest.status === 'success' && latest.data?.data.length === 0 && (
                <p className="p-6 text-center text-xs text-[#98A2B3]">لا توجد إشعارات بعد.</p>
              )}
              {(latest.data?.data ?? []).map((notification) => (
                <button
                  key={notification.id}
                  type="button"
                  onClick={() => void follow(notification)}
                  className={`w-full text-right px-4 py-3 border-b border-[#F7F9FC] hover:bg-[#F7F9FC] transition-colors cursor-pointer ${notification.read_at === null ? 'bg-[#EEEAFE]/40' : ''}`}
                >
                  <div className="flex items-start gap-2">
                    <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${notification.read_at === null ? 'bg-[#5146A5]' : 'bg-transparent'}`} />
                    <div className="min-w-0">
                      <div className="text-xs font-bold text-[#172033]">{notification.title}</div>
                      <div className="text-[11px] text-[#667085] leading-relaxed mt-0.5" dir="auto">{notification.body}</div>
                      <div className="text-[10px] text-[#98A2B3] mt-1">{formatDateTime(notification.created_at)}</div>
                    </div>
                  </div>
                </button>
              ))}
            </div>
            <button
              type="button"
              onClick={() => {
                setOpen(false);
                navigate('/notifications');
              }}
              className="w-full px-4 py-2.5 text-center text-xs font-bold text-[#5146A5] hover:bg-[#F7F9FC] cursor-pointer"
            >
              عرض كل الإشعارات
            </button>
          </div>
        </>
      )}
    </div>
  );
};
