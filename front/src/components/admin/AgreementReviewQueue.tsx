import React, { useState } from 'react';
import { CheckCircle2, ShieldCheck, XCircle } from 'lucide-react';
import { api } from '../../api';
import type { Agreement } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime, formatMoney } from '../../lib/format';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { EmptyState } from '../ui/EmptyState';
import { CardSkeleton } from '../ui/LoadingState';
import { Pagination } from '../ui/Pagination';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReasonModal } from '../ui/ReasonModal';

/**
 * Agreements awaiting IMC's decision, oldest concluded first in each page. Approving allows a contract
 * draft and an invoice; it makes nothing binding and charges nothing. A decision is final.
 */
export const ReviewQueue: React.FC = () => {
  const { user } = useAuth();
  const canReview = user?.permissions.includes('agreements.review') ?? false;
  const [page, setPage] = useState(1);
  const [rejecting, setRejecting] = useState<Agreement | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const queue = useApiQuery((signal) => api.agreements.list({ page, per_page: 10, filter: { review_status: 'pending' }, signal }), [page]);

  if (!canReview) {
    return <EmptyState icon={ShieldCheck} title="لا تملك صلاحية مراجعة الاتفاقيات" description="مراجعة الاتفاقيات واعتمادها متاحة لمن يملك صلاحية agreements.review." />;
  }

  return (
    <div className="space-y-4">
      {notice && (
        <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center gap-2">
          <CheckCircle2 className="w-4 h-4" /> {notice}
        </div>
      )}
      <QueryBoundary
        query={queue}
        loading={<CardSkeleton />}
        isEmpty={(result) => result.data.length === 0}
        empty={<EmptyState icon={ShieldCheck} title="لا توجد اتفاقيات بانتظار المراجعة" description="تظهر هنا كل اتفاقية جديدة عند قبول مصنع لعرض مزود." />}
      >
        {(result) => (
          <div className="space-y-3">
            {result.data.map((agreement) => (
              <ReviewItem
                key={agreement.id}
                agreement={agreement}
                onApproved={() => {
                  setNotice(`اعتُمدت الاتفاقية رقم ${agreement.id}، وأُبلغ طرفاها.`);
                  queue.refetch();
                }}
                onReject={() => setRejecting(agreement)}
              />
            ))}
            <Pagination meta={result.meta} onPage={setPage} />
          </div>
        )}
      </QueryBoundary>

      {rejecting && (
        <ReasonModal
          title="رفض الاتفاقية"
          subtitle={`اتفاقية رقم ${rejecting.id}`}
          description="القرار نهائي ويُبلَّغ لطرفي الاتفاقية مع السبب. لا يمكن بعده إعداد عقد أو فاتورة لها."
          confirmLabel="تأكيد الرفض"
          variant="danger"
          required
          onConfirm={(reason) => api.agreements.review(rejecting.id, 'rejected', reason)}
          onDone={() => {
            setNotice(`رُفضت الاتفاقية رقم ${rejecting.id}، وأُبلغ طرفاها.`);
            setRejecting(null);
            queue.refetch();
          }}
          onClose={() => setRejecting(null)}
        />
      )}
    </div>
  );
};

const ReviewItem: React.FC<{ agreement: Agreement; onApproved: () => void; onReject: () => void }> = ({ agreement, onApproved, onReject }) => {
  const approve = useApiMutation(() => api.agreements.review(agreement.id, 'approved'));
  return (
    <div className="jahez-card p-5 space-y-3 text-xs" data-agreement-id={agreement.id}>
      <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
        <div>
          <div className="font-mono text-[11px] font-bold text-[#5146A5]">اتفاقية #{agreement.id}</div>
          <h4 className="text-sm font-bold text-[#172033]">{agreement.service?.name_ar}</h4>
          <p className="text-[#667085]">
            {agreement.factory?.name} ← {agreement.provider?.name} · أُبرمت في {formatDateTime(agreement.concluded_at)}
          </p>
        </div>
        <span className="text-base font-extrabold text-[#35B779]">{formatMoney(agreement.price)}</span>
      </div>
      {agreement.terms && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
          <div>
            <span className="font-bold text-[#667085] block">نطاق العمل</span>
            <p className="text-[#172033] whitespace-pre-line line-clamp-4" dir="auto">{agreement.terms.scope}</p>
          </div>
          <div>
            <span className="font-bold text-[#667085] block">المخرجات</span>
            <p className="text-[#172033] whitespace-pre-line line-clamp-4" dir="auto">{agreement.terms.deliverables}</p>
          </div>
          <div>
            <span className="font-bold text-[#667085] block">المدة</span>
            <p className="text-[#172033]">{agreement.terms.duration_days} يومًا · نسخة العرض {agreement.terms.offer_version}</p>
          </div>
        </div>
      )}
      {approve.error !== null && <ApiErrorState compact error={approve.error} />}
      <div className="flex items-center justify-end gap-2">
        <Button variant="outline" size="sm" icon={XCircle} onClick={onReject} disabled={approve.pending}>
          رفض
        </Button>
        <Button
          variant="success"
          size="sm"
          icon={ShieldCheck}
          isLoading={approve.pending}
          data-action="approve-agreement"
          onClick={async () => {
            const result = await approve.run();
            if (result.ok) onApproved();
          }}
        >
          اعتماد الاتفاقية
        </Button>
      </div>
    </div>
  );
};
