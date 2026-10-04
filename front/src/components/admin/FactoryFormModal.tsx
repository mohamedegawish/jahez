import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { Factory } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useFactorySizes } from '../../hooks/useReference';
import { fieldErrorClass } from '../../lib/forms';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { ApiErrorState } from '../ui/ApiErrorState';
import { FieldError } from '../ui/FieldError';
import { SectorPicker } from '../ui/SectorPicker';

interface FactoryFormModalProps {
  /** Present: edit this factory (PATCH). Absent: create one (POST). */
  factory?: Factory | null;
  onClose: () => void;
  onSaved: (factory: Factory) => void;
}

/** IMC administrators create factories and may also set their declared size. */
export const FactoryFormModal: React.FC<FactoryFormModalProps> = ({ factory, onClose, onSaved }) => {
  const sizes = useFactorySizes();
  const [name, setName] = useState(factory?.name ?? '');
  const [size, setSize] = useState(factory?.size ?? '');
  const [sectorCodes, setSectorCodes] = useState<string[]>(factory?.sectors?.map((s) => s.code) ?? []);

  const save = useApiMutation(() =>
    factory
      ? api.factories.update(factory.id, { name: name.trim(), size: size || null, sectors: sectorCodes })
      : api.factories.create({ name: name.trim(), ...(size ? { size } : {}), sectors: sectorCodes }),
  );

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await save.run();
    if (result.ok) onSaved(result.data);
  };

  const nameErrors = fieldMessages(save.error, 'name');
  const sizeErrors = fieldMessages(save.error, 'size');
  const sectorErrors = fieldMessages(save.error, 'sectors');
  const hasFieldErrors = nameErrors.length + sizeErrors.length + sectorErrors.length > 0;

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={factory ? 'تعديل بيانات المنشأة' : 'إضافة منشأة صناعية'}
      subtitle="تُسجَّل هذه العملية في سجل التدقيق"
      maxWidth="lg"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={save.pending}>
            إلغاء
          </Button>
          <Button type="submit" form="factory-form" variant="primary" size="sm" isLoading={save.pending} disabled={name.trim() === ''}>
            {factory ? 'حفظ التعديلات' : 'إضافة المنشأة'}
          </Button>
        </div>
      }
    >
      <form id="factory-form" onSubmit={handleSubmit} className="space-y-4 text-xs" noValidate>
        {save.error !== null && !hasFieldErrors && <ApiErrorState compact error={save.error} />}

        <div>
          <label htmlFor="factory-form-name" className="font-bold text-[#172033] block mb-1">اسم المنشأة:</label>
          <input
            id="factory-form-name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            maxLength={255}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(nameErrors.length > 0)}`}
          />
          <FieldError messages={nameErrors} />
        </div>

        <div>
          <label htmlFor="factory-form-size" className="font-bold text-[#172033] block mb-1">الحجم المعلن:</label>
          <select
            id="factory-form-size"
            value={size}
            onChange={(e) => setSize(e.target.value)}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(sizeErrors.length > 0)}`}
          >
            <option value="">غير محدد</option>
            {(sizes.data ?? []).map((item) => (
              <option key={item.code} value={item.code}>
                {item.name_ar}
              </option>
            ))}
          </select>
          <FieldError messages={sizeErrors} />
        </div>

        <div>
          <span className="font-bold text-[#172033] block mb-1.5">القطاعات الصناعية:</span>
          <SectorPicker value={sectorCodes} onChange={setSectorCodes} disabled={save.pending} />
          <FieldError messages={sectorErrors} />
        </div>
      </form>
    </Modal>
  );
};
