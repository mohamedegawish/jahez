import React from 'react';
import { History } from 'lucide-react';
import { api } from '../../api';
import type { AuditSubjectType } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { auditEventLabel } from '../../lib/audit';
import { formatDateTime } from '../../lib/format';
import { approvalLabel, listingStatusLabel } from '../../lib/labels';
import { Card } from '../ui/Card';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';

/** The audit events that make up an organization's review history. */
const REVIEW_EVENTS = new Set([
  'factory.registered',
  'factory.created',
  'factory.approval_changed',
  'factory.review_requested',
  'factory.change_request_submitted',
  'factory.change_request_approved',
  'factory.change_request_rejected',
  'service_provider.registered',
  'service_provider.created',
  'service_provider.approval_changed',
  'service_provider.review_requested',
  'service_provider.evaluation_recorded',
  'service_provider.change_request_submitted',
  'service_provider.change_request_approved',
  'service_provider.change_request_rejected',
  'service_listing.reviewed',
]);

const text = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : null);

/**
 * Decisions and review steps of a provider or factory, read from the append-only audit log
 * (ADR-012), so the history cannot drift from what actually happened. Only the decision, its
 * reason and the reviewer are shown: profile values and documents are never in the log.
 */
export const ReviewHistory: React.FC<{ subjectType: AuditSubjectType; subjectId: number; refreshKey?: number }> = ({ subjectType, subjectId, refreshKey = 0 }) => {
  const history = useApiQuery(
    (signal) => api.auditLogs.list({ subject_type: subjectType, subject_id: subjectId, per_page: 100 }, signal),
    [subjectType, subjectId, refreshKey],
  );

  return (
    <Card title="سجل المراجعة والقرارات" subtitle="من سجل التدقيق؛ لا يُعدَّل ولا يُحذف" accent="purple">
      <QueryBoundary
        query={history}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.every((entry) => !REVIEW_EVENTS.has(entry.event))}
        empty={<p className="text-xs text-[#667085]">لا توجد قرارات مسجلة بعد.</p>}
      >
        {(page) => (
          <ol className="relative border-r-2 border-[#EEEAFE] pr-4 space-y-4" data-testid="review-history">
            {page.data
              .filter((entry) => REVIEW_EVENTS.has(entry.event))
              .map((entry) => {
                const meta = entry.metadata ?? {};
                const from = text(meta.from);
                const to = text(meta.to);
                const isListing = entry.event === 'service_listing.reviewed';
                const label = (value: string) => (isListing ? listingStatusLabel(value) : approvalLabel(value));
                const note = text(meta.reason) ?? text(meta.note) ?? text(meta.summary);
                return (
                  <li key={entry.id} className="relative text-xs">
                    <span className="absolute -right-[23px] top-1 w-3 h-3 rounded-full bg-[#5146A5] border-2 border-white" />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-bold text-[#172033] flex items-center gap-1.5">
                        <History className="w-3.5 h-3.5 text-[#5146A5]" />
                        {auditEventLabel(entry.event)}
                        {isListing && text(meta.service) && <span className="font-mono text-[10px] text-[#5146A5]" dir="ltr">{String(meta.service)}</span>}
                      </span>
                      <span className="text-[10px] text-[#98A2B3]">{formatDateTime(entry.created_at)}</span>
                    </div>
                    {from && to && (
                      <div className="mt-1 text-[#667085]">
                        {label(from)} ← {label(to)}
                      </div>
                    )}
                    {note && <p className="mt-1 p-2 rounded-lg bg-[#F7F9FC] text-[#172033] whitespace-pre-line" dir="auto">{note}</p>}
                    <div className="mt-1 text-[10px] text-[#98A2B3]">{entry.actor ? entry.actor.name ?? entry.actor.email : 'النظام'}</div>
                  </li>
                );
              })}
          </ol>
        )}
      </QueryBoundary>
    </Card>
  );
};
