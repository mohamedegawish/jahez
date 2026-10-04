import React, { useState } from 'react';
import { FilePen, XCircle } from 'lucide-react';
import { api, fieldMessages, isValidationError } from '../../api';
import type { Factory, FactoryChangeRequest, LegalField } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { LEGAL_ACCEPT, LEGAL_MAX_KB } from '../../lib/files';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { FieldError } from '../ui/FieldError';
import { FileField } from '../ui/FileField';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';
import { TextField } from '../ui/TextField';

const LEGAL_LABELS: Record<LegalField, string> = {
  legal_name: 'الاسم القانوني',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
};

const STATUS_TEXT: Record<FactoryChangeRequest['status'], string> = {
  pending: 'قيد المراجعة',
  approved: 'اعتُمد وطُبّقت القيم الجديدة',
  rejected: 'رُفض وبقيت القيم المسجلة',
  cancelled: 'ألغيتموه',
};

/**
 * Changes to the factory's recorded legal information (ADR-020): once a legal field or registration
 * document holds a value, it changes only after IMC approves a change request. The recorded values stay
 * in force until then; the history of requests and decisions is listed below the form.
 */
export const FactoryLegalChanges: React.FC<{ factory: Factory; onChanged: () => void }> = ({ factory, onChanged }) => {
  const history = useApiQuery((signal) => api.factoryChangeRequests.list(factory.id, { per_page: 10, signal }), [factory.id, factory.updated_at]);
  const open = history.data?.data.find((request) => request.status === 'pending') ?? null;
  const reload = () => {
    history.refetch();
    onChanged();
  };

  return (
    <Card title="تعديل البيانات القانونية" subtitle="تبقى القيم المسجلة سارية حتى يعتمد مركز تحديث الصناعة التعديل" accent="purple">
      <QueryBoundary query={history} loading={<CardSkeleton />}>
        {(page) => (
          <div className="space-y-4">
            {open ? <OpenRequest factory={factory} request={open} onChanged={reload} /> : <NewRequestForm factory={factory} onChanged={reload} />}
            {page.data.filter((request) => request.status !== 'pending').length > 0 && (
              <div className="pt-3 border-t border-[#E6EAF0] space-y-2 text-xs">
                <span className="font-bold text-[#172033]">سجل الطلبات</span>
                <ul className="space-y-2">
                  {page.data
                    .filter((request) => request.status !== 'pending')
                    .map((request) => (
                      <li key={request.id} className="p-2.5 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0]">
                        <div className="flex items-center justify-between gap-2">
                          <span className="font-semibold text-[#172033]">#{request.id} · {STATUS_TEXT[request.status]}</span>
                          <span className="text-[10px] text-[#98A2B3]">{formatDateTime(request.reviewed_at ?? request.created_at)}</span>
                        </div>
                        <div className="text-[11px] text-[#667085] mt-1">
                          {(Object.keys(request.changes) as LegalField[]).map((field) => LEGAL_LABELS[field]).join('، ') || 'مستندات'}
                        </div>
                        {request.review_reason && <p className="mt-1 text-[#172033]" dir="auto">السبب: {request.review_reason}</p>}
                      </li>
                    ))}
                </ul>
              </div>
            )}
          </div>
        )}
      </QueryBoundary>
    </Card>
  );
};

const OpenRequest: React.FC<{ factory: Factory; request: FactoryChangeRequest; onChanged: () => void }> = ({ factory, request, onChanged }) => {
  const cancel = useApiMutation(() => api.factoryChangeRequests.cancel(factory.id, request.id));
  return (
    <div className="space-y-3 text-xs">
      <div className="p-3 rounded-xl bg-[#FFF3E2] border border-[#F7D9A8] text-[#B26A09] font-semibold">
        طلب تعديل قيد المراجعة منذ {formatDateTime(request.created_at)}. تبقى البيانات المسجلة سارية حتى القرار.
      </div>
      <dl className="space-y-1.5">
        {(Object.keys(request.changes) as LegalField[]).map((field) => (
          <div key={field} className="flex flex-wrap gap-2">
            <dt className="text-[#667085]">{LEGAL_LABELS[field]}:</dt>
            <dd className="font-bold text-[#172033]" dir="auto">
              {factory[field] ?? '—'} ← {request.changes[field] ?? '— حذف القيمة —'}
            </dd>
          </div>
        ))}
      </dl>
      {(request.documents ?? []).length > 0 && <p className="text-[#667085]">مستندات مرفقة: {(request.documents ?? []).length}</p>}
      {cancel.error !== null && <ApiErrorState compact error={cancel.error} />}
      <Button
        variant="outline"
        size="sm"
        icon={XCircle}
        isLoading={cancel.pending}
        onClick={async () => {
          const result = await cancel.run();
          if (result.ok) onChanged();
        }}
      >
        إلغاء الطلب
      </Button>
    </div>
  );
};

const NewRequestForm: React.FC<{ factory: Factory; onChanged: () => void }> = ({ factory, onChanged }) => {
  const [legal, setLegal] = useState<Record<LegalField, string>>({
    legal_name: factory.legal_name ?? '',
    commercial_registration_number: factory.commercial_registration_number ?? '',
    tax_registration_number: factory.tax_registration_number ?? '',
  });
  const [crDocument, setCrDocument] = useState<File | null>(null);
  const [taxDocument, setTaxDocument] = useState<File | null>(null);
  const [note, setNote] = useState('');
  const submit = useApiMutation((form: FormData) => api.factoryChangeRequests.create(factory.id, form));
  const errors = (field: string) => fieldMessages(submit.error, field);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const form = new FormData();
    (Object.keys(legal) as LegalField[]).forEach((field) => {
      if (legal[field].trim() !== (factory[field] ?? '')) form.append(field, legal[field].trim());
    });
    if (crDocument) form.append('commercial_registration_document', crDocument);
    if (taxDocument) form.append('tax_registration_document', taxDocument);
    if (note.trim() !== '') form.append('note', note.trim());
    const result = await submit.run(form);
    if (result.ok) onChanged();
  };

  const set = (field: LegalField) => (value: string) => setLegal((prev) => ({ ...prev, [field]: value }));

  return (
    <form onSubmit={handleSubmit} className="space-y-3" noValidate>
      <TextField label="الاسم القانوني" value={legal.legal_name} onChange={set('legal_name')} messages={errors('legal_name')} maxLength={255} disabled={submit.pending} />
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <TextField label="رقم السجل التجاري" dir="ltr" value={legal.commercial_registration_number} onChange={set('commercial_registration_number')} messages={errors('commercial_registration_number')} maxLength={50} disabled={submit.pending} />
        <TextField label="رقم التسجيل الضريبي" dir="ltr" value={legal.tax_registration_number} onChange={set('tax_registration_number')} messages={errors('tax_registration_number')} maxLength={50} disabled={submit.pending} />
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
