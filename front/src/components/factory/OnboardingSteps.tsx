import React from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle2, Circle } from 'lucide-react';
import type { Factory } from '../../api';
import { Card } from '../ui/Card';

/** Arabic names of the factory fields the onboarding checklist may ask for (OQ-19). */
const FACTORY_FIELD_LABELS: Record<string, string> = {
  sectors: 'القطاعات الصناعية',
  legal_name: 'الاسم القانوني',
  contact_name: 'اسم مسؤول التواصل',
  contact_email: 'بريد مسؤول التواصل',
  contact_phone: 'هاتف مسؤول التواصل',
  governorate: 'المحافظة',
  city: 'المدينة',
  address: 'العنوان',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
};

/**
 * The factory's first steps after registering (ADR-019), from the API's `onboarding` state:
 * registered → profile → readiness assessment → marketplace. Hidden once every step is done.
 */
export const OnboardingSteps: React.FC<{ factory: Factory; alwaysShow?: boolean }> = ({ factory, alwaysShow = false }) => {
  const navigate = useNavigate();
  const onboarding = factory.onboarding;
  if (!onboarding) return null;

  const assessed = onboarding.readiness_status === 'completed';
  if (!alwaysShow && onboarding.profile_complete && assessed) return null;

  const missing = onboarding.missing_profile_fields.map((field) => FACTORY_FIELD_LABELS[field] ?? field);
  const steps = [
    { title: 'التسجيل في المنصة', desc: 'تم إنشاء حساب المنشأة.', done: true, action: null },
    {
      title: 'إكمال ملف المنشأة',
      desc: onboarding.profile_complete ? 'البيانات المطلوبة مكتملة.' : `ينقص: ${missing.join('، ')}.`,
      done: onboarding.profile_complete,
      action: { label: 'فتح ملف المنشأة', to: '/factory/profile' },
    },
    {
      title: 'تقييم الجاهزية الرقمية',
      desc: assessed ? `المستوى الحالي: ${factory.readiness_level?.name_ar ?? '-'}` : '10 أسئلة؛ يحدد النظام مستوى جاهزية منشأتكم.',
      done: assessed,
      action: { label: assessed ? 'عرض المستوى' : 'بدء التقييم', to: '/factory/assessment' },
    },
    {
      title: 'سوق الخدمات',
      desc: assessed ? 'تصفح المزودين المؤهلين لقطاعاتك والخدمات الموصى بها.' : 'متاح لك الآن، ويقترح الحلول بعد التقييم.',
      done: false,
      action: { label: 'دليل مزودي الخدمات', to: '/factory/providers' },
    },
  ];
  const current = steps.findIndex((step) => !step.done);

  return (
    <Card title="خطوات البدء" subtitle="أكمل هذه الخطوات للاستفادة من خدمات المنصة" accent="gradient">
      <ol className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {steps.map((step, index) => (
          <li
            key={step.title}
            className={`p-3.5 rounded-xl border text-xs flex flex-col gap-2 ${
              step.done
                ? 'bg-[#E7F8EE] border-[#C5F0D5]'
                : index === current
                  ? 'bg-[#EEEAFE] border-[#9B8AFB]'
                  : 'bg-[#F7F9FC] border-[#E6EAF0]'
            }`}
            aria-current={index === current ? 'step' : undefined}
          >
            <div className="flex items-center gap-2 font-bold text-[#172033]">
              {step.done ? <CheckCircle2 className="w-4 h-4 text-[#1D7E4C]" /> : <Circle className="w-4 h-4 text-[#98A2B3]" />}
              {index + 1}. {step.title}
            </div>
            <p className="text-[11px] text-[#667085] leading-relaxed flex-1">{step.desc}</p>
            {step.action && (
              <button
                type="button"
                onClick={() => navigate(step.action.to)}
                className="self-start text-[11px] font-bold text-[#5146A5] hover:text-[#43388E] cursor-pointer"
              >
                {step.action.label} ←
              </button>
            )}
          </li>
        ))}
      </ol>
    </Card>
  );
};
