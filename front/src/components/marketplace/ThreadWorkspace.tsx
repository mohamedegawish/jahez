import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertCircle, Building2, FileText } from 'lucide-react';
import { api } from '../../api';
import type { ProviderRequest } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { providerRequestStatusLabel } from '../../lib/labels';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReasonModal } from '../ui/ReasonModal';
import { LifecycleBadge, ReviewStatusBadge } from './LifecycleBadge';
import { MessagePanel } from './MessagePanel';
import { OffersPanel } from './OffersPanel';
import { ThreadHistory } from './ThreadHistory';

/** How often an open thread re-reads its status, messages and offers (no push channel exists). */
const REFRESH_MS = 30_000;

interface ThreadWorkspaceProps {
  threadId: number;
  mySide: 'factory' | 'provider';
  /** Called after an action changed the thread, so the parent can reload its own data. */
  onChanged?: () => void;
  /** Hide the request summary when the parent page already shows it (the factory's request page). */
  showRequestSummary?: boolean;
}

/**
 * One factory–provider thread: its status and history, the private messages, and the formal offer
 * versions, kept separate. Messages are conversation; only an offer version carries terms, and only the
 * factory's acceptance of the latest version records an agreement, which IMC must still approve before a
 * contract draft. The thread is polled while the tab is visible and marked read for the signed-in user.
 */
export const ThreadWorkspace: React.FC<ThreadWorkspaceProps> = ({ threadId, mySide, onChanged, showRequestSummary = true }) => {
  const navigate = useNavigate();
  const thread = useApiQuery((signal) => api.providerRequests.get(threadId, signal), [threadId]);
  const offers = useApiQuery((signal) => api.providerRequests.offers.list(threadId, signal), [threadId]);
  const [tick, setTick] = useState(0);
  const [dialog, setDialog] = useState<'decline' | 'withdraw' | null>(null);
  const accept = useApiMutation(() => api.providerRequests.accept(threadId));

  const { refetch: refetchThread } = thread;
  const { refetch: refetchOffers } = offers;
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      refetchThread();
      refetchOffers();
      setTick((n) => n + 1);
    }, REFRESH_MS);
    return () => window.clearInterval(timer);
  }, [refetchThread, refetchOffers]);

  // Opening the thread reads its messages: move this user's read mark once unread ones are shown.
  const unread = thread.data?.unread_messages_count ?? 0;
  useEffect(() => {
    if (unread === 0) return;
    let cancelled = false;
    api.providerRequests.markRead(threadId).then(
      () => {
        if (!cancelled) refetchThread();
      },
      () => undefined,
    );
    return () => {
      cancelled = true;
    };
  }, [unread, threadId, refetchThread]);

  const reload = () => {
    refetchThread();
    refetchOffers();
    setTick((n) => n + 1);
    onChanged?.();
  };

  const contractsPath = mySide === 'provider' ? '/provider/contracts' : '/factory/contracts';

  return (
    <QueryBoundary query={thread} loading={<CardSkeleton />}>
      {(t) => {
        const request = t.service_request;
        const counterparty = mySide === 'provider' ? request?.factory?.name : t.provider?.name;

        return (
          <div className="space-y-4" data-thread-workspace={t.id}>
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div className="space-y-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-mono text-xs font-bold text-[#5146A5]">#{t.id}</span>
                  <LifecycleBadge thread={t} />
                  {t.agreement && <ReviewStatusBadge status={t.agreement.review_status ?? 'pending'} />}
                </div>
                <h3 className="text-base sm:text-lg font-bold text-[#172033]" data-testid="workspace-title">
                  {mySide === 'provider' ? 'التفاوض مع' : 'التفاوض مع المزود'} {counterparty ?? ''}
                </h3>
              </div>

              <div className="flex items-center gap-2 flex-wrap">
                {mySide === 'provider' && t.status === 'pending' && (
                  <Button
                    variant="success"
                    size="sm"
                    data-action="accept"
                    isLoading={accept.pending}
                    onClick={async () => {
                      const result = await accept.run();
                      if (result.ok) reload();
                    }}
                  >
                    قبول الطلب وبدء التفاوض
                  </Button>
                )}
                {mySide === 'provider' && (t.status === 'pending' || t.status === 'accepted') && (
                  <Button variant="outline" size="sm" data-action="decline" onClick={() => setDialog('decline')}>
                    اعتذار
                  </Button>
                )}
                {mySide === 'factory' && (t.status === 'pending' || t.status === 'accepted') && (
                  <Button variant="outline" size="sm" data-action="withdraw" onClick={() => setDialog('withdraw')}>
                    سحب الطلب من هذا المزود
                  </Button>
                )}
                {t.agreement && (
                  <Button variant="secondary" size="sm" icon={FileText} onClick={() => navigate(`${contractsPath}/${t.agreement?.id}`)}>
                    الاتفاقية رقم {t.agreement.id}
                  </Button>
                )}
              </div>
            </div>

            {accept.error !== null && <ApiErrorState compact error={accept.error} />}
            {t.status !== 'accepted' && t.status !== 'agreed' && <StatusBanner thread={t} mySide={mySide} />}
            {t.status === 'agreed' && <AgreementBanner thread={t} />}

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
              <div className="lg:col-span-3 flex flex-col gap-3">
                {showRequestSummary && (
                  <div className="jahez-card p-4 space-y-3">
                    <div className="flex items-center gap-2.5">
                      <div className="w-10 h-10 rounded-full bg-[#DFF3FF] text-[#0A6EB0] flex items-center justify-center shrink-0">
                        <Building2 className="w-5 h-5" />
                      </div>
                      <div className="min-w-0">
                        <span className="text-[10px] text-[#98A2B3] block">{mySide === 'provider' ? 'المصنع' : 'المزود'}</span>
                        <h4 className="font-bold text-xs text-[#172033] truncate" data-testid="counterparty">{counterparty ?? '—'}</h4>
                      </div>
                    </div>
                    <div className="pt-2 border-t border-[#E6EAF0] text-xs space-y-2">
                      <div>
                        <span className="font-bold text-[#172033] block">{request?.title}</span>
                        <span className="text-[11px] text-[#667085]">{request?.service?.name_ar}</span>
                      </div>
                      <div className="text-[11px] text-[#98A2B3]">وصل في {formatDateTime(t.created_at)}</div>
                    </div>
                  </div>
                )}
                <div className="jahez-card p-4">
                  <h5 className="font-bold text-xs text-[#172033] mb-2.5">سجل الحالة والقرارات</h5>
                  <ThreadHistory threadId={t.id} refreshKey={tick} />
                </div>
              </div>

              <div className="lg:col-span-5">
                <MessagePanel thread={t} mySide={mySide} refreshKey={tick} />
              </div>

              <div className="lg:col-span-4 flex flex-col gap-3">
                <OffersPanel thread={t} mySide={mySide} offers={offers} onChanged={reload} />
                <div className="p-3 rounded-xl bg-gradient-to-r from-[#DFF3FF]/50 to-[#EEEAFE]/50 border border-[#BDE5FD] text-xs">
                  <div className="font-bold text-[#5146A5] flex items-center gap-1.5 mb-1">
                    <AlertCircle className="w-3.5 h-3.5" />
                    الرسائل غير العروض
                  </div>
                  <p className="text-[11px] text-[#667085] leading-relaxed">
                    الاتفاق في الرسائل لا يُلزم أحدًا. الشروط الرسمية هي نسخ العرض فقط، وقبول المصنع لآخر نسخة ينشئ اتفاقية تُعرض على مركز تحديث
                    الصناعة لاعتمادها قبل صياغة مسودة العقد. لا تُصدر فاتورة ولا يُسجل دفع بقبول العرض.
                  </p>
                </div>
              </div>
            </div>

            {dialog === 'decline' && (
              <ReasonModal
                title="الاعتذار عن الطلب"
                subtitle={request?.title}
                description="تنتهي المحادثة نهائيًا ولا يمكن إعادة فتحها، ويبقى سجلها محفوظًا. يمكنكم ذكر سبب يظهر للمصنع."
                confirmLabel="تأكيد الاعتذار"
                variant="danger"
                onConfirm={(reason) => api.providerRequests.decline(t.id, reason || null)}
                onDone={() => {
                  setDialog(null);
                  reload();
                }}
                onClose={() => setDialog(null)}
              />
            )}
            {dialog === 'withdraw' && (
              <ReasonModal
                title="سحب الطلب من المزود"
                subtitle={t.provider?.name}
                description="تنتهي محادثة هذا المزود نهائيًا ويبقى سجلها محفوظًا، وتستمر محادثات المزودين الآخرين."
                confirmLabel="تأكيد السحب"
                variant="danger"
                onConfirm={(reason) => api.providerRequests.withdraw(t.id, reason || null)}
                onDone={() => {
                  setDialog(null);
                  reload();
                }}
                onClose={() => setDialog(null)}
              />
            )}
          </div>
        );
      }}
    </QueryBoundary>
  );
};

const StatusBanner: React.FC<{ thread: ProviderRequest; mySide: 'factory' | 'provider' }> = ({ thread, mySide }) => {
  const text: Record<string, string> = {
    pending:
      mySide === 'provider'
        ? 'لم تقبلوا هذا الطلب بعد. اقبلوه لفتح الرسائل وتقديم العروض، أو اعتذروا عنه.'
        : 'بانتظار رد المزود على طلبك. تُفتح الرسائل والعروض بعد قبوله.',
    declined: 'اعتذر المزود عن هذا الطلب. انتهت المحادثة، وسجلها محفوظ.',
    withdrawn: 'سحب المصنع الطلب من هذا المزود. انتهت المحادثة، وسجلها محفوظ.',
    closed: 'أُغلقت هذه المحادثة لأن الطلب أُلغي أو رُسّي على مزود آخر. سجلها محفوظ.',
  };
  return (
    <div role="status" className="p-3.5 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs text-[#A66F0B] space-y-0.5">
      <div className="font-bold">{providerRequestStatusLabel(thread.status)}</div>
      <div>{text[thread.status]}</div>
      {thread.status_reason && !thread.status_reason.startsWith('request_') && <div className="opacity-80" dir="auto">السبب: {thread.status_reason}</div>}
    </div>
  );
};

/** After agreement: the ministry approval comes before any contract draft or invoice. */
const AgreementBanner: React.FC<{ thread: ProviderRequest }> = ({ thread }) => {
  const review = thread.agreement?.review_status ?? 'pending';
  const style =
    review === 'approved'
      ? 'bg-[#E7F8EE] border-[#C5F0D5] text-[#1D7E4C]'
      : review === 'rejected'
        ? 'bg-[#FDECEE] border-[#F9C3C9] text-[#B82B3B]'
        : 'bg-[#EEEAFE] border-[#DDD5FD] text-[#5146A5]';
  return (
    <div role="status" className={`p-3.5 rounded-xl border text-xs space-y-0.5 ${style}`}>
      <div className="font-bold">
        {review === 'approved' ? 'اعتمد مركز تحديث الصناعة الاتفاقية' : review === 'rejected' ? 'رفض مركز تحديث الصناعة الاتفاقية' : 'تم الاتفاق، والاتفاقية بانتظار اعتماد مركز تحديث الصناعة'}
      </div>
      <div className="opacity-90">
        {review === 'approved'
          ? 'يمكن الآن إعداد مسودة العقد من صفحة العقود. المسودة غير ملزمة قانونيًا إلى أن يُعتمد الإطار القانوني للعقود.'
          : review === 'rejected'
            ? 'لا يمكن إعداد عقد أو فاتورة لهذه الاتفاقية. راجعوا سبب الرفض في صفحة الاتفاقية.'
            : 'لا يُعد الاتفاق اشتراكًا معتمدًا قبل قرار المركز، ولا تُعد مسودة عقد أو فاتورة قبله.'}
      </div>
    </div>
  );
};
