import React, { useState } from 'react';
import { Link2, Plus, X } from 'lucide-react';
import { fieldMessages } from '../../api';
import { useCatalogServices } from '../../hooks/useReference';
import { QUESTIONNAIRE_SHAPE } from '../../lib/readinessDefinition';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { FieldError } from '../ui/FieldError';
import { ServicePicker } from '../ui/ServicePicker';
import { EditorFrame } from './EditorFrame';
import { IconButton, ReorderButtons } from './controls';
import { CATEGORY_COLORS, inputClass, move } from './shared';
import type { ReadinessWorkspace } from './useReadinessWorkspace';

/**
 * «التوصيات وخارطة الطريق»: each level's focus, steps and recommended service lines (source §5). A line
 * maps to existing catalog services only; a line the catalog lacks stays unmapped (OQ-42). A
 * recommendation never makes a provider eligible.
 */
export const RoadmapTab: React.FC<{ workspace: ReadinessWorkspace; canManage: boolean }> = ({ workspace, canManage }) => {
  const services = useCatalogServices();
  const [mapping, setMapping] = useState<string | null>(null);
  const errorsAt = (path: string) => fieldMessages(workspace.saveError, path);
  const serviceName = (code: string) => services.data?.find((service) => service.code === code)?.name_ar ?? code;

  return (
    <EditorFrame workspace={workspace} canManage={canManage}>
      {(definition, editable) => (
        <div className="space-y-4" data-testid="roadmap-tab">
          <p className="text-xs text-[#667085] leading-relaxed">
            تظهر هذه التوصيات للمنشأة مع نتيجتها حسب مستواها. تُربط كل توصية بخدمات الدليل الموجودة فقط، ولا تُنشأ خدمة جديدة من هنا؛ التوصية لا تجعل
            أي مزود مؤهلًا.
          </p>
          {definition.categories.map((category, categoryIndex) => {
            const lines = category.recommendations ?? [];
            return (
              <Card
                key={category.code}
                title={`${category.name_ar} (${category.min_score}–${category.max_score})`}
                subtitle={`${lines.length} توصية`}
                accent="none"
              >
                <div className="space-y-3 text-xs" style={{ borderInlineStart: `3px solid ${CATEGORY_COLORS[category.code]}`, paddingInlineStart: '0.75rem' }}>
                  {editable ? (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      <label className="block">
                        <span className="text-[#667085] block mb-1">التركيز</span>
                        <textarea className={inputClass} rows={2} value={category.focus_ar} onChange={(e) => workspace.update((d) => { d.categories[categoryIndex].focus_ar = e.target.value; })} />
                      </label>
                      <label className="block">
                        <span className="text-[#667085] block mb-1">الخطوات</span>
                        <textarea className={inputClass} rows={2} value={category.steps_ar} onChange={(e) => workspace.update((d) => { d.categories[categoryIndex].steps_ar = e.target.value; })} />
                      </label>
                    </div>
                  ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[#475467] leading-relaxed">
                      <p><strong className="text-[#172033]">التركيز: </strong>{category.focus_ar}</p>
                      <p><strong className="text-[#172033]">الخطوات: </strong>{category.steps_ar}</p>
                    </div>
                  )}
                  <FieldError messages={[...errorsAt(`categories.${categoryIndex}.focus_ar`), ...errorsAt(`categories.${categoryIndex}.steps_ar`), ...errorsAt(`categories.${categoryIndex}.recommendations`)]} />

                  <ol className="space-y-2">
                    {lines.map((line, lineIndex) => {
                      const key = `${category.code}:${lineIndex}`;
                      return (
                        <li key={key} className="p-2.5 rounded-lg border border-[#E6EAF0] bg-[#F7F9FC] space-y-2">
                          <div className="flex items-start gap-2">
                            <span className="font-bold text-[#5146A5] mt-2 w-5 shrink-0">{lineIndex + 1}.</span>
                            {editable ? (
                              <input className={inputClass} aria-label={`نص التوصية ${lineIndex + 1}`} value={line.text_ar} maxLength={500} onChange={(e) => workspace.update((d) => { d.categories[categoryIndex].recommendations![lineIndex].text_ar = e.target.value; })} />
                            ) : (
                              <span className="flex-1 mt-1 text-[#172033] font-semibold">{line.text_ar}</span>
                            )}
                            {editable && (
                              <>
                                <ReorderButtons editable index={lineIndex} length={lines.length} label="التوصية" onMove={(offset) => workspace.update((d) => move(d.categories[categoryIndex].recommendations!, lineIndex, offset))} />
                                <IconButton label="ربط بخدمات الدليل" onClick={() => setMapping(mapping === key ? null : key)}>
                                  <Link2 className="w-3.5 h-3.5" />
                                </IconButton>
                                <IconButton label="حذف التوصية" disabled={lines.length === 1} onClick={() => workspace.update((d) => { d.categories[categoryIndex].recommendations!.splice(lineIndex, 1); })}>
                                  <X className="w-3.5 h-3.5" />
                                </IconButton>
                              </>
                            )}
                          </div>
                          <div className="flex flex-wrap gap-1.5 pr-7">
                            {line.services.length === 0 ? (
                              <span className="text-[11px] text-[#98A2B3]">غير مرتبطة بخدمة في الدليل</span>
                            ) : (
                              line.services.map((code) => (
                                <span key={code} className="text-[11px] px-2 py-0.5 rounded-full bg-[#DFF3FF] text-[#0A6EB0]">{serviceName(code)}</span>
                              ))
                            )}
                          </div>
                          <FieldError messages={[...errorsAt(`categories.${categoryIndex}.recommendations.${lineIndex}.text_ar`), ...errorsAt(`categories.${categoryIndex}.recommendations.${lineIndex}.services`)]} />
                          {editable && mapping === key && (
                            <div className="pr-7">
                              <ServicePicker value={line.services} onChange={(codes) => workspace.update((d) => { d.categories[categoryIndex].recommendations![lineIndex].services = codes; })} />
                            </div>
                          )}
                        </li>
                      );
                    })}
                  </ol>
                  {editable && (
                    <Button
                      variant="outline"
                      size="sm"
                      icon={Plus}
                      disabled={lines.length >= QUESTIONNAIRE_SHAPE.maxRecommendations}
                      onClick={() => workspace.update((d) => { d.categories[categoryIndex].recommendations = [...(d.categories[categoryIndex].recommendations ?? []), { text_ar: '', services: [] }]; })}
                    >
                      إضافة توصية
                    </Button>
                  )}
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </EditorFrame>
  );
};
