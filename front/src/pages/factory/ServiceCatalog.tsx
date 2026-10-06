import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Users } from 'lucide-react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useListParams } from '../../hooks/useListParams';
import { useMyFactory } from '../../hooks/useMyOrganization';
import { useCatalogCategories } from '../../hooks/useReference';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';

const FILTER_KEYS = ['category', 'eligible', 'recommended'] as const;

const chip = (active: boolean) =>
  `px-3 py-1.5 rounded-xl font-semibold cursor-pointer shrink-0 transition-all ${
    active ? 'bg-[#5146A5] text-white shadow-xs' : 'bg-[#F7F9FC] text-[#667085] hover:bg-[#E6EAF0]'
  }`;

export const ServiceCatalog: React.FC = () => {
  const navigate = useNavigate();
  const list = useListParams(FILTER_KEYS);
  const categories = useCatalogCategories();
  const factory = useMyFactory();
  const hasAssessment = Boolean(factory.data?.current_readiness);

  const eligible = list.filters.eligible === '1';
  // The API refuses filter[recommended] until the factory has a readiness assessment (422).
  const recommended = list.filters.recommended === '1' && hasAssessment;

  const services = useApiQuery(
    (signal) =>
      api.catalog.services({
        filter: { category: list.filters.category || undefined, eligible: eligible || undefined, recommended: recommended || undefined },
        signal,
      }),
    [list.filters.category, eligible, recommended],
    // Wait for the factory so a pre-assessment `recommended` URL never reaches the API.
    { enabled: factory.status !== 'loading' },
  );

  const total = (categories.data ?? []).reduce((n, c) => n + (c.services?.length ?? 0), 0);
  const needle = list.search.trim().toLowerCase();

  return (
    <div className="space-y-6">
      <div>
        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#EEEAFE] text-[#5146A5] text-xs font-bold mb-2">
          كتالوج الخدمات الرقمية الصناعية المعتمد
        </div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">كتالوج خدمات التحول للثورة الصناعية الرابعة</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          خدمات الكتالوج المعتمد التي أتاحتها الوزارة لمستوى جاهزية منشأتك وللمستويات السابقة له. اختر خدمة لتعرف المزودين المؤهلين لمنشأتك وتقدّم طلبك.
        </p>
      </div>

      <Card className="p-4 space-y-3">
        <div className="flex flex-col sm:flex-row gap-3">
          <SearchInput
            value={list.search}
            onChange={list.setSearch}
            placeholder="ابحث باسم الخدمة أو كودها..."
            className="flex-1"
          />
          <Button variant="outline" size="sm" onClick={list.reset} disabled={!list.hasActiveFilters}>
            إعادة ضبط الفلاتر
          </Button>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs">
          <button
            type="button"
            aria-pressed={eligible}
            onClick={() => list.setFilter('eligible', eligible ? '' : '1')}
            className={chip(eligible)}
            title="الخدمات التي يقدمها مزود واحد على الأقل مؤهل لمنشأتك"
          >
            المتاحة لمنشأتي
          </button>
          <button
            type="button"
            aria-pressed={recommended}
            disabled={!hasAssessment}
            onClick={() => list.setFilter('recommended', recommended ? '' : '1')}
            className={`${chip(recommended)} disabled:opacity-50 disabled:cursor-not-allowed`}
            title={hasAssessment ? 'الخدمات التي توصي بها خارطة طريق فئة جاهزيتك' : 'أكمل تقييم الجاهزية الرقمية أولًا'}
          >
            الموصى بها لمنشأتي
          </button>
          {!hasAssessment && factory.status === 'success' && (
            <button type="button" onClick={() => navigate('/factory/assessment')} className="text-[#0A6EB0] font-semibold hover:underline cursor-pointer">
              أكمل تقييم الجاهزية لتفعيل التوصيات
            </button>
          )}
        </div>

        <div className="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar text-xs">
          <button type="button" onClick={() => list.setFilter('category', '')} className={chip(list.filters.category === '')}>
            الكل{total > 0 ? ` (${total})` : ''}
          </button>
          {(categories.data ?? []).map((category) => (
            <button
              key={category.code}
              type="button"
              data-category={category.code}
              onClick={() => list.setFilter('category', category.code)}
              className={chip(list.filters.category === category.code)}
            >
              {category.name_ar} ({category.services?.length ?? 0})
            </button>
          ))}
        </div>
      </Card>

      <QueryBoundary query={services} loading={<CardSkeleton />}>
        {(all) => {
          const shown = all.filter((s) => needle === '' || s.name_ar.toLowerCase().includes(needle) || s.code.toLowerCase().includes(needle));
          if (all.length === 0 && !list.hasActiveFilters) {
            // ADR-025: the API lists only the services IMC made available to the factory's readiness level.
            return hasAssessment ? (
              <EmptyState title="لم تُتح خدمات لمستوى جاهزيتكم بعد" description="تحدد الوزارة الخدمات المتاحة لكل مستوى جاهزية، وتظهر هنا خدمات مستواكم والمستويات السابقة له عند إتاحتها." />
            ) : (
              <EmptyState
                title="أكملوا تقييم الجاهزية الرقمية لعرض الخدمات"
                description="الخدمات المتاحة لمنشأتكم مرتبطة بمستوى جاهزيتها، ولا تظهر قبل إكمال التقييم."
                actionText="ابدأ التقييم"
                onAction={() => navigate('/factory/assessment')}
              />
            );
          }
          if (shown.length === 0) {
            return (
              <EmptyState
                title="لا توجد خدمات مطابقة"
                description={
                  eligible
                    ? 'لا يوجد حاليًا مزود معتمد ومؤهل لمنشأتك يقدم خدمة ضمن هذه المعايير.'
                    : 'جرّب تعديل البحث أو الفلاتر لعرض خدمات الكتالوج.'
                }
                actionText="إعادة ضبط الفلاتر"
                onAction={list.reset}
              />
            );
          }
          return (
            <>
              <p className="text-xs text-[#667085]" data-testid="catalog-count">
                {shown.length} من {all.length} خدمة
              </p>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                {shown.map((service) => (
                  <div
                    key={service.id}
                    data-service-id={service.id}
                    className="jahez-card p-5 flex flex-col justify-between hover:border-[#6EC8FF] transition-all cursor-pointer group"
                    onClick={() => navigate(`/factory/services/${service.id}`)}
                  >
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <span className="font-mono text-[11px] font-bold text-[#5146A5]" dir="ltr">{service.code}</span>
                        {service.category && (
                          <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-[#EEEAFE] text-[#5146A5]">{service.category.name_ar}</span>
                        )}
                      </div>
                      <h3 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors leading-snug">{service.name_ar}</h3>
                    </div>
                    <div className="mt-5 pt-3 border-t border-[#E6EAF0] flex items-center justify-between">
                      <span className="text-[11px] text-[#667085] inline-flex items-center gap-1">
                        <Users className="w-3.5 h-3.5" /> المزودون المؤهلون
                      </span>
                      <Button size="sm" variant="primary" icon={ArrowLeft} iconPosition="left">
                        التفاصيل
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            </>
          );
        }}
      </QueryBoundary>
    </div>
  );
};
