import React, { useState } from 'react';
import { api, firstFieldErrors, isValidationError } from '../../api';
import type { FinancialPolicy, FinancialPolicyKind, FinancialPolicyParameters, FinancialPolicyScopeType, FinancialPolicyVersion } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useCatalogServices, useSectors } from '../../hooks/useReference';
import {
  ALLOWED_SCOPES,
  KIND_LABELS,
  SCOPE_LABELS,
  buildParameters,
  businessToday,
  emptyForm,
  formFromParameters,
  periodErrors,
  type FormErrors,
  type PolicyForms,
} from '../../lib/financialPolicies';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';
import { TextField } from '../ui/TextField';
import { PolicyParametersForm } from './PolicyParametersForm';

export type PolicyFormMode =
  | { type: 'create'; kind: FinancialPolicyKind }
  | { type: 'version'; policy: FinancialPolicy; from: FinancialPolicyVersion | null }
  | { type: 'edit'; policy: FinancialPolicy; version: FinancialPolicyVersion };

interface Props {
  mode: PolicyFormMode;
  onClose: () => void;
  onSaved: (version: FinancialPolicyVersion) => void;
}

/**
 * Creates a policy with its first draft, drafts the next version of a policy, or edits a draft
 * (ADR-023). Saving never activates anything: a draft is submitted, then approved by another
 * administrator. The browser checks the form; the server checks it again and its 422s are shown
 * on the same fields.
 */
export const PolicyFormModal: React.FC<Props> = ({ mode, onClose, onSaved }) => {
  const kind: FinancialPolicyKind = mode.type === 'create' ? mode.kind : mode.policy.kind;
  const source = mode.type === 'edit' ? mode.version : mode.type === 'version' ? mode.from : null;
  const today = businessToday();

  const [form, setForm] = useState<PolicyForms[FinancialPolicyKind]>(() => (source ? formFromParameters(kind, source.parameters) : emptyForm(kind)));
  const [scopeType, setScopeType] = useState<FinancialPolicyScopeType>('global');
  const [scopeValue, setScopeValue] = useState('');
  const [nameAr, setNameAr] = useState(mode.type === 'create' ? KIND_LABELS[kind] : '');
  const [effectiveFrom, setEffectiveFrom] = useState(mode.type === 'edit' ? mode.version.effective_from : '');
  const [effectiveTo, setEffectiveTo] = useState(mode.type === 'edit' ? mode.version.effective_to ?? '' : '');
  const [reason, setReason] = useState(mode.type === 'edit' ? mode.version.change_reason : '');
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  const sectors = useSectors();
  const services = useCatalogServices();

  const save = useApiMutation(async (parameters: FinancialPolicyParameters) => {
    const draft = { parameters, effective_from: effectiveFrom, effective_to: effectiveTo === '' ? null : effectiveTo, change_reason: reason.trim() };
    if (mode.type === 'edit') return api.financialPolicyVersions.update(mode.version.id, draft);
    if (mode.type === 'version') return api.financialPolicies.draftVersion(mode.policy.id, draft);
    return api.financialPolicies.create({
      ...draft,
      kind,
      scope_type: scopeType,
      ...(scopeType === 'sector' ? { scope_code: scopeValue } : {}),
      ...(scopeType === 'catalog_service' || scopeType === 'service_provider' ? { scope_id: Number(scopeValue) } : {}),
      name_ar: nameAr.trim(),
    });
  });

  const serverErrors = isValidationError(save.error) ? firstFieldErrors(save.error) : {};
  const errors: FormErrors = { ...serverErrors, ...localErrors };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const built = buildParameters(kind, form);
    const local: FormErrors = { ...periodErrors(effectiveFrom, effectiveTo, today), ...(built.ok ? {} : built.errors) };
    if (reason.trim().length < 3) local.change_reason = 'اذكر سبب الإصدار أو التعديل (3 أحرف على الأقل).';
    if (mode.type === 'create') {
      if (nameAr.trim() === '') local.name_ar = 'أدخل اسم السياسة.';
      if (scopeType !== 'global' && scopeValue === '') local.scope = 'اختر النطاق.';
    }
    setLocalErrors(local);
    if (Object.keys(local).length > 0 || !built.ok) return;

    const result = await save.run(built.parameters);
    if (result.ok) onSaved(result.data);
  };

  const title = mode.type === 'create' ? `سياسة جديدة: ${KIND_LABELS[kind]}` : mode.type === 'version' ? `إصدار جديد: ${mode.policy.name_ar}` : `تعديل مسودة الإصدار ${mode.version.version}`;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={title}
      subtitle="الحفظ ينشئ مسودة فقط؛ لا تسري حتى يعتمدها مسؤول آخر."
      maxWidth="2xl"
      footer={
        <div className="flex items-center justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onClose}>إلغاء</Button>
          <Button variant="primary" size="sm" type="submit" form="policy-form" isLoading={save.pending} data-action="save-policy">حفظ المسودة</Button>
        </div>
      }
    >
      <form id="policy-form" onSubmit={handleSubmit} noValidate className="space-y-5 text-right" dir="rtl">
        {mode.type === 'create' && (
          <section className="space-y-3">
            <TextField label="اسم السياسة" value={nameAr} onChange={setNameAr} messages={errors.name_ar ? [errors.name_ar] : undefined} required />
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <label className="text-xs space-y-1">
                <span className="font-semibold text-[#344054]">النطاق</span>
                <select
                  value={scopeType}
                  onChange={(e) => {
                    setScopeType(e.target.value as FinancialPolicyScopeType);
                    setScopeValue('');
                  }}
                  className="w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white"
                  data-testid="scope-type"
                >
                  {ALLOWED_SCOPES[kind].map((scope) => (
                    <option key={scope} value={scope}>{SCOPE_LABELS[scope]}</option>
                  ))}
                </select>
                <FieldError messages={errors.scope_type ? [errors.scope_type] : undefined} />
              </label>
              {scopeType === 'sector' && (
                <label className="text-xs space-y-1">
                  <span className="font-semibold text-[#344054]">القطاع</span>
                  <select value={scopeValue} onChange={(e) => setScopeValue(e.target.value)} className="w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white">
                    <option value="">اختر…</option>
                    {(sectors.data ?? []).map((sector) => <option key={sector.code} value={sector.code}>{sector.name_ar}</option>)}
                  </select>
                  <FieldError messages={[errors.scope, errors.scope_code].filter((m): m is string => Boolean(m))} />
                </label>
              )}
              {scopeType === 'catalog_service' && (
                <label className="text-xs space-y-1">
                  <span className="font-semibold text-[#344054]">الخدمة</span>
                  <select value={scopeValue} onChange={(e) => setScopeValue(e.target.value)} className="w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white">
                    <option value="">اختر…</option>
                    {(services.data ?? []).map((service) => <option key={service.id} value={service.id}>{service.name_ar}</option>)}
                  </select>
                  <FieldError messages={[errors.scope, errors.scope_id].filter((m): m is string => Boolean(m))} />
                </label>
              )}
              {scopeType === 'service_provider' && (
                <TextField label="رقم مزود الخدمة" value={scopeValue} onChange={setScopeValue} dir="ltr" messages={[errors.scope, errors.scope_id].filter((m): m is string => Boolean(m))} hint="يظهر الرقم في صفحة مزودي الخدمات." />
              )}
            </div>
            <p className="text-[11px] text-[#98A2B3]">
              عند تعدد السياسات المنطبقة يُطبَّق الأكثر تحديدًا: مزود الخدمة ثم الخدمة ثم القطاع ثم السياسة العامة (ترتيب مقترح بانتظار اعتماد الجهة المالكة، OQ-47).
            </p>
          </section>
        )}

        <section className="space-y-3">
          <h4 className="text-xs font-bold text-[#172033]">القيم</h4>
          <PolicyParametersForm kind={kind} form={form} onChange={setForm} errors={errors} />
        </section>

        <section className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="text-xs space-y-1">
            <span className="font-semibold text-[#344054]">يسري من <span className="text-[#B82B3B]">*</span></span>
            <input type="date" min={today} value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} className="w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white" dir="ltr" data-testid="effective-from" />
            <FieldError messages={errors.effective_from ? [errors.effective_from] : undefined} />
          </label>
          <label className="text-xs space-y-1">
            <span className="font-semibold text-[#344054]">يسري حتى (اختياري)</span>
            <input type="date" min={effectiveFrom || today} value={effectiveTo} onChange={(e) => setEffectiveTo(e.target.value)} className="w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white" dir="ltr" />
            <FieldError messages={errors.effective_to ? [errors.effective_to] : undefined} />
          </label>
        </section>
        <TextField label="سبب الإصدار أو التعديل (يُسجَّل في سجل التدقيق)" value={reason} onChange={setReason} rows={2} messages={errors.change_reason ? [errors.change_reason] : undefined} required />

        {save.error !== null && !isValidationError(save.error) && <ApiErrorState compact error={save.error} />}
        {isValidationError(save.error) && Object.keys(serverErrors).length > 0 && (
          <p className="text-[11px] text-[#B82B3B]" role="alert">رفض الخادم بعض القيم؛ راجع الحقول المعلَّمة.</p>
        )}
      </form>
    </Modal>
  );
};
