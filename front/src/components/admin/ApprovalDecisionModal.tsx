import React, { useState } from 'react';
import { fieldMessages } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { fieldErrorClass } from '../../lib/forms';
import { decisionNeedsReason, type ApprovalDecision } from '../../lib/provider';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';

/** What is being decided: a provider, a factory account, or one listed service. */
export type DecisionSubject = 'provider' | 'factory' | 'listing';

const TITLES: Record<DecisionSubject, Record<ApprovalDecision, string>> = {
  provider: {
    approved: 'اعتماد مزود الخدمة',
    rejected: 'رفض اعتماد مزود الخدمة',
    suspended: 'إيقاف اعتماد مزود الخدمة',
    changes_requested: 'طلب استكمال بيانات المزود',
  },
  factory: {
    approved: 'اعتماد حساب المنشأة',
    rejected: 'رفض اعتماد المنشأة',
    suspended: 'إيقاف اعتماد المنشأة',
    changes_requested: 'طلب استكمال بيانات المنشأة',
  },
  listing: {
    approved: 'اعتماد الخدمة',
    rejected: 'رفض إدراج الخدمة',
    suspended: 'إيقاف ظهور الخدمة',
    changes_requested: 'طلب تصويب الخدمة',
  },
};

const EXPLANATIONS: Record<DecisionSubject, Record<ApprovalDecision, string>> = {
  provider: {
    approved: 'بعد الاعتماد يظهر المزود للمصانع المؤهلة في خدماته المعتمدة فقط. السبب اختياري.',
    rejected: 'يُبلَّغ المزود بالسبب ليتمكن من تصويب ملفه وطلب مراجعة جديدة.',
    suspended: 'يوقف الإيقاف ظهور المزود وتفاوضه على الطلبات المفتوحة مؤقتًا.',
    changes_requested: 'يبقى المزود مخفيًا عن المصانع حتى يستكمل البيانات المطلوبة ويطلب المراجعة من جديد. اكتب ما يجب تصويبه.',
  },
  factory: {
    approved: 'بعد الاعتماد تستطيع المنشأة إرسال طلبات الخدمة. لا يغيّر القرار نتيجة تقييم الجاهزية ولا فئتها.',
    rejected: 'تُبلَّغ المنشأة بالسبب ويمكنها التصويب وطلب مراجعة جديدة. لا يغيّر القرار نتيجة تقييم الجاهزية.',
    suspended: 'لا تستطيع المنشأة إرسال طلبات جديدة حتى إعادة الاعتماد. لا يغيّر القرار نتيجة تقييم الجاهزية.',
    changes_requested: 'تستكمل المنشأة البيانات ثم تطلب المراجعة من جديد. اكتب ما يجب تصويبه.',
  },
  listing: {
    approved: 'تظهر الخدمة للمصانع المؤهلة متى كان المزود نفسه معتمدًا. السبب اختياري.',
    rejected: 'لا تظهر الخدمة للمصانع، ويُبلَّغ المزود بالسبب.',
    suspended: 'تختفي الخدمة عن المصانع مؤقتًا، ويُبلَّغ المزود بالسبب.',
    changes_requested: '',
  },
};

interface ApprovalDecisionModalProps<T> {
  subject: DecisionSubject;
  decision: ApprovalDecision;
  /** Shown under the title, for example «المزود: …». */
  subtitle: string;
  submit: (payload: { decision: ApprovalDecision; reason?: string }) => Promise<T>;
  onClose: () => void;
  onDone: (result: T) => void;
}

/**
 * One IMC review decision with its reason. Every decision but approval needs a written reason; the
 * API validates it again (422) and refuses a transition the current status does not allow (409).
 */
export function ApprovalDecisionModal<T>({ subject, decision, subtitle, submit, onClose, onDone }: ApprovalDecisionModalProps<T>) {
  const [reason, setReason] = useState('');
  const needsReason = decisionNeedsReason(decision);
  const run = useApiMutation(() => submit({ decision, ...(reason.trim() ? { reason: reason.trim() } : {}) }));
  const reasonErrors = fieldMessages(run.error, 'reason');
  const decisionErrors = fieldMessages(run.error, 'decision');

  const handleConfirm = async () => {
    const result = await run.run();
    if (result.ok) onDone(result.data);
  };

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={TITLES[subject][decision]}
      subtitle={subtitle}
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={run.pending}>
            إلغاء
          </Button>
          <Button
            variant={decision === 'approved' ? 'success' : decision === 'changes_requested' ? 'primary' : 'danger'}
            size="sm"
            onClick={handleConfirm}
            isLoading={run.pending}
            disabled={needsReason && reason.trim() === ''}
            data-confirm-decision={decision}
          >
            تأكيد القرار
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <p className="text-[#667085] leading-relaxed">{EXPLANATIONS[subject][decision]}</p>
        <label htmlFor="decision-reason" className="font-bold text-[#172033] block">
          {decision === 'changes_requested' ? 'البيانات المطلوب استكمالها (مطلوب):' : needsReason ? 'السبب (مطلوب):' : 'ملاحظة (اختيارية):'}
        </label>
        <textarea
          id="decision-reason"
          rows={4}
          value={reason}
          maxLength={2000}
          onChange={(e) => setReason(e.target.value)}
          className={`w-full p-3 rounded-xl border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(reasonErrors.length > 0)}`}
        />
        <FieldError messages={[...reasonErrors, ...decisionErrors]} />
        {run.error !== null && reasonErrors.length === 0 && decisionErrors.length === 0 && <ApiErrorState compact error={run.error} />}
      </div>
    </Modal>
  );
}
