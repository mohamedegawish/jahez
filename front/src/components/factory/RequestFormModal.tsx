import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { CatalogService, ServiceRequest } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyFactory } from '../../hooks/useMyOrganization';
import { fieldErrorClass } from '../../lib/forms';
import { FactoryApprovalNotice } from './FactoryApprovalNotice';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { CardSkeleton } from '../ui/LoadingState';
import { Modal } from '../ui/Modal';
import { QueryBoundary } from '../ui/QueryBoundary';

/** The API accepts 1 to 20 providers per request (StoreServiceRequestRequest::MAX_PROVIDERS). */
const MAX_PROVIDERS = 20;

interface RequestFormModalProps {
  service: CatalogService;
  /** Providers ticked when the form opens (a listing the factory chose). */
  initialProviderIds?: number[];
  onClose: () => void;
  onCreated: (request: ServiceRequest) => void;
}

/**
 * A factory asks one or several eligible providers for a service. Nothing about the status is sent:
 * the API opens the request and one pending thread per provider.
 */
export const RequestFormModal: React.FC<RequestFormModalProps> = ({ service, initialProviderIds = [], onClose, onCreated }) => {
  const [title, setTitle] = useState('');
  const [need, setNeed] = useState('');
  const [requirements, setRequirements] = useState('');
  const [selected, setSelected] = useState<number[]>(initialProviderIds);
  // The API refuses requests from a factory IMC has not approved (409, ADR-021): say why up front.
  const factory = useMyFactory();
  const blocked = factory.data !== undefined && !factory.data.approval.may_send_requests;

  const providers = useApiQuery(
    (signal) => api.directory.list({ per_page: MAX_PROVIDERS, filter: { service: service.code }, sort: 'name', signal }),
    [service.code],
  );

  const create = useApiMutation(() =>
    api.serviceRequests.create({
      service: service.code,
      title: title.trim(),
      need: need.trim(),
      ...(requirements.trim() ? { requirements: requirements.trim() } : {}),
      provider_ids: selected,
    }),
  );

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await create.run();
    if (result.ok) onCreated(result.data);
  };

  const toggle = (id: number) => setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  const titleErrors = fieldMessages(create.error, 'title');
  const needErrors = fieldMessages(create.error, 'need');
  const reqErrors = fieldMessages(create.error, 'requirements');
  const providerErrors = fieldMessages(create.error, 'provider_ids');
  const hasFieldErrors = titleErrors.length + needErrors.length + reqErrors.length + providerErrors.length > 0 || fieldMessages(create.error, 'service').length > 0;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="طلب خدمة من مزودين مؤهلين"
      subtitle={service.name_ar}
      maxWidth="2xl"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={create.pending}>
            إلغاء
          </Button>
          <Button
            type="submit"
            form="request-form"
            variant="primary"
            size="sm"
            isLoading={create.pending}
            disabled={blocked || title.trim() === '' || need.trim() === '' || selected.length === 0}
          >
            إرسال الطلب ({selected.length})
          </Button>
        </div>
      }
    >
      <form id="request-form" onSubmit={handleSubmit} className="space-y-4 text-xs" noValidate>
        <p className="text-[#667085] leading-relaxed">
          يصل الطلب إلى المزودين الذين تختارهم، ويقرر كل مزود بشكل مستقل قبول التفاوض أو الاعتذار. لا يحمل الطلب سعرًا؛ يقدّم المزود سعره في عرضه.
        </p>
        {factory.data && <FactoryApprovalNotice factory={factory.data} compact />}
        {create.error !== null && !hasFieldErrors && <ApiErrorState compact error={create.error} />}

        <div>
          <label htmlFor="request-title" className="font-bold text-[#172033] block mb-1">عنوان الطلب:</label>
          <input
            id="request-title"
            type="text"
            value={title}
            maxLength={200}
            onChange={(e) => setTitle(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(titleErrors.length > 0)}`}
          />
          <FieldError messages={titleErrors} />
        </div>

        <div>
          <label htmlFor="request-need" className="font-bold text-[#172033] block mb-1">وصف الاحتياج:</label>
          <textarea
            id="request-need"
            rows={4}
            value={need}
            maxLength={5000}
            onChange={(e) => setNeed(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(needErrors.length > 0)}`}
          />
          <FieldError messages={needErrors} />
        </div>

        <div>
          <label htmlFor="request-requirements" className="font-bold text-[#172033] block mb-1">متطلبات إضافية (اختياري):</label>
          <textarea
            id="request-requirements"
            rows={3}
            value={requirements}
            maxLength={5000}
            onChange={(e) => setRequirements(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(reqErrors.length > 0)}`}
          />
          <FieldError messages={reqErrors} />
        </div>

        <div>
          <span className="font-bold text-[#172033] block mb-1.5">المزودون المرسل إليهم (1 إلى {MAX_PROVIDERS}):</span>
          <QueryBoundary
            query={providers}
            loading={<CardSkeleton />}
            isEmpty={(page) => page.data.length === 0}
            empty={<p className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-[#A66F0B]">لا يوجد مزود مؤهل لهذه الخدمة حاليًا، فلا يمكن إرسال الطلب.</p>}
          >
            {(page) => (
              <div className="space-y-2">
                {page.data.map((provider) => (
                  <label
                    key={provider.id}
                    className={`flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer transition-colors ${
                      selected.includes(provider.id) ? 'bg-[#EEEAFE]/50 border-[#9B8AFB]' : 'bg-white border-[#E6EAF0] hover:border-[#CCD5E2]'
                    }`}
                  >
                    <input
                      type="checkbox"
                      data-provider={provider.id}
                      checked={selected.includes(provider.id)}
                      onChange={() => toggle(provider.id)}
                      className="mt-0.5 accent-[#5146A5]"
                    />
                    <span className="flex-1">
                      <span className="font-bold text-[#172033] block">{provider.name}</span>
                      <span className="text-[11px] text-[#667085]">
                        الخبرة: {provider.dx_experience_years === null ? '—' : `${provider.dx_experience_years} سنة`}
                      </span>
                    </span>
                  </label>
                ))}
                {page.meta.total > page.data.length && (
                  <p className="text-[11px] text-[#98A2B3]">يُعرض أول {page.data.length} من {page.meta.total} مزودًا مؤهلًا.</p>
                )}
              </div>
            )}
          </QueryBoundary>
          <FieldError messages={providerErrors} />
        </div>
      </form>
    </Modal>
  );
};
