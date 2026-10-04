import React from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate } from '../../lib/format';
import { ThreadWorkspace } from '../../components/marketplace/ThreadWorkspace';
import { Button } from '../../components/ui/Button';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ServiceRequestStatusBadge } from '../../components/ui/StatusBadges';

/**
 * One factory request as sent to this provider: the full request context and the private negotiation
 * for it. Another provider's request is 404 from the API.
 */
export const ProviderRequestDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const threadId = /^\d+$/.test(id ?? '') ? Number(id) : null;
  const thread = useApiQuery((signal) => api.providerRequests.get(threadId as number, signal), [threadId], { enabled: threadId !== null });

  return (
    <div className="space-y-6">
      <Button variant="ghost" size="sm" icon={ArrowRight} onClick={() => navigate('/provider/requests')}>
        العودة إلى طلبات المصانع
      </Button>

      {threadId === null ? (
        <EmptyState title="الطلب غير موجود" description="رابط الطلب غير صالح." actionText="طلبات المصانع" onAction={() => navigate('/provider/requests')} />
      ) : (
        <QueryBoundary query={thread} loading={<CardSkeleton />}>
          {(t) => {
            const request = t.service_request;
            return (
              <>
                <div className="jahez-card p-6 space-y-4">
                  <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2 mb-2">
                        <span className="font-mono text-xs font-bold px-2 py-0.5 rounded bg-[#EEEAFE] text-[#5146A5]">طلب #{t.id}</span>
                        {request && <ServiceRequestStatusBadge status={request.status} size="sm" />}
                      </div>
                      <h2 className="text-xl font-bold text-[#172033] leading-snug">{request?.title}</h2>
                      <p className="text-xs text-[#667085] mt-1">
                        {request?.factory?.name} · {request?.service?.name_ar}
                        {request?.service?.category ? ` · ${request.service.category.name_ar}` : ''} · وصل في {formatDate(t.created_at)}
                      </p>
                    </div>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-[#F1F4F9] text-xs">
                    <div>
                      <span className="font-bold text-[#172033] block mb-1">وصف الاحتياج</span>
                      <p className="text-[#667085] leading-relaxed whitespace-pre-line bg-[#F7F9FC] p-3 rounded-xl border border-[#E6EAF0]" dir="auto">{request?.need}</p>
                    </div>
                    <div>
                      <span className="font-bold text-[#172033] block mb-1">متطلبات إضافية</span>
                      <p className="text-[#667085] leading-relaxed whitespace-pre-line bg-[#F7F9FC] p-3 rounded-xl border border-[#E6EAF0]" dir="auto">{request?.requirements ?? '—'}</p>
                    </div>
                  </div>
                </div>
                <ThreadWorkspace threadId={t.id} mySide="provider" onChanged={thread.refetch} showRequestSummary={false} />
              </>
            );
          }}
        </QueryBoundary>
      )}
    </div>
  );
};
