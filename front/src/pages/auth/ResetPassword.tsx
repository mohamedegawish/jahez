import React, { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Lock } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { FieldError } from '../../components/ui/FieldError';
import { fieldErrorClass } from '../../lib/forms';
import { AuthShell } from './AuthShell';

/**
 * Landing page of the reset and invitation links the API emails:
 *   {FRONTEND_URL}/reset-password?token=...&email=...
 * A new account's first password is set here too.
 */
export const ResetPassword: React.FC = () => {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [done, setDone] = useState(false);
  const submit = useApiMutation(() =>
    api.auth.resetPassword({ token, email, password, password_confirmation: confirmation }),
  );

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await submit.run();
    if (result.ok) setDone(true);
  };

  if (token === '' || email === '') {
    return (
      <AuthShell title="رابط غير صالح">
        <p className="text-xs text-[#667085] text-center leading-relaxed">
          هذا الرابط غير مكتمل. افتح الرابط كما وصلك في البريد الإلكتروني، أو اطلب رابطًا جديدًا.
        </p>
        <div className="text-center">
          <Link to="/forgot-password" className="text-xs font-bold text-[#0A6EB0] hover:underline">
            طلب رابط جديد
          </Link>
        </div>
      </AuthShell>
    );
  }

  if (done) {
    return (
      <AuthShell title="تم تعيين كلمة المرور">
        <div className="text-center space-y-4">
          <div className="w-14 h-14 mx-auto rounded-2xl bg-[#E7F8EE] text-[#35B779] flex items-center justify-center">
            <CheckCircle2 className="w-7 h-7" />
          </div>
          <p className="text-xs text-[#667085] leading-relaxed">يمكنك الآن تسجيل الدخول بكلمة المرور الجديدة.</p>
          <Link to="/login" className="inline-block text-xs font-bold text-[#0A6EB0] hover:underline">
            الذهاب إلى تسجيل الدخول
          </Link>
        </div>
      </AuthShell>
    );
  }

  const tokenErrors = fieldMessages(submit.error, 'token');
  const passwordErrors = fieldMessages(submit.error, 'password');
  const showGeneric = submit.error !== null && tokenErrors.length === 0 && passwordErrors.length === 0 && fieldMessages(submit.error, 'email').length === 0;

  return (
    <AuthShell title="تعيين كلمة مرور جديدة" subtitle={email}>
      {showGeneric && <ApiErrorState compact error={submit.error} />}
      {tokenErrors.length > 0 && (
        <div role="alert" className="p-3 rounded-xl bg-[#FDECEE] border border-[#F9C3C9] text-xs text-[#B82B3B] space-y-1">
          <div className="font-bold">انتهت صلاحية الرابط أو لم يعد صالحًا.</div>
          <div className="opacity-80" dir="auto">{tokenErrors[0]}</div>
          <Link to="/forgot-password" className="inline-block font-bold underline">
            اطلب رابطًا جديدًا
          </Link>
        </div>
      )}
      <form onSubmit={handleSubmit} className="space-y-4 text-xs" noValidate>
        <div>
          <label htmlFor="reset-password" className="font-bold text-[#172033] block mb-1">
            كلمة المرور الجديدة:
          </label>
          <div className="relative">
            <input
              id="reset-password"
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              dir="ltr"
              className={`w-full pr-3 pl-9 py-2.5 bg-white/95 border border-[#E6EAF0] rounded-xl text-xs text-[#172033] text-left focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(passwordErrors.length > 0)}`}
            />
            <Lock className="w-4 h-4 text-[#98A2B3] absolute left-3 top-3 pointer-events-none" />
          </div>
          <p className="mt-1 text-[11px] text-[#98A2B3]">12 حرفًا على الأقل.</p>
          <FieldError messages={passwordErrors} />
        </div>
        <div>
          <label htmlFor="reset-confirmation" className="font-bold text-[#172033] block mb-1">
            تأكيد كلمة المرور:
          </label>
          <input
            id="reset-confirmation"
            type="password"
            autoComplete="new-password"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
            required
            dir="ltr"
            className="w-full px-3 py-2.5 bg-white/95 border border-[#E6EAF0] rounded-xl text-xs text-[#172033] text-left focus:outline-none focus:border-[#6EC8FF]"
          />
        </div>
        <Button type="submit" variant="primary" size="lg" className="w-full" isLoading={submit.pending} disabled={password === '' || confirmation === ''}>
          حفظ كلمة المرور
        </Button>
      </form>
    </AuthShell>
  );
};
