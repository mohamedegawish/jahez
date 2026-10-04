import React, { useState } from 'react';
import { CheckCircle2, XCircle } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { LegalField, ProviderChangeRequest, ServiceProvider } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { documentTypeLabel } from '../../lib/files';
import { formatDateTime } from '../../lib/format';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { FieldError } from '../ui/FieldError';
import { DocumentRow, PrivateImage } from '../ui/PrivateFile';

const LEGAL_LABELS: Record<LegalField, string> = {
  legal_name: 'الاسم القانوني',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
};

/**
 * What IMC verifies when it approves a provider (ADR-019): the legal name, registration numbers,
 * registration documents, description and address, plus any pending request to change them.
 */
export const ProviderLegalReview: React.FC<{ provider: ServiceProvider; onChanged: (notice: string) => void }> = ({ provider, onChanged }) => {
  const documents = provider.documents;
  const logo = documents?.logo ?? null;

  return (
    <div className="space-y-5">
      {provider.open_change_request && (
        <ChangeRequestReview provider={provider} request={provider.open_change_request} onDecided={onChanged} />
      )}

      <Card title="البيانات القانونية والمستندات" subtitle={provider.legal_information_verified ? 'تحقق منها المركز عند اعتماد المزود' : 'تُراجع قبل اعتماد المزود'} accent="blue">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
          {(Object.keys(LEGAL_LABELS) as LegalField[]).map((field) => (
            <div key={field}>
              <span className="text-[#98A2B3] block text-[10px]">{LEGAL_LABELS[field]}</span>
              <span className="font-semibold text-[#172033]" dir="auto">{provider[field] ?? '—'}</span>
            </div>
          ))}
        </div>
        <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
          <div>
            <span className="text-[#98A2B3] block text-[10px]">العنوان</span>
            <span className="font-semibold text-[#172033]" dir="auto">
              {[provider.address, provider.city, provider.governorate].filter(Boolean).join('، ') || '—'}
            </span>
          </div>
          <div>
            <span className="text-[#98A2B3] block text-[10px]">نبذة</span>
            <span className="text-[#172033] whitespace-pre-line" dir="auto">{provider.description ?? '—'}</span>
          </div>
        </div>

        <div className="mt-4 pt-3 border-t border-[#E6EAF0] space-y-2">
          {logo && (
            <div className="flex items-center gap-3">
              <PrivateImage
                load={(signal) => api.serviceProviders.documentFile(provider.id, logo.id, signal)}
                version={logo.id}
                alt={`شعار ${provider.name}`}
                className="w-14 h-14 rounded-xl border border-[#E6EAF0] object-contain bg-white"
              />
              <span className="text-xs text-[#667085]">الشعار الحالي</span>
            </div>
          )}
          {(['commercial_registration', 'tax_registration'] as const).map((type) => {
            const document = documents?.[type] ?? null;
            return document ? (
              <DocumentRow key={type} document={document} label={documentTypeLabel[type]} load={() => api.serviceProviders.documentFile(provider.id, document.id)} />
            ) : (
              <p key={type} className="text-xs text-[#667085]">{documentTypeLabel[type]}: لم يُرفع.</p>
            );
          })}
        </div>
      </Card>
    </div>
  );
};

const ChangeRequestReview: React.FC<{ provider: ServiceProvider; request: ProviderChangeRequest; onDecided: (notice: string) => void }> = ({ provider, request, onDecided }) => {
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const approve = useApiMutation(() => api.serviceProviders.changeRequests.approve(provider.id, request.id));
  const reject = useApiMutation(() => api.serviceProviders.changeRequests.reject(provider.id, request.id, reason.trim()));

  const handleApprove = async () => {
    const result = await approve.run();
    if (result.ok) onDecided('تمت الموافقة على طلب التعديل وطُبّقت البيانات الجديدة.');
  };
  const handleReject = async () => {
    const result = await reject.run();
    if (result.ok) onDecided('رُفض طلب التعديل ولم تتغير البيانات.');
  };

  return (
    <Card title="طلب تعديل بيانات قانونية بانتظار القرار" subtitle={`أرسله ${request.requested_by?.name ?? '—'} في ${formatDateTime(request.created_at)}`} accent="gradient">
      <div className="space-y-3 text-xs">
        {Object.keys(request.changes).length > 0 && (
          <table className="w-full text-right">
            <thead>
              <tr className="text-[10px] text-[#98A2B3]">
                <th className="py-1 font-semibold">الحقل</th>
                <th className="py-1 font-semibold">القيمة الحالية</th>
                <th className="py-1 font-semibold">القيمة المطلوبة</th>
              </tr>
            </thead>
            <tbody>
              {(Object.keys(request.changes) as LegalField[]).map((field) => (
                <tr key={field} className="border-t border-[#F1F4F9]">
                  <td className="py-1.5 text-[#667085]">{LEGAL_LABELS[field]}</td>
                  <td className="py-1.5 text-[#172033]" dir="auto">{provider[field] ?? '—'}</td>
                  <td className="py-1.5 font-bold text-[#5146A5]" dir="auto">{request.changes[field] ?? '— حذف —'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {(request.documents ?? []).map((document) => (
          <DocumentRow
            key={document.id}
            document={document}
            label={`${documentTypeLabel[document.type]} (المستند الجديد)`}
            load={() => api.serviceProviders.documentFile(provider.id, document.id)}
          />
        ))}
        {request.note && <p className="text-[#667085] whitespace-pre-line" dir="auto">ملاحظة المزود: {request.note}</p>}

        {approve.error !== null && <ApiErrorState compact error={approve.error} />}
        {rejecting ? (
          <div className="space-y-2">
            <label htmlFor={`reject-${request.id}`} className="font-bold text-[#172033] block">سبب الرفض (مطلوب):</label>
            <textarea
              id={`reject-${request.id}`}
              rows={3}
              value={reason}
              maxLength={2000}
              onChange={(e) => setReason(e.target.value)}
              className="w-full p-2.5 rounded-xl border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF]"
            />
            <FieldError messages={fieldMessages(reject.error, 'reason')} />
            {reject.error !== null && fieldMessages(reject.error, 'reason').length === 0 && <ApiErrorState compact error={reject.error} />}
            <div className="flex gap-2">
              <Button variant="danger" size="sm" icon={XCircle} onClick={handleReject} isLoading={reject.pending} disabled={reason.trim() === ''}>تأكيد الرفض</Button>
              <Button variant="ghost" size="sm" onClick={() => setRejecting(false)} disabled={reject.pending}>إلغاء</Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-wrap gap-2">
            <Button variant="success" size="sm" icon={CheckCircle2} onClick={handleApprove} isLoading={approve.pending}>الموافقة وتطبيق التعديل</Button>
            <Button variant="outline" size="sm" icon={XCircle} onClick={() => setRejecting(true)} disabled={approve.pending}>رفض الطلب</Button>
          </div>
        )}
      </div>
    </Card>
  );
};
