import React from 'react';
import { CheckCircle2, TriangleAlert } from 'lucide-react';
import { fieldMessages } from '../../api';
import { rangesCoverScores, scoreRange } from '../../lib/readinessDefinition';
import { Card } from '../ui/Card';
import { FieldError } from '../ui/FieldError';
import { EditorFrame } from './EditorFrame';
import { CATEGORY_COLORS, inputClass } from './shared';
import type { ReadinessWorkspace } from './useReadinessWorkspace';

/**
 * «مستويات الجاهزية»: the four fixed levels of the source document with their score ranges. A draft may
 * change their texts and bounds; the ranges must cover every total from 10 to 40 exactly once.
 */
export const LevelsTab: React.FC<{ workspace: ReadinessWorkspace; canManage: boolean }> = ({ workspace, canManage }) => {
  const errorsAt = (path: string) => fieldMessages(workspace.saveError, path);

  return (
    <EditorFrame workspace={workspace} canManage={canManage}>
      {(definition, editable) => {
        const range = scoreRange(definition);
        const covers = rangesCoverScores(definition);
        const span = Math.max(1, range.max - range.min + 1);
        return (
          <div className="space-y-4" data-testid="levels-tab">
            <Card title="تغطية الدرجات" subtitle={`كل مجموع ممكن من ${range.min} إلى ${range.max} يقع في مستوى واحد فقط`}>
              <div className="flex h-8 rounded-lg overflow-hidden border border-[#E6EAF0]" dir="ltr" aria-hidden="true">
                {[...definition.categories]
                  .sort((a, b) => a.min_score - b.min_score)
                  .map((category) => (
                    <div
                      key={category.code}
                      className="h-full flex items-center justify-center text-[10px] font-bold text-white"
                      style={{ width: `${(Math.max(0, category.max_score - category.min_score + 1) * 100) / span}%`, backgroundColor: CATEGORY_COLORS[category.code] }}
                    >
                      {category.min_score}–{category.max_score}
                    </div>
                  ))}
              </div>
              <p className={`mt-3 text-xs font-semibold flex items-center gap-1.5 ${covers ? 'text-[#1D7E4C]' : 'text-[#B82B3B]'}`} role="status">
                {covers ? <CheckCircle2 className="w-4 h-4" /> : <TriangleAlert className="w-4 h-4" />}
                {covers
                  ? `الحدود تغطي كل الدرجات من ${range.min} إلى ${range.max} دون فجوات أو تداخل.`
                  : `يجب أن تغطي الحدود كل الدرجات من ${range.min} إلى ${range.max} مرة واحدة دون فجوات أو تداخل.`}
              </p>
              <FieldError messages={errorsAt('categories')} />
            </Card>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
              {definition.categories.map((category, index) => (
                <Card key={category.code} className="p-4">
                  <div className="flex items-center gap-2 mb-3">
                    <span className="w-3 h-3 rounded-full" style={{ backgroundColor: CATEGORY_COLORS[category.code] }} />
                    <span className="font-bold text-sm text-[#172033]">{category.name_ar}</span>
                    <span className="text-xs text-[#98A2B3]" dir="ltr">{category.name_en}</span>
                  </div>
                  {editable ? (
                    <div className="space-y-2 text-xs">
                      <div className="grid grid-cols-2 gap-2">
                        <input className={inputClass} aria-label={`اسم المستوى ${index + 1} بالعربية`} value={category.name_ar} onChange={(e) => workspace.update((d) => { d.categories[index].name_ar = e.target.value; })} />
                        <input className={inputClass} aria-label={`اسم المستوى ${index + 1} بالإنجليزية`} dir="ltr" value={category.name_en} onChange={(e) => workspace.update((d) => { d.categories[index].name_en = e.target.value; })} />
                      </div>
                      <div className="grid grid-cols-2 gap-2">
                        <label className="block">
                          <span className="text-[#667085] block mb-1">من درجة</span>
                          <input className={`${inputClass} text-center`} type="number" min={range.min} max={range.max} dir="ltr" aria-label={`أدنى درجة للمستوى ${index + 1}`} value={category.min_score} onChange={(e) => workspace.update((d) => { d.categories[index].min_score = Number(e.target.value); })} />
                        </label>
                        <label className="block">
                          <span className="text-[#667085] block mb-1">إلى درجة</span>
                          <input className={`${inputClass} text-center`} type="number" min={range.min} max={range.max} dir="ltr" aria-label={`أعلى درجة للمستوى ${index + 1}`} value={category.max_score} onChange={(e) => workspace.update((d) => { d.categories[index].max_score = Number(e.target.value); })} />
                        </label>
                      </div>
                      <textarea className={inputClass} rows={3} aria-label={`وصف المستوى ${index + 1}`} value={category.description_ar} onChange={(e) => workspace.update((d) => { d.categories[index].description_ar = e.target.value; })} />
                      <FieldError messages={['name_ar', 'name_en', 'description_ar', 'min_score', 'max_score'].flatMap((key) => errorsAt(`categories.${index}.${key}`))} />
                    </div>
                  ) : (
                    <div className="space-y-2 text-xs">
                      <div className="text-[#5146A5] font-extrabold text-lg" dir="ltr">
                        {category.min_score} – {category.max_score}
                      </div>
                      <p className="text-[#667085] leading-relaxed">{category.description_ar}</p>
                    </div>
                  )}
                </Card>
              ))}
            </div>
          </div>
        );
      }}
    </EditorFrame>
  );
};
