import React, { useMemo } from 'react';
import { CheckCircle2, Rocket, TriangleAlert } from 'lucide-react';
import { api } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { SECTION_LABELS } from '../../lib/readinessDefinition';
import type { DefinitionChange, DefinitionSection } from '../../lib/readinessDefinition';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import type { ReadinessWorkspace } from './useReadinessWorkspace';

/**
 * The review step before publishing a draft: every change compared with the version factories answer
 * now, the problems that block publication, and what publishing does and does not change.
 */
export const ReviewChangesModal: React.FC<{ workspace: ReadinessWorkspace; onClose: () => void; onPublished: () => void }> = ({ workspace, onClose, onPublished }) => {
  const draft = workspace.draft;
  const publish = useApiMutation(async () => {
    if (!draft) throw new Error('لا توجد مسودة للنشر.');
    if (workspace.dirty && !(await workspace.save())) throw workspace.saveError ?? new Error('تعذر حفظ المسودة قبل النشر.');
    return api.readinessVersions.publish(draft.id);
  });

  const grouped = useMemo(() => {
    const groups = new Map<DefinitionSection, DefinitionChange[]>();
    for (const change of workspace.changes) groups.set(change.section, [...(groups.get(change.section) ?? []), change]);
    return [...groups.entries()];
  }, [workspace.changes]);

  const blocked = workspace.problems.length > 0;

  const handlePublish = async () => {
    const result = await publish.run();
    if (result.ok) onPublished();
  };

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={`مراجعة تغييرات الإصدار ${draft?.version ?? ''} قبل النشر`}
      subtitle={`مقارنة بالإصدار ${workspace.current?.version ?? '—'} الذي تجيب عنه المصانع الآن`}
      maxWidth="4xl"
      footer={
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={publish.pending}>
            رجوع للتعديل
          </Button>
          <Button variant="success" size="sm" icon={Rocket} onClick={handlePublish} isLoading={publish.pending} disabled={blocked} data-testid="confirm-publish">
            {workspace.dirty ? 'حفظ ونشر الإصدار' : 'نشر الإصدار'}
          </Button>
        </div>
      }
    >
      <div className="space-y-4 text-xs" data-testid="review-changes">
        <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-[#475467] leading-relaxed">
          بعد النشر يصبح هذا الإصدار ما تجيب عنه المصانع، ولا يمكن تعديله بعد ذلك. التقييمات السابقة تحتفظ بإصدارها وإجاباتها ودرجتها
          ومستواها كما سُجّلت، ولا يُعاد حساب أي منها. حالة اعتماد المنشآت لا تتأثر بالنشر.
        </div>

        {blocked ? (
          <div role="alert" className="p-3 rounded-xl bg-[#FDECEE] border border-[#F7C6CD] text-[#B82B3B] space-y-1">
            <div className="font-bold flex items-center gap-1.5">
              <TriangleAlert className="w-4 h-4" /> لا يمكن النشر قبل معالجة ما يلي:
            </div>
            <ul className="list-disc pr-5 space-y-0.5">
              {workspace.problems.map((problem) => (
                <li key={problem}>{problem}</li>
              ))}
            </ul>
          </div>
        ) : (
          <div className="p-3 rounded-xl bg-[#E7F8EE] text-[#1D7E4C] font-semibold flex items-center gap-1.5">
            <CheckCircle2 className="w-4 h-4" /> البنية صالحة: خمسة محاور، سؤالان لكل محور، أربعة اختيارات بنقاط 1–4، وحدود تغطي 10–40.
          </div>
        )}

        {grouped.length === 0 ? (
          <p className="text-[#667085]">لا تختلف المسودة عن الإصدار الحالي.</p>
        ) : (
          grouped.map(([section, changes]) => (
            <section key={section} className="space-y-2">
              <h4 className="font-bold text-[#172033]">
                {SECTION_LABELS[section]} <span className="text-[#98A2B3] font-normal">({changes.length})</span>
              </h4>
              <ul className="space-y-1.5">
                {changes.map((change, index) => (
                  <li key={`${change.item}-${index}`} className="p-2.5 rounded-lg border border-[#E6EAF0] bg-white">
                    <div className="font-semibold text-[#344054] mb-1">{change.item}</div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      <div className="p-2 rounded-md bg-[#FDECEE]/50 text-[#7A2E39] break-words">
                        <span className="text-[10px] font-bold block mb-0.5">قبل</span>
                        {change.before || '—'}
                      </div>
                      <div className="p-2 rounded-md bg-[#E7F8EE]/60 text-[#1D5E3C] break-words">
                        <span className="text-[10px] font-bold block mb-0.5">بعد</span>
                        {change.after || '—'}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          ))
        )}

        {publish.error !== null && <ApiErrorState compact error={publish.error} />}
      </div>
    </Modal>
  );
};
