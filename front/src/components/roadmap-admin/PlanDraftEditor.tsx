import React, { useMemo, useState } from 'react';
import { AlertTriangle, ArrowDown, ArrowUp, Plus, Save, Trash2 } from 'lucide-react';
import { api, isConflict } from '../../api';
import type { FactoryServiceEligibility, TransformationPlan } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { draftFromVersion, draftProblems, emptyItem, emptyStage, toDraftPayload, type DraftItem, type DraftModel, type DraftStage } from '../../lib/roadmap';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';

const input = 'w-full p-2 rounded-lg border border-[#E6EAF0] bg-white text-xs text-[#172033] focus:outline-none focus:border-[#6EC8FF]';
const label = 'block text-[11px] font-bold text-[#344054] mb-1';

function move<T>(list: T[], index: number, offset: -1 | 1): T[] {
  const target = index + offset;
  if (target < 0 || target >= list.length) return list;
  const copy = [...list];
  [copy[index], copy[target]] = [copy[target], copy[index]];
  return copy;
}

interface PlanDraftEditorProps {
  plan: TransformationPlan;
  eligibility: FactoryServiceEligibility;
  onSaved: (plan: TransformationPlan) => void;
}

/**
 * IMC edits a plan's draft (jahez_api ADR-025): stages in order, the services of each stage in order,
 * the provider IMC assigns, finish-to-start dependencies, instructions for the factory, internal notes
 * and planned dates. Only services the factory's readiness level makes available are offered. A save
 * sends the whole structure with the revision it was loaded from; a 409 means someone else saved first.
 */
export const PlanDraftEditor: React.FC<PlanDraftEditorProps> = ({ plan, eligibility, onSaved }) => {
  const draft = plan.draft!;
  const [model, setModel] = useState<DraftModel>(() => draftFromVersion(draft));
  const [dirty, setDirty] = useState(false);
  const names = useMemo(() => new Map(eligibility.services.map((entry) => [entry.service.code, entry.service.name_ar])), [eligibility.services]);
  const nameOf = (code: string) => names.get(code) ?? code;
  const problems = draftProblems(model, nameOf);
  const placed = model.stages.flatMap((stage) => stage.items.map((item) => item.service));

  const save = useApiMutation(() => api.transformationPlans.saveDraft(plan.id, toDraftPayload(model, draft.revision ?? 0)));

  const update = (mutate: (current: DraftModel) => DraftModel) => {
    setModel(mutate);
    setDirty(true);
  };
  const updateStage = (index: number, mutate: (stage: DraftStage) => DraftStage) =>
    update((current) => ({ ...current, stages: current.stages.map((stage, i) => (i === index ? mutate(stage) : stage)) }));
  const removeService = (code: string) =>
    update((current) => ({
      ...current,
      stages: current.stages.map((stage) => ({
        ...stage,
        items: stage.items.filter((item) => item.service !== code).map((item) => ({ ...item, depends_on: item.depends_on.filter((dep) => dep !== code) })),
      })),
    }));

  const handleSave = async () => {
    const result = await save.run();
    if (result.ok) {
      setDirty(false);
      onSaved(result.data);
    }
  };

  return (
    <div className="space-y-4" data-testid="draft-editor">
      <div className="jahez-card p-4 space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label className={label} htmlFor="plan-title">عنوان الخطة</label>
            <input id="plan-title" className={input} value={model.title} maxLength={200} onChange={(e) => update((m) => ({ ...m, title: e.target.value }))} />
          </div>
          <div>
            <label className={label} htmlFor="plan-change-note">ما الذي تغيّر ولماذا؟ (للوزارة، مطلوب من الإصدار الثاني)</label>
            <input id="plan-change-note" className={input} value={model.change_note} maxLength={2000} onChange={(e) => update((m) => ({ ...m, change_note: e.target.value }))} />
          </div>
        </div>
        <div>
          <label className={label} htmlFor="plan-summary">ملخص الخطة للمصنع</label>
          <textarea id="plan-summary" rows={2} className={input} value={model.summary_ar} maxLength={5000} onChange={(e) => update((m) => ({ ...m, summary_ar: e.target.value }))} />
        </div>
      </div>

      {model.stages.map((stage, index) => (
        <StageEditor
          key={stage.key}
          stage={stage}
          index={index}
          count={model.stages.length}
          eligibility={eligibility}
          placed={placed}
          planServices={placed}
          nameOf={nameOf}
          onChange={(mutate) => updateStage(index, mutate)}
          onMove={(offset) => update((m) => ({ ...m, stages: move(m.stages, index, offset) }))}
          onRemove={() => {
            for (const item of stage.items) removeService(item.service);
            update((m) => ({ ...m, stages: m.stages.filter((_, i) => i !== index) }));
          }}
          onRemoveService={removeService}
        />
      ))}

      <Button variant="outline" size="sm" icon={Plus} onClick={() => update((m) => ({ ...m, stages: [...m.stages, emptyStage(`المرحلة ${m.stages.length + 1}`)] }))} data-testid="add-stage">
        إضافة مرحلة
      </Button>

      {problems.length > 0 && (
        <ul className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] space-y-1" data-testid="draft-problems">
          {problems.map((problem) => (
            <li key={problem} className="flex items-start gap-1.5 text-xs text-[#7A5207]">
              <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-px" /> {problem}
            </li>
          ))}
        </ul>
      )}

      {save.error !== null && (
        isConflict(save.error) ? (
          <p className="p-3 rounded-xl bg-[#FDECEE] border border-[#F9C3C9] text-xs text-[#B82B3B]">
            عدّل مستخدم آخر المسودة بعد تحميلها. أعد تحميل الصفحة ثم طبّق تعديلاتك من جديد؛ لم يُحفظ شيء.
          </p>
        ) : (
          <ApiErrorState compact error={save.error} />
        )
      )}

      <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-2 p-3 rounded-xl bg-white/95 border border-[#E6EAF0] shadow-sm">
        <span className="text-xs text-[#667085]">
          المراجعة {draft.revision ?? 0}{dirty ? ' · تعديلات غير محفوظة' : ' · محفوظة'}
        </span>
        <Button variant="primary" size="sm" icon={Save} isLoading={save.pending} disabled={!dirty} onClick={handleSave} data-testid="save-draft">
          حفظ المسودة
        </Button>
      </div>
    </div>
  );
};

interface StageEditorProps {
  stage: DraftStage;
  index: number;
  count: number;
  eligibility: FactoryServiceEligibility;
  placed: string[];
  planServices: string[];
  nameOf: (code: string) => string;
  onChange: (mutate: (stage: DraftStage) => DraftStage) => void;
  onMove: (offset: -1 | 1) => void;
  onRemove: () => void;
  onRemoveService: (code: string) => void;
}

const StageEditor: React.FC<StageEditorProps> = ({ stage, index, count, eligibility, placed, planServices, nameOf, onChange, onMove, onRemove, onRemoveService }) => {
  const [adding, setAdding] = useState('');
  const available = eligibility.services.filter((entry) => !placed.includes(entry.service.code));
  const setField = (field: keyof Omit<DraftStage, 'key' | 'items'>) => (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    onChange((s) => ({ ...s, [field]: event.target.value }));
  const updateItem = (itemIndex: number, mutate: (item: DraftItem) => DraftItem) =>
    onChange((s) => ({ ...s, items: s.items.map((item, i) => (i === itemIndex ? mutate(item) : item)) }));

  return (
    <section className="jahez-card p-4 space-y-3" data-testid="stage-editor">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-bold text-[#5146A5]">المرحلة {index + 1}</h3>
        <div className="flex items-center gap-1">
          <Button variant="ghost" size="sm" icon={ArrowUp} disabled={index === 0} onClick={() => onMove(-1)} aria-label="نقل المرحلة لأعلى" />
          <Button variant="ghost" size="sm" icon={ArrowDown} disabled={index === count - 1} onClick={() => onMove(1)} aria-label="نقل المرحلة لأسفل" />
          <Button variant="ghost" size="sm" icon={Trash2} onClick={onRemove} aria-label="حذف المرحلة" />
        </div>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label className={label}>اسم المرحلة</label>
          <input className={input} value={stage.name_ar} maxLength={200} onChange={setField('name_ar')} data-testid="stage-name" />
        </div>
        <div>
          <label className={label}>الهدف</label>
          <input className={input} value={stage.objective_ar} onChange={setField('objective_ar')} />
        </div>
        <div className="md:col-span-2">
          <label className={label}>الوصف</label>
          <textarea rows={2} className={input} value={stage.description_ar} onChange={setField('description_ar')} />
        </div>
        <div>
          <label className={label}>تعليمات للمصنع</label>
          <textarea rows={2} className={input} value={stage.factory_instructions_ar} onChange={setField('factory_instructions_ar')} />
        </div>
        <div>
          <label className={label}>ملاحظات داخلية (لا تظهر للمصنع)</label>
          <textarea rows={2} className={input} value={stage.internal_notes} onChange={setField('internal_notes')} />
        </div>
        <div className="grid grid-cols-2 gap-2 md:col-span-2">
          <div>
            <label className={label}>بداية مخططة</label>
            <input type="date" className={input} value={stage.planned_start_date} onChange={setField('planned_start_date')} />
          </div>
          <div>
            <label className={label}>نهاية مخططة</label>
            <input type="date" className={input} value={stage.planned_end_date} onChange={setField('planned_end_date')} />
          </div>
        </div>
      </div>

      <div className="space-y-2">
        {stage.items.map((item, itemIndex) => (
          <ItemEditor
            key={item.service}
            item={item}
            index={itemIndex}
            count={stage.items.length}
            factoryId={eligibility.factory_id}
            serviceId={eligibility.services.find((entry) => entry.service.code === item.service)?.service.id ?? null}
            planServices={planServices}
            nameOf={nameOf}
            onChange={(mutate) => updateItem(itemIndex, mutate)}
            onMove={(offset) => onChange((s) => ({ ...s, items: move(s.items, itemIndex, offset) }))}
            onRemove={() => onRemoveService(item.service)}
          />
        ))}
      </div>

      <div className="flex flex-col sm:flex-row gap-2">
        <select className={input} value={adding} onChange={(e) => setAdding(e.target.value)} aria-label="خدمة لإضافتها" data-testid="add-service-select">
          <option value="">اختر خدمة متاحة لمستوى المصنع…</option>
          {available.map((entry) => (
            <option key={entry.service.code} value={entry.service.code}>
              {entry.service.name_ar} ({entry.eligible_providers_count} مزود مؤهل){entry.recommended ? ' · موصى بها' : ''}
            </option>
          ))}
        </select>
        <Button
          variant="outline"
          size="sm"
          icon={Plus}
          disabled={adding === ''}
          onClick={() => {
            onChange((s) => ({ ...s, items: [...s.items, emptyItem(adding)] }));
            setAdding('');
          }}
          data-testid="add-service"
        >
          إضافة الخدمة
        </Button>
      </div>
    </section>
  );
};

interface ItemEditorProps {
  item: DraftItem;
  index: number;
  count: number;
  factoryId: number;
  serviceId: number | null;
  planServices: string[];
  nameOf: (code: string) => string;
  onChange: (mutate: (item: DraftItem) => DraftItem) => void;
  onMove: (offset: -1 | 1) => void;
  onRemove: () => void;
}

const ItemEditor: React.FC<ItemEditorProps> = ({ item, index, count, factoryId, serviceId, planServices, nameOf, onChange, onMove, onRemove }) => {
  const providers = useApiQuery((signal) => api.serviceEligibility.providers(factoryId, serviceId as number, signal), [factoryId, serviceId], {
    enabled: serviceId !== null,
  });
  const others = planServices.filter((code) => code !== item.service);
  const setField = (field: 'instructions_ar' | 'internal_notes' | 'planned_start_date' | 'planned_end_date') => (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    onChange((i) => ({ ...i, [field]: event.target.value }));

  return (
    <div className="rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] p-3 space-y-2" data-testid="item-editor" data-service={item.service}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-bold text-[#172033] break-words min-w-0">
          {index + 1}. {nameOf(item.service)}
          {serviceId === null && <span className="ms-1 text-[#B82B3B]">(غير متاحة لمستوى المصنع)</span>}
        </p>
        <div className="flex items-center gap-1 shrink-0">
          <Button variant="ghost" size="sm" icon={ArrowUp} disabled={index === 0} onClick={() => onMove(-1)} aria-label="نقل الخدمة لأعلى" />
          <Button variant="ghost" size="sm" icon={ArrowDown} disabled={index === count - 1} onClick={() => onMove(1)} aria-label="نقل الخدمة لأسفل" />
          <Button variant="ghost" size="sm" icon={Trash2} onClick={onRemove} aria-label="إزالة الخدمة" />
        </div>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
        <div>
          <label className={label}>المزود المعيَّن (اختياري؛ ملزم للمصنع)</label>
          <select
            className={input}
            value={item.service_provider_id ?? ''}
            onChange={(e) => onChange((i) => ({ ...i, service_provider_id: e.target.value === '' ? null : Number(e.target.value) }))}
            data-testid="assign-provider"
          >
            <option value="">دون تعيين: يختار المصنع من المؤهلين</option>
            {(providers.data ?? []).map((provider) => (
              <option key={provider.id} value={provider.id}>{provider.name}</option>
            ))}
            {item.service_provider_id !== null && providers.data !== undefined && !providers.data.some((p) => p.id === item.service_provider_id) && (
              <option value={item.service_provider_id}>مزود لم يعد مؤهلًا (#{item.service_provider_id})</option>
            )}
          </select>
          {providers.data !== undefined && providers.data.length === 0 && <p className="text-[11px] text-[#A66F0B] mt-1">لا يوجد مزود مؤهل لهذه الخدمة لهذا المصنع حاليًا.</p>}
        </div>
        <div>
          <span className={label}>تبدأ بعد اكتمال</span>
          {others.length === 0 ? (
            <p className="text-[11px] text-[#98A2B3]">لا توجد خدمات أخرى في الخطة.</p>
          ) : (
            <div className="flex flex-wrap gap-1.5" data-testid="depends-on-editor">
              {others.map((code) => (
                <label key={code} className={`text-[11px] px-2 py-1 rounded-lg border cursor-pointer ${item.depends_on.includes(code) ? 'bg-[#EEEAFE] border-[#9B8AFB] text-[#5146A5]' : 'bg-white border-[#E6EAF0] text-[#475467]'}`}>
                  <input
                    type="checkbox"
                    className="sr-only"
                    checked={item.depends_on.includes(code)}
                    onChange={() => onChange((i) => ({ ...i, depends_on: i.depends_on.includes(code) ? i.depends_on.filter((dep) => dep !== code) : [...i.depends_on, code] }))}
                    data-dependency={code}
                  />
                  {nameOf(code)}
                </label>
              ))}
            </div>
          )}
        </div>
        <div>
          <label className={label}>تعليمات للمصنع</label>
          <textarea rows={2} className={input} value={item.instructions_ar} onChange={setField('instructions_ar')} />
        </div>
        <div>
          <label className={label}>ملاحظات داخلية</label>
          <textarea rows={2} className={input} value={item.internal_notes} onChange={setField('internal_notes')} />
        </div>
        <div className="grid grid-cols-2 gap-2 md:col-span-2">
          <div>
            <label className={label}>بداية مخططة</label>
            <input type="date" className={input} value={item.planned_start_date} onChange={setField('planned_start_date')} />
          </div>
          <div>
            <label className={label}>نهاية مخططة</label>
            <input type="date" className={input} value={item.planned_end_date} onChange={setField('planned_end_date')} />
          </div>
        </div>
      </div>
    </div>
  );
};
