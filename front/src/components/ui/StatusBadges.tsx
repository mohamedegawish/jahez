import React from 'react';
import type { ContractStatus, InvoiceStatus, OfferState, PaymentStatus, ProviderRequestStatus, ServiceRequestStatus } from '../../api/types';
import {
  contractStatusLabel,
  invoiceStatusLabel,
  offerStateLabel,
  paymentStatusLabel,
  providerRequestStatusLabel,
  serviceRequestStatusLabel,
} from '../../lib/labels';
import { Badge } from './Badge';

type Variant = React.ComponentProps<typeof Badge>['variant'];
type Props<S> = { status: S; size?: 'sm' | 'md' };

// Labels come from lib/labels.ts (backend enum values -> Arabic); only the colour is chosen here.
// Unknown values fall back to a neutral badge showing the raw value, so a new backend state is visible.

const SERVICE_REQUEST: Record<ServiceRequestStatus, Variant> = { open: 'blue', awarded: 'success', cancelled: 'neutral' };
export const ServiceRequestStatusBadge: React.FC<Props<ServiceRequestStatus>> = ({ status, size = 'md' }) => (
  <Badge variant={SERVICE_REQUEST[status] ?? 'neutral'} size={size}>{serviceRequestStatusLabel(status)}</Badge>
);

const PROVIDER_REQUEST: Record<ProviderRequestStatus, Variant> = {
  pending: 'warning',
  accepted: 'blue',
  agreed: 'success',
  declined: 'error',
  withdrawn: 'neutral',
  closed: 'neutral',
};
export const ProviderRequestStatusBadge: React.FC<Props<ProviderRequestStatus>> = ({ status, size = 'md' }) => (
  <Badge variant={PROVIDER_REQUEST[status] ?? 'neutral'} size={size}>{providerRequestStatusLabel(status)}</Badge>
);

const OFFER_STATE: Record<OfferState, Variant> = {
  current: 'blue',
  accepted: 'success',
  expired: 'warning',
  lapsed: 'neutral',
  superseded: 'neutral',
};
export const OfferStateBadge: React.FC<Props<OfferState>> = ({ status, size = 'md' }) => (
  <Badge variant={OFFER_STATE[status] ?? 'neutral'} size={size}>{offerStateLabel(status)}</Badge>
);

const CONTRACT: Record<ContractStatus, Variant> = { draft: 'warning', cancelled: 'neutral' };
export const ContractStatusBadge: React.FC<Props<ContractStatus>> = ({ status, size = 'md' }) => (
  <Badge variant={CONTRACT[status] ?? 'neutral'} size={size}>{contractStatusLabel(status)}</Badge>
);

const INVOICE: Record<InvoiceStatus, Variant> = { draft: 'neutral', issued: 'blue', partially_paid: 'purple', paid: 'success', refunded: 'warning', cancelled: 'neutral' };
export const InvoiceStatusBadge: React.FC<Props<InvoiceStatus>> = ({ status, size = 'md' }) => (
  <Badge variant={INVOICE[status] ?? 'neutral'} size={size}>{invoiceStatusLabel(status)}</Badge>
);

const PAYMENT: Record<PaymentStatus, Variant> = { pending: 'warning', succeeded: 'success', failed: 'error', cancelled: 'neutral', refunded: 'warning' };
export const PaymentStatusBadge: React.FC<Props<PaymentStatus>> = ({ status, size = 'md' }) => (
  <Badge variant={PAYMENT[status] ?? 'neutral'} size={size}>{paymentStatusLabel(status)}</Badge>
);
