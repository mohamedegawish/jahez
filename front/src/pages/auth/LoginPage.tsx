import React, { useState } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { ArrowLeft, Lock, Mail, Home } from 'lucide-react';
import { fieldMessages, isApiError } from '../../api';
import { postLoginPath, useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { FieldError } from '../../components/ui/FieldError';
import { fieldErrorClass } from '../../lib/forms';
import logoUrl from '../../../logo.jpeg';

export const LoginPage: React.FC = () => {
  const { status, user, login, sessionExpired } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from;

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  // Session storage ends with the tab; local storage keeps the 8-hour token across restarts.
  const [remember, setRemember] = useState(false);

  const submit = useApiMutation((e: string, p: string, r: boolean) => login(e, p, r));

  // Someone who is already signed in has nothing to do here.
  if (status === 'authenticated' && user) return <Navigate to={postLoginPath(user.role, from)} replace />;

  const handleLogin = async (event: React.FormEvent) => {
    event.preventDefault();
    const result = await submit.run(email.trim(), password, remember);
    if (result.ok) navigate(postLoginPath(result.data.role, from), { replace: true });
    else setPassword('');
  };

  // The API answers every credential failure (unknown email, wrong password, deactivated account)
  // with the same 422 on `email`, on purpose: it must not reveal which accounts exist.
  const emailErrors = fieldMessages(submit.error, 'email');
  const isCredentialFailure = isApiError(submit.error) && submit.error.status === 422 && emailErrors.length > 0;
  const passwordErrors = fieldMessages(submit.error, 'password');
  const showGeneric = submit.error !== null && !isCredentialFailure && passwordErrors.length === 0;

  return (
    <div
      dir="rtl"
      className="relative min-h-screen bg-[#FAF3EB] flex items-center justify-center p-4 sm:p-6 overflow-hidden text-right"
    >
      <div className="absolute -top-24 right-[12%] w-96 h-96 bg-[#6EC8FF]/30 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute -bottom-32 left-[8%] w-[28rem] h-[28rem] bg-[#B8E2F4]/60 rounded-full blur-3xl pointer-events-none" />

      <button
        type="button"
        onClick={() => navigate('/')}
        className="absolute top-5 right-5 z-20 inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white/85 backdrop-blur-md border border-[#E6EAF0] text-xs font-bold text-[#0A6EB0] shadow-xs hover:bg-white transition-colors cursor-pointer"
      >
        <Home className="w-4 h-4" />
        الصفحة الرئيسية
      </button>

      <div className="relative w-full max-w-md rounded-[28px] border border-white/80 shadow-[0_30px_70px_-20px_rgba(23,32,51,0.28)] bg-[linear-gradient(140deg,#FFFDF9_0%,#F3F0FF_0%,#EAF6FC_45%,#B8E2F4_100%)] overflow-hidden">
        <div className="absolute -top-10 -right-10 w-44 h-44 bg-[#6EC8FF]/35 rounded-full blur-3xl pointer-events-none" />
        <div className="absolute -bottom-12 -left-10 w-48 h-48 bg-white/70 rounded-full blur-3xl pointer-events-none" />

        <div className="relative z-10 m-2 rounded-[22px] bg-white/75 backdrop-blur-xl border border-white/80 p-6 sm:p-7 shadow-lg space-y-5">
          <div className="text-center space-y-1.5">
            <img src={logoUrl} alt="JAHEZ" className="h-11 mx-auto object-contain" />
            <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/90 border border-[#BDE5FD] text-[10px] font-bold text-[#0A6EB0]">
              المنظومة الوطنية الذكية للثورة الصناعية الرابعة | Industry 4.0
            </div>
            <h2 className="text-xl font-bold text-[#172033] tracking-tight pt-1">تسجيل الدخول إلى المنظومة</h2>
            <p className="text-xs text-[#667085]">
              أدخل بيانات حسابك المعتمد. تُحدَّد بوابتك وصلاحياتك تلقائيًا من بيانات الحساب لدى الخادم.
            </p>
          </div>

          {sessionExpired && !submit.error && (
            <div role="status" className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs font-semibold text-[#A66F0B]">
              انتهت جلستك. الرجاء تسجيل الدخول مرة أخرى.
            </div>
          )}

          {showGeneric && <ApiErrorState compact error={submit.error} />}

          <form onSubmit={handleLogin} className="space-y-4 text-xs" noValidate>
            <div>
              <label htmlFor="login-email" className="font-bold text-[#172033] block mb-1">
                البريد الإلكتروني المعتمد:
              </label>
              <div className="relative">
                <input
                  id="login-email"
                  type="email"
                  autoComplete="username"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  dir="ltr"
                  className={`w-full pr-3 pl-9 py-2.5 bg-white/95 border border-[#E6EAF0] rounded-xl text-xs text-[#172033] text-left focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(isCredentialFailure)}`}
                />
                <Mail className="w-4 h-4 text-[#98A2B3] absolute left-3 top-3 pointer-events-none" />
              </div>
              {isCredentialFailure ? (
                <div role="alert" className="mt-1 text-[11px] font-medium text-[#B82B3B]">
                  البريد الإلكتروني أو كلمة المرور غير صحيحة، أو أن الحساب غير مفعّل.
                  <span className="block opacity-70" dir="ltr">{emailErrors[0]}</span>
                </div>
              ) : (
                <FieldError messages={emailErrors} />
              )}
            </div>

            <div>
              <div className="flex items-center justify-between mb-1">
                <label htmlFor="login-password" className="font-bold text-[#172033]">
                  كلمة المرور:
                </label>
                <Link to="/forgot-password" className="text-[11px] text-[#0A6EB0] hover:underline">
                  نسيت كلمة المرور؟
                </Link>
              </div>
              <div className="relative">
                <input
                  id="login-password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  dir="ltr"
                  className={`w-full pr-3 pl-9 py-2.5 bg-white/95 border border-[#E6EAF0] rounded-xl text-xs text-[#172033] text-left focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(passwordErrors.length > 0)}`}
                />
                <Lock className="w-4 h-4 text-[#98A2B3] absolute left-3 top-3 pointer-events-none" />
              </div>
              <FieldError messages={passwordErrors} />
            </div>

            <div className="flex items-center justify-between text-xs">
              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={remember}
                  onChange={(e) => setRemember(e.target.checked)}
                  className="rounded accent-[#0A6EB0]"
                />
                <span className="text-[#667085]">تذكر الجلسة على هذا الجهاز</span>
              </label>
            </div>

            <button
              type="submit"
              disabled={submit.pending || email.trim() === '' || password === ''}
              className="w-full inline-flex items-center justify-center gap-2.5 px-5 py-3 rounded-xl font-bold text-white bg-[linear-gradient(90deg,#0A6EB0_0%,#6EC8FF_100%)] shadow-md shadow-[#0A6EB0]/25 hover:shadow-lg hover:shadow-[#0A6EB0]/30 transition-all active:scale-[0.99] cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
            >
              {submit.pending ? 'جارٍ التحقق...' : 'الدخول إلى المنظومة'}
              {!submit.pending && <ArrowLeft className="w-4 h-4" />}
            </button>
          </form>

          <div className="pt-4 border-t border-[#E6EAF0]/80 text-center text-xs text-[#667085] space-y-2">
            <div>
              منشأة صناعية جديدة ترغب في الانضمام؟{' '}
              <Link to="/register/factory" className="text-[#0A6EB0] font-bold hover:underline">
                تقديم طلب تسجيل منشأة
              </Link>
            </div>
            <div>
              شركة تريد عرض حلولها على المصانع؟{' '}
              <Link to="/register/provider" className="text-[#0A6EB0] font-bold hover:underline">
                انضم كمزود خدمة
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
