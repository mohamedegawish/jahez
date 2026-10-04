import React, { useState } from 'react';
import { api, fieldMessages } from '../../api';
import type { Role, User } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { fieldErrorClass } from '../../lib/forms';
import { roleLabel } from '../../lib/labels';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { FieldError } from '../ui/FieldError';
import { Modal } from '../ui/Modal';

const ROLES: Role[] = ['factory_member', 'provider_member', 'imc_admin'];
const PICKER_LIMIT = 100;

/**
 * Invite an account (POST /users). The API emails an invitation link to set the first password; the role
 * and organization are chosen here once and can never be changed afterwards.
 */
export const UserCreateModal: React.FC<{ onClose: () => void; onCreated: (user: User) => void }> = ({ onClose, onCreated }) => {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<Role>('factory_member');
  const [orgId, setOrgId] = useState('');

  const factories = useApiQuery((signal) => api.factories.list({ per_page: PICKER_LIMIT, signal }), [], { enabled: role === 'factory_member' });
  const providers = useApiQuery((signal) => api.serviceProviders.list({ per_page: PICKER_LIMIT, signal }), [], { enabled: role === 'provider_member' });

  const create = useApiMutation(() =>
    api.users.create({
      name: name.trim(),
      email: email.trim(),
      role,
      ...(role === 'factory_member' && orgId ? { factory_id: Number(orgId) } : {}),
      ...(role === 'provider_member' && orgId ? { service_provider_id: Number(orgId) } : {}),
    }),
  );

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await create.run();
    if (result.ok) onCreated(result.data);
  };

  const orgList = role === 'factory_member' ? factories : role === 'provider_member' ? providers : null;
  const options = (orgList?.data?.data ?? []).map((o) => ({ id: o.id, name: o.name }));
  const nameErrors = fieldMessages(create.error, 'name');
  const emailErrors = fieldMessages(create.error, 'email');
  const roleErrors = fieldMessages(create.error, 'role');
  const orgErrors = [...fieldMessages(create.error, 'factory_id'), ...fieldMessages(create.error, 'service_provider_id')];
  const hasFieldErrors = nameErrors.length + emailErrors.length + roleErrors.length + orgErrors.length > 0;
  const needsOrg = role !== 'imc_admin';

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="دعوة مستخدم جديد"
      subtitle="يصل المستخدم رابط بالبريد الإلكتروني لتعيين كلمة مروره"
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={create.pending}>
            إلغاء
          </Button>
          <Button type="submit" form="user-form" variant="primary" size="sm" isLoading={create.pending} disabled={name.trim() === '' || email.trim() === '' || (needsOrg && orgId === '')}>
            إرسال الدعوة
          </Button>
        </div>
      }
    >
      <form id="user-form" onSubmit={handleSubmit} className="space-y-4 text-xs" noValidate>
        {create.error !== null && !hasFieldErrors && <ApiErrorState compact error={create.error} />}

        <div>
          <label htmlFor="user-name" className="font-bold text-[#172033] block mb-1">الاسم الكامل:</label>
          <input id="user-name" type="text" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(nameErrors.length > 0)}`} />
          <FieldError messages={nameErrors} />
        </div>
        <div>
          <label htmlFor="user-email" className="font-bold text-[#172033] block mb-1">البريد الإلكتروني:</label>
          <input id="user-email" type="email" maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(emailErrors.length > 0)}`} dir="ltr" />
          <FieldError messages={emailErrors} />
        </div>
        <div>
          <label htmlFor="user-role" className="font-bold text-[#172033] block mb-1">الدور:</label>
          <select
            id="user-role"
            value={role}
            onChange={(e) => {
              setRole(e.target.value as Role);
              setOrgId('');
            }}
            className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white ${fieldErrorClass(roleErrors.length > 0)}`}
          >
            {ROLES.map((r) => (
              <option key={r} value={r}>
                {roleLabel(r)}
              </option>
            ))}
          </select>
          <p className="text-[11px] text-[#98A2B3] mt-1">لا يمكن تغيير الدور أو الجهة بعد إنشاء الحساب.</p>
          <FieldError messages={roleErrors} />
        </div>

        {needsOrg && (
          <div>
            <label htmlFor="user-org" className="font-bold text-[#172033] block mb-1">{role === 'factory_member' ? 'المنشأة:' : 'مزود الخدمة:'}</label>
            <select id="user-org" value={orgId} onChange={(e) => setOrgId(e.target.value)} disabled={orgList?.status !== 'success'} className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white ${fieldErrorClass(orgErrors.length > 0)}`}>
              <option value="">{orgList?.status === 'loading' ? 'جارٍ التحميل...' : 'اختر...'}</option>
              {options.map((o) => (
                <option key={o.id} value={o.id}>
                  #{o.id} — {o.name}
                </option>
              ))}
            </select>
            {orgList?.status === 'error' && <ApiErrorState compact error={orgList.error} onRetry={orgList.refetch} />}
            {orgList?.data && orgList.data.meta.total > PICKER_LIMIT && <p className="text-[11px] text-[#98A2B3] mt-1">يُعرض أول {PICKER_LIMIT} من {orgList.data.meta.total}.</p>}
            <FieldError messages={orgErrors} />
          </div>
        )}
      </form>
    </Modal>
  );
};

/** Rename an account (the only profile field IMC can change; role and organization are fixed). */
export const UserRenameModal: React.FC<{ user: User; onClose: () => void; onSaved: (user: User) => void }> = ({ user, onClose, onSaved }) => {
  const [name, setName] = useState(user.name);
  const save = useApiMutation(() => api.users.update(user.id, { name: name.trim() }));
  const nameErrors = fieldMessages(save.error, 'name');

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await save.run();
    if (result.ok) onSaved(result.data);
  };

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="تعديل اسم المستخدم"
      subtitle={user.email}
      footer={
        <div className="flex items-center justify-end gap-2 w-full">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={save.pending}>
            إلغاء
          </Button>
          <Button type="submit" form="rename-form" variant="primary" size="sm" isLoading={save.pending} disabled={name.trim() === ''}>
            حفظ
          </Button>
        </div>
      }
    >
      <form id="rename-form" onSubmit={handleSubmit} className="space-y-3 text-xs" noValidate>
        {save.error !== null && nameErrors.length === 0 && <ApiErrorState compact error={save.error} />}
        <label htmlFor="rename-name" className="font-bold text-[#172033] block">الاسم:</label>
        <input id="rename-name" type="text" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} className={`w-full p-2.5 rounded-xl border border-[#E6EAF0] focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(nameErrors.length > 0)}`} />
        <FieldError messages={nameErrors} />
      </form>
    </Modal>
  );
};
