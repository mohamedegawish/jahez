import React, { useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowRight, CheckCircle2, MessageSquare, Plus } from 'lucide-react';
import { api } from '../../api';
import type { ServiceRequest } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate, formatDateTime } from '../../lib/format';
import { AddProvidersModal } from '../../components/factory/AddProvidersModal';
import { LifecycleBadge } from '../../components/marketplace/LifecycleBadge';
import { ThreadWorkspace } from '../../components/marketplace/ThreadWorkspace';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ReasonModal } from '../../components/ui/ReasonModal';
import { ServiceRequestStatusBadge } from '../../components/ui/StatusBadges';

type Dialog = { kind: 'cancel' } | { kind: 'add' } | null;

/**
 * One of the factory's service requests: its context, every provider's independent thread with its
 * stage, and the negotiation with the selected provider (`?thread=ID`) inside the same page.
 */
export const FactoryRequestDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const requestId = /^\d+$/.test(id ?? '') ? Number(id) : null;
  const request = useApiQuery((signal) => api.serviceRequests.get(requestId as number, signal), [requestId], { enabled: requestId !== null });
  const [dialog, setDialog] = useState<Dialog>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const threads = request.data?.provider_requests ?? [];
  const threadParam = Number.parseInt(params.get('thread') ?? '', 10) || null;
  const selectedId =
    (threadParam !== null && threads.some((thread) => thread.id === threadParam) ? threadParam : null) ??
    threads.find((thread) => thread.status === 'agreed' || thread.status === 'accepted')?.id ??
    threads[0]?.id ??
    null;

  const done = (message: string) => {
    setDialog(null);
    setNotice(message);
    request.refetch();
  };

  return (
    <div className="space-y-6">
      <Button variant="ghost" size="sm" icon={ArrowRight} onClick={() => navigate('/factory/requests')}>
        العودة إلى خدماتي
      </Button>

      {requestId === null ? (
        <EmptyState title="الطلب غير موجود" description="رابط الطلب غير صالح." actionText="خدماتي" onAction={() => navigate('/factory/requests')} />
      ) : (
        <QueryBoundary query={request} loading={<CardSkeleton />}>
          {(r) => (
            <>
              {notice && (
                <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center justify-between">
                  <span className="flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4" />
                    {notice}
                  </span>
                  <button onClick={() => setNotice(null)} className="hover:underline cursor-pointer">إغلاق</button>
                </div>
              )}

              <Header request={r} onCancel={() => setDialog({ kind: 'cancel' })} onAdd={() => setDialog({ kind: 'add' })} />

              <Card title="المزودون" subtitle="لكل مزود طلب مستقل: قبول أحدهم أو اعتذاره لا يغيّر طلبات الآخرين" accent="blue">
                {r.status === 'open' && r.active_provider_count === 0 && (
                  <div role="status" className="mb-4 p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs text-[#A66F0B]">
                    لا يوجد مزود قيد الرد أو التفاوض: اعتذر الجميع أو سُحب الطلب منهم. يبقى الطلب مفتوحًا؛ أضيفوا مزودين آخرين أو ألغوا الطلب.
                  </div>
                )}
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                  {threads.map((thread) => (
                    <button
                      key={thread.id}
                      type="button"
                      data-thread-id={thread.id}
                      onClick={() => setParams({ thread: String(thread.id) }, { replace: true })}
                      className={`text-right p-3.5 rounded-2xl border transition-all cursor-pointer ${
                        thread.id === selectedId ? 'bg-white border-[#5146A5] shadow-md ring-2 ring-[#EEEAFE]' : 'bg-white border-[#E6EAF0] hover:border-[#CCD5E2]'
                      }`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <span className="font-bold text-xs text-[#172033]">{thread.provider?.name ?? '—'}</span>
                        {(thread.unread_messages_count ?? 0) > 0 && (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#E45B6A] text-white text-[10px] font-bold">
                            <MessageSquare className="w-3 h-3" /> {thread.unread_messages_count}
                          </span>
                        )}
                      </div>
                      <div className="mt-2">
                        <LifecycleBadge thread={thread} />
                      </div>
                      <div className="mt-2 text-[10px] text-[#98A2B3]">آخر تغيير {formatDateTime(thread.status_changed_at ?? thread.created_at)}</div>
                    </button>
                  ))}
                </div>
              </Card>

              {selectedId !== null && <ThreadWorkspace key={selectedId} threadId={selectedId} mySide="factory" onChanged={request.refetch} />}

              {dialog?.kind === 'cancel' && (
                <ReasonModal
                  title="إلغاء طلب الخدمة"
                  subtitle={r.title}
                  description="يُغلق الطلب نهائيًا وتُغلق محادثاته المفتوحة مع المزودين، ويبقى سجلها محفوظًا. لا يمكن إعادة فتحه."
                  confirmLabel="تأكيد إلغاء الطلب"
                  variant="danger"
                  onConfirm={(reason) => api.serviceRequests.cancel(r.id, reason || null)}
                  onDone={() => done('تم إلغاء الطلب وإغلاق محادثاته.')}
                  onClose={() => setDialog(null)}
                />
              )}
              {dialog?.kind === 'add' && <AddProvidersModal request={r} onClose={() => setDialog(null)} onAdded={() => done('تمت إضافة المزودين المختارين إلى الطلب.')} />}
            </>
          )}
        </QueryBoundary>
      )}
    </div>
  );
};

const Header: React.FC<{ request: ServiceRequest; onCancel: () => void; onAdd: () => void }> = ({ request, onCancel, onAdd }) => (
  <div className="jahez-card p-6 space-y-4">
    <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
      <div>
        <div className="flex items-center gap-2 mb-2">
          <span className="font-mono text-xs font-bold px-2 py-0.5 rounded bg-[#EEEAFE] text-[#5146A5]">طلب #{request.id}</span>
          <ServiceRequestStatusBadge status={request.status} />
        </div>
        <h2 className="text-xl font-bold text-[#172033] leading-snug" data-testid="request-title">{request.title}</h2>
        <p className="text-xs text-[#667085] mt-1">
          {request.service?.name_ar}
          {request.service?.category ? ` · ${request.service.category.name_ar}` : ''} · قُدّم في {formatDate(request.created_at)}
        </p>
      </div>
      {request.status === 'open' && (
        <div className="flex items-center gap-2 shrink-0">
          <Button variant="outline" size="sm" icon={Plus} onClick={onAdd}>
            إضافة مزودين
          </Button>
          <Button variant="danger" size="sm" onClick={onCancel}>
            إلغاء الطلب
          </Button>
        </div>
      )}
    </div>
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-[#F1F4F9] text-xs">
      <div>
        <span className="font-bold text-[#172033] block mb-1">وصف الاحتياج</span>
        <p className="text-[#667085] leading-relaxed whitespace-pre-line bg-[#F7F9FC] p-3 rounded-xl border border-[#E6EAF0]" dir="auto">{request.need}</p>
      </div>
      <div>
        <span className="font-bold text-[#172033] block mb-1">متطلبات إضافية</span>
        <p className="text-[#667085] leading-relaxed whitespace-pre-line bg-[#F7F9FC] p-3 rounded-xl border border-[#E6EAF0]" dir="auto">{request.requirements ?? '—'}</p>
      </div>
    </div>
  </div>
);
