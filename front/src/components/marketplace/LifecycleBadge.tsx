import React from 'react';
import type { ProviderRequest } from '../../api';
import { LIFECYCLE_LABEL, lifecycleOf, type Lifecycle } from '../../lib/lifecycle';
import { Badge } from '../ui/Badge';

type Variant = React.ComponentProps<typeof Badge>['variant'];

const VARIANT: Record<Lifecycle, Variant> = {
  requested: 'warning',
  negotiating: 'blue',
  awaiting_imc: 'purple',
  imc_approved: 'success',
  contract_draft: 'success',
  imc_rejected: 'error',
  declined: 'error',
  withdrawn: 'neutral',
  closed_awarded_elsewhere: 'neutral',
  closed_cancelled: 'neutral',
  closed: 'neutral',
};

/** The thread's place in the request → negotiation → agreement → IMC approval → contract-draft path. */
export const LifecycleBadge: React.FC<{ thread: Pick<ProviderRequest, 'status' | 'status_reason' | 'agreement'>; size?: 'sm' | 'md' }> = ({
  thread,
  size = 'sm',
}) => {
  const stage = lifecycleOf(thread);
  return (
    <Badge variant={VARIANT[stage]} size={size}>
      {LIFECYCLE_LABEL[stage]}
    </Badge>
  );
};

/** The agreement's IMC review status on its own. */
export const ReviewStatusBadge: React.FC<{ status: 'pending' | 'approved' | 'rejected'; size?: 'sm' | 'md' }> = ({ status, size = 'sm' }) => (
  <Badge variant={status === 'approved' ? 'success' : status === 'rejected' ? 'error' : 'purple'} size={size}>
    {status === 'approved' ? 'معتمدة من المركز' : status === 'rejected' ? 'رفضها المركز' : 'بانتظار اعتماد المركز'}
  </Badge>
);
