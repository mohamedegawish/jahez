import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { ServiceRequest } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { CardSkeleton } from '../ui/LoadingState';
import { Modal } from '../ui/Modal';
import { QueryBoundary } from '../ui/QueryBoundary';

const MAX_PROVIDERS = 20;

/** Add eligible providers to an open request. Providers already on it are not offered again. */
export const AddProvidersModal: React.FC<{
  request: ServiceRequest;
  onClose: () => void;
  onAdded: (request: ServiceRequest) => void;
}> = ({ request, onClose, onAdded }) => {
  const [selected, setSelected] = useState<number[]>([]);
  const already = new Set((request.provider_requests ?? []).map((t) => t.provider?.id));
  const serviceCode = request.service?.code;

  const providers = useApiQuery(
    (signal) => api.directory.list({ per_page: 100, filter: { service: serviceCode }, sort: 'name', signal }),
    [serviceCode],
    { enabled: Boolean(serviceCode) },
  );

  const add = useApiMutation(() => api.serviceRequests.addProviders(request.id, selected));
  const providerErrors = fieldMessages(add.error, 'provider_ids');
  const room = MAX_PROVIDERS - (request.provider_requests ?? []).length;

  const handleAdd = async () => {
    const result = await add.run();
    if (result.ok) onAdded(result.data);
  };

  const toggle = (id: number) => setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="إضافة مزودين إلى الطلب"
      subtitle={request.title}
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={add.pending}>
            إلغاء
          </Button>
          <Button variant="primary" size="sm" onClick={handleAdd} isLoading={add.pending} disabled={selected.length === 0}>
            إضافة ({selected.length})
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <p className="text-[#667085] leading-relaxed">يُضاف كل مزود تختاره كمحادثة جديدة بحالة «بانتظار رد المزود». يمكن إضافة {Math.max(room, 0)} مزودًا آخرين على الأكثر.</p>
        {add.error !== null && providerErrors.length === 0 && <ApiErrorState compact error={add.error} />}
        <QueryBoundary
          query={providers}
          loading={<CardSkeleton />}
          isEmpty={(page) => page.data.filter((p) => !already.has(p.id)).length === 0}
          empty={<p className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-[#667085]">لا يوجد مزودون مؤهلون آخرون لإضافتهم إلى هذا الطلب.</p>}
        >
          {(page) => (
            <div className="space-y-2">
              {page.data
                .filter((provider) => !already.has(provider.id))
                .map((provider) => (
                  <label
                    key={provider.id}
                    className={`flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer transition-colors ${
                      selected.includes(provider.id) ? 'bg-[#EEEAFE]/50 border-[#9B8AFB]' : 'bg-white border-[#E6EAF0] hover:border-[#CCD5E2]'
                    }`}
                  >
                    <input type="checkbox" data-provider={provider.id} checked={selected.includes(provider.id)} onChange={() => toggle(provider.id)} className="mt-0.5 accent-[#5146A5]" />
                    <span className="font-bold text-[#172033]">{provider.name}</span>
                  </label>
                ))}
            </div>
          )}
        </QueryBoundary>
        <FieldError messages={providerErrors} />
      </div>
    </Modal>
  );
};
