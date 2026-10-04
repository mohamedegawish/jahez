import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ClipboardCheck, Compass, Eye, Handshake, Send } from 'lucide-react';
import { api } from '../../api';
import type { ServiceListing } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { useCatalogCategories } from '../../hooks/useReference';
import { RequestFormModal } from '../../components/factory/RequestFormModal';
import { ServiceListingCard } from '../../components/listings/ServiceListingCard';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';

const FILTER_KEYS = ['category', 'recommended', 'promoted'] as const;

const chip = (active: boolean) =>
  `px-3 py-1.5 rounded-xl font-semibold cursor-pointer shrink-0 transition-all ${
    active ? 'bg-[#5146A5] text-white shadow-xs' : 'bg-[#F7F9FC] text-[#667085] hover:bg-[#E6EAF0]'
  }`;

/**
 * The services available to this factory: one card per eligible provider listing (approved provider,
 * one of the factory's sectors, offering the service). IMC promotions come first and are labelled
 * «إعلان»; they never add a listing the factory is not eligible for. The order, eligibility and
 * pagination all come from the API.
 */
export const FactoryServices: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const categories = useCatalogCategories();
  const [requesting, setRequesting] = useState<ServiceListing | null>(null);

  const listings = useApiQuery(
    (signal) =>
      api.serviceListings.list({
        ...toApiQuery(list),
        filter: {
          category: list.filters.category || undefined,
          recommended: list.filters.recommended === '1' || undefined,
          promoted: list.filters.promoted === '1' || undefined,
        },
        per_page: 12,
        signal,
      }),
    [list.page, list.search, list.filters.category, list.filters.recommended, list.filters.promoted],
  );

  const meta = listings.data?.meta;
  const assessed = Boolean(meta?.readiness);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#EEEAFE] text-[#5146A5] text-xs font-bold mb-2">
            الخدمات المتاحة لمنشأتكم
          </div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">الخدمات</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
            خدمات الكتالوج المعتمد كما يقدمها المزودون المعتمدون في قطاعات منشأتكم. اختر خدمة لتقديم طلبك.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" icon={Compass} onClick={() => navigate('/factory/catalog')}>
            الكتالوج الكامل
          </Button>
          <Button variant="outline" size="sm" icon={Handshake} onClick={() => navigate('/factory/providers')}>
            دليل المزودين
          </Button>
        </div>
      </div>

      {listings.status === 'success' && !assessed && (
        <div className="p-4 rounded-2xl bg-gradient-to-l from-[#EEEAFE] to-[#DFF3FF] border border-[#DDD5FD] flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="text-xs">
            <div className="font-bold text-[#172033] text-sm">لم تُكمل منشأتكم تقييم الجاهزية الرقمية بعد</div>
            <p className="text-[#667085] mt-0.5">
              يحدد التقييم فئة جاهزيتكم والخدمات الموصى بها لخارطة طريقكم. تظهر الخدمات المتاحة أدناه، لكن لا تُحدَّد التوصيات قبل التقييم.
            </p>
          </div>
          <Button variant="primary" size="sm" icon={ClipboardCheck} onClick={() => navigate('/factory/assessment')}>
            ابدأ التقييم
          </Button>
        </div>
      )}
      {assessed && meta?.readiness?.category && (
        <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-xs text-[#667085]">
          فئة الجاهزية الحالية: <strong className="text-[#5146A5]">{meta.readiness.category.name_ar}</strong> ({meta.readiness.total_score} من 40). فعّل «الموصى بها» لعرض خدمات خارطة طريقكم فقط.
        </div>
      )}

      <Card className="p-4 space-y-3">
        <div className="flex flex-col sm:flex-row gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="ابحث باسم الخدمة أو المزود..." className="flex-1" />
          <Button variant="outline" size="sm" onClick={list.reset} disabled={!list.hasActiveFilters}>
            إعادة ضبط الفلاتر
          </Button>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <button
            type="button"
            aria-pressed={list.filters.recommended === '1'}
            disabled={!assessed}
            onClick={() => list.setFilter('recommended', list.filters.recommended === '1' ? '' : '1')}
            className={`${chip(list.filters.recommended === '1')} disabled:opacity-50 disabled:cursor-not-allowed`}
            title={assessed ? 'خدمات خارطة طريق فئة جاهزيتكم' : 'أكمل تقييم الجاهزية الرقمية أولًا'}
          >
            الموصى بها لمنشأتي
          </button>
          <button
            type="button"
            aria-pressed={list.filters.promoted === '1'}
            onClick={() => list.setFilter('promoted', list.filters.promoted === '1' ? '' : '1')}
            className={chip(list.filters.promoted === '1')}
          >
            الإعلانات فقط
          </button>
        </div>
        <div className="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar text-xs">
          <button type="button" onClick={() => list.setFilter('category', '')} className={chip(list.filters.category === '')}>
            كل الفئات
          </button>
          {(categories.data ?? []).map((category) => (
            <button key={category.code} type="button" onClick={() => list.setFilter('category', category.code)} className={chip(list.filters.category === category.code)}>
              {category.name_ar}
            </button>
          ))}
        </div>
      </Card>

      <QueryBoundary
        query={listings}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          list.page > 1 ? (
            <EmptyState title="هذه الصفحة فارغة" description="رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة." actionText="العودة إلى الصفحة الأولى" onAction={() => list.setPage(1)} />
          ) : meta?.has_sectors === false ? (
            <EmptyState
              title="حدّدوا قطاعات منشأتكم أولًا"
              description="لا يكون أي مزود مؤهلًا لمنشأة بلا قطاع. أضيفوا قطاعاتكم من الإعدادات لتظهر الخدمات المتاحة."
              actionText="فتح الإعدادات"
              onAction={() => navigate('/factory/settings')}
            />
          ) : list.hasActiveFilters ? (
            <EmptyState title="لا توجد خدمات مطابقة" description="جرّبوا تغيير البحث أو الفلاتر." actionText="إعادة ضبط الفلاتر" onAction={list.reset} />
          ) : (
            <EmptyState
              title="لا توجد خدمات متاحة لمنشأتكم حاليًا"
              description="لا يوجد مزود معتمد يقدم خدمات في قطاعات منشأتكم بعد. تصفحوا الكتالوج الكامل أو عودوا لاحقًا بعد اعتماد مزودين جدد."
              actionText="الكتالوج الكامل"
              onAction={() => navigate('/factory/catalog')}
            />
          )
        }
      >
        {(page) => (
          <div className="space-y-4">
            <div className="text-xs text-[#667085]">
              {page.meta.total} خدمة متاحة
              {page.data.some((listing) => listing.promotion) && ' · تظهر الإعلانات أولًا وتحمل علامة «إعلان»'}
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
              {page.data.map((listing) => (
                <ServiceListingCard
                  key={listing.id}
                  listing={listing}
                  detailsPath={`/factory/services/${listing.service?.id}`}
                  footer={
                    <div className="flex items-center gap-2">
                      <Button variant="outline" size="sm" icon={Eye} className="flex-1" onClick={() => navigate(`/factory/services/${listing.service?.id}`)}>
                        التفاصيل
                      </Button>
                      <Button variant="primary" size="sm" icon={Send} className="flex-1" data-action="request" onClick={() => setRequesting(listing)}>
                        طلب الخدمة
                      </Button>
                    </div>
                  }
                />
              ))}
            </div>
            <Pagination meta={page.meta} onPage={list.setPage} />
          </div>
        )}
      </QueryBoundary>

      {requesting?.service && requesting.provider && (
        <RequestFormModal
          service={{ id: requesting.service.id, code: requesting.service.code, name_ar: requesting.service.name_ar }}
          initialProviderIds={[requesting.provider.id]}
          onClose={() => setRequesting(null)}
          onCreated={(request) => {
            setRequesting(null);
            navigate(`/factory/requests/${request.id}`);
          }}
        />
      )}
    </div>
  );
};
