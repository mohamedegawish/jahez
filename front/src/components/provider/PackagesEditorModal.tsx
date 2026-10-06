import React, { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import type { ServiceListingPackage, ServiceProvider } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { fieldErrorClass } from '../../lib/forms';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';

/** PUT …/packages accepts at most this many packages (ServiceListingPackage::MAX_PER_LISTING). */
const MAX_PACKAGES = 10;

interface Row {
  key: number;
  name_ar: string;
  monthly_price: string;
  annual_price: string;
  users_count: string;
}

const inputClass = 'w-full p-2 rounded-lg border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF]';

let nextKey = 1;
const toRow = (pkg?: ServiceListingPackage): Row => ({
  key: nextKey++,
  name_ar: pkg?.name_ar ?? '',
  monthly_price: pkg?.monthly_price ?? '',
  annual_price: pkg?.annual_price ?? '',
  users_count: pkg?.users_count != null ? String(pkg.users_count) : '',
});

interface PackagesEditorModalProps {
  providerId: number;
  service: { id: number; name_ar: string };
  packages: ServiceListingPackage[];
  onClose: () => void;
  onSaved: (provider: ServiceProvider) => void;
}

/**
 * The provider's packages for one listing (jahez_api ADR-027): a name and, each optional, a monthly price,
 * an annual price and the number of users the price covers, with at least one price. Saving replaces the
 * whole list and sends the listing back to IMC review: factories do not see it until IMC approves it again.
 */
export const PackagesEditorModal: React.FC<PackagesEditorModalProps> = ({ providerId, service, packages, onClose, onSaved }) => {
  // Every row is sent as it stands, so the server's `packages.{index}` errors match the rows shown.
  const [rows, setRows] = useState<Row[]>(() => packages.map(toRow));
  const save = useApiMutation(() =>
    api.serviceProviders.updateListingPackages(
      providerId,
      service.id,
      rows.map((row) => ({
          name_ar: row.name_ar.trim(),
          monthly_price: row.monthly_price.trim() === '' ? null : row.monthly_price.trim(),
          annual_price: row.annual_price.trim() === '' ? null : row.annual_price.trim(),
          users_count: row.users_count.trim() === '' ? null : Number(row.users_count),
      })),
    ),
  );

  const update = (key: number, field: keyof Omit<Row, 'key'>, value: string) =>
    setRows((current) => current.map((row) => (row.key === key ? { ...row, [field]: value } : row)));

  const errors = (index: number, field: string) => fieldMessages(save.error, `packages.${index}.${field}`);
  const listErrors = [...fieldMessages(save.error, 'packages'), ...rows.flatMap((_, index) => fieldMessages(save.error, `packages.${index}`))];

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="الباقات والأسعار"
      subtitle={service.name_ar}
      maxWidth="2xl"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={save.pending}>
            إلغاء
          </Button>
          <Button
            variant="primary"
            size="sm"
            isLoading={save.pending}
            data-action="save-packages"
            onClick={async () => {
              const result = await save.run();
              if (result.ok) onSaved(result.data);
            }}
          >
            حفظ وإرسال للمراجعة
          </Button>
        </div>
      }
    >
      <div className="space-y-4 text-xs">
        <p className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-[#A66F0B] leading-relaxed">
          تعديل الباقات أو الأسعار يعيد الخدمة إلى مراجعة مركز تحديث الصناعة، ولا تظهر للمصانع حتى تُعتمد من جديد. الأسعار بالجنيه المصري وللاسترشاد؛ يظل
          السعر النهائي في عرضكم على كل طلب، ولا يتم أي دفع عبر المنصة.
        </p>
        <p className="text-[#667085]">
          لكل باقة اسم، وسعر شهري أو سنوي أو كلاهما، وعدد المستخدمين إن كان السعر مرتبطًا به (مثل أنظمة ERP). اتركوا الخانة فارغة إن لم تنطبق على الخدمة.
        </p>
        {save.error !== null && <ApiErrorState compact error={save.error} />}
        <FieldError messages={listErrors} />

        <div className="space-y-3">
          {rows.map((row, index) => (
            <div key={row.key} className="p-3 rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] space-y-2" data-package-row={index}>
              <div className="flex items-center justify-between gap-2">
                <span className="font-bold text-[#172033]">باقة {index + 1}</span>
                <Button variant="ghost" size="sm" icon={Trash2} onClick={() => setRows((current) => current.filter((r) => r.key !== row.key))} aria-label={`حذف الباقة ${index + 1}`}>
                  حذف
                </Button>
              </div>
              <div>
                <label className="block font-semibold text-[#344054] mb-1" htmlFor={`pkg-name-${row.key}`}>اسم الباقة</label>
                <input id={`pkg-name-${row.key}`} className={`${inputClass} ${fieldErrorClass(errors(index, 'name_ar').length > 0)}`} value={row.name_ar} maxLength={120} onChange={(e) => update(row.key, 'name_ar', e.target.value)} />
                <FieldError messages={errors(index, 'name_ar')} />
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <div>
                  <label className="block font-semibold text-[#344054] mb-1" htmlFor={`pkg-monthly-${row.key}`}>السعر الشهري (ج.م)</label>
                  <input id={`pkg-monthly-${row.key}`} dir="ltr" inputMode="decimal" className={`${inputClass} ${fieldErrorClass(errors(index, 'monthly_price').length > 0)}`} value={row.monthly_price} onChange={(e) => update(row.key, 'monthly_price', e.target.value)} />
                  <FieldError messages={errors(index, 'monthly_price')} />
                </div>
                <div>
                  <label className="block font-semibold text-[#344054] mb-1" htmlFor={`pkg-annual-${row.key}`}>السعر السنوي (ج.م)</label>
                  <input id={`pkg-annual-${row.key}`} dir="ltr" inputMode="decimal" className={`${inputClass} ${fieldErrorClass(errors(index, 'annual_price').length > 0)}`} value={row.annual_price} onChange={(e) => update(row.key, 'annual_price', e.target.value)} />
                  <FieldError messages={errors(index, 'annual_price')} />
                </div>
                <div>
                  <label className="block font-semibold text-[#344054] mb-1" htmlFor={`pkg-users-${row.key}`}>عدد المستخدمين (اختياري)</label>
                  <input id={`pkg-users-${row.key}`} dir="ltr" type="number" min={1} className={`${inputClass} ${fieldErrorClass(errors(index, 'users_count').length > 0)}`} value={row.users_count} onChange={(e) => update(row.key, 'users_count', e.target.value)} />
                  <FieldError messages={errors(index, 'users_count')} />
                </div>
              </div>
            </div>
          ))}
        </div>

        {rows.length < MAX_PACKAGES && (
          <Button variant="outline" size="sm" icon={Plus} onClick={() => setRows((current) => [...current, toRow()])}>
            إضافة باقة
          </Button>
        )}
        {rows.length === 0 && <p className="text-[#667085]">بدون باقات، يرى المصنع أن السعر يُحدد في عرضكم.</p>}
      </div>
    </Modal>
  );
};
