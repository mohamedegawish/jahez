import React, { useState } from 'react';
import { Megaphone, Plus, Power } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { ServiceListing, ServicePromotion } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { approvalLabel } from '../../lib/labels';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { CardSkeleton } from '../ui/LoadingState';
import { Modal } from '../ui/Modal';
import { Pagination } from '../ui/Pagination';
import { QueryBoundary } from '../ui/QueryBoundary';
import { SearchInput } from '../ui/SearchInput';
import { TextField } from '../ui/TextField';

const STATE_LABEL: Record<ServicePromotion['state'], string> = { active: 'نشط', scheduled: 'مجدول', expired: 'انتهت مدته', ended: 'أُنهي' };

/**
 * IMC promotions («إعلان») of provider listings in the factory portal, stored by the API. A promotion
 * orders and labels a listing; it never makes a provider or service visible to an ineligible factory.
 */
export const PromotionsPanel: React.FC = () => {
  const { hasPermission } = useAuth();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const promotions = useApiQuery((signal) => api.promotions.list({ page, per_page: 10, signal }), [page], { enabled: hasPermission('promotions.manage') });

  if (!hasPermission('promotions.manage')) {
    return <EmptyState icon={Megaphone} title="لا تملك صلاحية إدارة الإعلانات" description="إدارة إعلانات بوابة المصانع متاحة لمن يملك صلاحية promotions.manage." />;
  }

  return (
    <Card
      title="إعلانات الخدمات في بوابة المصانع"
      subtitle="تظهر الخدمة المروَّجة أولًا وبعلامة «إعلان» للمصانع المؤهلة لها فقط"
      accent="purple"
      action={
        <Button variant="primary" size="sm" icon={Plus} onClick={() => setCreating(true)}>
          إعلان جديد
        </Button>
      }
    >
      <QueryBoundary
        query={promotions}
        loading={<CardSkeleton />}
        isEmpty={(result) => result.data.length === 0}
        empty={<p className="text-xs text-[#667085] text-center py-6">لا توجد إعلانات بعد.</p>}
      >
        {(result) => (
          <div className="space-y-3">
            <div className="overflow-x-auto">
              <table className="w-full text-right text-xs border-collapse">
                <thead>
                  <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
                    <th className="py-2.5 px-3">الخدمة</th>
                    <th className="py-2.5 px-3">المزود</th>
                    <th className="py-2.5 px-3">الأولوية</th>
                    <th className="py-2.5 px-3">المدة</th>
                    <th className="py-2.5 px-3">الحالة</th>
                    <th className="py-2.5 px-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EAF0]">
                  {result.data.map((promotion) => (
                    <PromotionRow key={promotion.id} promotion={promotion} onChanged={promotions.refetch} />
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination meta={result.meta} onPage={setPage} />
          </div>
        )}
      </QueryBoundary>
      {creating && (
        <CreatePromotionModal
          onClose={() => setCreating(false)}
          onCreated={() => {
            setCreating(false);
            promotions.refetch();
          }}
        />
      )}
    </Card>
  );
};

const PromotionRow: React.FC<{ promotion: ServicePromotion; onChanged: () => void }> = ({ promotion, onChanged }) => {
  const end = useApiMutation(() => api.promotions.end(promotion.id));
  return (
    <tr data-promotion-id={promotion.id}>
      <td className="py-2.5 px-3">
        <div className="font-bold text-[#172033]">{promotion.service?.name_ar}</div>
        {promotion.headline && <div className="text-[11px] text-[#667085]" dir="auto">{promotion.headline}</div>}
      </td>
      <td className="py-2.5 px-3">
        <div className="text-[#172033]">{promotion.provider?.name}</div>
        {promotion.provider && promotion.provider.approval_status !== 'approved' && (
          <div className="text-[10px] text-[#A66F0B]">{approvalLabel(promotion.provider.approval_status)} — لا يظهر للمصانع</div>
        )}
      </td>
      <td className="py-2.5 px-3 font-bold">{promotion.priority}</td>
      <td className="py-2.5 px-3 text-[#667085]">
        {formatDateTime(promotion.starts_at)} ← {promotion.ends_at ? formatDateTime(promotion.ends_at) : 'مفتوحة'}
      </td>
      <td className="py-2.5 px-3">
        <Badge variant={promotion.state === 'active' ? 'success' : promotion.state === 'scheduled' ? 'blue' : 'neutral'} size="sm">
          {STATE_LABEL[promotion.state]}
        </Badge>
      </td>
      <td className="py-2.5 px-3">
        {promotion.state !== 'ended' && (
          <Button
            size="sm"
            variant="outline"
            icon={Power}
            isLoading={end.pending}
            onClick={async () => {
              const result = await end.run();
              if (result.ok) onChanged();
            }}
          >
            إنهاء
          </Button>
        )}
        {end.error !== null && <ApiErrorState compact error={end.error} />}
      </td>
    </tr>
  );
};

/**
 * A new promotion of an approved listing of an approved provider. `listing` preselects one (the
 * services page); the API still checks that the provider offers the service, and a promotion only
 * reorders what eligible factories already see.
 */
export const CreatePromotionModal: React.FC<{ onClose: () => void; onCreated: () => void; listing?: ServiceListing }> = ({ onClose, onCreated, listing }) => {
  const [search, setSearch] = useState('');
  const [listingId, setListingId] = useState(listing?.id ?? '');
  const [headline, setHeadline] = useState('');
  const [priority, setPriority] = useState('10');
  const [endsAt, setEndsAt] = useState('');
  const listings = useApiQuery(
    (signal) => api.serviceListings.list({ per_page: 50, search: search || undefined, filter: { approval_status: 'approved', listing_status: 'approved' }, signal }),
    [search],
    { enabled: listing === undefined },
  );
  const chosen = listing ?? listings.data?.data.find((candidate) => candidate.id === listingId);

  const create = useApiMutation(() =>
    api.promotions.create({
      service_provider_id: chosen?.provider?.id ?? 0,
      service: chosen?.service?.code ?? '',
      headline: headline.trim() || null,
      priority: Number(priority) || 0,
      ends_at: endsAt ? new Date(`${endsAt}T23:59:59`).toISOString() : null,
    }),
  );

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="إعلان جديد في بوابة المصانع"
      subtitle="اختر خدمة يقدمها مزود معتمد"
      maxWidth="2xl"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose}>
            إلغاء
          </Button>
          <Button
            variant="primary"
            size="sm"
            isLoading={create.pending}
            disabled={!chosen}
            onClick={async () => {
              const result = await create.run();
              if (result.ok) onCreated();
            }}
          >
            نشر الإعلان
          </Button>
        </div>
      }
    >
      <div className="space-y-3 text-xs">
        {listing ? (
          <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
            <div className="font-bold text-[#172033]">{listing.service?.name_ar}</div>
            <div className="text-[#667085]">{listing.provider?.name}</div>
          </div>
        ) : (
          <>
            <SearchInput value={search} onChange={setSearch} placeholder="ابحث باسم المزود أو الخدمة..." />
            <QueryBoundary query={listings} loading={<CardSkeleton />} isEmpty={(r) => r.data.length === 0} empty={<p className="text-[#667085]">لا توجد خدمات معتمدة مطابقة لدى مزودين معتمدين.</p>}>
              {(result) => (
                <select aria-label="الخدمة والمزود" value={listingId} onChange={(e) => setListingId(e.target.value)} className="w-full py-2 px-3 bg-white border border-[#E6EAF0] rounded-xl">
                  <option value="">— اختر —</option>
                  {result.data.map((candidate) => (
                    <option key={candidate.id} value={candidate.id}>
                      {candidate.service?.name_ar} — {candidate.provider?.name}
                      {candidate.promotion ? ' (عليها إعلان)' : ''}
                    </option>
                  ))}
                </select>
              )}
            </QueryBoundary>
          </>
        )}
        <TextField label="عنوان مختصر (اختياري)" value={headline} onChange={setHeadline} maxLength={120} messages={fieldMessages(create.error, 'headline')} />
        <div className="grid grid-cols-2 gap-3">
          <TextField label="الأولوية (0–1000، الأعلى أولًا)" type="number" value={priority} onChange={setPriority} messages={fieldMessages(create.error, 'priority')} />
          <label className="block">
            <span className="font-bold text-[#172033] block mb-1">ينتهي في (اختياري)</span>
            <input type="date" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} className="w-full p-2 rounded-xl border border-[#E6EAF0]" />
          </label>
        </div>
        {create.error !== null && <ApiErrorState compact error={create.error} />}
      </div>
    </Modal>
  );
};
