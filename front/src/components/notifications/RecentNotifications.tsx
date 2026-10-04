import React from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';

/** The latest in-app notifications as the dashboard's "recent activity". */
export const RecentNotifications: React.FC = () => {
  const navigate = useNavigate();
  const recent = useApiQuery((signal) => api.notifications.list({ per_page: 6, signal }), []);
  return (
    <Card
      title="آخر النشاطات والإشعارات"
      action={
        <Button variant="ghost" size="sm" onClick={() => navigate('/notifications')}>
          الكل
        </Button>
      }
    >
      <QueryBoundary
        query={recent}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={<p className="text-xs text-[#98A2B3] text-center py-6">لا يوجد نشاط بعد.</p>}
      >
        {(page) => (
          <ul className="space-y-2">
            {page.data.map((n) => (
              <li key={n.id}>
                <button
                  type="button"
                  onClick={() => n.link && navigate(n.link)}
                  className="w-full text-right p-2.5 rounded-xl hover:bg-[#F7F9FC] cursor-pointer flex items-start gap-2"
                >
                  <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${n.read_at === null ? 'bg-[#5146A5]' : 'bg-[#E6EAF0]'}`} />
                  <span className="min-w-0">
                    <span className="block text-xs font-bold text-[#172033]">{n.title}</span>
                    <span className="block text-[11px] text-[#667085] line-clamp-2" dir="auto">{n.body}</span>
                    <span className="block text-[10px] text-[#98A2B3] mt-0.5">{formatDateTime(n.created_at)}</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </QueryBoundary>
    </Card>
  );
};
