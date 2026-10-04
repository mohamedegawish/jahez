import React, { useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { LayoutDashboard, LogIn, Menu, X } from 'lucide-react';
import logoUrl from '../../../logo.jpeg';
import { portalFor, useAuth } from '../../auth/authContext';

const LINKS = [
  { label: 'الصفحة الرئيسية', to: '/', end: true },
  { label: 'تسجيل منشأة صناعية', to: '/register/factory', end: false },
  { label: 'تسجيل مزود خدمة', to: '/register/provider', end: false },
];

/**
 * Header of the public marketing pages. It never shows the portal navigation: a signed-in
 * visitor gets one button to their own portal instead.
 */
export const PublicHeader: React.FC = () => {
  const { user, status } = useAuth();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `px-3 py-2 rounded-xl text-xs font-bold transition-colors ${
      isActive ? 'bg-[#EEEAFE] text-[#5146A5]' : 'text-[#667085] hover:bg-[#F7F9FC] hover:text-[#172033]'
    }`;

  const action =
    status === 'loading' ? (
      <span className="w-28 h-9 rounded-xl bg-[#E6EAF0] animate-pulse" aria-hidden="true" />
    ) : user ? (
      <button
        type="button"
        onClick={() => navigate(portalFor(user.role))}
        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#5146A5] hover:bg-[#43388E] text-white text-xs font-bold shadow-sm transition-colors cursor-pointer"
      >
        <LayoutDashboard className="w-4 h-4" />
        الذهاب إلى بوابتي
      </button>
    ) : (
      <button
        type="button"
        onClick={() => navigate('/login')}
        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#5146A5] hover:bg-[#43388E] text-white text-xs font-bold shadow-sm transition-colors cursor-pointer"
      >
        <LogIn className="w-4 h-4" />
        تسجيل الدخول
      </button>
    );

  return (
    <header className="sticky top-0 z-30 bg-white/90 backdrop-blur-md border-b border-[#E6EAF0]">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
        <NavLink to="/" className="flex items-center gap-2 min-w-0" aria-label="جاهز - الصفحة الرئيسية">
          <img src={logoUrl} alt="JAHEZ" className="h-9 w-auto object-contain" />
          <div className="leading-none">
            <div className="text-lg font-black tracking-tight text-transparent bg-clip-text bg-gradient-to-l from-[#5146A5] to-[#6EC8FF]">جاهز</div>
            <div className="mt-1 text-[9px] font-extrabold tracking-[0.22em] text-[#667085]">JAHEZ</div>
          </div>
        </NavLink>

        <nav className="hidden md:flex items-center gap-1" aria-label="التنقل الرئيسي">
          {!user && LINKS.map((link) => (
            <NavLink key={link.to} to={link.to} end={link.end} className={linkClass}>
              {link.label}
            </NavLink>
          ))}
        </nav>

        <div className="hidden md:block">{action}</div>

        <button
          type="button"
          onClick={() => setOpen((value) => !value)}
          className="md:hidden p-2 rounded-xl text-[#667085] hover:bg-[#F1F4F9] cursor-pointer"
          aria-label={open ? 'إغلاق القائمة' : 'فتح القائمة'}
          aria-expanded={open}
        >
          {open ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
        </button>
      </div>

      {open && (
        <div className="md:hidden border-t border-[#E6EAF0] bg-white px-4 py-3 space-y-1">
          {!user && LINKS.map((link) => (
            <NavLink key={link.to} to={link.to} end={link.end} className={({ isActive }) => `block ${linkClass({ isActive })}`} onClick={() => setOpen(false)}>
              {link.label}
            </NavLink>
          ))}
          <div className="pt-2">{action}</div>
        </div>
      )}
    </header>
  );
};
