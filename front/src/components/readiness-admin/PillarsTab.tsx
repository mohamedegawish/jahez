import React from 'react';
import { Layers } from 'lucide-react';
import { QUESTIONNAIRE_SHAPE } from '../../lib/readinessDefinition';
import { Card } from '../ui/Card';
import { EditorFrame } from './EditorFrame';
import { ReorderButtons } from './controls';
import { inputClass, move } from './shared';
import type { ReadinessWorkspace } from './useReadinessWorkspace';

/**
 * «محاور التقييم»: the five pillars of the source document. Their names and order may change in a
 * draft; their number and their questions do not (ADR-018 addendum 2).
 */
export const PillarsTab: React.FC<{ workspace: ReadinessWorkspace; canManage: boolean }> = ({ workspace, canManage }) => (
  <EditorFrame workspace={workspace} canManage={canManage}>
    {(definition, editable) => {
      let firstQuestion = 1;
      return (
        <div className="space-y-4" data-testid="pillars-tab">
          <p className="text-xs text-[#667085] leading-relaxed">
            {QUESTIONNAIRE_SHAPE.pillars} محاور، لكل محور سؤالان (حتى {QUESTIONNAIRE_SHAPE.questionsPerPillar * 4} نقاط للمحور). يُرقَّم كل سؤال حسب
            ترتيب المحاور.
          </p>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {definition.pillars.map((pillar, index) => {
              const from = firstQuestion;
              firstQuestion += pillar.questions.length;
              return (
                <Card key={pillar.code} className="p-4" accent={index % 2 === 0 ? 'blue' : 'purple'}>
                  <div className="flex items-start gap-3">
                    <div className="w-10 h-10 rounded-xl bg-[#EEEAFE] text-[#5146A5] flex items-center justify-center shrink-0 font-bold">{index + 1}</div>
                    <div className="flex-1 space-y-2 text-xs min-w-0">
                      {editable ? (
                        <>
                          <input className={inputClass} aria-label={`اسم المحور ${index + 1} بالعربية`} value={pillar.name_ar} maxLength={255} onChange={(e) => workspace.update((d) => { d.pillars[index].name_ar = e.target.value; })} />
                          <input className={inputClass} aria-label={`اسم المحور ${index + 1} بالإنجليزية`} dir="ltr" value={pillar.name_en ?? ''} maxLength={255} onChange={(e) => workspace.update((d) => { d.pillars[index].name_en = e.target.value || null; })} />
                        </>
                      ) : (
                        <>
                          <div className="font-bold text-sm text-[#172033]">{pillar.name_ar}</div>
                          {pillar.name_en && <div className="text-[#98A2B3]" dir="ltr">{pillar.name_en}</div>}
                        </>
                      )}
                      <div className="flex items-center gap-2 text-[#667085]">
                        <Layers className="w-3.5 h-3.5" />
                        الأسئلة {from}–{from + pillar.questions.length - 1}
                        <span className="text-[#98A2B3] font-mono" dir="ltr">({pillar.code})</span>
                      </div>
                    </div>
                    <ReorderButtons editable={editable} index={index} length={definition.pillars.length} label="المحور" onMove={(offset) => workspace.update((d) => move(d.pillars, index, offset))} />
                  </div>
                </Card>
              );
            })}
          </div>
        </div>
      );
    }}
  </EditorFrame>
);
