import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Building2, Eye, Layers, Megaphone } from 'lucide-react';
import { api } from '../../api';
import type { ServiceListing, ServiceListingStatus, ServiceProvider } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { useCatalogCategories } from '../../hooks/useReference';
import { formatDate } from '../../lib/format';
import { listingStatusLabel } from '../../lib/labels';
import { allowedListingDecisions, type ApprovalDecision } from '../../lib/provider';
import { ApprovalDecisionModal } from '../../components/admin/ApprovalDecisionModal';
import { colorOptions } from '../../components/admin/colorOptions';
import { ListingDetailModal } from '../../components/admin/ListingDetailModal';
import { ReadinessLevelServices } from '../../components/admin/ReadinessLevelServices';
import { CreatePromotionModal } from '../../components/admin/PromotionsPanel';
import { PackageList } from '../../components/listings/PackageList';
import { ServiceListingCard } from '../../components/listings/ServiceListingCard';
import { ListingStatusBadge } from '../../components/ui/ApprovalBadge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';
import { Tabs } from '../../components/ui/Tabs';
import { UnavailableNotice } from '../../components/ui/UnavailableNotice';

const LISTING_STATUSES: ServiceListingStatus[] = ['pending', 'approved', 'rejected', 'suspended'];
const FILTER_KEYS = ['tab', 'listing_status', 'category', 'promoted', 'approval_status'] as const;
const selectClass = 'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * Services in two clearly separate parts (ADR-014, ADR-021):
 * - provider listings: what each provider offers, each with its own IMC decision. Factories see a
 *   listing only when it and its provider are approved; promotions («إعلان») only reorder those.
 * - the services each readiness level makes available (ADR-025): IMC decides them; factories see and
 *   request only those of their level.
 * - the ministry catalog: the 7 categories and 42 services from the approved workbook, read-only here.
 */
export const ServicesManagement: React.FC = () => {
  const list = useListParams(FILTER_KEYS);
  const tab = list.filters.tab === 'catalog' || list.filters.tab === 'levels' ? list.filters.tab : 'listings';

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">الخدمات وقوائم المزودين</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">اعتماد الخدمات التي يدرجها المزودون، والاطلاع على كتالوج الخدمات المعتمد من الوزارة.</p>
        </div>
        <Link to="/admin/ads" className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#5146A5] hover:underline">
          <Megaphone className="w-4 h-4" /> إدارة الإعلانات والترويج
        </Link>
      </div>

      <Tabs
        tabs={[
          { id: 'listings', label: 'خدمات المزودين (تُراجع وتُعتمد)' },
          { id: 'levels', label: 'إتاحة الخدمات حسب مستوى الجاهزية' },
          { id: 'catalog', label: 'كتالوج الوزارة (مرجعي)' },
        ]}
        activeTab={tab}
        onChange={(id) => list.setFilter('tab', id === 'listings' ? '' : id)}
      />

      {tab === 'listings' ? (
        <ProviderListings list={list} />
      ) : tab === 'levels' ? (
        <ReadinessLevelServices />
      ) : (
        <CatalogCards search={list.search} setSearch={list.setSearch} />
      )}
    </div>
  );
};

/* ───────────────────────── Provider listings ───────────────────────── */

const ProviderListings: React.FC<{ list: ReturnType<typeof useListParams<(typeof FILTER_KEYS)[number]>> }> = ({ list }) => {
  const search = useSearchBox(list.search, list.setSearch);
  const categories = useCatalogCategories();
  const status = (list.filters.listing_status || 'pending') as ServiceListingStatus | 'all';
  const [deciding, setDeciding] = useState<{ listing: ServiceListing; decision: ApprovalDecision } | null>(null);
  const [viewing, setViewing] = useState<ServiceListing | null>(null);
  const [promoting, setPromoting] = useState<ServiceListing | null>(null);
  const { hasPermission } = useAuth();
  const canReview = hasPermission('service_listings.review');
  const canPromote = hasPermission('promotions.manage');
  // The review queue reads oldest submissions first by default; any other view in catalog order.
  const sort = list.sort || (status === 'pending' ? 'newest' : 'catalog');

  const counts = useApiQuery(async (signal): Promise<Record<ServiceListingStatus, number>> => (await api.reviewSummary(signal)).listings, []);
  const query = useApiQuery(
    (signal) => {
      const q = toApiQuery({ ...list, sort: '', filters: { category: list.filters.category, approval_status: list.filters.approval_status } });
      return api.serviceListings.list({
        ...q,
        sort,
        filter: {
          ...(q.filter ?? {}),
          ...(status === 'all' ? {} : { listing_status: status }),
          ...(list.filters.promoted === '1' ? { promoted: true } : {}),
        },
        signal,
      });
    },
    [list.page, list.perPage, list.search, status, sort, list.filters.category, list.filters.promoted, list.filters.approval_status],
  );

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar text-xs">
        {[...LISTING_STATUSES, 'all' as const].map((s) => (
          <button
            key={s}
            type="button"
            onClick={() => list.setFilter('listing_status', s === 'pending' ? '' : s)}
            className={`px-3.5 py-2 rounded-xl font-semibold shrink-0 transition-all cursor-pointer ${
              status === s ? 'bg-[#5146A5] text-white shadow-xs' : 'bg-white text-[#667085] border border-[#E6EAF0] hover:bg-[#F7F9FC]'
            }`}
          >
            {s === 'all' ? 'الكل' : listingStatusLabel(s)}
            {s !== 'all' && counts.data && <span className="mr-1.5 opacity-80">({counts.data[s]})</span>}
          </button>
        ))}
      </div>

      <Card className="p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم المزود أو الخدمة أو كودها..." className="w-full lg:col-span-2" />
          <select aria-label="التصنيف" value={list.filters.category} onChange={(e) => list.setFilter('category', e.target.value)} className={selectClass}>
            <option value="">كل التصنيفات</option>
            {(categories.data ?? []).map((category) => (
              <option key={category.code} value={category.code}>
                {category.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="اعتماد المزود" value={list.filters.approval_status} onChange={(e) => list.setFilter('approval_status', e.target.value)} className={selectClass}>
            <option value="">كل حالات المزودين</option>
            <option value="approved">مزود معتمد</option>
            <option value="pending">مزود بانتظار المراجعة</option>
            <option value="changes_requested">مطلوب استكمال بيانات</option>
            <option value="rejected">مزود مرفوض</option>
            <option value="suspended">مزود موقوف</option>
          </select>
          <select aria-label="الترتيب" value={list.sort} onChange={(e) => list.setSort(e.target.value)} className={selectClass}>
            <option value="">{status === 'pending' ? 'الأقدم تقديمًا أولًا (الافتراضي)' : 'ترتيب الكتالوج (الافتراضي)'}</option>
            <option value="newest">حسب تاريخ الإدراج</option>
            <option value="catalog">ترتيب الكتالوج</option>
          </select>
        </div>
        <label className="mt-3 inline-flex items-center gap-2 text-xs text-[#172033] cursor-pointer">
          <input type="checkbox" checked={list.filters.promoted === '1'} onChange={(e) => list.setFilter('promoted', e.target.checked ? '1' : '')} />
          الخدمات التي عليها إعلان نشط فقط
        </label>
      </Card>

      <QueryBoundary
        query={query}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          <EmptyState
            title={status === 'pending' ? 'لا توجد خدمات بانتظار الاعتماد' : 'لا توجد خدمات'}
            description="لا توجد خدمات مزودين تطابق هذه الحالة أو البحث."
            actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : undefined}
            onAction={list.hasActiveFilters ? list.reset : undefined}
          />
        }
      >
        {(page) => (
          <>
            <div className="grid gap-5 justify-items-center [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">
              {page.data.map((listing) => {
                const review = listing.review;
                const providerPath = `/admin/approvals/providers/${listing.provider?.id}`;
                return (
                  <div key={listing.id} className="w-full max-w-[360px]">
                    <ServiceListingCard
                      listing={listing}
                      detailsPath={providerPath}
                      showApproval
                      footer={
                        review && (
                          <div className="space-y-2 text-[11px]" data-listing-status={review.status}>
                            <PackageList packages={listing.packages} compact />
                            <div className="flex items-center justify-between gap-2">
                              <span className="text-[#667085]">اعتماد الخدمة:</span>
                              <ListingStatusBadge status={review.status} size="sm" />
                            </div>
                            <div className="text-[10px] text-[#98A2B3]">
                              أُدرجت {formatDate(review.submitted_at)}
                              {review.changed_at ? ` · آخر قرار ${formatDate(review.changed_at)}` : ''}
                            </div>
                            {review.reason && <div className="text-[#667085]" dir="auto">السبب: {review.reason}</div>}
                            <div className="flex flex-wrap gap-1.5 pt-1" onClick={(e) => e.stopPropagation()}>
                              <Button size="sm" variant="secondary" icon={Eye} onClick={() => setViewing(listing)}>
                                التفاصيل والسجل
                              </Button>
                              {canReview && allowedListingDecisions(review.status).map((decision) => (
                                <Button
                                  key={decision}
                                  size="sm"
                                  variant={decision === 'approved' ? 'success' : decision === 'rejected' ? 'danger' : 'outline'}
                                  onClick={() => setDeciding({ listing, decision })}
                                  data-listing-decision={decision}
                                >
                                  {decision === 'approved' ? 'اعتماد' : decision === 'rejected' ? 'رفض' : 'إيقاف'}
                                </Button>
                              ))}
                              <Link to={providerPath} className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl border border-[#E6EAF0] text-[#5146A5] font-semibold hover:bg-[#F7F9FC]">
                                <Building2 className="w-3.5 h-3.5" /> ملف المزود
                              </Link>
                              {canPromote && !listing.promotion && review.status === 'approved' && listing.provider?.approval_status === 'approved' && (
                                <Button size="sm" variant="outline" icon={Megaphone} onClick={() => setPromoting(listing)}>
                                  ترويج
                                </Button>
                              )}
                            </div>
                          </div>
                        )
                      }
                    />
                  </div>
                );
              })}
            </div>
            <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
          </>
        )}
      </QueryBoundary>

      {viewing && <ListingDetailModal listing={viewing} onClose={() => setViewing(null)} />}
      {promoting && (
        <CreatePromotionModal
          listing={promoting}
          onClose={() => setPromoting(null)}
          onCreated={() => {
            setPromoting(null);
            query.refetch();
          }}
        />
      )}
      {deciding && deciding.listing.provider && deciding.listing.service && (
        <ApprovalDecisionModal<ServiceProvider>
          subject="listing"
          decision={deciding.decision}
          subtitle={`${deciding.listing.service.name_ar} — ${deciding.listing.provider.name}`}
          submit={(payload) =>
            api.serviceProviders.reviewListing(deciding.listing.provider!.id, deciding.listing.service!.id, {
              decision: payload.decision === 'changes_requested' ? 'rejected' : payload.decision,
              reason: payload.reason,
            })
          }
          onClose={() => setDeciding(null)}
          onDone={() => {
            setDeciding(null);
            query.refetch();
            counts.refetch();
          }}
        />
      )}
    </div>
  );
};

/* ───────────────────────── Ministry catalog ───────────────────────── */

const CatalogCards: React.FC<{ search: string; setSearch: (value: string) => void }> = ({ search, setSearch }) => {
  const categories = useCatalogCategories();
  const [category, setCategory] = useState('');
  const needle = search.trim().toLowerCase();

  return (
    <div className="space-y-5">
      <UnavailableNotice kind="gap" title="تعديل كتالوج الوزارة">
        الكتالوج مرجعي ومصدره ملف الخدمات المعتمد (ADR-014): لا تُضاف خدماته ولا تُعدَّل ولا تُعطَّل من الواجهة، ولا يوجد في الخادم مسار لذلك. أي تغيير
        يتم بتحديث الملف المعتمد وبذر الكتالوج من جديد. أما ظهور خدمة مزود بعينها فيُدار من تبويب «خدمات المزودين».
      </UnavailableNotice>

      <QueryBoundary query={categories} loading={<CardSkeleton />}>
        {(data) => {
          const total = data.reduce((n, c) => n + (c.services?.length ?? 0), 0);
          const rows = data
            .filter((c) => category === '' || c.code === category)
            .flatMap((c) => (c.services ?? []).map((s) => ({ ...s, category: c })))
            .filter((s) => needle === '' || s.name_ar.toLowerCase().includes(needle) || s.code.toLowerCase().includes(needle));

          return (
            <>
              <Card className="p-4">
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <SearchInput value={search} onChange={setSearch} placeholder="بحث بكود الخدمة أو اسمها..." className="w-full sm:col-span-2" />
                  <select aria-label="التصنيف" value={category} onChange={(e) => setCategory(e.target.value)} className={selectClass}>
                    <option value="">كافة التصنيفات ({total})</option>
                    {data.map((c) => (
                      <option key={c.code} value={c.code}>
                        {c.name_ar} ({c.services?.length ?? 0})
                      </option>
                    ))}
                  </select>
                </div>
              </Card>

              {rows.length === 0 ? (
                <EmptyState title="لا توجد خدمات مطابقة" description="جرّب تعديل البحث أو التصنيف." />
              ) : (
                <div className="space-y-6" data-testid="catalog-cards">
                  {data
                    .filter((c) => rows.some((row) => row.category.code === c.code))
                    .map((c, index) => {
                      const palette = colorOptions[index % colorOptions.length];
                      const services = rows.filter((row) => row.category.code === c.code);
                      return (
                        <section key={c.code} className="space-y-3">
                          <h3 className="text-sm font-bold text-[#172033] flex items-center gap-2">
                            <span className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: palette.hex }} />
                            {c.name_ar}
                            <span className="text-[#98A2B3] font-normal">({services.length})</span>
                          </h3>
                          <div className="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3">
                            {services.map((service) => (
                              <article
                                key={service.id}
                                data-service-id={service.id}
                                className="jahez-card p-4 flex items-start gap-3"
                                style={{ borderInlineStart: `4px solid ${palette.hex}` }}
                              >
                                <div className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0" style={{ backgroundColor: palette.soft, color: palette.ink }}>
                                  <Layers className="w-4 h-4" aria-hidden="true" />
                                </div>
                                <div className="min-w-0 space-y-1">
                                  <h4 className="text-sm font-bold text-[#172033] leading-relaxed">{service.name_ar}</h4>
                                  <div className="font-mono text-[11px] text-[#5146A5]" dir="ltr">
                                    {service.code}
                                  </div>
                                </div>
                              </article>
                            ))}
                          </div>
                        </section>
                      );
                    })}
                  <p className="text-xs text-[#667085]">{rows.length} من {total} خدمة (قائمة مرجعية محدودة تُعرض كاملة، للاطلاع فقط)</p>
                </div>
              )}
            </>
          );
        }}
      </QueryBoundary>
    </div>
  );
};
