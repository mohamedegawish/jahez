import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { Agreement, Contract } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { fieldErrorClass } from '../../lib/forms';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';

/** DOC section 6: the knowledge-transfer commitment trains at least two IMC engineers. */
const MIN_TRAINEES = 2;

interface ContractDraftModalProps {
  agreement: Agreement;
  onClose: () => void;
  onDrafted: (contract: Contract) => void;
}

/**
 * Either party may draft a contract for an agreement. A contract is only a DRAFT and is not legally
 * binding: there is no signing or approval step (OQ-17). Only one draft can be in force at a time (409).
 */
export const ContractDraftModal: React.FC<ContractDraftModalProps> = ({ agreement, onClose, onDrafted }) => {
  const [trainees, setTrainees] = useState('');
  const [plan, setPlan] = useState('');
  const [notes, setNotes] = useState('');

  const draft = useApiMutation(() =>
    api.agreements.draftContract(agreement.id, {
      knowledge_transfer: { trainees: Number(trainees), training_plan: plan.trim() },
      ...(notes.trim() ? { notes: notes.trim() } : {}),
    }),
  );

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await draft.run();
    if (result.ok) onDrafted(result.data);
  };

  const traineeErrors = fieldMessages(draft.error, 'knowledge_transfer.trainees');
  const planErrors = fieldMessages(draft.error, 'knowledge_transfer.training_plan');
  const noteErrors = fieldMessages(draft.error, 'notes');
  const generalFieldErrors = fieldMessages(draft.error, 'knowledge_transfer').filter((m) => !traineeErrors.includes(m) && !planErrors.includes(m));
  const hasFieldErrors = traineeErrors.length + planErrors.length + noteErrors.length > 0;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="إعداد مسودة عقد"
      subtitle={`اتفاقية رقم ${agreement.id} — ${agreement.service?.name_ar ?? ''}`}
      maxWidth="2xl"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={draft.pending}>
            إلغاء
          </Button>
          <Button type="submit" form="contract-form" variant="primary" size="sm" isLoading={draft.pending} disabled={trainees === '' || plan.trim() === ''}>
            حفظ المسودة
          </Button>
        </div>
      }
    >
      <form id="contract-form" onSubmit={handleSubmit} className="space-y-4 text-xs" noValidate>
        <div className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-[#A66F0B] leading-relaxed">
          هذه مسودة <strong>غير ملزمة قانونيًا</strong>. لا يوجد توقيع إلكتروني أو اعتماد للعقود حاليًا لأن الإطار القانوني للعقود لم يُعتمد بعد (OQ-17). لا يمكن أن توجد
          إلا مسودة واحدة سارية لكل اتفاقية؛ ألغِ الحالية أولًا لإعداد نسخة جديدة.
        </div>
        {draft.error !== null && !hasFieldErrors && generalFieldErrors.length === 0 && <ApiErrorState compact error={draft.error} />}
        <FieldError messages={generalFieldErrors} />

        <div>
          <label htmlFor="contract-trainees" className="font-bold text-[#172033] block mb-1">
            عدد مهندسي مركز تحديث الصناعة المراد تدريبهم (نقل المعرفة):
          </label>
          <input
            id="contract-trainees"
            type="number"
            min={MIN_TRAINEES}
            max={255}
            value={trainees}
            onChange={(e) => setTrainees(e.target.value)}
            className={`w-40 p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(traineeErrors.length > 0)}`}
            dir="ltr"
          />
          <p className="text-[11px] text-[#98A2B3] mt-1">لا يقل عن {MIN_TRAINEES} مهندسين وفق وثيقة الاعتماد (القسم 6).</p>
          <FieldError messages={traineeErrors} />
        </div>

        <div>
          <label htmlFor="contract-plan" className="font-bold text-[#172033] block mb-1">خطة التدريب:</label>
          <textarea
            id="contract-plan"
            rows={5}
            maxLength={10000}
            value={plan}
            onChange={(e) => setPlan(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(planErrors.length > 0)}`}
          />
          <FieldError messages={planErrors} />
        </div>

        <div>
          <label htmlFor="contract-notes" className="font-bold text-[#172033] block mb-1">ملاحظات (اختياري):</label>
          <textarea
            id="contract-notes"
            rows={3}
            maxLength={5000}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(noteErrors.length > 0)}`}
          />
          <FieldError messages={noteErrors} />
        </div>
      </form>
    </Modal>
  );
};
