// The lifecycle of one provider thread as the portals show it (ADR-020). It is derived only from
// statuses the API returns: the thread's status and its agreement's IMC review and contract-draft
// statuses. "Active" or "completed" subscriptions do not exist in the API (OQ-17), so they are never
// shown: an agreement, even approved, is not presented as an active or official subscription.

import type { ProviderRequest } from '../api';

export type Lifecycle =
  | 'requested'
  | 'negotiating'
  | 'awaiting_imc'
  | 'imc_approved'
  | 'contract_draft'
  | 'imc_rejected'
  | 'declined'
  | 'withdrawn'
  | 'closed_awarded_elsewhere'
  | 'closed_cancelled'
  | 'closed';

export const LIFECYCLE_LABEL: Record<Lifecycle, string> = {
  requested: 'طلب مُرسل · بانتظار رد المزود',
  negotiating: 'قيد التفاوض',
  awaiting_imc: 'تم الاتفاق · بانتظار اعتماد المركز',
  imc_approved: 'معتمد من المركز · بانتظار مسودة العقد',
  contract_draft: 'معتمد · مسودة عقد غير ملزمة',
  imc_rejected: 'رفض المركز الاتفاقية',
  declined: 'اعتذر المزود',
  withdrawn: 'سحبه المصنع',
  closed_awarded_elsewhere: 'أُغلق · رُسّي على مزود آخر',
  closed_cancelled: 'أُغلق · ألغى المصنع الطلب',
  closed: 'مغلق',
};

export function lifecycleOf(thread: Pick<ProviderRequest, 'status' | 'status_reason' | 'agreement'>): Lifecycle {
  switch (thread.status) {
    case 'pending':
      return 'requested';
    case 'accepted':
      return 'negotiating';
    case 'declined':
      return 'declined';
    case 'withdrawn':
      return 'withdrawn';
    case 'closed':
      return thread.status_reason === 'request_awarded'
        ? 'closed_awarded_elsewhere'
        : thread.status_reason === 'request_cancelled'
          ? 'closed_cancelled'
          : 'closed';
    case 'agreed': {
      const review = thread.agreement?.review_status ?? 'pending';
      if (review === 'rejected') return 'imc_rejected';
      if (review === 'approved') return thread.agreement?.contract_status === 'draft' ? 'contract_draft' : 'imc_approved';
      return 'awaiting_imc';
    }
    default:
      return 'closed';
  }
}

/** What the signed-in side should do next on this thread, if anything. */
export function pendingActionOf(
  thread: Pick<ProviderRequest, 'status' | 'unread_messages_count' | 'latest_offer_version' | 'agreement'>,
  side: 'factory' | 'provider',
): string | null {
  if (side === 'provider') {
    if (thread.status === 'pending') return 'الرد على الطلب (قبول أو اعتذار)';
    if (thread.status === 'accepted' && !thread.latest_offer_version) return 'تقديم العرض التجاري';
  } else if (thread.status === 'accepted' && thread.latest_offer_version) {
    return `مراجعة العرض (النسخة ${thread.latest_offer_version})`;
  }
  if (thread.status === 'accepted' && (thread.unread_messages_count ?? 0) > 0) return 'الرد على الرسائل الجديدة';
  if (thread.status === 'agreed' && thread.agreement?.review_status === 'approved' && thread.agreement.contract_status !== 'draft') {
    return 'إعداد مسودة العقد';
  }
  return null;
}
