import React from 'react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { ApiErrorState } from '../ui/ApiErrorState';
import { ProviderRequestStatusBadge } from '../ui/StatusBadges';

/** Every status change of a thread, oldest first, with who did it and why (GET /provider-requests/{id}/history). */
export const ThreadHistory: React.FC<{ threadId: number; refreshKey: number }> = ({ threadId, refreshKey }) => {
  const history = useApiQuery((signal) => api.providerRequests.history(threadId, signal), [threadId, refreshKey]);

  if (history.status === 'error') return <ApiErrorState compact error={history.error} />;
  const items = history.data ?? [];

  return (
    <ol className="space-y-2.5 text-[11px]" data-testid="thread-history">
      {history.status === 'loading' && <li className="text-[#98A2B3]">جارٍ التحميل...</li>}
      {items.map((entry, index) => (
        <li key={`${entry.at}-${index}`} className="flex gap-2.5">
          <span className="w-2 h-2 rounded-full bg-[#5146A5] mt-1.5 shrink-0" />
          <div className="min-w-0">
            <div className="flex items-center gap-1.5 flex-wrap">
              <ProviderRequestStatusBadge status={entry.to} size="sm" />
              <span className="text-[#98A2B3]">{formatDateTime(entry.at)}</span>
            </div>
            <div className="text-[#667085] mt-0.5">
              {entry.actor
                ? `${entry.actor.name}${entry.actor.side ? (entry.actor.side === 'factory' ? ' (المصنع)' : ' (المزود)') : ''}`
                : 'النظام'}
              {entry.reason && <span dir="auto"> — {entry.reason}</span>}
            </div>
          </div>
        </li>
      ))}
    </ol>
  );
};
