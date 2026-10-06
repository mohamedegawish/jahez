import React from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, ArrowLeftRight, CalendarDays, CheckCircle2, Clock, Eye, GitBranch, Info, Link2, Send } from 'lucide-react';
import { api } from '../../api';
import type { PlanItem, PlanItemAction, PlanProgress, PlanStage, PlanVersionView } from '../../api';
import { ATTENTION, ITEM_ACTION, ITEM_STATE, REQUEST_PROGRESS, STAGE_STATUS, groupStageItems, progressLabel, waitingReason, type Tone } from '../../lib/roadmap';
import { formatDate } from '../../lib/format';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { PrivateImage } from '../ui/PrivateFile';

export interface RoadmapViewProps {
  version: PlanVersionView;
  /** The factory sees request buttons; IMC sees the execution actions the server allows. */
  audience: 'factory' | 'imc';
  /** No buttons at all: the IMC preview of a draft as the factory will see it. */
  readOnly?: boolean;
  onRequest?: (item: PlanItem) => void;
  onAction?: (item: PlanItem, action: PlanItemAction) => void;
}

const DOT: Record<Tone, string> = {
  blue: 'bg-[#6EC8FF]',
  purple: 'bg-[#9B8AFB]',
  success: 'bg-[#35B779]',
  warning: 'bg-[#F2B84B]',
  error: 'bg-[#E45B6A]',
  neutral: 'bg-[#98A2B3]',
};

/**
 * A transformation plan as a vertical, right-to-left roadmap: numbered stages on a connecting line,
 * each with its goal, IMC's instructions, progress and services. Within a stage, services that can
 * run alongside each other are grouped apart from those waiting for another service of the stage;
 * every card names what it waits for. Everything shown comes from the API.
 */
export const RoadmapView: React.FC<RoadmapViewProps> = ({ version, audience, readOnly = false, onRequest, onAction }) => {
  const names = new Map<number, string>();
  for (const stage of version.stages) for (const item of stage.items) names.set(item.item_id, item.service.name_ar);

  return (
    <div className="space-y-6" data-testid="roadmap">
      <div className="jahez-card p-5 sm:p-6 space-y-3">
        <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs font-bold text-[#5146A5] mb-1">الإصدار {version.version}{version.published_at ? ` · نُشر ${formatDate(version.published_at)}` : ''}</p>
            <h2 className="text-lg sm:text-xl font-bold text-[#172033] leading-snug break-words">{version.title}</h2>
            {version.summary_ar && <p className="text-sm text-[#667085] mt-1 leading-relaxed whitespace-pre-line break-words">{version.summary_ar}</p>}
          </div>
          <div className="text-xs text-[#667085] shrink-0">{version.stages.length} مراحل</div>
        </div>
        <ProgressBar progress={version.progress} label="تقدم الخطة" />
      </div>

      <ol className="relative space-y-6">
        {version.stages.map((stage, index) => (
          <StageNode
            key={stage.id}
            stage={stage}
            last={index === version.stages.length - 1}
            names={names}
            audience={audience}
            readOnly={readOnly}
            onRequest={onRequest}
            onAction={onAction}
          />
        ))}
      </ol>
    </div>
  );
};

const ProgressBar: React.FC<{ progress: PlanProgress; label: string }> = ({ progress, label }) => (
  <div className="space-y-1" data-testid="progress">
    <div className="flex items-center justify-between text-xs">
      <span className="font-semibold text-[#344054]">{label}</span>
      <span className="text-[#667085]">
        {progressLabel(progress)}
        {progress.percent !== null && (
          <>
            {' · '}
            <span className="font-bold text-[#5146A5]" dir="ltr">{progress.percent}%</span>
          </>
        )}
      </span>
    </div>
    <div className="h-2 rounded-full bg-[#F1F4F9] overflow-hidden" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress.percent ?? 0}>
      <div className="h-full rounded-full bg-gradient-to-l from-[#5146A5] to-[#6EC8FF]" style={{ width: `${progress.percent ?? 0}%` }} />
    </div>
  </div>
);

interface StageNodeProps extends Omit<RoadmapViewProps, 'version'> {
  stage: PlanStage;
  last: boolean;
  names: Map<number, string>;
}

const StageNode: React.FC<StageNodeProps> = ({ stage, last, names, ...rest }) => {
  const status = STAGE_STATUS[stage.status];
  const { parallel, after } = groupStageItems(stage.items);

  return (
    <li className="relative ps-12 sm:ps-14" data-testid="stage" data-stage-number={stage.number}>
      {!last && <span aria-hidden className="absolute start-[19px] sm:start-[23px] top-12 bottom-[-24px] w-0.5 bg-gradient-to-b from-[#9B8AFB] to-[#DFF3FF]" />}
      <span
        className={`absolute start-0 top-0 w-10 h-10 sm:w-12 sm:h-12 rounded-full flex items-center justify-center font-bold text-white shadow-sm ${
          stage.status === 'completed' ? 'bg-[#35B779]' : stage.status === 'in_progress' ? 'bg-[#5146A5]' : 'bg-[#98A2B3]'
        }`}
        aria-label={`المرحلة ${stage.number}`}
      >
        {stage.status === 'completed' ? <CheckCircle2 className="w-5 h-5" /> : stage.number}
      </span>

      <div className="jahez-card p-4 sm:p-5 space-y-4">
        <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="text-[11px] font-bold text-[#667085]">المرحلة {stage.number}</p>
            <h3 className="text-base sm:text-lg font-bold text-[#172033] break-words">{stage.name_ar}</h3>
          </div>
          <Badge variant={status.tone} size="sm" className="self-start">{status.label}</Badge>
        </div>

        {(stage.objective_ar || stage.description_ar) && (
          <div className="space-y-1 text-sm text-[#475467] leading-relaxed">
            {stage.objective_ar && <p className="break-words"><span className="font-bold text-[#344054]">الهدف: </span>{stage.objective_ar}</p>}
            {stage.description_ar && <p className="whitespace-pre-line break-words">{stage.description_ar}</p>}
          </div>
        )}

        {(stage.planned_start_date || stage.planned_end_date) && (
          <p className="flex items-center gap-1.5 text-xs text-[#667085]">
            <CalendarDays className="w-3.5 h-3.5 shrink-0" />
            مخطط: {formatDate(stage.planned_start_date)} ← {formatDate(stage.planned_end_date)}
          </p>
        )}

        {stage.factory_instructions_ar && (
          <div className="flex gap-2 p-3 rounded-xl bg-[#DFF3FF]/60 border border-[#BDE5FD] text-sm text-[#0A4F7E]">
            <Info className="w-4 h-4 shrink-0 mt-0.5" />
            <p className="whitespace-pre-line break-words"><span className="font-bold">تعليمات الوزارة: </span>{stage.factory_instructions_ar}</p>
          </div>
        )}

        {rest.audience === 'imc' && stage.internal_notes && (
          <p className="text-xs p-2.5 rounded-lg bg-[#FEF5E7] border border-[#FDE5BE] text-[#7A5207] break-words">
            <span className="font-bold">ملاحظة داخلية (لا تظهر للمصنع): </span>{stage.internal_notes}
          </p>
        )}

        <ProgressBar progress={stage.progress} label="تقدم المرحلة" />

        {parallel.length > 0 && (
          <ItemGroup
            title={parallel.length > 1 ? 'خدمات يمكن تنفيذها بالتوازي' : 'خدمة المرحلة'}
            icon={parallel.length > 1 ? ArrowLeftRight : GitBranch}
            items={parallel}
            names={names}
            {...rest}
          />
        )}
        {after.length > 0 && <ItemGroup title="تبدأ بعد استكمال خدمات من هذه المرحلة" icon={Clock} items={after} names={names} {...rest} />}
      </div>
    </li>
  );
};

interface ItemGroupProps extends Omit<RoadmapViewProps, 'version'> {
  title: string;
  icon: React.ElementType;
  items: PlanItem[];
  names: Map<number, string>;
}

const ItemGroup: React.FC<ItemGroupProps> = ({ title, icon: Icon, items, ...rest }) => (
  <div className="space-y-2" data-testid="item-group">
    <p className="flex items-center gap-1.5 text-xs font-bold text-[#5146A5]">
      <Icon className="w-3.5 h-3.5" /> {title}
    </p>
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
      {items.map((item) => (
        <PlanItemCard key={item.id} item={item} {...rest} />
      ))}
    </div>
  </div>
);

interface PlanItemCardProps extends Omit<RoadmapViewProps, 'version'> {
  item: PlanItem;
  names: Map<number, string>;
}

/** One service of the plan: its state, provider, linked request, dependencies and the actions the server allows. */
export const PlanItemCard: React.FC<PlanItemCardProps> = ({ item, names, audience, readOnly, onRequest, onAction }) => {
  const state = ITEM_STATE[item.state];
  const reason = waitingReason(item);
  const progress = item.request ? REQUEST_PROGRESS[item.request.progress] : null;
  const parallelNames = item.parallel_with.map((id) => names.get(id)).filter((name): name is string => Boolean(name));

  return (
    <article
      className="rounded-2xl border border-[#E6EAF0] bg-white p-4 space-y-3 min-w-0"
      data-testid="plan-item"
      data-item-id={item.item_id}
      data-state={item.state}
      title={state.hint}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h4 className="text-sm font-bold text-[#172033] leading-snug break-words">{item.service.name_ar}</h4>
          {item.service.category && <p className="text-[11px] text-[#667085] mt-0.5">{item.service.category.name_ar}</p>}
        </div>
        <span className="inline-flex items-center gap-1.5 shrink-0 text-[11px] font-bold px-2 py-1 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-[#344054]" data-testid="item-state">
          <span className={`w-2 h-2 rounded-full ${DOT[state.tone]}`} />
          {state.label}
        </span>
      </div>

      {item.provider ? (
        <div className="flex items-center gap-2 text-xs text-[#344054]">
          {item.provider.has_logo ? (
            <PrivateImage
              load={(signal) => api.directory.logo(item.provider!.id, signal)}
              version={item.provider.id}
              alt=""
              className="w-7 h-7 rounded-md border border-[#E6EAF0] object-contain bg-white shrink-0"
              fallback={<ProviderInitial name={item.provider.name} />}
            />
          ) : (
            <ProviderInitial name={item.provider.name} />
          )}
          <span className="font-semibold break-words min-w-0">{item.provider.name}</span>
          <span className="text-[10px] text-[#667085] shrink-0">مزود معيَّن من الوزارة</span>
        </div>
      ) : item.request?.awarded_provider ? (
        <p className="text-xs text-[#344054]">المزود: <span className="font-semibold">{item.request.awarded_provider.name}</span></p>
      ) : null}

      {item.instructions_ar && <p className="text-xs text-[#475467] leading-relaxed whitespace-pre-line break-words">{item.instructions_ar}</p>}
      {audience === 'imc' && item.internal_notes && (
        <p className="text-[11px] p-2 rounded-lg bg-[#FEF5E7] text-[#7A5207] break-words"><span className="font-bold">داخلي: </span>{item.internal_notes}</p>
      )}

      {item.depends_on.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5 text-[11px]" data-testid="depends-on">
          <Link2 className="w-3.5 h-3.5 text-[#667085]" />
          <span className="text-[#667085]">بعد:</span>
          {item.depends_on.map((prerequisite) => (
            <span
              key={prerequisite.item_id}
              className={`px-2 py-0.5 rounded-full border ${
                prerequisite.execution_status === 'completed' ? 'bg-[#E7F8EE] border-[#C5F0D5] text-[#1D7E4C]' : 'bg-[#F7F9FC] border-[#E6EAF0] text-[#475467]'
              }`}
            >
              {prerequisite.execution_status === 'completed' && <CheckCircle2 className="w-3 h-3 inline me-0.5" />}
              {prerequisite.service_name_ar}
            </span>
          ))}
        </div>
      )}

      {parallelNames.length > 0 && (
        <p className="flex items-start gap-1.5 text-[11px] text-[#667085]" data-testid="parallel-with">
          <ArrowLeftRight className="w-3.5 h-3.5 shrink-0 mt-px" />
          <span className="break-words">بالتوازي مع: {parallelNames.join('، ')}</span>
        </p>
      )}

      {reason && (
        <p className="flex items-start gap-1.5 text-[11px] font-semibold text-[#A66F0B]" data-testid="waiting-reason">
          <Clock className="w-3.5 h-3.5 shrink-0 mt-px" />
          <span className="break-words">{reason}</span>
        </p>
      )}

      {progress && item.request && (
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant={progress.tone} size="sm">{progress.label}</Badge>
          <span className="text-[10px] text-[#98A2B3]" dir="ltr">#{item.request.id}</span>
        </div>
      )}

      {audience === 'imc' && item.attention && item.attention.length > 0 && (
        <ul className="space-y-1">
          {item.attention.map((code) => (
            <li key={code} className="flex items-start gap-1.5 text-[11px] text-[#B82B3B]">
              <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-px" /> {ATTENTION[code]}
            </li>
          ))}
        </ul>
      )}

      <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-[#667085]">
        {(item.planned_start_date || item.planned_end_date) && (
          <>
            <dt>المخطط</dt>
            <dd className="text-[#344054]" dir="ltr">{formatDate(item.planned_start_date)} → {formatDate(item.planned_end_date)}</dd>
          </>
        )}
        {item.started_at && (
          <>
            <dt>بدأ التنفيذ</dt>
            <dd className="text-[#344054]" dir="ltr">{formatDate(item.started_at)}</dd>
          </>
        )}
        {item.completed_at && (
          <>
            <dt>اكتمل</dt>
            <dd className="text-[#344054]" dir="ltr">{formatDate(item.completed_at)}</dd>
          </>
        )}
      </dl>

      {!readOnly && (
        <div className="flex flex-wrap gap-2 pt-1 border-t border-[#F1F4F9]">
          {audience === 'factory' ? (
            <>
              {item.service_available && (
                <Link to={`/factory/services/${item.service.id}`} className="inline-flex items-center gap-1 text-xs font-semibold text-[#5146A5] hover:underline px-1 py-1.5">
                  <Eye className="w-3.5 h-3.5" /> تفاصيل الخدمة
                </Link>
              )}
              {item.request && (
                <Link to={`/factory/requests/${item.request.id}`} className="inline-flex items-center gap-1 text-xs font-semibold text-[#5146A5] hover:underline px-1 py-1.5" data-testid="view-request">
                  متابعة الطلب
                </Link>
              )}
              {item.can_request && onRequest && (
                <Button size="sm" variant="primary" icon={Send} onClick={() => onRequest(item)} data-testid="request-item">
                  طلب الخدمة
                </Button>
              )}
            </>
          ) : (
            (item.allowed_actions ?? []).map((action) => (
              <Button
                key={action}
                size="sm"
                variant={ITEM_ACTION[action].variant}
                onClick={() => onAction?.(item, action)}
                data-testid={`item-action-${action}`}
              >
                {ITEM_ACTION[action].label}
              </Button>
            ))
          )}
        </div>
      )}
    </article>
  );
};

const ProviderInitial: React.FC<{ name: string }> = ({ name }) => (
  <span className="w-7 h-7 rounded-md bg-[#EEEAFE] text-[#5146A5] text-xs font-bold flex items-center justify-center shrink-0">{name.trim().charAt(0)}</span>
);
