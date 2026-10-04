import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { ServiceProvider } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { EMPTY_PROVIDER_FORM, toProviderPayload, valuesFromProvider, type ProviderFormValues } from '../../lib/provider';
import { ProviderFields } from '../provider/ProviderFields';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';
import { SectorPicker } from '../ui/SectorPicker';
import { ServicePicker } from '../ui/ServicePicker';

interface ProviderFormModalProps {
  /** Present: edit this provider (PATCH). Absent: create one (POST); a new provider starts `pending`. */
  provider?: ServiceProvider | null;
  onClose: () => void;
  onSaved: (provider: ServiceProvider) => void;
}

const PROFILE_FIELDS = Object.keys(EMPTY_PROVIDER_FORM);

/** IMC administrators create providers and edit any profile; approval is decided separately. */
export const ProviderFormModal: React.FC<ProviderFormModalProps> = ({ provider, onClose, onSaved }) => {
  const [values, setValues] = useState<ProviderFormValues>(() => (provider ? valuesFromProvider(provider) : EMPTY_PROVIDER_FORM));
  const [sectorCodes, setSectorCodes] = useState<string[]>(provider?.sectors?.map((s) => s.code) ?? []);
  const [serviceCodes, setServiceCodes] = useState<string[]>(provider?.services?.map((s) => s.code) ?? []);

  const save = useApiMutation(() => {
    const payload = { ...toProviderPayload(values), sectors: sectorCodes, services: serviceCodes };
    return provider ? api.serviceProviders.update(provider.id, payload) : api.serviceProviders.create(payload);
  });

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await save.run();
    if (result.ok) onSaved(result.data);
  };

  const sectorErrors = fieldMessages(save.error, 'sectors');
  const serviceErrors = fieldMessages(save.error, 'services');
  const hasFieldErrors = sectorErrors.length + serviceErrors.length > 0 || PROFILE_FIELDS.some((f) => fieldMessages(save.error, f).length > 0);

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={provider ? 'تعديل بيانات مزود الخدمة' : 'إضافة مزود خدمة'}
      subtitle={provider ? 'لا يغيّر التعديل حالة الاعتماد' : 'يبدأ المزود الجديد بحالة «قيد المراجعة»'}
      maxWidth="2xl"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={save.pending}>
            إلغاء
          </Button>
          <Button type="submit" form="provider-form" variant="primary" size="sm" isLoading={save.pending} disabled={values.name.trim() === ''}>
            {provider ? 'حفظ التعديلات' : 'إضافة المزود'}
          </Button>
        </div>
      }
    >
      <form id="provider-form" onSubmit={handleSubmit} className="space-y-5 text-xs" noValidate>
        {save.error !== null && !hasFieldErrors && <ApiErrorState compact error={save.error} />}

        <ProviderFields
          values={values}
          onChange={(changes) => setValues((prev) => ({ ...prev, ...changes }))}
          error={save.error}
          disabled={save.pending}
          idPrefix="provider-form"
        />

        <div>
          <span className="font-bold text-[#172033] block mb-1.5">القطاعات الصناعية المستهدفة:</span>
          <SectorPicker value={sectorCodes} onChange={setSectorCodes} disabled={save.pending} />
          <FieldError messages={sectorErrors} />
        </div>

        <div>
          <span className="font-bold text-[#172033] block mb-1.5">الخدمات المقدمة من الكتالوج:</span>
          <ServicePicker value={serviceCodes} onChange={setServiceCodes} disabled={save.pending} />
          <FieldError messages={serviceErrors} />
        </div>
      </form>
    </Modal>
  );
};
