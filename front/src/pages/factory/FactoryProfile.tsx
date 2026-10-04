import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Save, CheckCircle2, Gauge } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { Factory, FactoryProfilePayload } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useMyFactory } from '../../hooks/useMyOrganization';
import { useFactorySizes, nameOf } from '../../hooks/useReference';
import { formatDate } from '../../lib/format';
import { FactoryLegalChanges } from '../../components/factory/FactoryLegalChanges';
import { OnboardingSteps } from '../../components/factory/OnboardingSteps';
import { ApprovalStatusPanel } from '../../components/organization/ApprovalStatusPanel';
import { OrganizationDocumentsCard } from '../../components/organization/OrganizationDocumentsCard';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { FieldError } from '../../components/ui/FieldError';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ReadinessBadge } from '../../components/ui/ReadinessBadge';
import { SectorPicker } from '../../components/ui/SectorPicker';
import { TextField } from '../../components/ui/TextField';

type ProfileValues = Required<{ [K in keyof FactoryProfilePayload]: string }>;

const PROFILE_KEYS: (keyof ProfileValues)[] = [
  'legal_name',
  'contact_name',
  'contact_job_title',
  'contact_email',
  'contact_phone',
  'website',
  'governorate',
  'city',
  'address',
  'commercial_registration_number',
  'tax_registration_number',
];

const valuesFrom = (factory: Factory): ProfileValues =>
  Object.fromEntries(PROFILE_KEYS.map((key) => [key, factory[key] ?? ''])) as ProfileValues;

export const FactoryProfile: React.FC = () => {
  const factory = useMyFactory();
  // Kept here, outside the keyed form: refetching after a save remounts the form, which would hide the banner.
  const [saved, setSaved] = useState(false);
  return (
    <QueryBoundary query={factory} loading={<CardSkeleton />}>
      {(f) => (
        <ProfileForm
          key={f.updated_at ?? f.id}
          factory={f}
          saved={saved}
          onSaving={() => setSaved(false)}
          onSaved={() => {
            setSaved(true);
            factory.refetch();
          }}
          onChanged={factory.refetch}
        />
      )}
    </QueryBoundary>
  );
};

/** What the IMC review of the factory account means for the factory (ADR-021). */
const FACTORY_APPROVAL_TEXT: Record<Factory['approval']['status'], string> = {
  pending: 'ملف منشأتكم قيد المراجعة لدى مركز تحديث الصناعة. يمكنكم استكمال البيانات وإجراء تقييم الجاهزية، وإرسال طلبات الخدمة بعد الاعتماد.',
  approved: 'منشأتكم معتمدة، ويمكنكم إرسال طلبات الخدمة إلى المزودين المؤهلين. لا يتأثر تصنيف الجاهزية بقرار الاعتماد.',
  rejected: 'رُفض اعتماد ملف منشأتكم. صوّبوا البيانات ثم اطلبوا مراجعة جديدة.',
  suspended: 'اعتماد منشأتكم موقوف حاليًا، فلا يمكن إرسال طلبات خدمة جديدة. تواصلوا مع مركز تحديث الصناعة.',
  changes_requested: 'طلب المركز استكمال بيانات منشأتكم أو تصويبها. حدّثوا البيانات المطلوبة ثم اطلبوا مراجعة جديدة.',
};

const ProfileForm: React.FC<{ factory: Factory; saved: boolean; onSaving: () => void; onSaved: () => void; onChanged: () => void }> = ({
  factory,
  saved,
  onSaving,
  onSaved,
  onChanged,
}) => {
  const navigate = useNavigate();
  const { refresh } = useAuth();
  const sizes = useFactorySizes();
  const [name, setName] = useState(factory.name);
  const [sectorCodes, setSectorCodes] = useState<string[]>(factory.sectors?.map((s) => s.code) ?? []);
  const [values, setValues] = useState<ProfileValues>(() => valuesFrom(factory));

  // Members change the name, sectors and registration details; the declared size is set by IMC.
  const save = useApiMutation(() => {
    const profile = Object.fromEntries(PROFILE_KEYS.map((key) => [key, values[key].trim() === '' ? null : values[key].trim()]));
    return api.factories.update(factory.id, { name: name.trim(), sectors: sectorCodes, ...profile });
  });

  const handleSave = async () => {
    onSaving();
    const result = await save.run();
    if (result.ok) {
      onSaved();
      void refresh(); // the header shows the organization name from /me
    }
  };

  const set = (key: keyof ProfileValues) => (value: string) => setValues((prev) => ({ ...prev, [key]: value }));
  const errors = (field: string) => fieldMessages(save.error, field);
  const hasFieldErrors = ['name', 'sectors', ...PROFILE_KEYS].some((field) => errors(field).length > 0);
  const readiness = factory.current_readiness;
  const disabled = save.pending;
  // A recorded legal value changes only through a reviewed change request (ADR-020).
  const recorded = (key: 'legal_name' | 'commercial_registration_number' | 'tax_registration_number') => (factory[key] ?? '') !== '';

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">ملف المنشأة</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">البيانات المسجلة لدى المنظومة لـ {factory.name}.</p>
        </div>
        <Button variant="primary" size="sm" icon={Save} onClick={handleSave} isLoading={save.pending} disabled={name.trim() === ''}>
          حفظ التحديثات
        </Button>
      </div>

      {saved && (
        <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center gap-2">
          <CheckCircle2 className="w-4 h-4" />
          تم حفظ بيانات المنشأة في المنظومة.
        </div>
      )}
      {save.error !== null && !hasFieldErrors && <ApiErrorState compact error={save.error} />}

      <OnboardingSteps factory={factory} />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <Card title="المعلومات العامة" accent="blue">
            <div className="space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <TextField label="اسم المنشأة الصناعية" required value={name} onChange={setName} messages={errors('name')} maxLength={255} disabled={disabled} />
                <TextField label="الاسم القانوني" value={values.legal_name} onChange={set('legal_name')} messages={errors('legal_name')} maxLength={255} disabled={disabled || recorded('legal_name')} />
              </div>

              <div>
                <span className="font-bold text-[#172033] block mb-1.5">القطاعات الصناعية:</span>
                <SectorPicker value={sectorCodes} onChange={setSectorCodes} disabled={disabled} />
                <FieldError messages={errors('sectors')} />
              </div>

              <div>
                <span className="font-bold text-[#172033] block mb-1">حجم المنشأة:</span>
                <div className="p-2.5 rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] text-[#172033]">
                  {factory.size ? nameOf(sizes.data, factory.size) : 'لم يحدده مركز تحديث الصناعة بعد'}
                </div>
                <p className="text-[11px] text-[#98A2B3] mt-1">يحدد مركز تحديث الصناعة حجم المنشأة ولا يمكن تعديله من هنا.</p>
              </div>
            </div>
          </Card>

          <Card title="مسؤول التواصل" accent="purple">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <TextField label="الاسم" value={values.contact_name} onChange={set('contact_name')} messages={errors('contact_name')} maxLength={255} disabled={disabled} />
              <TextField label="المسمى الوظيفي" value={values.contact_job_title} onChange={set('contact_job_title')} messages={errors('contact_job_title')} maxLength={255} disabled={disabled} />
              <TextField label="البريد الإلكتروني" type="email" dir="ltr" value={values.contact_email} onChange={set('contact_email')} messages={errors('contact_email')} maxLength={255} disabled={disabled} />
              <TextField label="رقم الهاتف" type="tel" dir="ltr" value={values.contact_phone} onChange={set('contact_phone')} messages={errors('contact_phone')} maxLength={30} disabled={disabled} />
            </div>
          </Card>

          <Card title="العنوان وبيانات التسجيل" accent="blue">
            <div className="space-y-3">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <TextField label="المحافظة" value={values.governorate} onChange={set('governorate')} messages={errors('governorate')} maxLength={100} disabled={disabled} />
                <TextField label="المدينة / المنطقة الصناعية" value={values.city} onChange={set('city')} messages={errors('city')} maxLength={100} disabled={disabled} />
              </div>
              <TextField label="العنوان التفصيلي" value={values.address} onChange={set('address')} messages={errors('address')} maxLength={500} disabled={disabled} />
              <TextField label="الموقع الإلكتروني" type="url" dir="ltr" placeholder="https://" value={values.website} onChange={set('website')} messages={errors('website')} maxLength={255} disabled={disabled} />
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <TextField label="رقم السجل التجاري" dir="ltr" value={values.commercial_registration_number} onChange={set('commercial_registration_number')} messages={errors('commercial_registration_number')} maxLength={50} disabled={disabled || recorded('commercial_registration_number')} />
                <TextField label="رقم التسجيل الضريبي" dir="ltr" value={values.tax_registration_number} onChange={set('tax_registration_number')} messages={errors('tax_registration_number')} maxLength={50} disabled={disabled || recorded('tax_registration_number')} />
              </div>
              <p className="text-[11px] text-[#98A2B3]">الاسم القانوني وأرقام التسجيل المسجلة لا تُعدّل مباشرة؛ أرسلوا طلب تعديل للمراجعة من بطاقة «تعديل البيانات القانونية».</p>
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <ApprovalStatusPanel
            approval={factory.approval}
            texts={FACTORY_APPROVAL_TEXT}
            requestReview={(note) => api.factories.requestReview(factory.id, note)}
            onChanged={onChanged}
          />
          <Card title="فئة الجاهزية الرقمية" accent="purple">
            <div className="p-4 rounded-xl bg-gradient-to-br from-[#EEEAFE] to-[#DFF3FF] border border-[#DDD5FD] text-center">
              <span className="text-xs font-bold text-[#5146A5] block">نتيجة آخر تقييم</span>
              <div className="text-4xl font-extrabold text-[#5146A5] my-2">{readiness ? readiness.total_score : '—'}</div>
              <ReadinessBadge category={readiness?.category} />
            </div>

            <div className="mt-4 pt-3 border-t border-[#E6EAF0] text-xs space-y-2">
              <div className="flex justify-between">
                <span className="text-[#667085]">تاريخ آخر تقييم:</span>
                <span className="font-bold text-[#172033]">{readiness ? formatDate(readiness.completed_at) : '-'}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-[#667085]">تاريخ التسجيل:</span>
                <span className="font-bold text-[#172033]">{formatDate(factory.created_at)}</span>
              </div>
            </div>

            <Button variant="primary" size="sm" className="w-full mt-4 font-bold" icon={Gauge} onClick={() => navigate('/factory/assessment')}>
              {readiness ? 'تقييم جديد للجاهزية' : 'بدء تقييم الجاهزية الرقمية'}
            </Button>
          </Card>

          <FactoryLegalChanges factory={factory} onChanged={onChanged} />

          <OrganizationDocumentsCard
            documents={factory.documents}
            upload={(type, file) => api.factories.uploadDocument(factory.id, type, file)}
            loadFile={(documentId, signal) => api.factories.documentFile(factory.id, documentId, signal)}
            onUploaded={onChanged}
          />
        </div>
      </div>
    </div>
  );
};
