import React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import type { FinancialPolicyKind } from '../../api';
import type {
  Choice,
  ContractTemplateForm,
  FormErrors,
  InvoicingForm,
  PaymentTermsForm,
  PolicyForms,
  RevenueShareForm,
  TaxForm,
} from '../../lib/financialPolicies';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { TextField } from '../ui/TextField';

interface Props<K extends FinancialPolicyKind> {
  kind: K;
  form: PolicyForms[K];
  onChange: (form: PolicyForms[K]) => void;
  errors: FormErrors;
}

/**
 * The values of one policy kind (ADR-023). Every field starts empty and every yes/no rule starts
 * unanswered: the platform proposes no rate, tax, due date or numbering of its own.
 */
export function PolicyParametersForm<K extends FinancialPolicyKind>({ kind, form, onChange, errors }: Props<K>) {
  const msg = (field: string) => (errors[`parameters.${field}`] ? [errors[`parameters.${field}`]] : undefined);
  const set = <F,>(patch: Partial<F>) => onChange({ ...(form as F), ...patch } as unknown as PolicyForms[K]);

  switch (kind) {
    case 'revenue_share': {
      const f = form as RevenueShareForm;
      return (
        <div className="space-y-3" data-testid="form-revenue_share">
          <TextField label="النسبة المئوية لحصة الوزارة" value={f.rate_percent} onChange={(rate_percent) => set<RevenueShareForm>({ rate_percent })} messages={msg('rate_percent')} dir="ltr" placeholder="مثال: 12.5" required hint="من 0 إلى 100 بحد أقصى منزلتين عشريتين. تُحسب من المجموع الفرعي قبل الضريبة، للاطلاع فقط: لا تُصرف أي مبالغ." />
        </div>
      );
    }
    case 'tax': {
      const f = form as TaxForm;
      const updateTax = (i: number, patch: Partial<TaxForm['taxes'][number]>) => set<TaxForm>({ taxes: f.taxes.map((t, j) => (j === i ? { ...t, ...patch } : t)) });
      const updateFee = (i: number, patch: Partial<TaxForm['fees'][number]>) => set<TaxForm>({ fees: f.fees.map((x, j) => (j === i ? { ...x, ...patch } : x)) });
      return (
        <div className="space-y-4" data-testid="form-tax">
          <ChoiceField label="هل الأسعار المتفق عليها شاملة الضريبة؟" value={f.prices_include_tax} onChange={(prices_include_tax) => set<TaxForm>({ prices_include_tax })} error={msg('prices_include_tax')} />
          <fieldset className="space-y-2">
            <legend className="text-xs font-bold text-[#172033]">الضرائب</legend>
            <p className="text-[11px] text-[#98A2B3]">قائمة فارغة تعني قرارًا معتمدًا بعدم وجود ضريبة؛ لا تُضاف ضريبة افتراضيًا.</p>
            {f.taxes.map((tax, i) => (
              <div key={i} className="grid grid-cols-1 sm:grid-cols-7 gap-2 items-start p-2 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0]">
                <div className="sm:col-span-2"><TextField label="الرمز" value={tax.code} onChange={(code) => updateTax(i, { code })} messages={msg(`taxes.${i}.code`)} dir="ltr" /></div>
                <div className="sm:col-span-3"><TextField label="الاسم" value={tax.name_ar} onChange={(name_ar) => updateTax(i, { name_ar })} messages={msg(`taxes.${i}.name_ar`)} /></div>
                <div className="sm:col-span-1"><TextField label="النسبة %" value={tax.rate_percent} onChange={(rate_percent) => updateTax(i, { rate_percent })} messages={msg(`taxes.${i}.rate_percent`)} dir="ltr" /></div>
                <button type="button" aria-label="حذف الضريبة" onClick={() => set<TaxForm>({ taxes: f.taxes.filter((_, j) => j !== i) })} className="mt-6 p-2 rounded-lg text-[#B82B3B] hover:bg-[#FDECEE] cursor-pointer justify-self-start"><Trash2 className="w-4 h-4" /></button>
              </div>
            ))}
            <FieldError messages={msg('taxes')} />
            <Button type="button" variant="ghost" size="sm" icon={Plus} onClick={() => set<TaxForm>({ taxes: [...f.taxes, { code: '', name_ar: '', rate_percent: '' }] })}>إضافة ضريبة</Button>
          </fieldset>
          <fieldset className="space-y-2">
            <legend className="text-xs font-bold text-[#172033]">الرسوم</legend>
            {f.fees.map((fee, i) => (
              <div key={i} className="space-y-2 p-2 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0]">
                <div className="grid grid-cols-1 sm:grid-cols-6 gap-2 items-start">
                  <div className="sm:col-span-2"><TextField label="الرمز" value={fee.code} onChange={(code) => updateFee(i, { code })} messages={msg(`fees.${i}.code`)} dir="ltr" /></div>
                  <div className="sm:col-span-3"><TextField label="الاسم" value={fee.name_ar} onChange={(name_ar) => updateFee(i, { name_ar })} messages={msg(`fees.${i}.name_ar`)} /></div>
                  <button type="button" aria-label="حذف الرسم" onClick={() => set<TaxForm>({ fees: f.fees.filter((_, j) => j !== i) })} className="mt-6 p-2 rounded-lg text-[#B82B3B] hover:bg-[#FDECEE] cursor-pointer justify-self-start"><Trash2 className="w-4 h-4" /></button>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 items-start">
                  <label className="text-xs space-y-1">
                    <span className="font-semibold text-[#344054]">طريقة الحساب</span>
                    <select value={fee.calculation} onChange={(e) => updateFee(i, { calculation: e.target.value as TaxForm['fees'][number]['calculation'] })} className="w-full p-2 rounded-lg border border-[#E6EAF0] bg-white">
                      <option value="">اختر…</option>
                      <option value="fixed">مبلغ ثابت</option>
                      <option value="percentage">نسبة من صافي الخدمة</option>
                    </select>
                    <FieldError messages={msg(`fees.${i}.calculation`)} />
                  </label>
                  {fee.calculation === 'fixed' && <TextField label="المبلغ (ج.م)" value={fee.amount} onChange={(amount) => updateFee(i, { amount })} messages={msg(`fees.${i}.amount`)} dir="ltr" />}
                  {fee.calculation === 'percentage' && <TextField label="النسبة %" value={fee.rate_percent} onChange={(rate_percent) => updateFee(i, { rate_percent })} messages={msg(`fees.${i}.rate_percent`)} dir="ltr" />}
                  <ChoiceField label="خاضع للضريبة؟" value={fee.taxable} onChange={(taxable) => updateFee(i, { taxable })} error={msg(`fees.${i}.taxable`)} />
                </div>
              </div>
            ))}
            <Button type="button" variant="ghost" size="sm" icon={Plus} onClick={() => set<TaxForm>({ fees: [...f.fees, { code: '', name_ar: '', calculation: '', amount: '', rate_percent: '', taxable: null }] })}>إضافة رسم</Button>
          </fieldset>
        </div>
      );
    }
    case 'invoicing': {
      const f = form as InvoicingForm;
      return (
        <div className="space-y-3" data-testid="form-invoicing">
          <label className="block text-xs space-y-1">
            <span className="font-semibold text-[#344054]">الجهة المُصدِرة للفواتير <span className="text-[#B82B3B]">*</span></span>
            <select value={f.issuer} onChange={(e) => set<InvoicingForm>({ issuer: e.target.value as InvoicingForm['issuer'] })} className="w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white">
              <option value="">اختر…</option>
              <option value="imc">مركز تحديث الصناعة</option>
              <option value="service_provider">مزود الخدمة</option>
            </select>
            <FieldError messages={msg('issuer')} />
          </label>
          <p className="text-[11px] text-[#667085] p-2 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0]">
            الجهة المُلزَمة بالسداد: المنشأة الصناعية · العملة: الجنيه المصري · نوع الفاتورة: خدمة متفق عليها — هذه هي الخيارات الوحيدة التي يدعمها النظام حاليًا.
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <TextField label="بادئة رقم الفاتورة" value={f.number_prefix} onChange={(number_prefix) => set<InvoicingForm>({ number_prefix })} messages={msg('number_prefix')} dir="ltr" required placeholder="مثال: JZ" />
            <TextField label="عدد خانات التسلسل" value={f.number_padding} onChange={(number_padding) => set<InvoicingForm>({ number_padding })} messages={msg('number_padding')} dir="ltr" required placeholder="4 إلى 10" />
          </div>
          <ChoiceField label="هل يُسمح بتسجيل مدفوعات يدوية موثقة (تحويل بنكي)؟" value={f.manual_payments_allowed} onChange={(manual_payments_allowed) => set<InvoicingForm>({ manual_payments_allowed })} error={msg('manual_payments_allowed')} />
          <ChoiceField label="هل يُشترط وجود حصة إيرادات معتمدة لإصدار الفاتورة؟" value={f.requires_revenue_share} onChange={(requires_revenue_share) => set<InvoicingForm>({ requires_revenue_share })} error={msg('requires_revenue_share')} />
        </div>
      );
    }
    case 'payment_terms': {
      const f = form as PaymentTermsForm;
      return (
        <div className="space-y-3" data-testid="form-payment_terms">
          <TextField label="الاستحقاق بعد الإصدار (بالأيام)" value={f.due_days} onChange={(due_days) => set<PaymentTermsForm>({ due_days })} messages={msg('due_days')} dir="ltr" required placeholder="0 إلى 365" />
          <ChoiceField label="هل يُقبل السداد الجزئي؟" value={f.partial_payments_allowed} onChange={(partial_payments_allowed) => set<PaymentTermsForm>({ partial_payments_allowed })} error={msg('partial_payments_allowed')} />
        </div>
      );
    }
    default: {
      const f = form as ContractTemplateForm;
      const updateClause = (i: number, patch: Partial<ContractTemplateForm['clauses'][number]>) => set<ContractTemplateForm>({ clauses: f.clauses.map((c, j) => (j === i ? { ...c, ...patch } : c)) });
      return (
        <div className="space-y-3" data-testid="form-contract_template">
          <p className="text-[11px] text-[#A66F0B] p-2.5 rounded-lg bg-[#FEF5E7] border border-[#FDE5BE]">
            النموذج يولّد مسودات عقود غير ملزمة فقط. لا يجعل أي عقد موقّعًا أو ساريًا قانونيًا حتى يُعتمد إجراء التوقيع والإطار القانوني (OQ-17).
          </p>
          <TextField label="عنوان النموذج" value={f.title_ar} onChange={(title_ar) => set<ContractTemplateForm>({ title_ar })} messages={msg('title_ar')} required />
          <ChoiceField label="هل مركز تحديث الصناعة طرف في العقد (عقد ثلاثي)؟" value={f.include_imc} onChange={(include_imc) => set<ContractTemplateForm>({ include_imc })} error={msg('parties')} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <TextField label="مدة العقد (بالأشهر)" value={f.duration_months} onChange={(duration_months) => set<ContractTemplateForm>({ duration_months })} messages={msg('duration_months')} dir="ltr" hint="اتركها فارغة إن لم تُحدَّد." />
            <TextField label="الحد الأدنى لمهندسي نقل المعرفة" value={f.knowledge_transfer_min_trainees} onChange={(knowledge_transfer_min_trainees) => set<ContractTemplateForm>({ knowledge_transfer_min_trainees })} messages={msg('knowledge_transfer_min_trainees')} dir="ltr" required hint="مهندسان على الأقل وفق DOC §6." />
          </div>
          <fieldset className="space-y-2">
            <legend className="text-xs font-bold text-[#172033]">البنود</legend>
            {f.clauses.map((clause, i) => (
              <div key={i} className="space-y-2 p-2 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0]">
                <div className="flex items-start gap-2">
                  <div className="flex-1"><TextField label={`عنوان البند ${i + 1}`} value={clause.heading_ar} onChange={(heading_ar) => updateClause(i, { heading_ar })} messages={msg(`clauses.${i}.heading_ar`)} /></div>
                  <button type="button" aria-label="حذف البند" onClick={() => set<ContractTemplateForm>({ clauses: f.clauses.filter((_, j) => j !== i) })} className="mt-6 p-2 rounded-lg text-[#B82B3B] hover:bg-[#FDECEE] cursor-pointer"><Trash2 className="w-4 h-4" /></button>
                </div>
                <TextField label="نص البند" value={clause.body_ar} onChange={(body_ar) => updateClause(i, { body_ar })} messages={msg(`clauses.${i}.body_ar`)} rows={3} />
              </div>
            ))}
            <FieldError messages={msg('clauses')} />
            <Button type="button" variant="ghost" size="sm" icon={Plus} onClick={() => set<ContractTemplateForm>({ clauses: [...f.clauses, { heading_ar: '', body_ar: '' }] })}>إضافة بند</Button>
          </fieldset>
        </div>
      );
    }
  }
}

/** A yes/no rule with no preselected answer. */
const ChoiceField: React.FC<{ label: string; value: Choice; onChange: (value: boolean) => void; error?: string[] }> = ({ label, value, onChange, error }) => (
  <fieldset className="text-xs space-y-1.5">
    <legend className="font-semibold text-[#344054]">{label} <span className="text-[#B82B3B]">*</span></legend>
    <div className="flex items-center gap-4">
      {[true, false].map((option) => (
        <label key={String(option)} className="inline-flex items-center gap-1.5 cursor-pointer">
          <input type="radio" checked={value === option} onChange={() => onChange(option)} className="accent-[#7A5AF8]" />
          {option ? 'نعم' : 'لا'}
        </label>
      ))}
    </div>
    <FieldError messages={error} />
  </fieldset>
);
