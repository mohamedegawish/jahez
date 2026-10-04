import React, { useState } from 'react';
import { CheckCircle2, FileCheck } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api';
import type { Offer, ProviderRequest } from '../../api';
import type { ApiQuery } from '../../hooks/useApiQuery';
import { formatDate, formatDateTime, formatMoney } from '../../lib/format';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { ConfirmModal } from '../ui/ConfirmModal';
import { CardSkeleton } from '../ui/LoadingState';
import { OfferStateBadge } from '../ui/StatusBadges';
import { OfferForm } from './OfferForm';

interface OffersPanelProps {
  thread: ProviderRequest;
  mySide: 'factory' | 'provider';
  offers: ApiQuery<Offer[]>;
  /** Reload the thread and the offers after a change. */
  onChanged: () => void;
}

/**
 * Offers are append-only versions authored by the provider. The factory negotiates by message and
 * may accept only the LATEST version while it is current; accepting concludes an agreement.
 */
export const OffersPanel: React.FC<OffersPanelProps> = ({ thread, mySide, offers, onChanged }) => {
  const navigate = useNavigate();
  const [composing, setComposing] = useState(false);
  const [accepting, setAccepting] = useState<Offer | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const list = offers.data ?? [];
  const latest = list[0] ?? null;
  const older = list.slice(1);
  const canSubmit = mySide === 'provider' && thread.status === 'accepted';
  const canAccept = mySide === 'factory' && thread.status === 'accepted' && latest?.state === 'current';
  const agreementLink = mySide === 'factory' ? '/factory/contracts' : '/provider/contracts';

  return (
    <div className="jahez-card p-4 space-y-4">
      <div className="flex items-center justify-between">
        <h4 className="font-bold text-xs text-[#172033] flex items-center gap-1.5">
          <FileCheck className="w-4 h-4 text-[#35B779]" />
          العروض الفنية والمالية
        </h4>
        {latest && <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-[#EEEAFE] text-[#5146A5]">النسخة {latest.version}</span>}
      </div>

      {notice && (
        <div role="status" className="p-3 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-[11px] font-semibold text-[#1D7E4C] flex items-start gap-2">
          <CheckCircle2 className="w-4 h-4 shrink-0" />
          <span>{notice}</span>
        </div>
      )}

      {offers.status === 'loading' && <CardSkeleton />}
      {offers.status === 'error' && <ApiErrorState compact error={offers.error} onRetry={offers.refetch} />}
      {offers.refreshError !== null && <ApiErrorState compact error={offers.refreshError} onRetry={offers.refetch} />}

      {thread.status === 'agreed' && (
        <div className="p-3 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-[11px] text-[#1D7E4C] space-y-1.5" data-testid="agreed-banner">
          <div className="font-bold">تم الاتفاق على العرض.</div>
          <div>
            أُنشئت اتفاقية موثّقة غير ملزمة قانونيًا
            {thread.agreement_id != null && <> (رقم {thread.agreement_id})</>}. لا يُنشئ القبول عقدًا ولا فاتورة ولا دفعة.
          </div>
          <Button size="sm" variant="outline" onClick={() => navigate(thread.agreement_id != null ? `${agreementLink}?agreement=${thread.agreement_id}` : agreementLink)}>
            عرض الاتفاقية
          </Button>
        </div>
      )}

      {offers.status === 'success' && !latest && (
        <p className="text-xs text-[#98A2B3] text-center py-3">
          {mySide === 'factory' ? 'لم يقدم المزود عرضًا بعد. تفاوضوا عبر الرسائل.' : 'لم تقدموا عرضًا بعد.'}
        </p>
      )}

      {latest && (
        <div className="space-y-3 text-xs" data-testid="latest-offer">
          <div className="flex items-center justify-between">
            <OfferStateBadge status={latest.state} size="sm" />
            <span className="text-[10px] text-[#98A2B3]">{formatDateTime(latest.created_at)}</span>
          </div>
          <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-[#667085]">السعر الإجمالي</span>
              <span className="font-extrabold text-[#35B779] text-sm" data-testid="offer-price">{formatMoney(latest.price)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-[#667085]">مدة التنفيذ</span>
              <span className="font-semibold text-[#172033]">{latest.duration_days} يومًا</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-[#667085]">صالح حتى</span>
              <span className="font-semibold text-[#172033]">{latest.valid_until ? formatDate(latest.valid_until) : 'بلا تاريخ محدد'}</span>
            </div>
          </div>
          <div>
            <span className="font-bold text-[#667085] block mb-0.5">نطاق العمل</span>
            <p className="text-[#172033] leading-relaxed whitespace-pre-line" dir="auto">{latest.scope}</p>
          </div>
          <div>
            <span className="font-bold text-[#667085] block mb-0.5">المخرجات</span>
            <p className="text-[#172033] leading-relaxed whitespace-pre-line" dir="auto">{latest.deliverables}</p>
          </div>
          {latest.state === 'expired' && <p className="text-[11px] text-[#A66F0B]">انتهت صلاحية هذا العرض فلا يمكن قبوله. اطلبوا من المزود نسخة جديدة.</p>}
        </div>
      )}

      {canAccept && latest && (
        <Button variant="success" size="md" className="w-full" data-action="accept-offer" onClick={() => setAccepting(latest)}>
          قبول العرض (النسخة {latest.version})
        </Button>
      )}

      {canSubmit && (
        <div className="pt-3 border-t border-[#E6EAF0] space-y-3">
          {!composing && latest ? (
            <Button variant="primary" size="md" className="w-full" data-action="new-version" onClick={() => setComposing(true)}>
              تقديم نسخة جديدة من العرض
            </Button>
          ) : (
            <OfferForm
              key={latest?.version ?? 0}
              threadId={thread.id}
              latest={latest}
              onConflict={() => {
                offers.refetch();
                onChanged();
              }}
              onSubmitted={(offer) => {
                setComposing(false);
                setNotice(`تم إرسال النسخة ${offer.version} من العرض.`);
                offers.refetch();
                onChanged();
              }}
            />
          )}
        </div>
      )}

      {older.length > 0 && (
        <details className="pt-3 border-t border-[#E6EAF0] text-xs">
          <summary className="cursor-pointer font-semibold text-[#5146A5]">النسخ السابقة ({older.length})</summary>
          <ul className="mt-2 space-y-2">
            {older.map((offer) => (
              <li key={offer.id} data-offer-version={offer.version} className="p-2.5 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                <div className="flex items-center justify-between">
                  <span className="font-bold text-[#172033]">النسخة {offer.version}</span>
                  <OfferStateBadge status={offer.state} size="sm" />
                </div>
                <div className="text-[#667085] mt-1">{formatMoney(offer.price)} · {offer.duration_days} يومًا</div>
              </li>
            ))}
          </ul>
        </details>
      )}

      {accepting && (
        <ConfirmModal
          title="قبول العرض"
          subtitle={`النسخة ${accepting.version} — ${formatMoney(accepting.price)}`}
          description={
            <>
              عند القبول تُرسّى الخدمة على هذا المزود وتُنشأ <strong>اتفاقية موثّقة غير ملزمة قانونيًا</strong>، ويُغلق باقي محادثات هذا الطلب وفق سياسة
              الترسية الحالية. لا يُنشئ القبول عقدًا ولا فاتورة ولا دفعة، ولا يمكن التراجع عنه.
            </>
          }
          confirmLabel="تأكيد قبول العرض"
          variant="success"
          onConfirm={() => api.providerRequests.offers.accept(thread.id, accepting.id)}
          onDone={() => {
            setAccepting(null);
            setNotice('تم قبول العرض وإنشاء الاتفاقية.');
            offers.refetch();
            onChanged();
          }}
          onClose={() => setAccepting(null)}
        />
      )}
    </div>
  );
};
