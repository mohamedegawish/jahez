import React, { useMemo, useState } from 'react';
import { ArrowLeft, ArrowRight, RotateCcw } from 'lucide-react';
import { previewResult } from '../../lib/readinessDefinition';
import type { DefinitionPayload } from '../../lib/readinessDefinition';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { QuestionStep } from '../readiness/QuestionStep';

/**
 * The questionnaire as a factory member answers it, one question at a time, for IMC to check a draft
 * before publishing. Nothing is sent or stored; the total and level at the end follow the ranges
 * of the version being previewed, as the server would classify a real submission.
 */
export const QuestionnairePreview: React.FC<{ definition: DefinitionPayload; versionLabel: string; onClose: () => void }> = ({ definition, versionLabel, onClose }) => {
  const flat = useMemo(
    () => definition.pillars.flatMap((pillar, pillarIndex) => pillar.questions.map((question) => ({ question, pillar, pillarIndex }))),
    [definition],
  );
  const [step, setStep] = useState(0);
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const finished = step >= flat.length;

  const points = flat.map(({ question }) => question.choices.find((choice) => choice.code === answers[question.code])?.points ?? 0);
  const result = previewResult(definition, points);
  const category = definition.categories.find((candidate) => candidate.code === result.category);

  return (
    <Modal isOpen onClose={onClose} title="معاينة الاستبيان كما تراه المنشأة" subtitle={`${versionLabel} — معاينة فقط، لا يُحفظ شيء`} maxWidth="2xl">
      {flat.length === 0 ? (
        <p className="text-sm text-[#667085] text-center">الاستبيان لا يحتوي على أسئلة.</p>
      ) : finished ? (
        <div className="space-y-4 text-center" data-testid="preview-result">
          <div className="text-xs text-[#667085]">مجموع النقاط في هذه المعاينة</div>
          <div className="text-4xl font-extrabold text-[#5146A5]">{result.total}</div>
          <div className="text-sm font-bold text-[#172033]">{category ? `${category.name_ar} (${category.min_score}–${category.max_score})` : 'لا يقع المجموع في مستوى واحد'}</div>
          {category && <p className="text-xs text-[#667085] leading-relaxed max-w-lg mx-auto">{category.description_ar}</p>}
          <Button variant="outline" size="sm" icon={RotateCcw} onClick={() => { setAnswers({}); setStep(0); }}>
            إعادة المعاينة
          </Button>
        </div>
      ) : (
        <div className="space-y-6">
          <QuestionStep
            step={step}
            total={flat.length}
            pillarName={flat[step].pillar.name_ar}
            pillarIndex={flat[step].pillarIndex}
            questionText={flat[step].question.text_ar}
            choices={flat[step].question.choices.map((choice) => ({ key: choice.code, label_ar: choice.label_ar, text_ar: choice.text_ar }))}
            selected={answers[flat[step].question.code]}
            onSelect={(code) => setAnswers((previous) => ({ ...previous, [flat[step].question.code]: String(code) }))}
          />
          <div className="flex items-center justify-between pt-4 border-t border-[#E6EAF0]">
            <Button variant="outline" size="md" onClick={() => setStep(step - 1)} disabled={step === 0} icon={ArrowRight}>
              السابق
            </Button>
            <Button variant="primary" size="md" onClick={() => setStep(step + 1)} icon={ArrowLeft} iconPosition="left" disabled={answers[flat[step].question.code] === undefined}>
              {step === flat.length - 1 ? 'عرض النتيجة' : 'السؤال التالي'}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
};
