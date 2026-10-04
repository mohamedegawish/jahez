import React, { useState } from 'react';
import { api } from '../../api';
import type { ProviderDirectoryEntry } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { useMyFactory } from '../../hooks/useMyOrganization';
import { useCatalogCategories, useCatalogServices } from '../../hooks/useReference';
import { DirectoryProfileModal } from '../../components/provider/DirectoryProfileModal';
import { ProviderCard } from '../../components/provider/ProviderCard';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';

const FILTER_KEYS = ['service', 'category', 'sector', 'recommended'] as const;
const SORTS = [
  { value: '', label: 'الاسم (أ - ي)' },
  { value: '-name', label: 'الاسم (ي - أ)' },
  { value: '-dx_experience_years', label: 'الأكثر خبرة' },
  { value: 'dx_experience_years', label: 'الأقل خبرة' },
];
const selectClass =
  'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * Provider directory for a factory. The API decides who appears: approved providers that target
 * one of the factory's sectors. Contact details are not shown unless the owner enables them (OQ-37).
 */
export const ProviderDirectory: React.FC = () => {
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const factory = useMyFactory();
  const categories = useCatalogCategories();
  const services = useCatalogServices();
  const [open, setOpen] = useState<ProviderDirectoryEntry | null>(null);

  const hasAssessment = Boolean(factory.data?.current_readiness);
  // Both the sector filter and `recommended` are validated against the factory by the API (422 otherwise).
  const ownSectors = factory.data?.sectors ?? [];
  const sector = ownSectors.some((s) => s.code === list.filters.sector) ? list.filters.sector : '';
  const recommended = list.filters.recommended === '1' && hasAssessment;

  const query = useApiQuery(
    (signal) => {
      const base = toApiQuery(list);
      return api.directory.list({
        ...base,
        filter: {
          service: list.filters.service || undefined,
          category: list.filters.category || undefined,
          sector: sector || undefined,
          recommended: recommended || undefined,
        },
        signal,
      });
    },
    [list.page, list.perPage, list.search, list.sort, list.filters.service, list.filters.category, sector, recommended],
    { enabled: factory.status !== 'loading' },
  );

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">دليل مزودي الخدمات المؤهلين</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          تظهر هنا الشركات المعتمدة من مركز تحديث الصناعة التي تستهدف قطاعات منشأتك فقط. اختر مزودًا للاطلاع على ملفه.
        </p>
      </div>

      <Card className="p-4 space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم المزود..." className="w-full" />
          <select aria-label="الخدمة" value={list.filters.service} onChange={(e) => list.setFilter('service', e.target.value)} className={selectClass}>
            <option value="">كافة الخدمات</option>
            {(services.data ?? []).map((service) => (
              <option key={service.code} value={service.code}>
                {service.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="التصنيف" value={list.filters.category} onChange={(e) => list.setFilter('category', e.target.value)} className={selectClass}>
            <option value="">كافة التصنيفات</option>
            {(categories.data ?? []).map((category) => (
              <option key={category.code} value={category.code}>
                {category.name_ar}
              </option>
            ))}
          </select>
          <select aria-label="القطاع" value={sector} onChange={(e) => list.setFilter('sector', e.target.value)} className={selectClass}>
            <option value="">كل قطاعات منشأتي</option>
            {ownSectors.map((item) => (
              <option key={item.code} value={item.code}>
                {item.name_ar}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-wrap items-center gap-3 text-xs">
          <button
            type="button"
            aria-pressed={recommended}
            disabled={!hasAssessment}
            onClick={() => list.setFilter('recommended', recommended ? '' : '1')}
            title={hasAssessment ? 'مزودون يقدمون خدمات توصي بها فئة جاهزيتك' : 'أكمل تقييم الجاهزية الرقمية أولًا'}
            className={`px-3 py-1.5 rounded-xl font-semibold cursor-pointer transition-all disabled:opacity-50 disabled:cursor-not-allowed ${
              recommended ? 'bg-[#5146A5] text-white shadow-xs' : 'bg-[#F7F9FC] text-[#667085] hover:bg-[#E6EAF0]'
            }`}
          >
            خدماتهم موصى بها لمنشأتي
          </button>

          <label className="flex items-center gap-1.5 text-[#667085]">
            <span>الترتيب:</span>
            <select aria-label="الترتيب" value={list.sort} onChange={(e) => list.setSort(e.target.value)} className="py-1.5 px-2 rounded-lg border border-[#E6EAF0] bg-white text-[#172033]">
              {SORTS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <Button variant="outline" size="sm" onClick={list.reset} disabled={!list.hasActiveFilters}>
            إعادة ضبط الفلاتر
          </Button>
        </div>
      </Card>

      <QueryBoundary
        query={query}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          list.page > 1 ? (
            <EmptyState title="هذه الصفحة فارغة" description="رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة." actionText="العودة إلى الصفحة الأولى" onAction={() => list.setPage(1)} />
          ) : (
            <EmptyState
              title={list.hasActiveFilters ? 'لا يوجد مزودون مطابقون' : 'لا يوجد مزودون مؤهلون حاليًا'}
              description={
                list.hasActiveFilters
                  ? 'جرّب تعديل الفلاتر.'
                  : 'لا يوجد مزود معتمد يستهدف قطاعات منشأتك بعد. إن لم تحدد قطاعات منشأتك فحدّدها من ملف المنشأة.'
              }
              actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : undefined}
              onAction={list.hasActiveFilters ? list.reset : undefined}
            />
          )
        }
      >
        {(page) => (
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
              {page.data.map((provider) => (
                <ProviderCard key={provider.id} provider={provider} highlightService={list.filters.service || undefined} onOpen={setOpen} />
              ))}
            </div>
            <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
          </div>
        )}
      </QueryBoundary>

      {open && <DirectoryProfileModal providerId={open.id} onClose={() => setOpen(null)} />}
    </div>
  );
};
