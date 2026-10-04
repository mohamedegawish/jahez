import React, { useState } from 'react';
import { Save, CheckCircle2, FilePen, XCircle } from 'lucide-react';
import { api, fieldMessages, isValidationError } from '../../api';
import type { LegalField, ProviderChangeRequest, ServiceProvider } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyProvider } from '../../hooks/useMyOrganization';
import { formatDateTime } from '../../lib/format';
import { documentTypeLabel, LEGAL_ACCEPT, LEGAL_MAX_KB } from '../../lib/files';
import { EMPTY_PROVIDER_FORM, toProviderPayload, valuesFromProvider, type ProviderFormValues } from '../../lib/provider';
import { ApprovalStatusPanel } from '../../components/organization/ApprovalStatusPanel';
import { OrganizationDocumentsCard } from '../../components/organization/OrganizationDocumentsCard';
import { ProviderFields } from '../../components/provider/ProviderFields';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { ApprovalBadge } from '../../components/ui/ApprovalBadge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { FieldError } from '../../components/ui/FieldError';
import { FileField } from '../../components/ui/FileField';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { DocumentRow } from '../../components/ui/PrivateFile';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { SectorPicker } from '../../components/ui/SectorPicker';
import { TextField } from '../../components/ui/TextField';

export const ProviderProfile: React.FC = () => {
  const provider = useMyProvider();
  // Kept here, outside the keyed form: refetching after a save remounts the form, which would hide the banner.
  const [saved, setSaved] = useState(false);
  return (
    <QueryBoundary query={provider} loading={<CardSkeleton />}>
      {(p) => (
        <ProfileForm
          key={`${p.updated_at}-${p.approval.changed_at}`}
          provider={p}
          saved={saved}
          onSaving={() => setSaved(false)}
          onSaved={() => setSaved(true)}
          onChanged={provider.refetch}
        />
      )}
    </QueryBoundary>
  );
};

const ProfileForm: React.FC<{
  provider: ServiceProvider;
  saved: boolean;
  onSaving: () => void;
  onSaved: () => void;
  onChanged: () => void;
}> = ({ provider, saved, onSaving, onSaved, onChanged }) => {
  const { refresh } = useAuth();
  const [values, setValues] = useState<ProviderFormValues>(() => valuesFromProvider(provider));
  const [sectorCodes, setSectorCodes] = useState<string[]>(provider.sectors?.map((s) => s.code) ?? []);
  const verified = provider.legal_information_verified;

  // Profile edits never change the approval status (owner decision); services have their own page.
  // After approval the legal fields are not sent: they change only through a change request.
  const save = useApiMutation(() =>
    api.serviceProviders.update(provider.id, { ...toProviderPayload(values, { includeLegal: !verified }), sectors: sectorCodes }),
  );

  const handleSave = async () => {
    onSaving();
    const result = await save.run();
    if (result.ok) {
      onSaved();
      onChanged();
      void refresh(); // the header shows the organization name from /me
    }
  };

  const sectorErrors = fieldMessages(save.error, 'sectors');
  const hasFieldErrors = sectorErrors.length > 0 || Object.keys(EMPTY_PROVIDER_FORM).some((f) => fieldMessages(save.error, f).length > 0);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">الملف التعريفي والاعتماد</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">بيانات شركتكم كما تظهر لمركز تحديث الصناعة وللمصانع المؤهلة بعد الاعتماد.</p>
        </div>
        <div className="flex items-center gap-3">
          <ApprovalBadge status={provider.approval.status} />
          <Button variant="primary" size="sm" icon={Save} onClick={handleSave} isLoading={save.pending} disabled={values.name.trim() === ''}>
            حفظ التعديلات
          </Button>
        </div>
      </div>

      {saved && (
        <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center gap-2">
          <CheckCircle2 className="w-4 h-4" />
          تم حفظ بيانات الملف التعريفي. لا يغيّر التعديل حالة الاعتماد.
        </div>
      )}
      {save.error !== null && !hasFieldErrors && <ApiErrorState compact error={save.error} />}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <Card title="المعلومات الأساسية للشركة" accent="blue">
            <ProviderFields
              values={values}
              onChange={(changes) => setValues((prev) => ({ ...prev, ...changes }))}
              error={save.error}
              disabled={save.pending}
              legalLocked={verified}
            />
            <div className="mt-4 pt-4 border-t border-[#E6EAF0] text-xs">
              <span className="font-bold text-[#172033] block mb-1.5">القطاعات الصناعية المستهدفة:</span>
              <SectorPicker value={sectorCodes} onChange={setSectorCodes} disabled={save.pending} />
              <FieldError messages={sectorErrors} />
              <p className="text-[11px] text-[#98A2B3] mt-1.5">تظهر شركتكم لمنشآت هذه القطاعات فقط.</p>
            </div>
          </Card>

          {verified && <LegalChangePanel provider={provider} onChanged={onChanged} />}
        </div>

        <div className="space-y-6">
          <ApprovalPanel provider={provider} onChanged={onChanged} />
          <OrganizationDocumentsCard
            documents={provider.documents}
            upload={(type, file) => api.serviceProviders.uploadDocument(provider.id, type, file)}
            loadFile={(documentId, signal) => api.serviceProviders.documentFile(provider.id, documentId, signal)}
            onUploaded={onChanged}
            legalLocked={verified}
          />
        </div>
      </div>
    </div>
  );
};

const LEGAL_LABELS: Record<LegalField, string> = {
  legal_name: 'الاسم القانوني',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
};

const CHANGE_STATUS_TEXT: Record<ProviderChangeRequest['status'], string> = {
  pending: 'قيد المراجعة',
  approved: 'تمت الموافقة وطُبّق التعديل',
  rejected: 'رُفض الطلب',
  cancelled: 'ألغيتم الطلب',
};

/**
 * After IMC approval the legal name, registration numbers and registration documents change only
 * through a change request that an IMC administrator reviews (ADR-019).
 */
const LegalChangePanel: React.FC<{ provider: ServiceProvider; onChanged: () => void }> = ({ provider, onChanged }) => {
  const open = provider.open_change_request ?? null;
  const history = useApiQuery(
    (signal) => api.serviceProviders.changeRequests.list(provider.id, { per_page: 5, signal }),
    [provider.id, open?.id],
  );
  const latestClosed = history.data?.data.find((request) => request.status !== 'pending') ?? null;

  return (
    <Card title="طلب تعديل البيانات القانونية" subtitle="تُطبَّق التعديلات بعد موافقة مركز تحديث الصناعة فقط" accent="purple">
      {open ? <OpenRequest provider={provider} request={open} onChanged={onChanged} /> : <NewRequestForm provider={provider} onChanged={onChanged} />}
      {latestClosed && (
        <div className="mt-4 pt-3 border-t border-[#E6EAF0] text-xs space-y-1">
          <span className="font-bold text-[#172033]">آخر طلب: {CHANGE_STATUS_TEXT[latestClosed.status]}</span>
          <span className="text-[11px] text-[#98A2B3] block">{formatDateTime(latestClosed.reviewed_at ?? latestClosed.created_at)}</span>
          {latestClosed.review_reason && (
            <p className="p-2.5 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-[#172033] whitespace-pre-line" dir="auto">
              {latestClosed.review_reason}
            </p>
          )}
        </div>
      )}
    </Card>
  );
};

const OpenRequest: React.FC<{ provider: ServiceProvider; request: ProviderChangeRequest; onChanged: () => void }> = ({ provider, request, onChanged }) => {
  const cancel = useApiMutation(() => api.serviceProviders.changeRequests.cancel(provider.id, request.id));

  const handleCancel = async () => {
    const result = await cancel.run();
    if (result.ok) onChanged();
  };

  return (
    <div className="space-y-3 text-xs">
      <div className="p-3 rounded-xl bg-[#FFF3E2] border border-[#F7D9A8] text-[#B26A09] font-semibold">
        لديكم طلب تعديل قيد المراجعة منذ {formatDateTime(request.created_at)}. لا يمكن إرسال طلب آخر قبل البت فيه أو إلغائه.
      </div>
      <dl className="space-y-1.5">
        {(Object.keys(request.changes) as LegalField[]).map((field) => (
          <div key={field} className="flex flex-wrap gap-2">
            <dt className="text-[#667085]">{LEGAL_LABELS[field]}:</dt>
            <dd className="font-bold text-[#172033]" dir="auto">{request.changes[field] ?? '— حذف القيمة —'}</dd>
          </div>
        ))}
      </dl>
      {(request.documents ?? []).map((document) => (
        <DocumentRow
          key={document.id}
          document={document}
          label={`${documentTypeLabel[document.type]} (جديد)`}
          load={() => api.serviceProviders.documentFile(provider.id, document.id)}
        />
      ))}
      {request.note && <p className="text-[#667085] whitespace-pre-line" dir="auto">ملاحظتكم: {request.note}</p>}
      {cancel.error !== null && <ApiErrorState compact error={cancel.error} />}
      <Button variant="outline" size="sm" icon={XCircle} onClick={handleCancel} isLoading={cancel.pending}>
        إلغاء الطلب
      </Button>
    </div>
  );
};

const NewRequestForm: React.FC<{ provider: ServiceProvider; onChanged: () => void }> = ({ provider, onChanged }) => {
  const [legal, setLegal] = useState<Record<LegalField, string>>({
    legal_name: provider.legal_name ?? '',
    commercial_registration_number: provider.commercial_registration_number ?? '',
    tax_registration_number: provider.tax_registration_number ?? '',
  });
  const [crDocument, setCrDocument] = useState<File | null>(null);
  const [taxDocument, setTaxDocument] = useState<File | null>(null);
  const [note, setNote] = useState('');

  const submit = useApiMutation((form: FormData) => api.serviceProviders.changeRequests.create(provider.id, form));
  const errors = (field: string) => fieldMessages(submit.error, field);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const form = new FormData();
    // Only the fields that differ are sent; the API keeps only values that change anyway.
    (Object.keys(legal) as LegalField[]).forEach((field) => {
      if (legal[field].trim() !== (provider[field] ?? '')) form.append(field, legal[field].trim());
    });
    if (crDocument) form.append('commercial_registration_document', crDocument);
    if (taxDocument) form.append('tax_registration_document', taxDocument);
    if (note.trim() !== '') form.append('note', note.trim());

    const result = await submit.run(form);
    if (result.ok) onChanged();
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-3" noValidate>
      <TextField label="الاسم القانوني" value={legal.legal_name} onChange={(value) => setLegal((prev) => ({ ...prev, legal_name: value }))} messages={errors('legal_name')} maxLength={255} disabled={submit.pending} />
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <TextField label="رقم السجل التجاري" dir="ltr" value={legal.commercial_registration_number} onChange={(value) => setLegal((prev) => ({ ...prev, commercial_registration_number: value }))} messages={errors('commercial_registration_number')} maxLength={50} disabled={submit.pending} />
        <TextField label="رقم التسجيل الضريبي" dir="ltr" value={legal.tax_registration_number} onChange={(value) => setLegal((prev) => ({ ...prev, tax_registration_number: value }))} messages={errors('tax_registration_number')} maxLength={50} disabled={submit.pending} />
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <FileField label="مستند سجل تجاري جديد" accept={LEGAL_ACCEPT} maxKb={LEGAL_MAX_KB} value={crDocument} onChange={setCrDocument} messages={errors('commercial_registration_document')} disabled={submit.pending} />
        <FileField label="مستند تسجيل ضريبي جديد" accept={LEGAL_ACCEPT} maxKb={LEGAL_MAX_KB} value={taxDocument} onChange={setTaxDocument} messages={errors('tax_registration_document')} disabled={submit.pending} />
      </div>
      <TextField label="سبب التعديل (اختياري)" rows={2} value={note} onChange={setNote} messages={errors('note')} maxLength={2000} disabled={submit.pending} />
      <FieldError messages={errors('changes')} />
      {submit.error !== null && !isValidationError(submit.error) && <ApiErrorState compact error={submit.error} />}
      <Button type="submit" variant="primary" size="sm" icon={FilePen} isLoading={submit.pending}>
        إرسال طلب التعديل للمراجعة
      </Button>
    </form>
  );
};

const STATUS_TEXT: Record<ServiceProvider['approval']['status'], string> = {
  pending: 'ملفكم قيد المراجعة لدى مركز تحديث الصناعة. لا يظهر للمصانع قبل الاعتماد.',
  approved: 'ملفكم معتمد. تظهر خدماتكم المعتمدة للمصانع المؤهلة، ويمكنكم استقبال الطلبات وتقديم العروض.',
  rejected: 'رُفض اعتماد ملفكم. يمكنكم تعديل البيانات ثم طلب مراجعة جديدة.',
  suspended: 'اعتماد ملفكم موقوف حاليًا، فتتوقف طلباتكم المفتوحة مؤقتًا. تواصلوا مع مركز تحديث الصناعة.',
  changes_requested: 'طلب المركز استكمال بيانات ملفكم أو تصويبها. حدّثوا البيانات المطلوبة ثم اطلبوا مراجعة جديدة.',
};

const ApprovalPanel: React.FC<{ provider: ServiceProvider; onChanged: () => void }> = ({ provider, onChanged }) => (
  <ApprovalStatusPanel
    approval={provider.approval}
    texts={STATUS_TEXT}
    requestReview={(note) => api.serviceProviders.requestReview(provider.id, note)}
    onChanged={onChanged}
  />
);
