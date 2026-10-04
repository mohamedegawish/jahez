import React from 'react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { Button } from '../ui/Button';
import { CardSkeleton } from '../ui/LoadingState';
import { Modal } from '../ui/Modal';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReadinessBadge } from '../ui/ReadinessBadge';

/**
 * One submitted readiness assessment as stored (ADR-018): the questionnaire version answered, each
 * answer with the text and points recorded at submission, the pillar scores and the server-computed
 * total and category, with the calculation.
 * Read-only: nothing an administrator does here changes a result.
 */
export const AssessmentDetailModal: React.FC<{ factoryId: number; assessmentId: number; factoryName?: string; onClose: () => void }> = ({
  factoryId,
  assessmentId,
  factoryName,
  onClose,
}) => {
  const assessment = useApiQuery((signal) => api.readiness.get(factoryId, assessmentId, signal), [factoryId, assessmentId]);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={`نتيجة تقييم الجاهزية رقم ${assessmentId}`}
      subtitle={factoryName}
      maxWidth="2xl"
      footer={
        <Button variant="outline" size="sm" onClick={onClose}>
          إغلاق
        </Button>
      }
    >
      <QueryBoundary query={assessment} loading={<CardSkeleton />}>
        {(a) => {
          const answers = a.answers ?? [];
          return (
            <div className="space-y-5 text-xs">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <Fact label="الدرجة الكلية" value={`${a.total_score}${a.max_score ? ` من ${a.max_score}` : ''}`} />
                <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                  <span className="text-[10px] text-[#98A2B3] block">الفئة</span>
                  <ReadinessBadge category={a.category} size="sm" />
                </div>
                <Fact label="إصدار الاستبيان" value={a.questionnaire_version === null ? '—' : String(a.questionnaire_version)} />
                <Fact label="تاريخ الإكمال" value={formatDateTime(a.completed_at)} />
              </div>

              {(a.pillars ?? []).length > 0 && (
                <div className="space-y-2">
                  <h4 className="font-bold text-[#172033]">درجات المحاور</h4>
                  {(a.pillars ?? []).map((pillar) => (
                    <div key={pillar.code} className="flex items-center gap-3">
                      <span className="w-48 text-[#667085] truncate">{pillar.name_ar}</span>
                      <div className="flex-1 h-2 rounded-full bg-[#F1F4F9] overflow-hidden">
                        <div className="h-full bg-[#9B8AFB] rounded-full" style={{ width: `${pillar.max_score ? (pillar.score * 100) / pillar.max_score : 0}%` }} />
                      </div>
                      <span className="font-bold text-[#172033] w-12 text-left" dir="ltr">
                        {pillar.score}/{pillar.max_score}
                      </span>
                    </div>
                  ))}
                </div>
              )}

              {answers.length > 0 && (
                <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] space-y-1" data-testid="score-calculation">
                  <h4 className="font-bold text-[#172033]">حساب الدرجة</h4>
                  <p className="text-[#475467] leading-relaxed" dir="ltr">
                    {answers.map((answer) => answer.points).join(' + ')} = <strong className="text-[#5146A5]">{a.total_score}</strong>
                  </p>
                  <p className="text-[#667085]">
                    مجموع نقاط الاختيارات كما سُجّلت عند الإرسال، ويقع في مستوى «{a.category?.name_ar ?? '—'}» ({a.category?.min_score}–{a.category?.max_score}) من
                    حدود الإصدار {a.questionnaire_version}. لا يُعاد الحساب بعد نشر إصدار جديد.
                  </p>
                </div>
              )}

              <div className="space-y-2">
                <h4 className="font-bold text-[#172033]">الإجابات ({answers.length})</h4>
                <ol className="space-y-2">
                  {answers.map((answer) => {
                    // The text the answer was given with (stored with it), not today's wording.
                    return (
                      <li key={answer.question_id} className="p-3 rounded-xl border border-[#E6EAF0]" data-answer-question={answer.question_code ?? ''}>
                        <div className="flex items-start justify-between gap-3">
                          <span className="font-semibold text-[#172033] leading-relaxed">
                            {answer.question_number}. {answer.question_text_ar ?? answer.question_code}
                          </span>
                          <span className="shrink-0 px-2 py-0.5 rounded-full bg-[#EEEAFE] text-[#5146A5] font-bold">{answer.points} نقاط</span>
                        </div>
                        <p className="mt-1 text-[#667085] leading-relaxed">
                          {answer.choice_label_ar}) {answer.choice_text_ar ?? ''}
                        </p>
                      </li>
                    );
                  })}
                </ol>
              </div>
            </div>
          );
        }}
      </QueryBoundary>
    </Modal>
  );
};

const Fact: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
    <span className="text-[10px] text-[#98A2B3] block">{label}</span>
    <span className="font-bold text-[#172033]">{value}</span>
  </div>
);
