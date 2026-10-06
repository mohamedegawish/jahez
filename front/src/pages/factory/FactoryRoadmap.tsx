import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Map as MapIcon, PauseCircle, Lock } from 'lucide-react';
import { api } from '../../api';
import type { PlanItem, TransformationPlan } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { RequestFormModal } from '../../components/factory/RequestFormModal';
import { RoadmapView } from '../../components/roadmap/RoadmapView';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Badge } from '../../components/ui/Badge';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { PLAN_STATUS } from '../../lib/roadmap';
import { formatDate } from '../../lib/format';

/** The plan the factory should see first: its open plan (published or suspended), else the latest closed one. */
function currentPlan(plans: TransformationPlan[]): TransformationPlan | null {
  return plans.find((plan) => plan.status === 'published' || plan.status === 'suspended') ?? plans[0] ?? null;
}

/**
 * The factory's digital transformation plan, as IMC published it (jahez_api ADR-025). The API sends
 * only published versions, never IMC's notes; each service's state, what it waits for and its linked
 * request come from the server. Requesting a service links the request to the plan item; starting
 * and completing it are recorded by IMC.
 */
export const FactoryRoadmap: React.FC = () => {
  const navigate = useNavigate();
  const [requesting, setRequesting] = useState<PlanItem | null>(null);
  const plans = useApiQuery((signal) => api.transformationPlans.list({ per_page: 20, signal }), []);
  const selected = plans.data ? currentPlan(plans.data.data) : null;
  const plan = useApiQuery((signal) => api.transformationPlans.get(selected!.id, signal), [selected?.id], { enabled: selected !== null });

  return (
    <div className="space-y-6 max-w-5xl mx-auto" data-testid="factory-roadmap">
      <div>
        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#EEEAFE] text-[#5146A5] text-xs font-bold mb-2">
          <MapIcon className="w-3.5 h-3.5" /> خطة التحول الرقمي
        </div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">خارطة طريق منشأتكم</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          الخطة التي أعدّها مركز تحديث الصناعة لمنشأتكم: مراحل متتابعة، وخدمات يمكن تنفيذها بالتوازي، وحالة كل خدمة كما يسجلها النظام.
        </p>
      </div>

      {plans.status === 'loading' || (selected !== null && plan.status === 'loading') ? (
        <CardSkeleton />
      ) : plans.status === 'error' ? (
        <ApiErrorState error={plans.error} onRetry={plans.refetch} />
      ) : selected === null ? (
        <EmptyState
          icon={MapIcon}
          title="لم تنشر الوزارة خطة لمنشأتكم بعد"
          description="عندما يعتمد مركز تحديث الصناعة خطة التحول الرقمي لمنشأتكم ستظهر هنا بمراحلها وخدماتها. يمكنكم في الأثناء تصفح الخدمات المتاحة لمستوى جاهزيتكم."
          actionText="الخدمات المتاحة"
          onAction={() => navigate('/factory/services')}
        />
      ) : plan.status === 'error' ? (
        <ApiErrorState error={plan.error} onRetry={plan.refetch} />
      ) : plan.data?.published ? (
        <>
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <Badge variant={PLAN_STATUS[plan.data.status].tone} size="sm">{PLAN_STATUS[plan.data.status].label}</Badge>
            {plan.data.status_changed_at && <span className="text-[#667085]">آخر تحديث للحالة: {formatDate(plan.data.status_changed_at)}</span>}
          </div>
          {plan.data.status === 'suspended' && (
            <div className="flex gap-2 p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-sm text-[#7A5207]">
              <PauseCircle className="w-4 h-4 shrink-0 mt-0.5" />
              أوقف مركز تحديث الصناعة الخطة مؤقتًا: لا تُرسل طلبات لخدماتها ولا تبدأ خدمات جديدة حتى استئنافها.
            </div>
          )}
          {plan.data.status === 'closed' && (
            <div className="flex gap-2 p-3 rounded-xl bg-[#F1F4F9] border border-[#E2E8F0] text-sm text-[#475467]">
              <Lock className="w-4 h-4 shrink-0 mt-0.5" />
              هذه الخطة مغلقة وتُعرض للاطلاع.
            </div>
          )}
          <RoadmapView version={plan.data.published} audience="factory" onRequest={setRequesting} />
        </>
      ) : null}

      {requesting && (
        <RequestFormModal
          service={{ id: requesting.service.id, code: requesting.service.code, name_ar: requesting.service.name_ar }}
          planItemId={requesting.item_id}
          lockedProviderIds={requesting.provider ? [requesting.provider.id] : undefined}
          onClose={() => setRequesting(null)}
          onCreated={(created) => navigate(`/factory/requests/${created.id}`)}
        />
      )}
    </div>
  );
};
