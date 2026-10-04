import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Home } from 'lucide-react';
import logoUrl from '../../../logo.jpeg';

interface AuthShellProps {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  /** A wider card for long forms (registration). */
  wide?: boolean;
}

/** The login page's visual shell (cream background, glass card), shared by the password pages. */
export const AuthShell: React.FC<AuthShellProps> = ({ title, subtitle, children, wide = false }) => {
  const navigate = useNavigate();
  return (
    <div dir="rtl" className="relative min-h-screen bg-[#FAF3EB] flex items-center justify-center p-4 sm:p-6 overflow-hidden text-right">
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

      <div className={`relative w-full ${wide ? 'max-w-3xl my-14' : 'max-w-md'} rounded-[28px] border border-white/80 shadow-[0_30px_70px_-20px_rgba(23,32,51,0.28)] bg-[linear-gradient(140deg,#FFFDF9_0%,#F3F0FF_0%,#EAF6FC_45%,#B8E2F4_100%)] overflow-hidden`}>
        <div className="relative z-10 m-2 rounded-[22px] bg-white/75 backdrop-blur-xl border border-white/80 p-6 sm:p-7 shadow-lg space-y-5">
          <div className="text-center space-y-1.5">
            <img src={logoUrl} alt="JAHEZ" className="h-11 mx-auto object-contain" />
            <h2 className="text-xl font-bold text-[#172033] tracking-tight pt-1">{title}</h2>
            {subtitle && <p className="text-xs text-[#667085] leading-relaxed">{subtitle}</p>}
          </div>
          {children}
        </div>
      </div>
    </div>
  );
};
