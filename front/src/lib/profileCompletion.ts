import type { Factory, FactorySummary, ServiceProvider } from '../api';

export interface Completion {
  filled: number;
  total: number;
  percent: number;
  missing: string[];
}

const filled = (value: unknown) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && value.length === 0);

function measure(fields: [string, unknown][]): Completion {
  const missing = fields.filter(([, value]) => !filled(value)).map(([label]) => label);
  const done = fields.length - missing.length;
  return { filled: done, total: fields.length, percent: Math.round((done * 100) / fields.length), missing };
}

/**
 * How many of the profile fields the provider has filled, counted from the stored record. This is a
 * guide for the provider only: which fields are REQUIRED is an open decision (OQ-36) and is enforced by
 * the API, not by this count.
 */
export function providerCompletion(p: ServiceProvider): Completion {
  return measure([
    ['الوصف', p.description],
    ['الاسم القانوني', p.legal_name],
    ['اسم الممثل', p.representative_name],
    ['المسمى الوظيفي', p.job_title],
    ['البريد الإلكتروني', p.email],
    ['الهاتف', p.phone],
    ['الموقع الإلكتروني', p.website],
    ['سنوات الخبرة', p.dx_experience_years],
    ['المحافظة', p.governorate],
    ['العنوان', p.address],
    ['رقم السجل التجاري', p.commercial_registration_number],
    ['رقم التسجيل الضريبي', p.tax_registration_number],
    ['القطاعات', p.sectors],
    ['الخدمات', p.services],
    ['الشعار', p.documents?.logo],
  ]);
}

/** The same guide for a factory (required fields: OQ-19, enforced only by the API's onboarding list). */
export function factoryCompletion(f: Factory): Completion {
  return measure([
    ['الاسم القانوني', f.legal_name],
    ['اسم مسؤول التواصل', f.contact_name],
    ['البريد الإلكتروني', f.contact_email],
    ['الهاتف', f.contact_phone],
    ['المحافظة', f.governorate],
    ['العنوان', f.address],
    ['رقم السجل التجاري', f.commercial_registration_number],
    ['رقم التسجيل الضريبي', f.tax_registration_number],
    ['القطاعات', f.sectors],
    ['الشعار', f.documents?.logo],
  ]);
}

const FACTORY_FIELD_LABELS: Record<string, string> = {
  legal_name: 'الاسم القانوني',
  contact_name: 'اسم مسؤول التواصل',
  contact_email: 'البريد الإلكتروني',
  contact_phone: 'الهاتف',
  governorate: 'المحافظة',
  address: 'العنوان',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
  sectors: 'القطاعات',
  logo: 'الشعار',
};

/**
 * The same guide from a list card (FactorySummaryResource): the server reports which fields are filled,
 * never their values, so the list carries no legal or contact detail.
 */
export function factorySummaryCompletion(f: FactorySummary): Completion {
  const completion = f.profile_completion;
  if (!completion || completion.total === 0) return { filled: 0, total: 0, percent: 0, missing: [] };
  return {
    filled: completion.filled,
    total: completion.total,
    percent: Math.round((completion.filled * 100) / completion.total),
    missing: completion.missing.map((field) => FACTORY_FIELD_LABELS[field] ?? field),
  };
}
