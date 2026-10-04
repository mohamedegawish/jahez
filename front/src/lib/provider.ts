import type { ProviderApprovalStatus, ServiceListingStatus, ServiceProvider, ServiceProviderPayload } from '../api/types';

/** Text-input state for the provider profile fields the API stores (ADR-014, ADR-019). */
export interface ProviderFormValues {
  name: string;
  legal_name: string;
  description: string;
  representative_name: string;
  job_title: string;
  email: string;
  phone: string;
  website: string;
  dx_experience_years: string;
  governorate: string;
  city: string;
  address: string;
  commercial_registration_number: string;
  tax_registration_number: string;
}

/** Legal fields IMC verifies: after approval a member changes them only through a change request. */
export const LEGAL_FIELDS = ['legal_name', 'commercial_registration_number', 'tax_registration_number'] as const;

export const EMPTY_PROVIDER_FORM: ProviderFormValues = {
  name: '',
  legal_name: '',
  description: '',
  representative_name: '',
  job_title: '',
  email: '',
  phone: '',
  website: '',
  dx_experience_years: '',
  governorate: '',
  city: '',
  address: '',
  commercial_registration_number: '',
  tax_registration_number: '',
};

export function valuesFromProvider(provider: ServiceProvider): ProviderFormValues {
  return {
    name: provider.name,
    legal_name: provider.legal_name ?? '',
    description: provider.description ?? '',
    representative_name: provider.representative_name ?? '',
    job_title: provider.job_title ?? '',
    email: provider.email ?? '',
    phone: provider.phone ?? '',
    website: provider.website ?? '',
    dx_experience_years: provider.dx_experience_years === null ? '' : String(provider.dx_experience_years),
    governorate: provider.governorate ?? '',
    city: provider.city ?? '',
    address: provider.address ?? '',
    commercial_registration_number: provider.commercial_registration_number ?? '',
    tax_registration_number: provider.tax_registration_number ?? '',
  };
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

/**
 * Empty optional fields are sent as null (the API treats them as "not provided"). With
 * `includeLegal: false` the verified legal fields are left out, so a member of an approved
 * provider can save the rest of the profile.
 */
export function toProviderPayload(values: ProviderFormValues, { includeLegal = true } = {}): ServiceProviderPayload & { name: string } {
  const years = values.dx_experience_years.trim();
  return {
    name: values.name.trim(),
    ...(includeLegal
      ? {
          legal_name: orNull(values.legal_name),
          commercial_registration_number: orNull(values.commercial_registration_number),
          tax_registration_number: orNull(values.tax_registration_number),
        }
      : {}),
    description: orNull(values.description),
    governorate: orNull(values.governorate),
    city: orNull(values.city),
    address: orNull(values.address),
    representative_name: orNull(values.representative_name),
    job_title: orNull(values.job_title),
    email: orNull(values.email),
    phone: orNull(values.phone),
    website: orNull(values.website),
    dx_experience_years: years === '' ? null : Number(years),
  };
}

export type ApprovalDecision = 'approved' | 'rejected' | 'suspended' | 'changes_requested';

/**
 * The decisions IMC may take from a status (docs/workflows.md section 1, enforced by the API's
 * ProviderApprovalStatus::canBecome and FactoryApprovalStatus::canBecome, which are the same).
 * The UI only decides which buttons to offer; the server still answers 409 for anything else.
 */
export function allowedApprovalDecisions(status: ProviderApprovalStatus): ApprovalDecision[] {
  switch (status) {
    case 'pending':
      return ['approved', 'changes_requested', 'rejected'];
    case 'changes_requested':
      return ['approved', 'rejected'];
    case 'approved':
      return ['suspended'];
    case 'rejected':
    case 'suspended':
      return ['approved'];
  }
}

/** The decisions IMC may take on one listed service (ServiceListingStatus::canBecome). */
export function allowedListingDecisions(status: ServiceListingStatus): Exclude<ApprovalDecision, 'changes_requested'>[] {
  switch (status) {
    case 'pending':
      return ['approved', 'rejected'];
    case 'approved':
      return ['suspended'];
    case 'rejected':
    case 'suspended':
      return ['approved'];
  }
}

/** Every decision but approval needs a written reason (the API answers 422 without one). */
export const decisionNeedsReason = (decision: ApprovalDecision): boolean => decision !== 'approved';

/** Whether the organization itself may ask IMC for a new review. */
export const canRequestReview = (status: ProviderApprovalStatus): boolean => status === 'rejected' || status === 'changes_requested';
