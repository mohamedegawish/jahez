import React from 'react';
import { Megaphone, RotateCcw } from 'lucide-react';
import { api } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { AdMarquee } from './AdMarquee';

/**
 * Advertisement strip rendered directly BELOW the hero section: the live announcements IMC
 * published (GET /public/announcements, ADR-022), in their display order. Nothing is stored in the
 * browser. With no live announcement the strip is not shown.
 */
export const AdStrip: React.FC = () => {
  const ads = useApiQuery((signal) => api.announcements.publicList(signal), []);

  const items = ads.data ?? [];
  if (ads.status === 'success' && items.length === 0) return null;

  return (
    <section className="jahez-ad-strip" dir="rtl" aria-label="إعلانات المنصة" data-ads-state={ads.status}>
      <div className="jahez-ad-strip__head">
        <span className="jahez-ad-strip__title">
          <Megaphone aria-hidden="true" />
          إعلانات وحملات المنصة
        </span>
      </div>
      {ads.status === 'success' && <AdMarquee ads={items} />}
      {ads.status === 'loading' && (
        <div className="flex gap-4 overflow-hidden py-1" aria-busy="true" aria-label="جارٍ تحميل الإعلانات">
          {[0, 1, 2, 3].map((n) => (
            <div key={n} className="w-[300px] h-[300px] shrink-0 rounded-3xl bg-white/70 border border-[#E6EAF0] animate-pulse" />
          ))}
        </div>
      )}
      {ads.status === 'error' && (
        <div role="status" className="flex items-center justify-center gap-3 py-6 text-xs text-[#667085]">
          تعذّر تحميل الإعلانات حاليًا.
          <button type="button" onClick={ads.refetch} className="inline-flex items-center gap-1 font-semibold text-[#5146A5] hover:underline cursor-pointer">
            <RotateCcw className="w-3.5 h-3.5" /> إعادة المحاولة
          </button>
        </div>
      )}
    </section>
  );
};
