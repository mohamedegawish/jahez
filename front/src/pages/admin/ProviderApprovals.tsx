import React from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api';
import type { ProviderApprovalStatus } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { useCatalogCategories } from '../../hooks/useReference';
import { approvalLabel } from '../../lib/labels';
import { providerCompletion } from '../../lib/profileCompletion';
import { OrganizationReviewCard } from '../../components/admin/OrganizationReviewCard';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';
import { Tabs } from '../../components/ui/Tabs';

const STATUSES: ProviderApprovalStatus[] = ['pending', 'changes_requested', 'approved', 'rejected', 'suspended'];
const FILTER_KEYS = ['status', 'category', 'listing_status'] as const;
const selectClass = 'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * IMC review queue of service providers (ADR-014, ADR-021), separate from the factory queue. Each card
 * summarises one provider; the decision, documents, listings and history are on its details page.
 */
export const ProviderApprovals: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const categories = useCatalogCategories();
  const status = list.filters.status || 'pending';

  const counts = useApiQuery(async (signal): Promise<Record<ProviderApprovalStatus, number>> => (await api.reviewSummary(signal)).providers, []);

  const query = useApiQuery(
    (signal) => {
      const q = toApiQuery({ ...list, filters: { category: list.filters.category, listing_status: list.filters.listing_status } });
      return api.serviceProviders.list({
        ...q,
        sort: list.sort || 'newest',
        filter: { ...(q.filter ?? {}), ...(status === 'all' ? {} : { approval_status: status }) },
        signal,
      });
    },
    [list.page, list.perPage, list.search, list.sort, status, list.filters.category, list.filters.listing_status],
  );

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">اعتماد مزودي الخدمات</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          مراجعة ملفات الشركات ومستنداتها وخدماتها، ثم اعتمادها أو طلب استكمالها أو رفضها أو إيقافها بقرار مسبَّب.
        </p>
      </div>

      <Tabs
        tabs={[
          ...STATUSES.map((s) => ({ id: s, label: approvalLabel(s), count: counts.data?.[s] })),
          { id: 'all', label: 'الكل' },
        ]}
        activeTab={status}
        onChange={(id) => list.setFilter('status', id === 'pending' ? '' : id)}
      />

      <Card className="p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم الشركة..." className="w-full lg:col-span-2" />
          <select aria-label="تصنيف الخدمات" value={list.filters.category} onChange={(e) => list.setFilter('category', e.target.value)} className={selectClass}>
            <option value="">كل تصنيفات الخدمات</option>
            {(categories.data ?? []).map((category) => (
              <option key={category.code} value={category.code}>
                {category.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="الترتيب" value={list.sort || 'newest'} onChange={(e) => list.setSort(e.target.value === 'newest' ? '' : e.target.value)} className={selectClass}>
            <option value="newest">الأحدث تسجيلًا</option>
            <option value="oldest">الأقدم تسجيلًا</option>
            <option value="recently_decided">آخر قرار</option>
            <option value="name">الاسم</option>
          </select>
          <label className="flex items-center gap-2 text-xs text-[#172033] px-3 py-2 rounded-xl border border-[#E6EAF0] bg-white cursor-pointer">
            <input
              type="checkbox"
              checked={list.filters.listing_status === 'pending'}
              onChange={(e) => list.setFilter('listing_status', e.target.checked ? 'pending' : '')}
            />
            لديه خدمات بانتظار الاعتماد
          </label>
        </div>
        {list.hasActiveFilters && (
          <div className="mt-3 flex justify-end">
            <Button variant="ghost" size="sm" onClick={list.reset}>
              إعادة ضبط الفلاتر
            </Button>
          </div>
        )}
      </Card>

      <QueryBoundary
        query={query}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          <EmptyState
            title="لا توجد ملفات"
            description={list.hasActiveFilters ? 'لا يوجد مزودون يطابقون البحث أو الفلاتر.' : 'لا يوجد مزودون بهذه الحالة حاليًا.'}
            actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : undefined}
            onAction={list.hasActiveFilters ? list.reset : undefined}
          />
        }
      >
        {(page) => (
          <>
            <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3">
              {page.data.map((provider) => {
                const logo = provider.documents?.logo ?? null;
                const listings = provider.service_listings ?? [];
                const pendingListings = listings.filter((listing) => listing.status === 'pending').length;
                return (
                  <OrganizationReviewCard
                    key={provider.id}
                    kind="provider"
                    id={provider.id}
                    name={provider.name}
                    loadLogo={logo ? (signal) => api.serviceProviders.documentFile(provider.id, logo.id, signal) : undefined}
                    logoVersion={logo?.id}
                    status={provider.approval.status}
                    submittedAt={provider.created_at}
                    facts={[
                      provider.governorate,
                      provider.dx_experience_years === null ? null : `خبرة ${provider.dx_experience_years} سنة`,
                      (provider.sectors ?? []).length > 0 ? `${(provider.sectors ?? []).length} قطاع` : null,
                    ].filter((fact): fact is string => fact !== null && fact !== '')}
                    chips={listings.map((listing) => ({ label: listing.service.name_ar, tone: listing.status === 'approved' ? 'purple' : 'warning' }))}
                    completion={providerCompletion(provider)}
                    footnote={
                      pendingListings > 0 ? (
                        <span className="text-[#A66F0B] font-semibold">{pendingListings} خدمة بانتظار الاعتماد</span>
                      ) : undefined
                    }
                    onDetails={() => navigate(`/admin/approvals/providers/${provider.id}`)}
                  />
                );
              })}
            </div>
            <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
          </>
        )}
      </QueryBoundary>
    </div>
  );
};
