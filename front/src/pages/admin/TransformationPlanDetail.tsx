import React, { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowRight, CheckCircle2, FilePlus2, Lock, PauseCircle, PlayCircle, Send, Trash2 } from 'lucide-react';
import { api, describeError, fieldMessages } from '../../api';
import type { PlanItem, PlanItemAction, TransformationPlan } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useListParams } from '../../hooks/useListParams';
import { PlanDraftEditor } from '../../components/roadmap-admin/PlanDraftEditor';
import { RoadmapView } from '../../components/roadmap/RoadmapView';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Badge } from '../../components/ui/Badge';
import { Button } from '../../components/ui/Button';
import { ConfirmModal } from '../../components/ui/ConfirmModal';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { ReasonModal } from '../../components/ui/ReasonModal';
import { Tabs } from '../../components/ui/Tabs';
import { ITEM_ACTION, PLAN_STATUS, REVIEW_PROBLEM } from '../../lib/roadmap';
import { formatDate, formatDateTime } from '../../lib/format';

type PlanTab = 'execution' | 'draft' | 'preview' | 'publish' | 'versions';
type PlanCommand = 'suspend' | 'resume' | 'close' | 'delete' | 'discard';

/**
 * IMC's workspace for one factory's transformation plan (jahez_api ADR-025): the published version
 * with the execution actions the server allows, the draft editor, a preview of the draft as the
 * factory will see it, the publication review, and the version history. Every action goes to the
 * API, which checks permissions, dependencies and approvals again.
 */
export const TransformationPlanDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const planId = /^\d+$/.test(id ?? '') ? Number(id) : null;
  const { hasPermission } = useAuth();
  const canManage = hasPermission('transformation_plans.manage');
  const list = useListParams(['tab'] as const);
  const [command, setCommand] = useState<PlanCommand | null>(null);
  const [itemAction, setItemAction] = useState<{ item: PlanItem; action: PlanItemAction } | null>(null);

  const plan = useApiQuery((signal) => api.transformationPlans.get(planId as number, signal), [planId], { enabled: planId !== null });
  const data = plan.data;
  const factoryId = data?.factory?.id ?? null;
  const eligibility = useApiQuery((signal) => api.serviceEligibility.get(factoryId as number, signal), [factoryId], { enabled: factoryId !== null && canManage });
  const startDraft = useApiMutation(() => api.transformationPlans.startDraft(planId as number));

  if (planId === null) return <EmptyState title="الخطة غير موجودة" description="رابط الخطة غير صالح." />;
  if (plan.status === 'loading' && !data) return <CardSkeleton />;
  if (plan.status === 'error' && !data) return <ApiErrorState error={plan.error} onRetry={plan.refetch} />;
  if (!data) return null;

  const defaultTab: PlanTab = data.published ? 'execution' : 'draft';
  const tab = (list.filters.tab || defaultTab) as PlanTab;
  const tabs = [
    ...(data.published ? [{ id: 'execution', label: 'الخطة المنشورة والتنفيذ' }] : []),
    ...(data.draft ? [{ id: 'draft', label: `المسودة (الإصدار ${data.draft.version})` }, { id: 'preview', label: 'معاينة كما يراها المصنع' }, { id: 'publish', label: 'المراجعة والنشر' }] : []),
    { id: 'versions', label: 'الإصدارات والسجل' },
  ];
  const refresh = () => plan.refetch();

  return (
    <div className="space-y-6" data-testid="plan-detail">
      <div>
        <Link to="/admin/roadmaps" className="inline-flex items-center gap-1 text-xs font-semibold text-[#5146A5] hover:underline mb-2">
          <ArrowRight className="w-3.5 h-3.5" /> خطط التحول الرقمي
        </Link>
        <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight break-words">{data.factory?.name}</h2>
            <div className="flex flex-wrap items-center gap-2 mt-1 text-xs text-[#667085]">
              <Badge variant={PLAN_STATUS[data.status].tone} size="sm">{PLAN_STATUS[data.status].label}</Badge>
              {data.current_readiness && (
                <span>تقييم الجاهزية: <span className="font-bold text-[#172033]">{data.current_readiness.name_ar}</span> ({data.current_readiness.total_score}/40)</span>
              )}
              {data.current_level && (
                <span>
                  المستوى المتاح للمصنع: <span className="font-bold text-[#172033]">{data.current_level.name_ar}</span>
                  {data.current_level.unlocked_by === 'plan_completion' ? ' (فُتح بإتمام خدمات الخطة)' : ''}
                </span>
              )}
              {data.factory && <Link to={`/admin/approvals/factories/${data.factory.id}`} className="text-[#5146A5] hover:underline">ملف المصنع</Link>}
              {data.status_reason && <span>سبب آخر تغيير: {data.status_reason}</span>}
            </div>
          </div>
          {canManage && (
            <div className="flex flex-wrap gap-2">
              {data.published && !data.draft && data.status !== 'closed' && (
                <Button
                  size="sm"
                  variant="primary"
                  icon={FilePlus2}
                  isLoading={startDraft.pending}
                  onClick={async () => {
                    const result = await startDraft.run();
                    if (result.ok) {
                      await plan.refetch();
                      list.setFilter('tab', 'draft');
                    }
                  }}
                  data-testid="start-draft"
                >
                  إصدار جديد
                </Button>
              )}
              {data.status === 'published' && <Button size="sm" variant="outline" icon={PauseCircle} onClick={() => setCommand('suspend')}>إيقاف مؤقت</Button>}
              {data.status === 'suspended' && <Button size="sm" variant="outline" icon={PlayCircle} onClick={() => setCommand('resume')}>استئناف</Button>}
              {(data.status === 'published' || data.status === 'suspended') && <Button size="sm" variant="outline" icon={Lock} onClick={() => setCommand('close')}>إغلاق الخطة</Button>}
              {data.status === 'draft' && <Button size="sm" variant="danger" icon={Trash2} onClick={() => setCommand('delete')}>حذف الخطة</Button>}
              {data.status !== 'draft' && data.draft && <Button size="sm" variant="ghost" icon={Trash2} onClick={() => setCommand('discard')}>تجاهل المسودة</Button>}
            </div>
          )}
        </div>
        {startDraft.error !== null && <ApiErrorState compact error={startDraft.error} className="mt-2" />}
      </div>

      {data.readiness_changed && (
        <div className="flex gap-2 p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-sm text-[#7A5207]" data-testid="readiness-changed">
          <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
          قدّم المصنع تقييم جاهزية جديدًا بعد آخر نشر للخطة. الخطة القائمة محفوظة كما هي؛ راجعوا خدماتها مقابل المستوى الحالي وانشروا إصدارًا جديدًا عند الحاجة.
        </div>
      )}

      <Tabs tabs={tabs} activeTab={tab} onChange={(next) => list.setFilter('tab', next)} />

      {tab === 'execution' && data.published && (
        <RoadmapView version={data.published} audience="imc" readOnly={!canManage} onAction={(item, action) => setItemAction({ item, action })} />
      )}

      {tab === 'draft' && data.draft && (
        canManage ? (
          eligibility.data ? (
            <PlanDraftEditor key={`${data.draft.id}-${data.draft.revision}`} plan={data} eligibility={eligibility.data} onSaved={() => refresh()} />
          ) : eligibility.status === 'error' ? (
            <ApiErrorState error={eligibility.error} onRetry={eligibility.refetch} />
          ) : (
            <CardSkeleton />
          )
        ) : (
          <RoadmapView version={data.draft} audience="imc" readOnly />
        )
      )}

      {tab === 'preview' && data.draft && (
        <div className="space-y-3">
          <p className="text-xs p-3 rounded-xl bg-[#DFF3FF]/60 border border-[#BDE5FD] text-[#0A4F7E]">
            معاينة المسودة المحفوظة كما ستظهر للمصنع بعد النشر: دون الملاحظات الداخلية. احفظ المسودة لتظهر آخر تعديلاتك هنا.
          </p>
          <RoadmapView version={data.draft} audience="factory" readOnly />
        </div>
      )}

      {tab === 'publish' && data.draft && <PublishPanel plan={data} canManage={canManage} onPublished={() => { refresh(); list.setFilter('tab', 'execution'); }} />}

      {tab === 'versions' && <VersionsPanel planId={planId} />}

      {command && (command === 'delete' || command === 'discard') && (
        <ConfirmModal
          title={command === 'delete' ? 'حذف الخطة' : 'تجاهل المسودة'}
          description={command === 'delete' ? 'تُحذف الخطة التي لم تُنشر بمسودتها. لا يمكن التراجع.' : 'تُحذف المسودة الحالية، ويبقى الإصدار المنشور كما هو.'}
          confirmLabel={command === 'delete' ? 'حذف' : 'تجاهل'}
          variant="danger"
          onConfirm={async (): Promise<void> => {
            if (command === 'delete') await api.transformationPlans.remove(planId);
            else await api.transformationPlans.discardDraft(planId);
          }}
          onDone={() => {
            setCommand(null);
            if (command === 'delete') navigate('/admin/roadmaps');
            else refresh();
          }}
          onClose={() => setCommand(null)}
        />
      )}

      {command && command !== 'delete' && command !== 'discard' && (
        <ReasonModal
          title={{ suspend: 'إيقاف الخطة مؤقتًا', resume: 'استئناف الخطة', close: 'إغلاق الخطة' }[command]}
          description={{
            suspend: 'يبقى المصنع قادرًا على رؤية الخطة، لكن لا تُرسل طلبات لخدماتها ولا يبدأ تنفيذ جديد حتى الاستئناف.',
            resume: 'تعود الخطة منشورة ويُستأنف إرسال الطلبات وتسجيل التنفيذ.',
            close: 'تُغلق الخطة وتبقى للاطلاع، ويمكن بعدها إنشاء خطة جديدة للمصنع.',
          }[command]}
          confirmLabel="تأكيد"
          label="السبب (اختياري، يظهر في سجل التدقيق)"
          onConfirm={(reason) => api.transformationPlans[command](planId, reason || null)}
          onDone={() => {
            setCommand(null);
            refresh();
          }}
          onClose={() => setCommand(null)}
        />
      )}

      {itemAction && (
        <ReasonModal
          title={ITEM_ACTION[itemAction.action].label}
          subtitle={itemAction.item.service.name_ar}
          description={ITEM_ACTION[itemAction.action].description}
          confirmLabel="تأكيد"
          variant={itemAction.action === 'cancel' ? 'danger' : 'primary'}
          label="ملاحظة (اختيارية، تُحفظ مع الحالة)"
          onConfirm={(reason) => api.transformationPlans.itemAction(planId, itemAction.item.item_id, itemAction.action, reason || null)}
          onDone={() => {
            setItemAction(null);
            refresh();
          }}
          onClose={() => setItemAction(null)}
        />
      )}
    </div>
  );
};

const PublishPanel: React.FC<{ plan: TransformationPlan; canManage: boolean; onPublished: () => void }> = ({ plan, canManage, onPublished }) => {
  const [note, setNote] = useState(plan.draft?.change_note ?? '');
  const publish = useApiMutation(() => api.transformationPlans.publish(plan.id, note.trim() || null));
  const review = plan.review ?? [];
  const blocking = review.filter((problem) => problem.blocking);
  const warnings = review.filter((problem) => !problem.blocking);
  const needsNote = (plan.draft?.version ?? 1) > 1;
  const serverErrors = [...fieldMessages(publish.error, 'draft'), ...fieldMessages(publish.error, 'change_note')];

  return (
    <div className="space-y-4" data-testid="publish-panel">
      <div className="jahez-card p-5 space-y-3">
        <h3 className="text-sm font-bold text-[#172033]">مراجعة الخادم للمسودة المحفوظة</h3>
        {review.length === 0 ? (
          <p className="flex items-center gap-1.5 text-sm text-[#1D7E4C]"><CheckCircle2 className="w-4 h-4" /> لا توجد ملاحظات؛ المسودة جاهزة للنشر.</p>
        ) : (
          <ul className="space-y-1.5">
            {[...blocking, ...warnings].map((problem, index) => (
              <li key={`${problem.code}-${index}`} className={`flex items-start gap-1.5 text-xs ${problem.blocking ? 'text-[#B82B3B]' : 'text-[#A66F0B]'}`} data-testid={problem.blocking ? 'blocking-problem' : 'warning-problem'}>
                <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-px" />
                <span>
                  <span className="font-bold">{problem.blocking ? 'يمنع النشر: ' : 'تنبيه: '}</span>
                  {REVIEW_PROBLEM[problem.code] ?? problem.message}
                  {problem.stage_position !== null && ` (المرحلة ${problem.stage_position})`}
                  {problem.service_code && <span className="text-[10px] ms-1" dir="ltr">{problem.service_code}</span>}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>

      {canManage && (
        <div className="jahez-card p-5 space-y-3">
          <label htmlFor="publish-note" className="block text-xs font-bold text-[#344054]">
            ما الذي تغيّر ولماذا؟ {needsNote ? '(مطلوب لهذا الإصدار)' : '(اختياري للإصدار الأول)'}
          </label>
          <textarea id="publish-note" rows={3} value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} className="w-full p-2.5 rounded-xl border border-[#E6EAF0] text-sm focus:outline-none focus:border-[#6EC8FF]" />
          {serverErrors.length > 0 && (
            <ul className="text-xs text-[#B82B3B] space-y-1">{serverErrors.map((message) => <li key={message}>{message}</li>)}</ul>
          )}
          {publish.error !== null && serverErrors.length === 0 && <p className="text-xs text-[#B82B3B]">{describeError(publish.error).title}</p>}
          <Button
            variant="primary"
            icon={Send}
            isLoading={publish.pending}
            disabled={blocking.length > 0 || (needsNote && note.trim() === '')}
            onClick={async () => {
              const result = await publish.run();
              if (result.ok) onPublished();
            }}
            data-testid="publish-plan"
          >
            نشر الخطة للمصنع
          </Button>
        </div>
      )}
    </div>
  );
};

const VersionsPanel: React.FC<{ planId: number }> = ({ planId }) => {
  const versions = useApiQuery((signal) => api.transformationPlans.versions(planId, signal), [planId]);
  const [open, setOpen] = useState<number | null>(null);
  const version = useApiQuery((signal) => api.transformationPlans.version(planId, open as number, signal), [planId, open], { enabled: open !== null });

  if (versions.status === 'error') return <ApiErrorState error={versions.error} onRetry={versions.refetch} />;
  if (!versions.data) return <CardSkeleton />;

  return (
    <div className="space-y-4" data-testid="versions-panel">
      <ol className="space-y-3">
        {versions.data.map((entry) => (
          <li key={entry.id} className="jahez-card p-4 space-y-1.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-bold text-[#172033]">الإصدار {entry.version}: {entry.title}</p>
              <Badge variant={entry.status === 'published' ? 'success' : entry.status === 'draft' ? 'warning' : 'neutral'} size="sm">
                {{ draft: 'مسودة', published: 'منشور', superseded: 'إصدار سابق' }[entry.status]}
              </Badge>
            </div>
            <p className="text-xs text-[#667085]">
              {entry.stage_count} مراحل · {entry.item_count} خدمات · المراجعة {entry.revision}
              {entry.published_at && ` · نُشر ${formatDateTime(entry.published_at)}${entry.published_by ? ` بواسطة ${entry.published_by.name}` : ''}`}
              {entry.superseded_at && ` · استُبدل ${formatDate(entry.superseded_at)}`}
            </p>
            {entry.change_note && <p className="text-xs text-[#344054]">سبب التغيير: {entry.change_note}</p>}
            {entry.status !== 'draft' && (
              <Button size="sm" variant="ghost" onClick={() => setOpen(open === entry.id ? null : entry.id)}>
                {open === entry.id ? 'إخفاء' : 'عرض هذا الإصدار'}
              </Button>
            )}
          </li>
        ))}
      </ol>
      {open !== null && (version.data ? <RoadmapView version={version.data} audience="imc" readOnly /> : version.status === 'error' ? <ApiErrorState error={version.error} /> : <CardSkeleton />)}
      <p className="text-[11px] text-[#98A2B3]">كل تغيير على الخطة وحالات خدماتها مسجّل أيضًا في سجل التدقيق.</p>
    </div>
  );
};
