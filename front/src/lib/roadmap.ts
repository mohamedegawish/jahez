import type {
  PlanDraftPayload,
  PlanItem,
  PlanItemAction,
  PlanItemAttention,
  PlanItemState,
  PlanProgress,
  PlanRequestProgress,
  PlanStageStatus,
  PlanVersionView,
  ReadinessCategoryCode,
  TransformationPlanStatus,
} from '../api/types';

/**
 * Display rules for transformation plans (jahez_api ADR-025). Every state shown here comes from the
 * API: the server computes each item's state from the recorded execution status, its prerequisites
 * and the linked request; these functions only label, group and prepare what it sent.
 */

export type Tone = 'blue' | 'purple' | 'success' | 'warning' | 'error' | 'neutral';

/** Item states (PlanItemState on the server). */
export const ITEM_STATE: Record<PlanItemState, { label: string; tone: Tone; hint: string }> = {
  waiting_prerequisites: { label: 'تنتظر متطلبًا سابقًا', tone: 'neutral', hint: 'تبدأ بعد اكتمال الخدمات التي تعتمد عليها.' },
  not_started: { label: 'لم تبدأ', tone: 'neutral', hint: 'لم يصل طلب هذه الخدمة إلى اتفاق معتمد بعد.' },
  awaiting_approval: { label: 'بانتظار اعتماد الوزارة', tone: 'warning', hint: 'الاتفاق مع المزود بانتظار مراجعة مركز تحديث الصناعة.' },
  ready: { label: 'جاهزة للبدء', tone: 'blue', hint: 'اكتملت المتطلبات واعتُمد الاتفاق؛ يسجّل المركز بدء التنفيذ.' },
  in_progress: { label: 'قيد التنفيذ', tone: 'purple', hint: 'سجّل مركز تحديث الصناعة بدء التنفيذ.' },
  on_hold: { label: 'متوقفة مؤقتًا', tone: 'warning', hint: 'أوقف المركز تنفيذ هذه الخدمة مؤقتًا.' },
  completed: { label: 'مكتملة', tone: 'success', hint: 'سجّل مركز تحديث الصناعة اكتمال هذه الخدمة.' },
  cancelled: { label: 'ملغاة', tone: 'error', hint: 'ألغى المركز هذه الخدمة من التنفيذ.' },
};

export const PLAN_STATUS: Record<TransformationPlanStatus, { label: string; tone: Tone }> = {
  draft: { label: 'مسودة لم تُنشر', tone: 'neutral' },
  published: { label: 'منشورة', tone: 'success' },
  suspended: { label: 'موقوفة مؤقتًا', tone: 'warning' },
  closed: { label: 'مغلقة', tone: 'neutral' },
};

export const STAGE_STATUS: Record<PlanStageStatus, { label: string; tone: Tone }> = {
  not_started: { label: 'لم تبدأ', tone: 'neutral' },
  in_progress: { label: 'قيد التنفيذ', tone: 'purple' },
  completed: { label: 'مكتملة', tone: 'success' },
  cancelled: { label: 'ملغاة', tone: 'error' },
};

/** Where the linked service request stands in the existing marketplace workflow. */
export const REQUEST_PROGRESS: Record<PlanRequestProgress, { label: string; tone: Tone }> = {
  open: { label: 'أُرسل الطلب', tone: 'blue' },
  negotiating: { label: 'قيد التفاوض', tone: 'purple' },
  no_active_provider: { label: 'لا يوجد مزود نشط على الطلب', tone: 'warning' },
  awaiting_imc_review: { label: 'اتفاق بانتظار مراجعة الوزارة', tone: 'warning' },
  agreed: { label: 'تم الاتفاق', tone: 'success' },
  imc_approved: { label: 'اتفاق معتمد من الوزارة', tone: 'success' },
  imc_rejected: { label: 'رفضت الوزارة الاتفاق', tone: 'error' },
  cancelled: { label: 'أُلغي الطلب', tone: 'neutral' },
};

/** IMC execution actions, recorded by IMC only (owner decision; OQ-52). */
export const ITEM_ACTION: Record<PlanItemAction, { label: string; variant: 'primary' | 'success' | 'danger' | 'outline'; description: string }> = {
  start: { label: 'تسجيل بدء التنفيذ', variant: 'primary', description: 'تُسجَّل الخدمة «قيد التنفيذ». يتحقق الخادم من اكتمال المتطلبات واعتماد الاتفاق.' },
  complete: { label: 'تسجيل الاكتمال', variant: 'success', description: 'تُسجَّل الخدمة «مكتملة»، فتصبح الخدمات المعتمدة عليها قابلة للبدء.' },
  hold: { label: 'إيقاف مؤقت', variant: 'outline', description: 'تُوقَف الخدمة مؤقتًا دون إلغائها.' },
  resume: { label: 'استئناف', variant: 'outline', description: 'تعود الخدمة إلى حالتها السابقة للإيقاف.' },
  cancel: { label: 'إلغاء من التنفيذ', variant: 'danger', description: 'تبقى الخدمة في الخطة ملغاة، وتظل الخدمات المعتمدة عليها بانتظار مراجعة الخطة.' },
  reopen: { label: 'إعادة فتح', variant: 'outline', description: 'تعود الخدمة الملغاة إلى «لم تبدأ».' },
};

export const ATTENTION: Record<PlanItemAttention, string> = {
  service_unavailable: 'الخدمة لم تعد متاحة لمستوى جاهزية المصنع الحالي.',
  no_active_provider: 'لا يوجد مزود نشط على الطلب (رفض أو سُحب).',
  agreement_rejected: 'رفضت الوزارة اتفاق هذه الخدمة؛ يمكن للمصنع إرسال طلب جديد.',
  prerequisite_cancelled: 'خدمة سابقة تعتمد عليها أُلغيت؛ راجعوا التبعيات في إصدار جديد.',
};

/** Publication review codes (TransformationPlanReview on the server). */
export const REVIEW_PROBLEM: Record<string, string> = {
  no_assessment: 'لم يُكمل المصنع تقييم الجاهزية الرقمية.',
  readiness_changed: 'تغيّر مستوى جاهزية المصنع بعد آخر نشر للخطة.',
  factory_not_approved: 'حساب المصنع غير معتمد بعد، فلا يستطيع إرسال الطلبات.',
  no_stages: 'الخطة بلا مراحل.',
  empty_stage: 'مرحلة بلا خدمات.',
  service_not_available: 'خدمة غير متاحة لمستوى جاهزية المصنع.',
  provider_not_eligible: 'المزود المعيَّن غير مؤهل لهذا المصنع وهذه الخدمة.',
  no_eligible_provider: 'لا يوجد مزود مؤهل لهذه الخدمة حاليًا.',
  dependency_cycle: 'التبعيات تكوّن حلقة مغلقة.',
  removed_item_in_use: 'حُذفت خدمة بدأ تنفيذها أو لها طلب قائم.',
};

export const LEVEL_LABEL: Record<ReadinessCategoryCode, string> = {
  b4_automation: 'المستوى الأول: ما قبل الأتمتة',
  basic: 'المستوى الثاني: مبتدئ',
  advanced: 'المستوى الثالث: متقدم',
  smart: 'المستوى الرابع: ذكي ومبتكر',
};

/** «3 من 5 خدمات مكتملة», leaving cancelled services out as the server does. */
export function progressLabel(progress: PlanProgress): string {
  const counted = progress.total - progress.cancelled;
  if (progress.total === 0) return 'لا خدمات بعد';
  if (counted === 0) return 'كل الخدمات ملغاة';
  return `${progress.completed} من ${counted} خدمات مكتملة`;
}

/**
 * Splits a stage's items into those that can run alongside each other now and those that wait for
 * another item of the same stage. Items depending only on earlier stages stay in the first group:
 * their cards show what they wait for.
 */
export function groupStageItems(items: PlanItem[]): { parallel: PlanItem[]; after: PlanItem[] } {
  const inStage = new Set(items.map((item) => item.item_id));
  const parallel: PlanItem[] = [];
  const after: PlanItem[] = [];
  for (const item of items) {
    (item.depends_on.some((prerequisite) => inStage.has(prerequisite.item_id)) ? after : parallel).push(item);
  }
  return { parallel, after };
}

/** Why an item cannot move on yet, for the factory: names only, never IMC notes. */
export function waitingReason(item: PlanItem): string | null {
  if (!item.service_available) return 'الخدمة غير متاحة لمستوى جاهزية منشأتكم الحالي، بانتظار مراجعة الوزارة للخطة.';
  if (item.state === 'waiting_prerequisites' && item.waiting_for.length > 0) {
    return `تبدأ بعد اكتمال: ${item.waiting_for.map((prerequisite) => prerequisite.service_name_ar).join('، ')}`;
  }
  if (item.state === 'awaiting_approval') return ITEM_STATE.awaiting_approval.hint;
  if (item.request?.progress === 'no_active_provider') return 'لا يوجد مزود نشط على الطلب الحالي.';
  return null;
}

// ───────────────────────── Draft editing (IMC) ─────────────────────────

export interface DraftItem {
  service: string;
  service_provider_id: number | null;
  instructions_ar: string;
  internal_notes: string;
  planned_start_date: string;
  planned_end_date: string;
  depends_on: string[];
}

export interface DraftStage {
  /** Local key for React lists; never sent. */
  key: string;
  name_ar: string;
  objective_ar: string;
  description_ar: string;
  factory_instructions_ar: string;
  internal_notes: string;
  planned_start_date: string;
  planned_end_date: string;
  items: DraftItem[];
}

export interface DraftModel {
  title: string;
  summary_ar: string;
  change_note: string;
  stages: DraftStage[];
}

let stageCounter = 0;
export function newStageKey(): string {
  stageCounter += 1;
  return `stage-${stageCounter}`;
}

export function emptyStage(name = ''): DraftStage {
  return {
    key: newStageKey(),
    name_ar: name,
    objective_ar: '',
    description_ar: '',
    factory_instructions_ar: '',
    internal_notes: '',
    planned_start_date: '',
    planned_end_date: '',
    items: [],
  };
}

export function emptyItem(service: string): DraftItem {
  return { service, service_provider_id: null, instructions_ar: '', internal_notes: '', planned_start_date: '', planned_end_date: '', depends_on: [] };
}

/** The editable model of a version, as IMC sees it (with internal notes). */
export function draftFromVersion(version: PlanVersionView): DraftModel {
  const codeOf = new Map<number, string>();
  for (const stage of version.stages) for (const item of stage.items) codeOf.set(item.item_id, item.service.code);

  return {
    title: version.title,
    summary_ar: version.summary_ar ?? '',
    change_note: version.change_note ?? '',
    stages: version.stages.map((stage) => ({
      key: newStageKey(),
      name_ar: stage.name_ar,
      objective_ar: stage.objective_ar ?? '',
      description_ar: stage.description_ar ?? '',
      factory_instructions_ar: stage.factory_instructions_ar ?? '',
      internal_notes: stage.internal_notes ?? '',
      planned_start_date: stage.planned_start_date ?? '',
      planned_end_date: stage.planned_end_date ?? '',
      items: stage.items.map((item) => ({
        service: item.service.code,
        service_provider_id: item.provider?.id ?? null,
        instructions_ar: item.instructions_ar ?? '',
        internal_notes: item.internal_notes ?? '',
        planned_start_date: item.planned_start_date ?? '',
        planned_end_date: item.planned_end_date ?? '',
        depends_on: item.depends_on.map((prerequisite) => codeOf.get(prerequisite.item_id)).filter((code): code is string => code !== undefined),
      })),
    })),
  };
}

const textOrNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

/** The PUT /draft body: the whole structure in the order shown. */
export function toDraftPayload(model: DraftModel, basedOnRevision: number): PlanDraftPayload {
  return {
    based_on_revision: basedOnRevision,
    title: model.title.trim(),
    summary_ar: textOrNull(model.summary_ar),
    change_note: textOrNull(model.change_note),
    stages: model.stages.map((stage) => ({
      name_ar: stage.name_ar.trim(),
      objective_ar: textOrNull(stage.objective_ar),
      description_ar: textOrNull(stage.description_ar),
      factory_instructions_ar: textOrNull(stage.factory_instructions_ar),
      internal_notes: textOrNull(stage.internal_notes),
      planned_start_date: textOrNull(stage.planned_start_date),
      planned_end_date: textOrNull(stage.planned_end_date),
      items: stage.items.map((item) => ({
        service: item.service,
        service_provider_id: item.service_provider_id,
        instructions_ar: textOrNull(item.instructions_ar),
        internal_notes: textOrNull(item.internal_notes),
        planned_start_date: textOrNull(item.planned_start_date),
        planned_end_date: textOrNull(item.planned_end_date),
        depends_on: [...new Set(item.depends_on)],
      })),
    })),
  };
}

/** One dependency cycle among the draft's services, as service codes, or null (a hint; the server decides). */
export function findCycle(model: DraftModel): string[] | null {
  const edges = new Map<string, string[]>();
  for (const stage of model.stages) for (const item of stage.items) edges.set(item.service, item.depends_on);

  const state = new Map<string, 1 | 2>();
  const path: string[] = [];
  const visit = (node: string): string[] | null => {
    if (state.get(node) === 2) return null;
    if (state.get(node) === 1) return [...path.slice(path.indexOf(node)), node];
    state.set(node, 1);
    path.push(node);
    for (const next of edges.get(node) ?? []) {
      if (!edges.has(next)) continue;
      const cycle = visit(next);
      if (cycle) return cycle;
    }
    path.pop();
    state.set(node, 2);
    return null;
  };

  for (const node of edges.keys()) {
    const cycle = visit(node);
    if (cycle) return cycle;
  }
  return null;
}

/** Problems the editor can see before saving; the API checks all of them again. */
export function draftProblems(model: DraftModel, nameOf: (code: string) => string = (code) => code): string[] {
  const problems: string[] = [];
  if (model.title.trim() === '') problems.push('عنوان الخطة مطلوب.');
  const placed = new Set<string>();
  model.stages.forEach((stage, index) => {
    if (stage.name_ar.trim() === '') problems.push(`المرحلة ${index + 1} بلا اسم.`);
    if (stage.items.length === 0) problems.push(`المرحلة ${index + 1} بلا خدمات.`);
    if (stage.planned_start_date && stage.planned_end_date && stage.planned_end_date < stage.planned_start_date) {
      problems.push(`تاريخ انتهاء المرحلة ${index + 1} قبل تاريخ بدايتها.`);
    }
    for (const item of stage.items) {
      if (placed.has(item.service)) problems.push(`الخدمة «${nameOf(item.service)}» مكررة في الخطة.`);
      placed.add(item.service);
    }
  });
  for (const stage of model.stages) {
    for (const item of stage.items) {
      for (const prerequisite of item.depends_on) {
        if (prerequisite === item.service) problems.push(`الخدمة «${nameOf(item.service)}» لا يمكن أن تعتمد على نفسها.`);
        else if (!placed.has(prerequisite)) problems.push(`الخدمة «${nameOf(item.service)}» تعتمد على خدمة ليست في الخطة.`);
      }
    }
  }
  const cycle = findCycle(model);
  if (cycle) problems.push(`التبعيات تكوّن حلقة: ${cycle.map(nameOf).join(' ← ')}`);
  return problems;
}
