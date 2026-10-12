import React from 'react';
import { Link, Outlet } from 'react-router-dom';
import { PublicHeader } from '../components/layout/PublicHeader';
import { useSiteSettings } from '../site/siteSettingsContext';

/**
 * Layout of the public marketing pages (the landing page and advertisements). It has no
 * sidebar and needs no session; the portals use AppLayout behind RequireAuth. The footer
 * line and links come from the site settings, with the built-in copy as fallback.
 */
export const PublicLayout: React.FC = () => {
  const { t } = useSiteSettings();

  return (
    <div className="min-h-screen bg-[#F7F9FC] flex flex-col text-right" dir="rtl">
      <PublicHeader />
      <main className="flex-1 w-full py-4 sm:py-6 lg:py-8">
        <Outlet />
      </main>
      <footer className="border-t border-[#E6EAF0] bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] text-[#667085]">
          <span>{t('footer.line', 'منصة جاهز — بإشراف مركز تحديث الصناعة (IMC)')}</span>
          <nav className="flex items-center gap-4" aria-label="روابط التسجيل">
            <Link to="/register/factory" className="font-bold text-[#0A6EB0] hover:underline">{t('footer.link_factory', 'تسجيل منشأة صناعية')}</Link>
            <Link to="/register/provider" className="font-bold text-[#5146A5] hover:underline">{t('footer.link_provider', 'تسجيل مزود خدمة')}</Link>
            <Link to="/login" className="font-bold text-[#172033] hover:underline">{t('footer.link_login', 'تسجيل الدخول')}</Link>
          </nav>
        </div>
      </footer>
    </div>
  );
};
