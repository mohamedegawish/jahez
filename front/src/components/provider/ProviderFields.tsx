import React from 'react';
import { Lock } from 'lucide-react';
import { fieldMessages } from '../../api';
import type { ProviderFormValues } from '../../lib/provider';
import { TextField } from '../ui/TextField';

interface ProviderFieldsProps {
  values: ProviderFormValues;
  onChange: (changes: Partial<ProviderFormValues>) => void;
  /** The last failed save: 422 messages are shown under the matching field. */
  error: unknown;
  disabled?: boolean;
  /** Prefix for element ids, so two forms on a page do not clash. */
  idPrefix?: string;
  /**
   * The legal fields are verified by IMC and shown read-only: a member of an approved provider
   * changes them through a change request (ADR-019).
   */
  legalLocked?: boolean;
}

/**
 * The provider profile fields the API stores (ADR-014, ADR-019), shared by the member profile and
 * the IMC form.
 */
export const ProviderFields: React.FC<ProviderFieldsProps> = ({ values, onChange, error, disabled = false, legalLocked = false }) => {
  const field = (key: keyof ProviderFormValues, label: string, props: Partial<React.ComponentProps<typeof TextField>> = {}) => (
    <TextField
      label={label}
      value={values[key]}
      onChange={(value) => onChange({ [key]: value })}
      messages={fieldMessages(error, key)}
      disabled={disabled}
      {...props}
    />
  );

  return (
    <div className="space-y-4 text-xs">
      {field('name', 'اسم الشركة (الاسم التجاري):', { maxLength: 255, required: true })}
      {field('description', 'نبذة عن الشركة وخبراتها:', { rows: 3, maxLength: 5000 })}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {field('representative_name', 'الممثل المفوض:', { maxLength: 255 })}
        {field('job_title', 'المسمى الوظيفي:', { maxLength: 255 })}
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {field('email', 'البريد الإلكتروني:', { type: 'email', dir: 'ltr', maxLength: 255 })}
        {field('phone', 'رقم الهاتف:', { type: 'tel', dir: 'ltr', maxLength: 30 })}
        {field('website', 'الموقع الإلكتروني:', { type: 'url', dir: 'ltr', placeholder: 'https://', maxLength: 255 })}
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {field('dx_experience_years', 'سنوات الخبرة في التحول الرقمي:', { type: 'number', dir: 'ltr' })}
        {field('governorate', 'المحافظة:', { maxLength: 100 })}
        {field('city', 'المدينة:', { maxLength: 100 })}
      </div>
      {field('address', 'العنوان التفصيلي:', { maxLength: 500 })}

      <div className="pt-4 border-t border-[#E6EAF0] space-y-3">
        <div className="flex items-center gap-2">
          <span className="font-bold text-[#5146A5]">البيانات القانونية</span>
          {legalLocked && (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#F1F4F9] border border-[#E2E8F0] text-[10px] font-bold text-[#475467]">
              <Lock className="w-3 h-3" />
              تحقق منها مركز تحديث الصناعة
            </span>
          )}
        </div>
        {field('legal_name', 'الاسم القانوني:', { maxLength: 255, readOnly: legalLocked })}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {field('commercial_registration_number', 'رقم السجل التجاري:', { dir: 'ltr', maxLength: 50, readOnly: legalLocked })}
          {field('tax_registration_number', 'رقم التسجيل الضريبي:', { dir: 'ltr', maxLength: 50, readOnly: legalLocked })}
        </div>
        {legalLocked && (
          <p className="text-[11px] text-[#667085] leading-relaxed">
            لتعديل هذه البيانات أو مستندات التسجيل، أرسل طلب تعديل من لوحة «طلب تعديل البيانات القانونية»؛ لا يتغير شيء قبل موافقة المركز.
          </p>
        )}
      </div>
    </div>
  );
};
