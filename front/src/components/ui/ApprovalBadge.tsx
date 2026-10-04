import React from 'react';
import type { ProviderApprovalStatus, ServiceListingStatus } from '../../api/types';
import { approvalLabel, listingStatusLabel } from '../../lib/labels';
import { Badge } from './Badge';

type BadgeVariant = React.ComponentProps<typeof Badge>['variant'];

const VARIANT: Record<ProviderApprovalStatus, BadgeVariant> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'error',
  suspended: 'neutral',
  changes_requested: 'purple',
};

/** A provider's or factory's IMC approval status, exactly as the API reports it. */
export const ApprovalBadge: React.FC<{ status: ProviderApprovalStatus; size?: 'sm' | 'md' }> = ({ status, size = 'md' }) => (
  <Badge variant={VARIANT[status] ?? 'neutral'} size={size}>
    {approvalLabel(status)}
  </Badge>
);

/** IMC's review of one listed service (ADR-021). */
export const ListingStatusBadge: React.FC<{ status: ServiceListingStatus; size?: 'sm' | 'md' }> = ({ status, size = 'md' }) => (
  <Badge variant={VARIANT[status] ?? 'neutral'} size={size}>
    {listingStatusLabel(status)}
  </Badge>
);
