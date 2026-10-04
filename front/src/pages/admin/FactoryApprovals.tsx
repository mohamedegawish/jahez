import React from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api';
import type { FactoryApprovalStatus } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { nameOf, useFactorySizes, useSectors } from '../../hooks/useReference';
import { approvalLabel } from '../../lib/labels';
import { factorySummaryCompletion } from '../../lib/profileCompletion';
import { OrganizationReviewCard } from '../../components/admin/OrganizationReviewCard';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ReadinessBadge } from '../../components/ui/ReadinessBadge';
import { SearchInput } from '../../components/ui/SearchInput';
import { Tabs } from '../../components/ui/Tabs';

const STATUSES: FactoryApprovalStatus[] = ['pending', 'changes_requested', 'approved', 'rejected', 'suspended'];
const FILTER_KEYS = ['status', 'sector', 'readiness'] as const;
const READINESS_FILTERS = [
  { value: '', label: 'كل فئات الجاهزية' },
  { value: 'none', label: 'لم تُكمل التقييم' },
  { value: 'b4_automation', label: 'ما قبل الأتمتة' },
  { value: 'basic', label: 'مبتدئ — رقمنة أساسية' },
  { value: 'advanced', label: 'متقدم' },
  { value: 'smart', label: 'ذكي ومبتكر' },
];
const selectClass = 'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * IMC review queue of factories (ADR-021), separate from the provider queue. The approval is about the
 * account and profile; the readiness category shown is the server's score-based classification, which
 * no approval decision changes.
 */
export const FactoryApprovals: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const sectors = useSectors();
  const sizes = useFactorySizes();
  const status = list.filters.status || 'pending';

  const counts = useApiQuery(async (signal): Promise<Record<FactoryApprovalStatus, number>> => (await api.reviewSummary(signal)).factories, []);

  const query = useApiQuery(
    (signal) => {
      const q = toApiQuery({ ...list, filters: { sector: list.filters.sector, readiness: list.filters.readiness } });
      return api.factories.list({
        ...q,
        sort: list.sort || 'newest',
        filter: { ...(q.filter ?? {}), ...(status === 'all' ? {} : { approval_status: status }) },
        signal,
      });
    },
    [list.page, list.perPage, list.search, list.sort, status, list.filters.sector, list.filters.readiness],
  );

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">اعتماد المنشآت الصناعية</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          مراجعة حسابات المصانع وبياناتها ومستنداتها. قرار الاعتماد منفصل عن فئة الجاهزية الرقمية التي يحسبها الخادم من التقييم.
        </p>
      </div>

      <Tabs
        tabs={[...STATUSES.map((s) => ({ id: s, label: approvalLabel(s), count: counts.data?.[s] })), { id: 'all', label: 'الكل' }]}
        activeTab={status}
        onChange={(id) => list.setFilter('status', id === 'pending' ? '' : id)}
      />

      <Card className="p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم المنشأة..." className="w-full lg:col-span-2" />
          <select aria-label="القطاع الصناعي" value={list.filters.sector} onChange={(e) => list.setFilter('sector', e.target.value)} className={selectClass}>
            <option value="">كل القطاعات</option>
            {(sectors.data ?? []).map((sector) => (
              <option key={sector.code} value={sector.code}>
                {sector.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="فئة الجاهزية" value={list.filters.readiness} onChange={(e) => list.setFilter('readiness', e.target.value)} className={selectClass}>
            {READINESS_FILTERS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <select aria-label="الترتيب" value={list.sort || 'newest'} onChange={(e) => list.setSort(e.target.value === 'newest' ? '' : e.target.value)} className={selectClass}>
            <option value="newest">الأحدث تسجيلًا</option>
            <option value="oldest">الأقدم تسجيلًا</option>
            <option value="recently_decided">آخر قرار</option>
            <option value="name">الاسم</option>
          </select>
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
            title="لا توجد منشآت"
            description={list.hasActiveFilters ? 'لا توجد منشآت تطابق البحث أو الفلاتر.' : 'لا توجد منشآت بهذه الحالة حاليًا.'}
            actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : undefined}
            onAction={list.hasActiveFilters ? list.reset : undefined}
          />
        }
      >
        {(page) => (
          <>
            <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3">
              {page.data.map((factory) => {
                const logo = factory.logo ?? null;
                return (
                  <OrganizationReviewCard
                    key={factory.id}
                    kind="factory"
                    id={factory.id}
                    name={factory.name}
                    loadLogo={logo ? (signal) => api.factories.documentFile(factory.id, logo.id, signal) : undefined}
                    logoVersion={logo?.id}
                    status={factory.approval.status}
                    submittedAt={factory.created_at}
                    facts={[factory.governorate, factory.city, factory.size ? nameOf(sizes.data, factory.size) : null].filter(
                      (fact): fact is string => fact !== null && fact !== '',
                    )}
                    chips={(factory.sectors ?? []).map((sector) => ({ label: sector.name_ar, tone: 'blue' as const }))}
                    completion={factorySummaryCompletion(factory)}
                    footnote={
                      <span className="flex items-center gap-2 text-[#667085]">
                        الجاهزية الرقمية:
                        {factory.current_readiness ? (
                          <>
                            <ReadinessBadge category={factory.current_readiness.category} size="sm" />
                            <span className="font-bold text-[#172033]">{factory.current_readiness.total_score}</span>
                          </>
                        ) : (
                          <span className="font-semibold text-[#A66F0B]">لم يكتمل التقييم</span>
                        )}
                      </span>
                    }
                    onDetails={() => navigate(`/admin/approvals/factories/${factory.id}`)}
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
