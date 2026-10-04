import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, Mail } from 'lucide-react';
import { api, fieldMessages } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { FieldError } from '../../components/ui/FieldError';
import { fieldErrorClass } from '../../lib/forms';
import { AuthShell } from './AuthShell';

export const ForgotPassword: React.FC = () => {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const submit = useApiMutation((value: string) => api.auth.forgotPassword(value));

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await submit.run(email.trim());
    if (result.ok) setSent(true);
  };

  const emailErrors = fieldMessages(submit.error, 'email');
  const showGeneric = submit.error !== null && emailErrors.length === 0;

  if (sent) {
    return (
      <AuthShell title="تحقق من بريدك الإلكتروني">
        <div className="text-center space-y-4">
          <div className="w-14 h-14 mx-auto rounded-2xl bg-[#E7F8EE] text-[#35B779] flex items-center justify-center">
            <CheckCircle2 className="w-7 h-7" />
          </div>
          {/* The API answers identically for every address, so this never confirms an account exists. */}
          <p className="text-xs text-[#667085] leading-relaxed">
            إذا كان هذا البريد مسجّلًا لدينا فسيصله رابط لإعادة تعيين كلمة المرور خلال دقائق. الرابط صالح لمدة 60 دقيقة.
          </p>
          <Link to="/login" className="inline-block text-xs font-bold text-[#0A6EB0] hover:underline">
            العودة إلى تسجيل الدخول
          </Link>
        </div>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="استعادة كلمة المرور" subtitle="أدخل بريدك الإلكتروني وسنرسل إليك رابطًا لإعادة تعيين كلمة المرور.">
      {showGeneric && <ApiErrorState compact error={submit.error} />}
      <form onSubmit={handleSubmit} className="space-y-4 text-xs" noValidate>
        <div>
          <label htmlFor="forgot-email" className="font-bold text-[#172033] block mb-1">
            البريد الإلكتروني:
          </label>
          <div className="relative">
            <input
              id="forgot-email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              dir="ltr"
              className={`w-full pr-3 pl-9 py-2.5 bg-white/95 border border-[#E6EAF0] rounded-xl text-xs text-[#172033] text-left focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(emailErrors.length > 0)}`}
            />
            <Mail className="w-4 h-4 text-[#98A2B3] absolute left-3 top-3 pointer-events-none" />
          </div>
          <FieldError messages={emailErrors} />
        </div>
        <Button type="submit" variant="primary" size="lg" className="w-full" isLoading={submit.pending} disabled={email.trim() === ''}>
          إرسال رابط الاستعادة
        </Button>
        <div className="text-center">
          <Link to="/login" className="text-[11px] text-[#0A6EB0] hover:underline">
            العودة إلى تسجيل الدخول
          </Link>
        </div>
      </form>
    </AuthShell>
  );
};
