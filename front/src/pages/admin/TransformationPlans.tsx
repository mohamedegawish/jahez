import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Factory as FactoryIcon, Route as RouteIcon } from 'lucide-react';
import { api } from '../../api';
import type { TransformationPlanStatus } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { toApiQuery, useListParams, useSearchBox } from '../../hooks/useListParams';
import { Badge } from '../../components/ui/Badge';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SearchInput } from '../../components/ui/SearchInput';
import { Tabs } from '../../components/ui/Tabs';
import { PLAN_STATUS, progressLabel } from '../../lib/roadmap';
import { formatDate } from '../../lib/format';

const STATUSES: TransformationPlanStatus[] = ['published', 'draft', 'suspended', 'closed'];
const FILTER_KEYS = ['status'] as const;

/**
 * IMC's list of factory transformation plans (jahez_api ADR-025), with their status and the
 * progress the server computes from recorded execution. A plan is created from the factory's file
 * (its readiness result decides the services the plan may use).
 */
export const TransformationPlans: React.FC = () => {
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const list = useListParams(FILTER_KEYS);
  const search = useSearchBox(list.search, list.setSearch);
  const status = (list.filters.status || 'all') as TransformationPlanStatus | 'all';

  const plans = useApiQuery(
    (signal) =>
      api.transformationPlans.list({
        ...toApiQuery({ ...list, filters: {} }),
        filter: status === 'all' ? undefined : { status },
        sort: 'recently_changed',
        signal,
      }),
    [list.page, list.perPage, list.search, status],
  );

  if (!hasPermission('transformation_plans.view_any')) {
    return <EmptyState title="لا تملك صلاحية عرض خطط التحول" description="يتطلب هذا القسم صلاحية transformation_plans.view_any." />;
  }

  return (
    <div className="space-y-6" data-testid="admin-plans">
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">خطط التحول الرقمي</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
            خطة لكل مصنع بمراحل وخدمات وتبعيات تحددها الوزارة. تُنشأ الخطة من ملف المصنع بعد تقييم جاهزيته.
          </p>
        </div>
        <Link to="/admin/approvals/factories?status=all" className="inline-flex items-center gap-1.5 text-xs font-bold text-[#5146A5] hover:underline">
          <FactoryIcon className="w-4 h-4" /> فتح ملف مصنع لإنشاء خطة
        </Link>
      </div>

      <Tabs
        tabs={[{ id: 'all', label: 'الكل' }, ...STATUSES.map((s) => ({ id: s, label: PLAN_STATUS[s].label }))]}
        activeTab={status}
        onChange={(id) => list.setFilter('status', id === 'all' ? '' : id)}
      />

      <Card className="p-4">
        <SearchInput value={search.value} onChange={search.onChange} placeholder="بحث باسم المصنع..." className="w-full sm:max-w-sm" />
      </Card>

      <QueryBoundary
        query={plans}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={<EmptyState icon={RouteIcon} title="لا توجد خطط" description="لا توجد خطط تحول تطابق هذا العرض." />}
      >
        {(page) => (
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              {page.data.map((plan) => (
                <button
                  key={plan.id}
                  type="button"
                  onClick={() => navigate(`/admin/roadmaps/${plan.id}`)}
                  className="jahez-card p-5 text-start space-y-3 hover:border-[#9B8AFB] min-w-0"
                  data-testid="plan-card"
                >
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-bold text-sm text-[#172033] break-words">{plan.factory?.name}</p>
                      <p className="text-xs text-[#667085] break-words">{plan.published_version?.title ?? plan.draft_version?.title ?? 'خطة بلا عنوان'}</p>
                    </div>
                    <Badge variant={PLAN_STATUS[plan.status].tone} size="sm">{PLAN_STATUS[plan.status].label}</Badge>
                  </div>
                  <div className="flex flex-wrap gap-2 text-[11px] text-[#667085]">
                    {plan.published_version && <span>الإصدار المنشور {plan.published_version.version}</span>}
                    {plan.draft_version && <span className="text-[#A66F0B]">مسودة الإصدار {plan.draft_version.version}</span>}
                    {plan.readiness_basis?.name_ar && <span>المستوى: {plan.readiness_basis.name_ar}</span>}
                  </div>
                  {plan.progress && (
                    <div className="space-y-1">
                      <div className="h-1.5 rounded-full bg-[#F1F4F9] overflow-hidden">
                        <div className="h-full bg-[#5146A5]" style={{ width: `${plan.progress.percent ?? 0}%` }} />
                      </div>
                      <p className="text-[11px] text-[#667085]">{progressLabel(plan.progress)}</p>
                    </div>
                  )}
                  <p className="text-[10px] text-[#98A2B3]">آخر تحديث {formatDate(plan.updated_at)}</p>
                </button>
              ))}
            </div>
            <Pagination meta={page.meta} onPage={list.setPage} onPerPage={list.setPerPage} />
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};
