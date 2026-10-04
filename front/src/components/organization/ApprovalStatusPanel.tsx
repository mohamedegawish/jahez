import React, { useState } from 'react';
import { RotateCcw } from 'lucide-react';
import type { ProviderApprovalStatus } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { formatDateTime } from '../../lib/format';
import { canRequestReview } from '../../lib/provider';
import { ApiErrorState } from '../ui/ApiErrorState';
import { ApprovalBadge } from '../ui/ApprovalBadge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';

interface ApprovalStatusPanelProps {
  approval: { status: ProviderApprovalStatus; reason: string | null; changed_at: string | null };
  /** What the status means for this organization. */
  texts: Record<ProviderApprovalStatus, string>;
  requestReview: (note: string | null) => Promise<unknown>;
  onChanged: () => void;
}

/**
 * An organization's own view of its IMC review (ADR-014 providers, ADR-021 factories): the status,
 * the reason IMC gave, and — after a rejection or a request for corrections — the way back to review.
 */
export const ApprovalStatusPanel: React.FC<ApprovalStatusPanelProps> = ({ approval, texts, requestReview, onChanged }) => {
  const [note, setNote] = useState('');
  const review = useApiMutation(() => requestReview(note.trim() || null));
  const { status, reason, changed_at: changedAt } = approval;

  const handleReview = async () => {
    const result = await review.run();
    if (result.ok) {
      setNote('');
      onChanged();
    }
  };

  return (
    <Card title="حالة الاعتماد لدى مركز تحديث الصناعة" accent="purple">
      <div className="space-y-3 text-xs" data-approval-status={status}>
        <div className="flex items-center justify-between">
          <ApprovalBadge status={status} />
          <span className="text-[11px] text-[#98A2B3]">{changedAt ? formatDateTime(changedAt) : ''}</span>
        </div>
        <p className="text-[#667085] leading-relaxed">{texts[status]}</p>
        {reason && (
          <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
            <span className="text-[10px] font-bold text-[#98A2B3] block mb-0.5">{status === 'changes_requested' ? 'المطلوب استكماله' : 'سبب القرار'}</span>
            <span className="text-[#172033] leading-relaxed whitespace-pre-line" dir="auto">{reason}</span>
          </div>
        )}

        {canRequestReview(status) && (
          <div className="pt-3 border-t border-[#E6EAF0] space-y-2">
            <label htmlFor="review-note" className="font-bold text-[#172033] block">ملاحظة للمراجع (اختياري):</label>
            <textarea
              id="review-note"
              rows={3}
              value={note}
              maxLength={2000}
              onChange={(e) => setNote(e.target.value)}
              className="w-full p-2.5 rounded-xl border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF]"
            />
            {review.error !== null && <ApiErrorState compact error={review.error} />}
            <Button variant="primary" size="sm" icon={RotateCcw} className="w-full" onClick={handleReview} isLoading={review.pending}>
              طلب مراجعة جديدة
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
};
