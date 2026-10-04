import React, { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowRight, Clock, Megaphone, Tag } from 'lucide-react';
import { api, publicApiUrl } from '../../api';
import { isApiError } from '../../api/errors';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate } from '../../lib/format';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { getAdPalette } from '../../components/admin/colorOptions';

/** One live announcement (ADR-022), opened from the landing-page strip. */
export const AdDetails: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const adId = Number.parseInt(id ?? '', 10);
  const query = useApiQuery((signal) => api.announcements.publicGet(adId, signal), [adId], { enabled: Number.isFinite(adId) });
  const [coverFailed, setCoverFailed] = useState(false);

  const back = (
    <Button variant="ghost" size="sm" icon={ArrowRight} onClick={() => navigate('/')}>
      العودة للصفحة الرئيسية
    </Button>
  );

  if (query.status === 'loading' && Number.isFinite(adId)) {
    return (
      <div className="space-y-6 max-w-5xl mx-auto">
        {back}
        <CardSkeleton />
      </div>
    );
  }

  const notFound = !Number.isFinite(adId) || (query.status === 'error' && isApiError(query.error) && query.error.status === 404);
  if (notFound) {
    return (
      <div className="max-w-xl mx-auto mt-16 text-center space-y-4">
        <div className="w-16 h-16 rounded-full bg-[#FDECEE] text-[#B82B3B] flex items-center justify-center mx-auto">
          <Megaphone className="w-8 h-8" aria-hidden="true" />
        </div>
        <h1 className="text-xl font-bold text-[#172033]">الإعلان غير موجود</h1>
        <p className="text-xs text-[#667085] leading-relaxed">ربما انتهت مدة هذا الإعلان أو أُلغي نشره، أو أن الرابط غير صحيح.</p>
        <Button variant="primary" size="md" icon={ArrowRight} onClick={() => navigate('/')}>
          العودة للصفحة الرئيسية
        </Button>
      </div>
    );
  }

  if (query.status === 'error') {
    return (
      <div className="space-y-6 max-w-5xl mx-auto">
        {back}
        <ApiErrorState error={query.error} onRetry={query.refetch} />
      </div>
    );
  }

  const ad = query.data!;
  const palette = getAdPalette(ad.color);

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      <div>{back}</div>

      <div className="jahez-card overflow-hidden">
        {coverFailed || !ad.cover_path ? (
          <div className="w-full h-56 sm:h-72 flex items-center justify-center" style={{ background: `linear-gradient(135deg, ${palette.soft} 0%, #FFFFFF 100%)` }}>
            <Megaphone aria-hidden="true" className="w-14 h-14" style={{ color: palette.ink }} />
          </div>
        ) : (
          <img src={publicApiUrl(ad.cover_path)} alt={ad.cover_alt || ad.title} onError={() => setCoverFailed(true)} className="w-full h-56 sm:h-72 object-cover" />
        )}

        <div className="p-6 sm:p-8 space-y-5">
          <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2 mb-2">
                {ad.badge_text && (
                  <span className="text-xs font-bold px-2.5 py-1 rounded-lg" style={{ backgroundColor: palette.soft, color: palette.ink }}>
                    {ad.badge_text}
                  </span>
                )}
                {(ad.countdown_text || ad.ends_at) && (
                  <span className="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-[#667085]">
                    <Clock className="w-3.5 h-3.5" aria-hidden="true" />
                    {ad.countdown_text ?? `حتى ${formatDate(ad.ends_at)}`}
                  </span>
                )}
              </div>
              <h1 className="text-xl sm:text-2xl font-bold text-[#172033] leading-snug">{ad.title}</h1>
              <p className="text-xs sm:text-sm text-[#667085] mt-2 leading-relaxed whitespace-pre-line">{ad.description}</p>
            </div>

            {ad.link_path && (
              <div className="text-left shrink-0">
                {/* The API accepts only paths of this web client, so the link never leaves the platform. */}
                <Button variant="primary" size="md" onClick={() => navigate(ad.link_path as string)}>
                  الانتقال لعرض الإعلان
                </Button>
              </div>
            )}
          </div>

          {ad.tags.length > 0 && (
            <div className="pt-4 border-t border-[#F1F4F9] space-y-2">
              <span className="inline-flex items-center gap-1.5 text-[11px] font-bold text-[#667085]">
                <Tag className="w-3.5 h-3.5" aria-hidden="true" />
                الوسوم
              </span>
              <ul className="flex flex-wrap gap-2">
                {ad.tags.map((tag) => (
                  <li key={tag} className="text-xs font-bold px-3 py-1.5 rounded-full border" style={{ backgroundColor: palette.focus, borderColor: palette.soft, color: palette.ink }}>
                    {tag}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
