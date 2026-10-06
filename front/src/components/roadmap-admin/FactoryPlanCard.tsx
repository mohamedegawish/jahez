import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FilePlus2, Route as RouteIcon } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { Factory } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { PLAN_STATUS, progressLabel } from '../../lib/roadmap';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { FieldError } from '../ui/FieldError';
import { CardSkeleton } from '../ui/LoadingState';

/**
 * The factory's transformation plan in its IMC file (jahez_api ADR-025): the open plan with its status
 * and progress, or the form to create one. A plan needs a readiness assessment (its level decides the
 * services the plan may use) and at most one plan is open per factory; the API enforces both.
 */
export const FactoryPlanCard: React.FC<{ factory: Factory }> = ({ factory }) => {
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const canManage = hasPermission('transformation_plans.manage');
  const [title, setTitle] = useState('خطة التحول الرقمي');
  const plans = useApiQuery((signal) => api.transformationPlans.list({ per_page: 10, filter: { factory: factory.id }, signal }), [factory.id]);
  const create = useApiMutation(() => api.transformationPlans.create(factory.id, { title: title.trim() }));
  const eligibility = useApiQuery((signal) => api.serviceEligibility.get(factory.id, signal), [factory.id]);

  if (!hasPermission('transformation_plans.view_any')) return null;

  const open = plans.data?.data.find((plan) => plan.status !== 'closed') ?? null;
  const closed = plans.data?.data.filter((plan) => plan.status === 'closed') ?? [];

  return (
    <Card title="خطة التحول الرقمي" subtitle="تعدّها الوزارة بمراحل وخدمات متاحة لمستوى جاهزية المصنع" accent="blue">
      <div data-testid="factory-plan-card">
        {plans.status === 'error' ? (
          <ApiErrorState compact error={plans.error} onRetry={plans.refetch} />
        ) : !plans.data ? (
          <CardSkeleton />
        ) : open ? (
          <div className="space-y-3 text-xs">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={PLAN_STATUS[open.status].tone} size="sm">{PLAN_STATUS[open.status].label}</Badge>
              {open.published_version && <span className="text-[#667085]">الإصدار {open.published_version.version}</span>}
              {open.draft_version && <span className="text-[#A66F0B]">مسودة الإصدار {open.draft_version.version}</span>}
              {open.progress && <span className="text-[#667085]">{progressLabel(open.progress)}</span>}
            </div>
            <Button size="sm" variant="primary" icon={RouteIcon} onClick={() => navigate(`/admin/roadmaps/${open.id}`)} data-testid="open-plan">
              فتح الخطة
            </Button>
          </div>
        ) : (
          <div className="space-y-3 text-xs">
            {eligibility.data && (
              <p className="text-[#667085]">
                {eligibility.data.status === 'no_assessment'
                  ? 'لم يُكمل المصنع تقييم الجاهزية؛ لا يمكن إنشاء خطة قبل معرفة مستواه.'
                  : `المستوى الحالي: ${eligibility.data.readiness?.name_ar} — ${eligibility.data.services.length} خدمة متاحة لهذا المستوى.`}
              </p>
            )}
            {closed.length > 0 && <p className="text-[#98A2B3]">{closed.length} خطة مغلقة سابقة.</p>}
            {canManage && eligibility.data?.status === 'eligible' && (
              <div className="flex flex-col sm:flex-row gap-2">
                <input
                  aria-label="عنوان الخطة"
                  value={title}
                  maxLength={200}
                  onChange={(e) => setTitle(e.target.value)}
                  className="w-full p-2 rounded-lg border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF]"
                />
                <Button
                  size="sm"
                  variant="primary"
                  icon={FilePlus2}
                  isLoading={create.pending}
                  disabled={title.trim() === ''}
                  onClick={async () => {
                    const result = await create.run();
                    if (result.ok) navigate(`/admin/roadmaps/${result.data.id}?tab=draft`);
                  }}
                  data-testid="create-plan"
                >
                  إنشاء خطة
                </Button>
              </div>
            )}
            <FieldError messages={fieldMessages(create.error, 'title')} />
            {create.error !== null && fieldMessages(create.error, 'title').length === 0 && <ApiErrorState compact error={create.error} />}
          </div>
        )}
      </div>
    </Card>
  );
};
