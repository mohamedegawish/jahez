// Labels, empty forms and client-side checks for the financial and contract policies
// (jahez_api ADR-023). Nothing here is a policy value: the forms start EMPTY (no rate, no
// tax, no due date is ever assumed), every check is repeated by the server, and amounts and
// totals are calculated only by the server (preview endpoints). Self-contained so that
// `node --test` can run its tests without a bundler.

import type {
  ContractTemplateParameters,
  FinancialPolicyEffectiveStatus,
  FinancialPolicyKind,
  FinancialPolicyParameters,
  FinancialPolicyScopeType,
  FinancialPolicyVersionStatus,
  InvoicingParameters,
  PaymentTermsParameters,
  RevenueShareParameters,
  TaxParameters,
} from '../api/types';

export const KIND_LABELS: Record<FinancialPolicyKind, string> = {
  revenue_share: 'حصة الوزارة من الإيرادات',
  tax: 'الضرائب والرسوم',
  invoicing: 'سياسة الفوترة',
  payment_terms: 'شروط السداد',
  contract_template: 'نموذج العقد',
};

export const KIND_DECISIONS: Record<FinancialPolicyKind, string> = {
  revenue_share: 'OQ-15',
  tax: 'OQ-16',
  invoicing: 'OQ-16',
  payment_terms: 'OQ-16',
  contract_template: 'OQ-17',
};

export const SCOPE_LABELS: Record<FinancialPolicyScopeType, string> = {
  global: 'كل الخدمات',
  sector: 'قطاع',
  catalog_service: 'خدمة',
  service_provider: 'مزود خدمة',
};

/** Mirrors the server's FinancialPolicyKind::allowedScopes(); the server refuses any other. */
export const ALLOWED_SCOPES: Record<FinancialPolicyKind, FinancialPolicyScopeType[]> = {
  revenue_share: ['global', 'sector', 'catalog_service', 'service_provider'],
  payment_terms: ['global', 'sector', 'catalog_service', 'service_provider'],
  tax: ['global', 'catalog_service'],
  contract_template: ['global', 'catalog_service'],
  invoicing: ['global'],
};

export const STATUS_LABELS: Record<FinancialPolicyVersionStatus, string> = {
  draft: 'مسودة',
  pending_approval: 'بانتظار الاعتماد',
  rejected: 'مرفوضة',
  approved: 'معتمدة',
  superseded: 'مستبدلة بإصدار أحدث',
  ended: 'منتهية',
  archived: 'مؤرشفة',
};

export type BadgeTone = 'blue' | 'purple' | 'success' | 'warning' | 'error' | 'neutral';

export const EFFECTIVE_STATUS: Record<FinancialPolicyEffectiveStatus, { label: string; tone: BadgeTone }> = {
  draft: { label: 'مسودة', tone: 'neutral' },
  pending_approval: { label: 'بانتظار الاعتماد', tone: 'warning' },
  rejected: { label: 'مرفوضة', tone: 'error' },
  archived: { label: 'مؤرشفة', tone: 'neutral' },
  scheduled: { label: 'معتمدة ومجدولة', tone: 'blue' },
  active: { label: 'سارية', tone: 'success' },
  expired: { label: 'منتهية المدة', tone: 'neutral' },
};

const HISTORY_EVENTS: Record<string, string> = {
  'financial_policy.created': 'إنشاء السياسة',
  'financial_policy.version_drafted': 'إعداد مسودة إصدار',
  'financial_policy.version_updated': 'تعديل المسودة',
  'financial_policy.version_submitted': 'إرسال للاعتماد',
  'financial_policy.version_approved': 'اعتماد',
  'financial_policy.version_rejected': 'رفض',
  'financial_policy.version_archived': 'أرشفة',
  'financial_policy.version_ended': 'إنهاء مبكر',
  'financial_policy.version_superseded': 'استبدال بإصدار أحدث',
};

export const historyEventLabel = (event: string): string => HISTORY_EVENTS[event] ?? event;

/** Two decimals at most, 0–100, written as a string: what the API accepts. */
export const PERCENT = /^\d{1,3}(\.\d{1,2})?$/;
export const AMOUNT = /^\d{1,12}(\.\d{1,2})?$/;
export const DAY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Today in the business calendar the API reads effective dates in (Africa/Cairo by default,
 * JAHEZ_BUSINESS_TIMEZONE). Only a hint for the form: the server decides.
 */
export function businessToday(now: Date = new Date(), timeZone = 'Africa/Cairo'): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

export const isPercent = (value: string): boolean => PERCENT.test(value.trim()) && Number(value) <= 100;

/* ───────────────────────── Form models (strings and explicit choices) ───────────────────────── */

/** `null` means "not chosen yet": a yes/no rule is never assumed. */
export type Choice = boolean | null;

export interface RevenueShareForm { rate_percent: string }
export interface TaxForm {
  prices_include_tax: Choice;
  taxes: { code: string; name_ar: string; rate_percent: string }[];
  fees: { code: string; name_ar: string; calculation: '' | 'fixed' | 'percentage'; amount: string; rate_percent: string; taxable: Choice }[];
}
export interface InvoicingForm {
  issuer: '' | 'imc' | 'service_provider';
  number_prefix: string;
  number_padding: string;
  manual_payments_allowed: Choice;
  requires_revenue_share: Choice;
}
export interface PaymentTermsForm { due_days: string; partial_payments_allowed: Choice }
export interface ContractTemplateForm {
  title_ar: string;
  include_imc: Choice;
  duration_months: string;
  knowledge_transfer_min_trainees: string;
  clauses: { heading_ar: string; body_ar: string }[];
}

export interface PolicyForms {
  revenue_share: RevenueShareForm;
  tax: TaxForm;
  invoicing: InvoicingForm;
  payment_terms: PaymentTermsForm;
  contract_template: ContractTemplateForm;
}

/** Empty forms: every value must be entered by the administrator. */
export function emptyForm<K extends FinancialPolicyKind>(kind: K): PolicyForms[K] {
  const forms: PolicyForms = {
    revenue_share: { rate_percent: '' },
    tax: { prices_include_tax: null, taxes: [], fees: [] },
    invoicing: { issuer: '', number_prefix: '', number_padding: '', manual_payments_allowed: null, requires_revenue_share: null },
    payment_terms: { due_days: '', partial_payments_allowed: null },
    contract_template: { title_ar: '', include_imc: null, duration_months: '', knowledge_transfer_min_trainees: '', clauses: [{ heading_ar: '', body_ar: '' }] },
  };
  return forms[kind];
}

/** A form filled from an existing version, to draft the next one from it. */
export function formFromParameters<K extends FinancialPolicyKind>(kind: K, parameters: FinancialPolicyParameters): PolicyForms[K] {
  switch (kind) {
    case 'revenue_share':
      return { rate_percent: (parameters as RevenueShareParameters).rate_percent } as PolicyForms[K];
    case 'tax': {
      const p = parameters as TaxParameters;
      return {
        prices_include_tax: p.prices_include_tax,
        taxes: p.taxes.map((t) => ({ ...t })),
        fees: p.fees.map((f) => ({ code: f.code, name_ar: f.name_ar, calculation: f.calculation, amount: f.amount ?? '', rate_percent: f.rate_percent ?? '', taxable: f.taxable })),
      } as PolicyForms[K];
    }
    case 'invoicing': {
      const p = parameters as InvoicingParameters;
      return {
        issuer: p.issuer,
        number_prefix: p.number_prefix,
        number_padding: String(p.number_padding),
        manual_payments_allowed: p.manual_payments_allowed,
        requires_revenue_share: p.requires_revenue_share,
      } as PolicyForms[K];
    }
    case 'payment_terms': {
      const p = parameters as PaymentTermsParameters;
      return { due_days: String(p.due_days), partial_payments_allowed: p.partial_payments_allowed } as PolicyForms[K];
    }
    default: {
      const p = parameters as ContractTemplateParameters;
      return {
        title_ar: p.title_ar,
        include_imc: p.parties.includes('imc'),
        duration_months: p.duration_months === null ? '' : String(p.duration_months),
        knowledge_transfer_min_trainees: String(p.knowledge_transfer_min_trainees),
        clauses: p.clauses.map((c) => ({ ...c })),
      } as PolicyForms[K];
    }
  }
}

export type FormErrors = Record<string, string>;

const wholeNumber = (value: string, min: number, max: number): boolean => /^\d+$/.test(value.trim()) && Number(value) >= min && Number(value) <= max;

/**
 * Checks a form and builds the parameters the API expects, or returns Arabic errors keyed by
 * the API's own field paths (`parameters.rate_percent`), so server 422s land on the same fields.
 */
export function buildParameters<K extends FinancialPolicyKind>(
  kind: K,
  form: PolicyForms[K],
): { ok: true; parameters: FinancialPolicyParameters } | { ok: false; errors: FormErrors } {
  const errors: FormErrors = {};
  const need = (condition: boolean, field: string, message: string) => {
    if (!condition && !(field in errors)) errors[field] = message;
  };
  const choice = (value: Choice, field: string) => need(value !== null, field, 'اختر نعم أو لا؛ لا تُفترض أي قيمة.');

  let parameters: FinancialPolicyParameters | null = null;

  switch (kind) {
    case 'revenue_share': {
      const f = form as RevenueShareForm;
      need(isPercent(f.rate_percent), 'parameters.rate_percent', 'أدخل نسبة من 0 إلى 100 بحد أقصى منزلتين عشريتين.');
      parameters = { method: 'percentage', rate_percent: f.rate_percent.trim(), base: 'subtotal_before_tax' };
      break;
    }
    case 'tax': {
      const f = form as TaxForm;
      choice(f.prices_include_tax, 'parameters.prices_include_tax');
      const codes = new Set<string>();
      f.taxes.forEach((tax, i) => {
        need(/^[A-Za-z0-9_-]{1,30}$/.test(tax.code), `parameters.taxes.${i}.code`, 'رمز من حروف إنجليزية وأرقام فقط.');
        need(!codes.has(tax.code), `parameters.taxes.${i}.code`, 'الرمز مكرر.');
        codes.add(tax.code);
        need(tax.name_ar.trim() !== '', `parameters.taxes.${i}.name_ar`, 'أدخل اسم الضريبة.');
        need(isPercent(tax.rate_percent), `parameters.taxes.${i}.rate_percent`, 'أدخل نسبة من 0 إلى 100 بحد أقصى منزلتين عشريتين.');
      });
      const totalBasisPoints = f.taxes.reduce((sum, tax) => sum + (isPercent(tax.rate_percent) ? Math.round(Number(tax.rate_percent) * 100) : 0), 0);
      need(totalBasisPoints <= 10000, 'parameters.taxes', 'مجموع نسب الضرائب لا يتجاوز 100%.');
      const feeCodes = new Set<string>();
      f.fees.forEach((fee, i) => {
        need(/^[A-Za-z0-9_-]{1,30}$/.test(fee.code), `parameters.fees.${i}.code`, 'رمز من حروف إنجليزية وأرقام فقط.');
        need(!feeCodes.has(fee.code), `parameters.fees.${i}.code`, 'الرمز مكرر.');
        feeCodes.add(fee.code);
        need(fee.name_ar.trim() !== '', `parameters.fees.${i}.name_ar`, 'أدخل اسم الرسم.');
        need(fee.calculation !== '', `parameters.fees.${i}.calculation`, 'اختر طريقة الحساب.');
        if (fee.calculation === 'fixed') need(AMOUNT.test(fee.amount.trim()), `parameters.fees.${i}.amount`, 'أدخل مبلغًا بحد أقصى منزلتين عشريتين.');
        if (fee.calculation === 'percentage') need(isPercent(fee.rate_percent), `parameters.fees.${i}.rate_percent`, 'أدخل نسبة صحيحة.');
        choice(fee.taxable, `parameters.fees.${i}.taxable`);
      });
      parameters = {
        prices_include_tax: f.prices_include_tax === true,
        taxes: f.taxes.map((t) => ({ code: t.code.trim(), name_ar: t.name_ar.trim(), rate_percent: t.rate_percent.trim() })),
        fees: f.fees.map((fee) => ({
          code: fee.code.trim(),
          name_ar: fee.name_ar.trim(),
          calculation: fee.calculation === 'percentage' ? 'percentage' : 'fixed',
          amount: fee.calculation === 'fixed' ? fee.amount.trim() : null,
          rate_percent: fee.calculation === 'percentage' ? fee.rate_percent.trim() : null,
          taxable: fee.taxable === true,
        })),
      };
      break;
    }
    case 'invoicing': {
      const f = form as InvoicingForm;
      need(f.issuer !== '', 'parameters.issuer', 'اختر الجهة المُصدِرة للفواتير.');
      need(/^[A-Za-z0-9/-]{1,20}$/.test(f.number_prefix), 'parameters.number_prefix', 'بادئة من 1 إلى 20 حرفًا إنجليزيًا أو رقمًا أو - أو /.');
      need(wholeNumber(f.number_padding, 4, 10), 'parameters.number_padding', 'عدد الخانات من 4 إلى 10.');
      choice(f.manual_payments_allowed, 'parameters.manual_payments_allowed');
      choice(f.requires_revenue_share, 'parameters.requires_revenue_share');
      parameters = {
        issuer: f.issuer === 'imc' ? 'imc' : 'service_provider',
        payer: 'factory',
        currency: 'EGP',
        number_prefix: f.number_prefix,
        number_padding: Number(f.number_padding),
        invoice_types: ['agreement_service'],
        manual_payments_allowed: f.manual_payments_allowed === true,
        requires_revenue_share: f.requires_revenue_share === true,
      };
      break;
    }
    case 'payment_terms': {
      const f = form as PaymentTermsForm;
      need(wholeNumber(f.due_days, 0, 365), 'parameters.due_days', 'عدد أيام من 0 إلى 365.');
      choice(f.partial_payments_allowed, 'parameters.partial_payments_allowed');
      parameters = { due_rule: 'days_after_issue', due_days: Number(f.due_days), partial_payments_allowed: f.partial_payments_allowed === true };
      break;
    }
    default: {
      const f = form as ContractTemplateForm;
      need(f.title_ar.trim() !== '', 'parameters.title_ar', 'أدخل عنوان النموذج.');
      choice(f.include_imc, 'parameters.parties');
      need(f.duration_months.trim() === '' || wholeNumber(f.duration_months, 1, 120), 'parameters.duration_months', 'مدة من 1 إلى 120 شهرًا، أو اتركها فارغة إن لم تُحدَّد.');
      need(wholeNumber(f.knowledge_transfer_min_trainees, 2, 50), 'parameters.knowledge_transfer_min_trainees', 'مهندسان على الأقل (المصدر: DOC §6).');
      need(f.clauses.length > 0, 'parameters.clauses', 'أضف بندًا واحدًا على الأقل.');
      f.clauses.forEach((clause, i) => {
        need(clause.heading_ar.trim() !== '', `parameters.clauses.${i}.heading_ar`, 'أدخل عنوان البند.');
        need(clause.body_ar.trim() !== '', `parameters.clauses.${i}.body_ar`, 'أدخل نص البند.');
      });
      parameters = {
        title_ar: f.title_ar.trim(),
        parties: f.include_imc ? ['factory', 'service_provider', 'imc'] : ['factory', 'service_provider'],
        duration_months: f.duration_months.trim() === '' ? null : Number(f.duration_months),
        knowledge_transfer_min_trainees: Number(f.knowledge_transfer_min_trainees),
        clauses: f.clauses.map((c) => ({ heading_ar: c.heading_ar.trim(), body_ar: c.body_ar.trim() })),
      };
    }
  }

  return Object.keys(errors).length > 0 || parameters === null ? { ok: false, errors } : { ok: true, parameters };
}

/** Checks the effective period against the business day `today` (YYYY-MM-DD). */
export function periodErrors(effectiveFrom: string, effectiveTo: string, today: string): FormErrors {
  const errors: FormErrors = {};
  if (!DAY.test(effectiveFrom)) errors.effective_from = 'أدخل تاريخ البدء.';
  else if (effectiveFrom < today) errors.effective_from = 'لا يبدأ أي إصدار في الماضي؛ لا يُطبَّق شيء بأثر رجعي.';
  if (effectiveTo !== '' && !DAY.test(effectiveTo)) errors.effective_to = 'تاريخ غير صالح.';
  else if (effectiveTo !== '' && DAY.test(effectiveFrom) && effectiveTo < effectiveFrom) errors.effective_to = 'تاريخ الانتهاء بعد تاريخ البدء أو مساوٍ له.';
  return errors;
}

/** Read-only rows describing a version's values. */
export function describeParameters(kind: FinancialPolicyKind, parameters: FinancialPolicyParameters): { label: string; value: string }[] {
  const yesNo = (value: boolean) => (value ? 'نعم' : 'لا');
  switch (kind) {
    case 'revenue_share': {
      const p = parameters as RevenueShareParameters;
      return [
        { label: 'طريقة الحساب', value: 'نسبة مئوية' },
        { label: 'النسبة', value: `${p.rate_percent}%` },
        { label: 'الأساس', value: 'المجموع الفرعي قبل الضريبة (للاطلاع فقط؛ لا تُصرف مبالغ)' },
      ];
    }
    case 'tax': {
      const p = parameters as TaxParameters;
      return [
        { label: 'الأسعار شاملة الضريبة', value: yesNo(p.prices_include_tax) },
        { label: 'الضرائب', value: p.taxes.length === 0 ? 'لا توجد ضرائب (قرار معتمد)' : p.taxes.map((t) => `${t.name_ar} ${t.rate_percent}%`).join('، ') },
        {
          label: 'الرسوم',
          value: p.fees.length === 0 ? 'لا توجد رسوم' : p.fees.map((f) => `${f.name_ar}: ${f.calculation === 'fixed' ? `${f.amount} ج.م` : `${f.rate_percent}%`}${f.taxable ? ' (خاضع للضريبة)' : ''}`).join('، '),
        },
      ];
    }
    case 'invoicing': {
      const p = parameters as InvoicingParameters;
      return [
        { label: 'الجهة المُصدِرة', value: p.issuer === 'imc' ? 'مركز تحديث الصناعة' : 'مزود الخدمة' },
        { label: 'الجهة المُلزَمة بالسداد', value: 'المنشأة الصناعية' },
        { label: 'العملة', value: p.currency },
        { label: 'صيغة الترقيم', value: `${p.number_prefix}-${'0'.repeat(Math.max(0, p.number_padding - 1))}1` },
        { label: 'تسجيل مدفوعات يدوية', value: yesNo(p.manual_payments_allowed) },
        { label: 'يشترط حصة إيرادات معتمدة', value: yesNo(p.requires_revenue_share) },
      ];
    }
    case 'payment_terms': {
      const p = parameters as PaymentTermsParameters;
      return [
        { label: 'الاستحقاق', value: `${p.due_days} يومًا بعد الإصدار` },
        { label: 'السداد الجزئي', value: yesNo(p.partial_payments_allowed) },
      ];
    }
    default: {
      const p = parameters as ContractTemplateParameters;
      const party = { factory: 'المنشأة', service_provider: 'مزود الخدمة', imc: 'مركز تحديث الصناعة' } as const;
      return [
        { label: 'العنوان', value: p.title_ar },
        { label: 'الأطراف', value: p.parties.map((x) => party[x]).join('، ') },
        { label: 'المدة', value: p.duration_months === null ? 'غير محددة' : `${p.duration_months} شهرًا` },
        { label: 'الحد الأدنى لمهندسي نقل المعرفة', value: String(p.knowledge_transfer_min_trainees) },
        { label: 'عدد البنود', value: String(p.clauses.length) },
        { label: 'الصفة القانونية', value: 'مسودة غير ملزمة — التوقيع غير مفعّل (OQ-17)' },
      ];
    }
  }
}
