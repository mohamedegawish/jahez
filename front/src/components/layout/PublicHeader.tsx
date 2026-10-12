import React, { useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { LayoutDashboard, LogIn, Menu, X } from 'lucide-react';
import logoUrl from '../../../logo.jpeg';
import { publicApiUrl } from '../../api';
import { portalFor, useAuth } from '../../auth/authContext';
import { useSiteSettings } from '../../site/siteSettingsContext';
import { logoAlt, logoSrc } from '../../site/siteSettings';

const LINKS = [
  { key: 'header.nav_home', to: '/', end: true, fallback: 'الصفحة الرئيسية' },
  { key: 'header.nav_register_factory', to: '/register/factory', end: false, fallback: 'تسجيل منشأة صناعية' },
  { key: 'header.nav_register_provider', to: '/register/provider', end: false, fallback: 'تسجيل مزود خدمة' },
];

/**
 * Header of the public marketing pages. It never shows the portal navigation: a signed-in
 * visitor gets one button to their own portal instead. Brand, links and buttons come from
 * the site settings (GET /public/site-settings), with the built-in copy as fallback.
 */
export const PublicHeader: React.FC = () => {
  const { user, status } = useAuth();
  const { map, lang, setLang, t } = useSiteSettings();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const brandLogo = logoSrc(map, publicApiUrl('')) ?? logoUrl;

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
        {t('header.portal_button', 'الذهاب إلى بوابتي')}
      </button>
    ) : (
      <button
        type="button"
        onClick={() => navigate('/login')}
        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#5146A5] hover:bg-[#43388E] text-white text-xs font-bold shadow-sm transition-colors cursor-pointer"
      >
        <LogIn className="w-4 h-4" />
        {t('header.login_button', 'تسجيل الدخول')}
      </button>
    );

  return (
    <header className="sticky top-0 z-30 bg-white/90 backdrop-blur-md border-b border-[#E6EAF0]">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
        <NavLink to="/" className="flex items-center gap-2 min-w-0" aria-label="جاهز - الصفحة الرئيسية">
          <img src={brandLogo} alt={logoAlt(map, 'JAHEZ')} className="h-9 w-auto object-contain" />
          <div className="leading-none">
            <div className="text-lg font-black tracking-tight text-transparent bg-clip-text bg-gradient-to-l from-[#5146A5] to-[#6EC8FF]">{t('header.brand_ar', 'جاهز')}</div>
            <div className="mt-1 text-[9px] font-extrabold tracking-[0.22em] text-[#667085]">{t('header.brand_en', 'JAHEZ')}</div>
          </div>
        </NavLink>

        <nav className="hidden md:flex items-center gap-1" aria-label="التنقل الرئيسي">
          {!user && LINKS.map((link) => (
            <NavLink key={link.to} to={link.to} end={link.end} className={linkClass}>
              {t(link.key, link.fallback)}
            </NavLink>
          ))}
        </nav>

        <div className="hidden md:flex items-center gap-2">
          <div className="flex items-center rounded-xl border border-[#E6EAF0] p-0.5 text-[11px] font-bold" role="group" aria-label="لغة المحتوى">
            {(['ar', 'en'] as const).map((option) => (
              <button
                key={option}
                type="button"
                onClick={() => setLang(option)}
                aria-pressed={lang === option}
                className={`px-2.5 py-1 rounded-lg transition-colors cursor-pointer ${
                  lang === option ? 'bg-[#5146A5] text-white' : 'text-[#667085] hover:text-[#172033]'
                }`}
              >
                {option === 'ar' ? 'عربي' : 'EN'}
              </button>
            ))}
          </div>
          {action}
        </div>

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
              {t(link.key, link.fallback)}
            </NavLink>
          ))}
          <div className="pt-2 flex items-center gap-2">
            <div className="flex items-center rounded-xl border border-[#E6EAF0] p-0.5 text-[11px] font-bold" role="group" aria-label="لغة المحتوى">
              {(['ar', 'en'] as const).map((option) => (
                <button
                  key={option}
                  type="button"
                  onClick={() => setLang(option)}
                  aria-pressed={lang === option}
                  className={`px-2.5 py-1 rounded-lg transition-colors cursor-pointer ${
                    lang === option ? 'bg-[#5146A5] text-white' : 'text-[#667085] hover:text-[#172033]'
                  }`}
                >
                  {option === 'ar' ? 'عربي' : 'EN'}
                </button>
              ))}
            </div>
            {action}
          </div>
        </div>
      )}
    </header>
  );
};
