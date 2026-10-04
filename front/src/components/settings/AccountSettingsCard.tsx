import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle2, KeyRound, Save } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { roleLabel } from '../../lib/labels';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { TextField } from '../ui/TextField';

/**
 * The signed-in account's own settings: display name and whether notifications are also emailed. The
 * email address, role and organization are fixed by the server; the password changes only through the
 * emailed reset link (no password is ever shown or sent from here).
 */
export const AccountSettingsCard: React.FC = () => {
  const { user, refresh } = useAuth();
  const navigate = useNavigate();
  const [name, setName] = useState(user?.name ?? '');
  const [emails, setEmails] = useState(user?.email_notifications ?? true);
  const [saved, setSaved] = useState(false);
  const save = useApiMutation(() => api.account.update({ name: name.trim(), email_notifications: emails }));

  if (!user) return null;
  const changed = name.trim() !== user.name || emails !== user.email_notifications;

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <Card title="الحساب" subtitle="بيانات دخولكم كما يحددها الخادم" accent="blue">
        <div className="space-y-3 text-xs">
          <TextField label="الاسم الظاهر" value={name} onChange={(value) => { setName(value); setSaved(false); }} messages={fieldMessages(save.error, 'name')} maxLength={255} disabled={save.pending} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className="p-2.5 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
              <span className="text-[10px] text-[#98A2B3] block">البريد الإلكتروني (للدخول)</span>
              <span className="font-bold text-[#172033]" dir="ltr">{user.email}</span>
            </div>
            <div className="p-2.5 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
              <span className="text-[10px] text-[#98A2B3] block">الدور والجهة</span>
              <span className="font-bold text-[#172033]">{roleLabel(user.role)} · {user.organization?.name ?? '—'}</span>
            </div>
          </div>
          <p className="text-[11px] text-[#98A2B3]">لا يُغيَّر البريد الإلكتروني أو الدور من هنا. لتغيير كلمة المرور استخدموا رابط إعادة التعيين المرسل إلى بريدكم.</p>
          <Button variant="outline" size="sm" icon={KeyRound} onClick={() => navigate('/forgot-password')}>
            طلب رابط تغيير كلمة المرور
          </Button>
        </div>
      </Card>

      <Card title="الإشعارات" subtitle="تُحفظ كل الإشعارات داخل المنصة دائمًا" accent="purple">
        <div className="space-y-4 text-xs">
          <label className="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" className="mt-0.5 accent-[#5146A5]" checked={emails} onChange={(e) => { setEmails(e.target.checked); setSaved(false); }} disabled={save.pending} />
            <span>
              <span className="font-bold text-[#172033] block">إرسال نسخة من الإشعارات إلى بريدي الإلكتروني</span>
              <span className="text-[#667085]">الطلبات الجديدة والردود والرسائل والعروض وقرارات المركز والفواتير. لا تتضمن الرسائل البريدية نص المفاوضات أو الأسعار أو المستندات.</span>
            </span>
          </label>
          {saved && (
            <div role="status" className="p-2.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] font-semibold text-[#1D7E4C] flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4" /> تم حفظ إعدادات الحساب.
            </div>
          )}
          {save.error !== null && fieldMessages(save.error, 'name').length === 0 && <ApiErrorState compact error={save.error} />}
          <Button
            variant="primary"
            size="sm"
            icon={Save}
            disabled={!changed || name.trim() === ''}
            isLoading={save.pending}
            onClick={async () => {
              const result = await save.run();
              if (result.ok) {
                setSaved(true);
                void refresh();
              }
            }}
          >
            حفظ إعدادات الحساب
          </Button>
        </div>
      </Card>
    </div>
  );
};
