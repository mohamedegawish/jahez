import React, { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ClipboardList, Eye, X } from 'lucide-react';
import { api } from '../../api';
import type { ReadinessAssessment } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useSearchBox } from '../../hooks/useListParams';
import type { useListParams } from '../../hooks/useListParams';
import { formatDateTime } from '../../lib/format';
import { AssessmentDetailModal } from '../admin/AssessmentDetailModal';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { TableSkeleton } from '../ui/LoadingState';
import { Pagination } from '../ui/Pagination';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReadinessBadge } from '../ui/ReadinessBadge';
import { SearchInput } from '../ui/SearchInput';
import { RESULT_FILTER_KEYS, selectClass } from './shared';

type ResultsList = ReturnType<typeof useListParams<'tab' | (typeof RESULT_FILTER_KEYS)[number]>>;

/**
 * «نتائج تقييم المصانع»: every submitted assessment, paginated and filtered by the server (category,
 * score range, period, version, current only, factory). Each row opens the answers and the calculation.
 */
export const ResultsTab: React.FC<{ list: ResultsList }> = ({ list }) => {
  const search = useSearchBox(list.search, list.setSearch);
  const [viewing, setViewing] = useState<ReadinessAssessment | null>(null);
  const [, setParams] = useSearchParams();
  const f = list.filters;
  // One navigation: clearing the filters one by one would drop all but the last change.
  const clearFilters = () => setParams(new URLSearchParams({ tab: 'results' }), { replace: true });
  const query = useApiQuery(
    (signal) => {
      const q = toApiQuery({
        ...list,
        filters: { category: f.category, version: f.version, current: f.current, from: f.from, to: f.to, score_min: f.score_min, score_max: f.score_max, factory: f.factory },
      });
      return api.readiness.listAll({ ...q, filter: q.filter as Record<string, string> | undefined, signal });
    },
    [list.page, list.perPage, list.search, list.sort, f.category, f.version, f.current, f.from, f.to, f.score_min, f.score_max, f.factory],
  );

  return (
    <div className="space-y-5" data-testid="results-tab">
      <Card className="p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8 gap-3">
          <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم المنشأة..." className="w-full sm:col-span-2" />
          <select aria-label="المستوى" value={f.category} onChange={(e) => list.setFilter('category', e.target.value)} className={selectClass}>
            <option value="">كل المستويات</option>
            <option value="b4_automation">ما قبل الأتمتة</option>
            <option value="basic">مبتدئ</option>
            <option value="advanced">متقدم</option>
            <option value="smart">ذكي ومبتكر</option>
          </select>
          <input type="number" min={0} aria-label="أدنى درجة" placeholder="من درجة" value={f.score_min} onChange={(e) => list.setFilter('score_min', e.target.value)} className={selectClass} dir="ltr" />
          <input type="number" min={0} aria-label="أعلى درجة" placeholder="إلى درجة" value={f.score_max} onChange={(e) => list.setFilter('score_max', e.target.value)} className={selectClass} dir="ltr" />
          <input type="date" aria-label="من تاريخ" value={f.from} onChange={(e) => list.setFilter('from', e.target.value)} className={selectClass} dir="ltr" />
          <input type="date" aria-label="إلى تاريخ" value={f.to} onChange={(e) => list.setFilter('to', e.target.value)} className={selectClass} dir="ltr" />
          <select aria-label="الترتيب" value={list.sort} onChange={(e) => list.setSort(e.target.value)} className={selectClass}>
            <option value="">الأحدث أولًا</option>
            <option value="oldest">الأقدم أولًا</option>
            <option value="score_desc">الأعلى درجة</option>
            <option value="score_asc">الأدنى درجة</option>
          </select>
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-3 text-xs">
          <label className="inline-flex items-center gap-2 text-[#172033] cursor-pointer">
            <input type="checkbox" checked={f.current === '1'} onChange={(e) => list.setFilter('current', e.target.checked ? '1' : '')} />
            آخر تقييم لكل منشأة فقط (المستوى الحالي)
          </label>
          {f.factory && (
            <button type="button" onClick={() => list.setFilter('factory', '')} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-[#EEEAFE] text-[#5146A5] font-bold cursor-pointer">
              منشأة واحدة: {query.data?.data[0]?.factory?.name ?? `#${f.factory}`}
              <X className="w-3 h-3" aria-label="إلغاء تصفية المنشأة" />
            </button>
          )}
        </div>
      </Card>

      <QueryBoundary
        query={query}
        loading={<TableSkeleton rows={6} cols={6} />}
        isEmpty={(page) => page.data.length === 0}
        empty={<EmptyState icon={ClipboardList} title="لا توجد نتائج" description="لا توجد تقييمات تطابق البحث أو الفلاتر." actionText={list.hasActiveFilters ? 'إعادة ضبط الفلاتر' : undefined} onAction={list.hasActiveFilters ? clearFilters : undefined} />}
      >
        {(page) => (
          <div className="jahez-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-right border-collapse min-w-[720px]">
                <thead>
                  <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] text-xs font-semibold">
                    <th className="py-3 px-4">المنشأة</th>
                    <th className="py-3 px-4">الدرجة</th>
                    <th className="py-3 px-4">المستوى</th>
                    <th className="py-3 px-4">الإصدار</th>
                    <th className="py-3 px-4">تاريخ التقييم</th>
                    <th className="py-3 px-4">أكمله</th>
                    <th className="py-3 px-4 text-center">التفاصيل</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EAF0] text-xs">
                  {page.data.map((assessment) => (
                    <tr key={assessment.id} data-assessment-id={assessment.id} className="hover:bg-[#F7F9FC]">
                      <td className="py-3 px-4">
                        <Link to={`/admin/approvals/factories/${assessment.factory_id}`} className="font-bold text-[#172033] hover:text-[#5146A5]">
                          {assessment.factory?.name ?? `#${assessment.factory_id}`}
                        </Link>
                      </td>
                      <td className="py-3 px-4 font-extrabold text-[#5146A5]">{assessment.total_score}</td>
                      <td className="py-3 px-4">
                        <ReadinessBadge category={assessment.category} size="sm" />
                      </td>
                      <td className="py-3 px-4">{assessment.questionnaire_version}</td>
                      <td className="py-3 px-4 text-[#667085]">{formatDateTime(assessment.completed_at)}</td>
                      <td className="py-3 px-4 text-[#667085]">{assessment.submitted_by?.name ?? '—'}</td>
                      <td className="py-3 px-4 text-center">
                        <Button size="sm" variant="secondary" icon={Eye} onClick={() => setViewing(assessment)}>
                          الإجابات
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="p-3 border-t border-[#E6EAF0] bg-[#F7F9FC]">
              <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
            </div>
          </div>
        )}
      </QueryBoundary>

      {viewing && (
        <AssessmentDetailModal factoryId={viewing.factory_id} assessmentId={viewing.id} factoryName={viewing.factory?.name} onClose={() => setViewing(null)} />
      )}
    </div>
  );
};
