import React from 'react';
import { Link } from 'react-router-dom';
import { Building2, Megaphone } from 'lucide-react';
import { api } from '../../api';
import type { AuditLogEntry, ServiceListing, ServiceListingStatus } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate, formatDateTime } from '../../lib/format';
import { approvalLabel, listingStatusLabel } from '../../lib/labels';
import { ListingStatusBadge } from '../ui/ApprovalBadge';
import { Button } from '../ui/Button';
import { CardSkeleton } from '../ui/LoadingState';
import { Modal } from '../ui/Modal';
import { QueryBoundary } from '../ui/QueryBoundary';

const EVENTS = ['service_listing.reviewed', 'service_listing.resubmitted'] as const;

/**
 * One provider listing for IMC: the catalog service, the provider, IMC's review of the listing, its
 * promotion, and the listing's decision history from the append-only audit log (ADR-021 §6), filtered
 * to this service. The catalog service itself is read-only.
 */
export const ListingDetailModal: React.FC<{ listing: ServiceListing; onClose: () => void }> = ({ listing, onClose }) => {
  const providerId = listing.provider?.id;
  const serviceCode = listing.service?.code;
  const history = useApiQuery(
    async (signal) => {
      if (!providerId) return [] as AuditLogEntry[];
      const pages = await Promise.all(
        EVENTS.map((event) => api.auditLogs.list({ subject_type: 'service_provider', subject_id: providerId, event, per_page: 100 }, signal)),
      );
      return pages
        .flatMap((page) => page.data)
        .filter((entry) => entry.metadata?.service === serviceCode)
        .sort((a, b) => b.created_at.localeCompare(a.created_at));
    },
    [providerId, serviceCode],
  );
  const review = listing.review;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={listing.service?.name_ar ?? 'خدمة مزود'}
      subtitle={`${listing.provider?.name ?? ''} · ${listing.service?.category?.name_ar ?? ''}`}
      maxWidth="2xl"
      footer={
        <div className="flex items-center justify-between w-full">
          {providerId ? (
            <Link to={`/admin/approvals/providers/${providerId}`} className="inline-flex items-center gap-1 text-xs font-semibold text-[#5146A5] hover:underline">
              <Building2 className="w-3.5 h-3.5" /> ملف المزود الكامل
            </Link>
          ) : (
            <span />
          )}
          <Button variant="outline" size="sm" onClick={onClose}>
            إغلاق
          </Button>
        </div>
      }
    >
      <div className="space-y-5 text-xs" data-testid="listing-detail">
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <Fact label="كود الخدمة في الكتالوج" value={serviceCode ?? '—'} ltr />
          <Fact label="اعتماد المزود" value={listing.provider?.approval_status ? approvalLabel(listing.provider.approval_status) : '—'} />
          <Fact label="أُدرجت" value={formatDate(review?.submitted_at ?? null)} />
          <Fact label="آخر قرار" value={formatDate(review?.changed_at ?? null)} />
        </div>

        {review && (
          <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] space-y-1.5">
            <div className="flex items-center gap-2">
              <span className="font-bold text-[#172033]">اعتماد الخدمة:</span>
              <ListingStatusBadge status={review.status} size="sm" />
            </div>
            {review.reason && <p className="text-[#667085]" dir="auto">السبب: {review.reason}</p>}
            <p className="text-[#98A2B3]">تظهر الخدمة للمصانع المؤهلة فقط حين تكون هي والمزود معتمدين ويستهدف المزود أحد قطاعات المنشأة.</p>
          </div>
        )}

        {listing.provider?.description && (
          <div>
            <h4 className="font-bold text-[#172033] mb-1">نبذة المزود</h4>
            <p className="text-[#475467] leading-relaxed">{listing.provider.description}</p>
          </div>
        )}

        <div className="p-3 rounded-xl border border-[#FDE5BE] bg-[#FEF5E7]/50">
          <div className="flex items-center gap-1.5 font-bold text-[#A66F0B]">
            <Megaphone className="w-4 h-4" /> الترويج
          </div>
          <p className="mt-1 text-[#667085]">
            {listing.promotion
              ? `${listing.promotion.label}: ${listing.promotion.headline ?? 'بدون عنوان'}${listing.promotion.ends_at ? ` · ينتهي ${formatDate(listing.promotion.ends_at)}` : ''}`
              : 'لا يوجد إعلان نشط على هذه الخدمة. الترويج يغيّر الترتيب فقط ولا يجعل خدمة غير معتمدة تظهر.'}
          </p>
        </div>

        <div>
          <h4 className="font-bold text-[#172033] mb-2">سجل قرارات الخدمة</h4>
          <QueryBoundary query={history} loading={<CardSkeleton />} isEmpty={(entries) => entries.length === 0} empty={<p className="text-[#667085]">لا توجد قرارات مسجلة على هذه الخدمة بعد.</p>}>
            {(entries) => (
              <ol className="space-y-2" data-testid="listing-history">
                {entries.map((entry) => {
                  const from = typeof entry.metadata?.from === 'string' ? entry.metadata.from : null;
                  const to = typeof entry.metadata?.to === 'string' ? entry.metadata.to : null;
                  const text = typeof entry.metadata?.reason === 'string' ? entry.metadata.reason : typeof entry.metadata?.note === 'string' ? entry.metadata.note : null;
                  return (
                    <li key={entry.id} className="p-2.5 rounded-lg border border-[#E6EAF0] bg-white">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="font-semibold text-[#172033]">
                          {entry.event === 'service_listing.resubmitted' ? 'أعاد المزود التقديم' : 'قرار المركز'}
                          {from && to ? `: ${listingStatusLabel(from as ServiceListingStatus)} ← ${listingStatusLabel(to as ServiceListingStatus)}` : ''}
                        </span>
                        <span className="text-[#98A2B3]">{formatDateTime(entry.created_at)}</span>
                      </div>
                      <div className="text-[#667085] mt-0.5">
                        {entry.actor?.name ?? '—'}
                        {text ? ` · ${text}` : ''}
                      </div>
                    </li>
                  );
                })}
              </ol>
            )}
          </QueryBoundary>
        </div>
      </div>
    </Modal>
  );
};

const Fact: React.FC<{ label: string; value: string; ltr?: boolean }> = ({ label, value, ltr = false }) => (
  <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
    <span className="text-[10px] text-[#98A2B3] block">{label}</span>
    <span className="font-bold text-[#172033]" dir={ltr ? 'ltr' : undefined}>
      {value}
    </span>
  </div>
);
