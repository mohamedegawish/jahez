import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { EvaluationCriterion, ProviderEvaluation } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useEvaluationCriteria } from '../../hooks/useReference';
import { formatDate, today } from '../../lib/format';
import { fieldErrorClass } from '../../lib/forms';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { FieldError } from '../ui/FieldError';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';
import { UnavailableNotice } from '../ui/UnavailableNotice';

/* ───────────────────────── Evaluation ───────────────────────── */

export const EvaluationPanel: React.FC<{
  providerId: number;
  evaluations: ReturnType<typeof useApiQuery<ProviderEvaluation[]>>;
  onRecorded: () => void;
}> = ({ providerId, evaluations, onRecorded }) => {
  const criteria = useEvaluationCriteria();
  return (
    <Card
      title="لوحة التقييم الفني"
      subtitle="معايير الاعتماد الخمسة المعتمدة من مركز تحديث الصناعة. تُسجَّل التقييمات ولا تُعدَّل."
      accent="blue"
    >
      <QueryBoundary query={criteria} loading={<CardSkeleton />}>
        {(matrix) => (
          <EvaluationForm
            providerId={providerId}
            criteria={matrix}
            // A scale is known only from evaluations that already used one (OQ-13).
            knownScale={(evaluations.data ?? []).find((e) => e.scale_max !== null)?.scale_max ?? null}
            onRecorded={onRecorded}
          />
        )}
      </QueryBoundary>

      <div className="mt-6 pt-4 border-t border-[#E6EAF0]">
        <h5 className="font-bold text-xs text-[#172033] mb-3">سجل التقييمات</h5>
        <QueryBoundary
          query={evaluations}
          loading={<CardSkeleton />}
          isEmpty={(list) => list.length === 0}
          empty={<p className="text-xs text-[#667085]">لم يُسجَّل أي تقييم لهذا المزود بعد.</p>}
        >
          {(list) => (
            <ul className="space-y-3">
              {list.map((evaluation) => (
                <EvaluationItem key={evaluation.id} evaluation={evaluation} />
              ))}
            </ul>
          )}
        </QueryBoundary>
      </div>
    </Card>
  );
};

const EvaluationForm: React.FC<{
  providerId: number;
  criteria: EvaluationCriterion[];
  knownScale: number | null;
  onRecorded: () => void;
}> = ({ providerId, criteria, knownScale, onRecorded }) => {
  const [summary, setSummary] = useState('');
  const [evaluatedOn, setEvaluatedOn] = useState(today());
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [scores, setScores] = useState<Record<string, string>>({});
  const [scoresRequired, setScoresRequired] = useState(false);

  const showScores = knownScale !== null || scoresRequired;

  const save = useApiMutation(() =>
    api.serviceProviders.evaluations.create(providerId, {
      summary: summary.trim(),
      evaluated_on: evaluatedOn,
      criteria: Object.fromEntries(
        criteria.map((c) => [c.code, { note: (notes[c.code] ?? '').trim(), ...(showScores ? { score: scores[c.code] ?? '' } : {}) }]),
      ),
    }),
  );

  const handleSave = async () => {
    const result = await save.run();
    if (result.ok) {
      setSummary('');
      setNotes({});
      setScores({});
      onRecorded();
    } else if (fieldMessagesAnyScore(result.error)) {
      // The API requires scores once the owner approves a scale (OQ-13): reveal the inputs.
      setScoresRequired(true);
    }
  };

  const summaryErrors = fieldMessages(save.error, 'summary');
  const dateErrors = fieldMessages(save.error, 'evaluated_on');
  const generalErrors = fieldMessages(save.error, 'criteria').filter((m) => !criteria.some((c) => m.includes(c.code)));
  const hasFieldErrors = summaryErrors.length + dateErrors.length > 0 || criteria.some((c) => fieldMessages(save.error, `criteria.${c.code}`).length > 0);
  const complete = summary.trim() !== '' && criteria.every((c) => (notes[c.code] ?? '').trim() !== '') && (!showScores || criteria.every((c) => (scores[c.code] ?? '') !== ''));

  return (
    <div className="space-y-4 text-xs">
      {!showScores && (
        <UnavailableNotice kind="decision" title="درجات المعايير ودرجة النجاح" decisionNeeded="OQ-13">
          لم يعتمد المركز بعد مقياس الدرجات ولا درجة النجاح، لذلك يُسجَّل التقييم نصيًا بملاحظة لكل معيار ولا تُحسب نسبة. القرار بالاعتماد
          يدوي من المركز.
        </UnavailableNotice>
      )}
      {save.error !== null && !hasFieldErrors && generalErrors.length === 0 && <ApiErrorState compact error={save.error} />}
      <FieldError messages={generalErrors} />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div className="sm:col-span-2">
          <label htmlFor="eval-summary" className="font-bold text-[#172033] block mb-1">ملخص التقييم:</label>
          <textarea
            id="eval-summary"
            rows={3}
            value={summary}
            maxLength={5000}
            onChange={(e) => setSummary(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(summaryErrors.length > 0)}`}
          />
          <FieldError messages={summaryErrors} />
        </div>
        <div>
          <label htmlFor="eval-date" className="font-bold text-[#172033] block mb-1">تاريخ التقييم:</label>
          <input
            id="eval-date"
            type="date"
            value={evaluatedOn}
            max={today()}
            onChange={(e) => setEvaluatedOn(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(dateErrors.length > 0)}`}
            dir="ltr"
          />
          <FieldError messages={dateErrors} />
        </div>
      </div>

      {criteria.map((criterion, index) => {
        const errors = fieldMessages(save.error, `criteria.${criterion.code}`);
        return (
          <div key={criterion.code} data-criterion={criterion.code} className="p-3.5 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
            <div className="flex items-start justify-between gap-3 mb-2">
              <div>
                <span className="font-bold text-[#172033]">
                  {index + 1}. {criterion.name_ar}
                </span>
                {criterion.sub_elements_ar && <span className="text-[11px] text-[#667085] block mt-0.5 leading-relaxed">{criterion.sub_elements_ar}</span>}
                {criterion.verification_ar && <span className="text-[11px] text-[#98A2B3] block mt-0.5">أسلوب التحقق: {criterion.verification_ar}</span>}
              </div>
              <span className="font-extrabold text-[#5146A5] text-sm shrink-0">{Number(criterion.weight_percent)}%</span>
            </div>
            <textarea
              aria-label={`ملاحظة معيار ${criterion.name_ar}`}
              rows={2}
              maxLength={2000}
              value={notes[criterion.code] ?? ''}
              onChange={(e) => setNotes((prev) => ({ ...prev, [criterion.code]: e.target.value }))}
              placeholder="ملاحظة المقيِّم على هذا المعيار (مطلوبة)"
              className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(errors.length > 0)}`}
            />
            {showScores && (
              <input
                aria-label={`درجة معيار ${criterion.name_ar}`}
                type="number"
                step="0.01"
                min={0}
                max={knownScale ?? undefined}
                value={scores[criterion.code] ?? ''}
                onChange={(e) => setScores((prev) => ({ ...prev, [criterion.code]: e.target.value }))}
                placeholder={knownScale !== null ? `الدرجة من ${knownScale}` : 'الدرجة'}
                className="mt-2 w-40 p-2 rounded-xl border border-[#E6EAF0] bg-white"
                dir="ltr"
              />
            )}
            <FieldError messages={errors} />
          </div>
        );
      })}

      <div className="flex justify-end">
        <Button variant="primary" size="md" onClick={handleSave} isLoading={save.pending} disabled={!complete}>
          تسجيل التقييم
        </Button>
      </div>
    </div>
  );
};

/** True when a 422 complains about a missing per-criterion score. */
function fieldMessagesAnyScore(error: unknown): boolean {
  if (typeof error !== 'object' || error === null || !('fieldErrors' in error)) return false;
  return Object.keys((error as { fieldErrors: Record<string, string[]> }).fieldErrors).some((key) => key.endsWith('.score'));
}

const EvaluationItem: React.FC<{ evaluation: ProviderEvaluation }> = ({ evaluation }) => (
  <li className="p-3.5 rounded-xl border border-[#E6EAF0] bg-white text-xs space-y-2" data-evaluation-id={evaluation.id}>
    <div className="flex items-center justify-between gap-3">
      <span className="font-bold text-[#172033]">{formatDate(evaluation.evaluated_on)}</span>
      <span className="text-[#98A2B3]">{evaluation.recorded_by ? `سجّله ${evaluation.recorded_by.name}` : ''}</span>
    </div>
    <p className="text-[#667085] leading-relaxed whitespace-pre-line" dir="auto">{evaluation.summary}</p>
    {evaluation.scoring === 'scored' ? (
      <div className="p-2.5 rounded-lg bg-[#EEEAFE]/50 text-[#5146A5] font-semibold">
        المجموع المرجَّح: {evaluation.weighted_total} من 100
        {evaluation.meets_pass_mark !== null && (
          <span className="mr-2 font-normal text-[#667085]">
            ({evaluation.meets_pass_mark ? 'يبلغ' : 'لا يبلغ'} درجة النجاح — للاطلاع فقط، والاعتماد قرار يدوي)
          </span>
        )}
      </div>
    ) : (
      <div className="text-[11px] text-[#98A2B3]">تقييم نصي بلا درجات (مقياس الدرجات غير معتمد بعد).</div>
    )}
    <details className="group">
      <summary className="cursor-pointer text-[#5146A5] font-semibold">ملاحظات المعايير</summary>
      <ul className="mt-2 space-y-1.5">
        {evaluation.criteria.map((c) => (
          <li key={c.code ?? c.name_ar} className="p-2 rounded-lg bg-[#F7F9FC]">
            <span className="font-semibold text-[#172033]">{c.name_ar}</span>
            {c.score !== null && <span className="text-[#5146A5] font-bold"> — {c.score}</span>}
            <div className="text-[#667085] mt-0.5 whitespace-pre-line" dir="auto">{c.note}</div>
          </li>
        ))}
      </ul>
    </details>
  </li>
);
