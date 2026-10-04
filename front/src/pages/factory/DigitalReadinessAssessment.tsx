import React, { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Award, Compass, RefreshCw } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { ReadinessAssessment, ReadinessQuestionnaire } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyFactoryId } from '../../hooks/useMyOrganization';
import { formatDate } from '../../lib/format';
import { newIdempotencyKey } from '../../lib/files';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { QuestionStep } from '../../components/readiness/QuestionStep';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ReadinessBadge } from '../../components/ui/ReadinessBadge';

export const DigitalReadinessAssessment: React.FC = () => {
  const factoryId = useMyFactoryId();
  const questionnaire = useApiQuery((signal) => api.readiness.questionnaire(signal), []);
  const history = useApiQuery((signal) => api.readiness.list(factoryId as number, { per_page: 10, signal }), [factoryId], {
    enabled: factoryId !== null,
  });
  const [starting, setStarting] = useState(false);
  const [submitted, setSubmitted] = useState<ReadinessAssessment | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  if (factoryId === null) {
    return <p className="text-sm text-[#667085] text-center py-10">حسابك غير مرتبط بمنشأة صناعية.</p>;
  }

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div className="text-center space-y-2">
        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#EEEAFE] text-[#5146A5] text-xs font-bold">
          أداة التقييم الوطني لجاهزية الثورة الصناعية الرابعة
        </div>
        <h2 className="text-2xl sm:text-3xl font-extrabold text-[#172033] tracking-tight">تقييم الجاهزية الرقمية للمصنع</h2>
        <p className="text-xs sm:text-sm text-[#667085] max-w-xl mx-auto leading-relaxed">
          أجب عن أسئلة الاستبيان؛ يحسب الخادم النتيجة ويحدد فئة الجاهزية وخارطة الطريق. لا يمكن تعديل التقييمات السابقة، وكل تقييم جديد
          يُضاف إلى السجل.
        </p>
      </div>

      <QueryBoundary query={history} loading={<CardSkeleton />}>
        {(page) => {
          const hasHistory = page.data.length > 0 || submitted !== null;
          if (starting || !hasHistory) {
            return (
              <QueryBoundary query={questionnaire} loading={<CardSkeleton />}>
                {(q) => (
                  <Questionnaire
                    key={q.version}
                    questionnaire={q}
                    factoryId={factoryId}
                    canCancel={hasHistory}
                    onCancel={() => setStarting(false)}
                    onReload={questionnaire.refetch}
                    onSubmitted={(result) => {
                      setSubmitted(result);
                      setSelectedId(null);
                      setStarting(false);
                      history.refetch();
                    }}
                  />
                )}
              </QueryBoundary>
            );
          }

          const shownId = selectedId ?? submitted?.id ?? page.data[0].id;
          return (
            <>
              {submitted && submitted.id === shownId ? (
                <AssessmentResult assessment={submitted} onRetake={() => setStarting(true)} />
              ) : (
                <ResultLoader factoryId={factoryId} assessmentId={shownId} onRetake={() => setStarting(true)} />
              )}
              <History
                items={page.data}
                total={page.meta.total}
                shownId={shownId}
                onSelect={(id) => {
                  setSelectedId(id);
                  setSubmitted(null);
                }}
              />
            </>
          );
        }}
      </QueryBoundary>
    </div>
  );
};

/* ───────────────────────── Questionnaire ───────────────────────── */

const Questionnaire: React.FC<{
  questionnaire: ReadinessQuestionnaire;
  factoryId: number;
  canCancel: boolean;
  onCancel: () => void;
  onReload: () => void;
  onSubmitted: (result: ReadinessAssessment) => void;
}> = ({ questionnaire, factoryId, canCancel, onCancel, onReload, onSubmitted }) => {
  const flat = useMemo(
    () => questionnaire.pillars.flatMap((pillar, pillarIndex) => pillar.questions.map((question) => ({ question, pillar, pillarIndex }))),
    [questionnaire],
  );
  const [step, setStep] = useState(0);
  // question id -> choice id. Nothing is pre-selected: every answer is the factory's own.
  const [answers, setAnswers] = useState<Record<number, number>>({});
  // One key per attempt: a double click or a retry after a lost response returns the assessment
  // already stored instead of recording a second one. A new attempt (remount) gets a new key.
  const [attemptKey] = useState(() => newIdempotencyKey('readiness'));

  const submit = useApiMutation(() =>
    api.readiness.submit(
      factoryId,
      {
        questionnaire_version: questionnaire.version,
        answers: flat.map(({ question }) => ({ question_id: question.id, choice_id: answers[question.id] })),
      },
      attemptKey,
    ),
  );

  if (flat.length === 0) return <p className="text-sm text-[#667085] text-center">الاستبيان لا يحتوي على أسئلة.</p>;

  const current = flat[step];
  const isLast = step === flat.length - 1;
  const answered = answers[current.question.id] !== undefined;
  const versionErrors = fieldMessages(submit.error, 'questionnaire_version');

  const handleNext = async () => {
    if (!isLast) return setStep(step + 1);
    const result = await submit.run();
    if (result.ok) onSubmitted(result.data);
  };

  return (
    <Card className="p-6 sm:p-8 space-y-6">
      <QuestionStep
        step={step}
        total={flat.length}
        pillarName={current.pillar.name_ar}
        pillarIndex={current.pillarIndex}
        questionText={current.question.text_ar}
        choices={current.question.choices.map((choice) => ({ key: choice.id, label_ar: choice.label_ar, text_ar: choice.text_ar }))}
        selected={answers[current.question.id]}
        onSelect={(choiceId) => setAnswers((prev) => ({ ...prev, [current.question.id]: Number(choiceId) }))}
      />

      {versionErrors.length > 0 ? (
        <div role="alert" className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs text-[#A66F0B] space-y-2">
          <div className="font-bold">تغيّر الاستبيان منذ أن بدأت. حمّل النسخة الحالية وأجب عنها من جديد.</div>
          <div className="opacity-80" dir="auto">{versionErrors[0]}</div>
          <Button variant="outline" size="sm" icon={RefreshCw} onClick={onReload}>
            تحميل الاستبيان الحالي
          </Button>
        </div>
      ) : (
        submit.error !== null && <ApiErrorState compact error={submit.error} />
      )}

      <div className="flex items-center justify-between pt-4 border-t border-[#E6EAF0]">
        <Button variant="outline" size="md" onClick={() => setStep(step - 1)} disabled={step === 0 || submit.pending} icon={ArrowRight}>
          السابق
        </Button>
        {canCancel && (
          <Button variant="ghost" size="md" onClick={onCancel} disabled={submit.pending}>
            إلغاء
          </Button>
        )}
        <Button
          variant="primary"
          size="md"
          onClick={handleNext}
          icon={ArrowLeft}
          iconPosition="left"
          disabled={!answered}
          isLoading={submit.pending}
        >
          {isLast ? 'إنهاء وإرسال التقييم' : 'السؤال التالي'}
        </Button>
      </div>
    </Card>
  );
};

/* ───────────────────────── Result ───────────────────────── */

const ResultLoader: React.FC<{ factoryId: number; assessmentId: number; onRetake: () => void }> = ({ factoryId, assessmentId, onRetake }) => {
  const result = useApiQuery((signal) => api.readiness.get(factoryId, assessmentId, signal), [factoryId, assessmentId]);
  return (
    <QueryBoundary query={result} loading={<CardSkeleton />}>
      {(assessment) => <AssessmentResult assessment={assessment} onRetake={onRetake} />}
    </QueryBoundary>
  );
};

const AssessmentResult: React.FC<{ assessment: ReadinessAssessment; onRetake: () => void }> = ({ assessment, onRetake }) => {
  const navigate = useNavigate();
  const category = assessment.category;
  const roadmap = category?.roadmap;

  return (
    <div className="space-y-6 animate-in zoom-in-95 duration-200">
      <Card className="p-6 sm:p-8 text-center space-y-6" accent="gradient">
        <div className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-[#5146A5] to-[#6EC8FF] text-white flex items-center justify-center mx-auto shadow-md">
          <Award className="w-8 h-8" />
        </div>

        <div>
          <span className="text-xs font-bold text-[#667085] uppercase tracking-wider">نتيجة التقييم المعتمدة لمنشأتكم</span>
          <div className="text-5xl font-black text-[#5146A5] my-2" data-testid="total-score">
            {assessment.total_score}
            {assessment.max_score !== undefined && <span className="text-xl text-[#98A2B3] font-bold"> من {assessment.max_score}</span>}
          </div>
          <div className="inline-block">
            <ReadinessBadge category={category} />
          </div>
          <p className="text-[11px] text-[#98A2B3] mt-2">
            أُجري في {formatDate(assessment.completed_at)}
            {assessment.submitted_by ? ` بواسطة ${assessment.submitted_by.name}` : ''}
          </p>
        </div>

        {category?.description_ar && (
          <p className="text-xs sm:text-sm text-[#667085] max-w-lg mx-auto leading-relaxed whitespace-pre-line">{category.description_ar}</p>
        )}

        {assessment.pillars && assessment.pillars.length > 0 && (
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-right pt-4 border-t border-[#E6EAF0]">
            {assessment.pillars.map((pillar) => (
              <div key={pillar.code} className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                <span className="text-[11px] text-[#667085] block truncate" title={pillar.name_ar}>{pillar.name_ar}</span>
                <div className="flex items-center justify-between mt-1">
                  <span className="font-bold text-[#172033] text-sm">
                    {pillar.score}/{pillar.max_score}
                  </span>
                  <div className="w-12 bg-[#E6EAF0] h-1.5 rounded-full overflow-hidden">
                    <div className="bg-[#5146A5] h-full" style={{ width: `${pillar.max_score > 0 ? (pillar.score / pillar.max_score) * 100 : 0}%` }} />
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        <div className="pt-4 border-t border-[#E6EAF0] flex flex-col sm:flex-row items-center justify-center gap-3">
          <Button variant="primary" size="lg" icon={Compass} onClick={() => navigate('/factory/catalog')}>
            استعراض الحلول الموصى بها لمصنعك
          </Button>
          <Button variant="outline" size="lg" onClick={onRetake}>
            إجراء تقييم جديد
          </Button>
        </div>
      </Card>

      {roadmap && (
        <Card title="خارطة الطريق المقترحة لفئتكم" accent="blue">
          <div className="space-y-4 text-xs text-[#172033]">
            {roadmap.focus_ar && (
              <div>
                <h5 className="font-bold text-[#5146A5] mb-1">محور التركيز</h5>
                <p className="leading-relaxed text-[#667085] whitespace-pre-line">{roadmap.focus_ar}</p>
              </div>
            )}
            {roadmap.steps_ar && (
              <div>
                <h5 className="font-bold text-[#5146A5] mb-1">الخطوات</h5>
                <p className="leading-relaxed text-[#667085] whitespace-pre-line">{roadmap.steps_ar}</p>
              </div>
            )}
            {roadmap.recommendations.length > 0 && (
              <div>
                <h5 className="font-bold text-[#5146A5] mb-2">التوصيات</h5>
                <ul className="space-y-2">
                  {roadmap.recommendations.map((rec) => (
                    <li key={rec.position} className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                      <div className="font-semibold leading-relaxed">{rec.text_ar}</div>
                      {rec.services.length > 0 ? (
                        <div className="flex flex-wrap gap-2 mt-2">
                          {rec.services.map((service) => (
                            <button
                              key={service.id}
                              type="button"
                              onClick={() => navigate(`/factory/services/${service.id}`)}
                              className="px-2.5 py-1 rounded-full bg-[#EEEAFE] text-[#5146A5] text-[11px] font-bold hover:bg-[#9B8AFB] hover:text-white transition-colors cursor-pointer"
                            >
                              {service.name_ar}
                            </button>
                          ))}
                        </div>
                      ) : (
                        <div className="text-[11px] text-[#98A2B3] mt-1.5">لا يوجد في الكتالوج الحالي خدمة مقابلة لهذه التوصية (OQ-42).</div>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        </Card>
      )}
    </div>
  );
};

/* ───────────────────────── History ───────────────────────── */

const History: React.FC<{
  items: ReadinessAssessment[];
  total: number;
  shownId: number;
  onSelect: (id: number) => void;
}> = ({ items, total, shownId, onSelect }) => (
  <Card title="سجل التقييمات" subtitle={`${total} تقييم — لا تُعدَّل التقييمات السابقة`}>
    <ul className="divide-y divide-[#F1F4F9] text-xs">
      {items.map((item) => (
        <li key={item.id}>
          <button
            type="button"
            onClick={() => onSelect(item.id)}
            aria-current={item.id === shownId}
            className={`w-full flex items-center justify-between gap-3 py-2.5 px-2 rounded-lg text-right cursor-pointer transition-colors ${
              item.id === shownId ? 'bg-[#EEEAFE]/50' : 'hover:bg-[#F7F9FC]'
            }`}
          >
            <span className="text-[#667085]">{formatDate(item.completed_at)}</span>
            <span className="font-bold text-[#172033]">{item.total_score}</span>
            <ReadinessBadge category={item.category} size="sm" />
          </button>
        </li>
      ))}
    </ul>
  </Card>
);
