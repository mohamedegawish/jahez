import React from 'react';
import { fieldMessages } from '../../api';
import { QUESTIONNAIRE_SHAPE } from '../../lib/readinessDefinition';
import { Card } from '../ui/Card';
import { FieldError } from '../ui/FieldError';
import { EditorFrame } from './EditorFrame';
import { ReorderButtons } from './controls';
import { inputClass, move, selectClass } from './shared';
import type { ReadinessWorkspace } from './useReadinessWorkspace';

/**
 * «الأسئلة والاختيارات»: the ten questions with their four choices. In a draft the wording, the
 * labels, the order and which choice carries which of the points 1–4 may change; questions and
 * choices are neither added nor removed (ADR-018 addendum 2). The API checks the scale again on save.
 */
export const QuestionsTab: React.FC<{ workspace: ReadinessWorkspace; canManage: boolean }> = ({ workspace, canManage }) => {
  const errorsAt = (path: string) => fieldMessages(workspace.saveError, path);

  return (
    <EditorFrame workspace={workspace} canManage={canManage}>
      {(definition, editable) => {
        let number = 0;
        return (
          <div className="space-y-4" data-testid="questions-tab">
            <p className="text-xs text-[#667085] leading-relaxed">
              لكل سؤال {QUESTIONNAIRE_SHAPE.choicesPerQuestion} اختيارات بنقاط 1 و2 و3 و4، كل قيمة مرة واحدة؛ مجموع الإجابات من 10 إلى 40. الصياغة
              المنشورة في الإصدار الأول منقولة حرفيًا من وثيقة الإطار.
            </p>
            {definition.pillars.map((pillar, pillarIndex) => (
              <Card key={pillar.code} title={`المحور ${pillarIndex + 1}: ${pillar.name_ar}`} subtitle={pillar.name_en ?? undefined}>
                <div className="space-y-4 text-xs">
                  {pillar.questions.map((question, questionIndex) => {
                    number++;
                    const path = `pillars.${pillarIndex}.questions.${questionIndex}`;
                    return (
                      <div key={question.code} className="p-3 rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] space-y-2" data-question-code={question.code}>
                        <div className="flex items-center justify-between gap-2">
                          <span className="font-bold text-[#5146A5]">
                            س{number} <span className="text-[#98A2B3] font-mono" dir="ltr">({question.code})</span>
                          </span>
                          <ReorderButtons
                            editable={editable}
                            index={questionIndex}
                            length={pillar.questions.length}
                            label="السؤال"
                            onMove={(offset) => workspace.update((d) => move(d.pillars[pillarIndex].questions, questionIndex, offset))}
                          />
                        </div>
                        {editable ? (
                          <textarea className={inputClass} rows={2} aria-label={`نص السؤال ${number}`} value={question.text_ar} maxLength={2000} onChange={(e) => workspace.update((d) => { d.pillars[pillarIndex].questions[questionIndex].text_ar = e.target.value; })} />
                        ) : (
                          <p className="font-bold text-[#172033] leading-relaxed">{question.text_ar}</p>
                        )}
                        <FieldError messages={[...errorsAt(`${path}.text_ar`), ...errorsAt(`${path}.choices`), ...errorsAt(`${path}`)]} />
                        <div className="space-y-1.5">
                          {question.choices.map((choice, choiceIndex) =>
                            editable ? (
                              <div key={choice.code} className="flex flex-wrap sm:flex-nowrap items-center gap-2">
                                <input className={`${inputClass} w-14! text-center`} aria-label={`رمز الاختيار ${choiceIndex + 1} في السؤال ${number}`} value={choice.label_ar} maxLength={10} onChange={(e) => workspace.update((d) => { d.pillars[pillarIndex].questions[questionIndex].choices[choiceIndex].label_ar = e.target.value; })} />
                                <input className={`${inputClass} flex-1 min-w-48`} aria-label={`نص الاختيار ${choiceIndex + 1} في السؤال ${number}`} value={choice.text_ar} maxLength={2000} onChange={(e) => workspace.update((d) => { d.pillars[pillarIndex].questions[questionIndex].choices[choiceIndex].text_ar = e.target.value; })} />
                                <select className={`${selectClass} w-24!`} aria-label={`نقاط الاختيار ${choiceIndex + 1} في السؤال ${number}`} value={choice.points} onChange={(e) => workspace.update((d) => { d.pillars[pillarIndex].questions[questionIndex].choices[choiceIndex].points = Number(e.target.value); })}>
                                  {QUESTIONNAIRE_SHAPE.points.map((value) => (
                                    <option key={value} value={value}>{value} نقطة</option>
                                  ))}
                                </select>
                                <ReorderButtons
                                  editable
                                  index={choiceIndex}
                                  length={question.choices.length}
                                  label="الاختيار"
                                  onMove={(offset) => workspace.update((d) => move(d.pillars[pillarIndex].questions[questionIndex].choices, choiceIndex, offset))}
                                />
                              </div>
                            ) : (
                              <div key={choice.code} className="flex items-start justify-between gap-3 text-[#475467]">
                                <span>
                                  <span className="font-bold text-[#5146A5] ml-1">{choice.label_ar})</span>
                                  {choice.text_ar}
                                </span>
                                <span className="shrink-0 font-bold text-[#5146A5]">{choice.points} نقطة</span>
                              </div>
                            ),
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </Card>
            ))}
          </div>
        );
      }}
    </EditorFrame>
  );
};
