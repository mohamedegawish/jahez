import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Megaphone, Pencil } from 'lucide-react';
import { publicApiUrl } from '../../api';
import type { PublicAnnouncement } from '../../api';
import { NotchedProjectCard } from '../ui/notched-project-card';
import { getAdPalette } from '../admin/colorOptions';

interface AdCardProps {
  ad: PublicAnnouncement;
  /**
   * The cover image URL. Defaults to the public cover of a live announcement; the admin preview
   * passes an object URL read with the session token (drafts have no public cover).
   */
  coverSrc?: string | null;
  /** Admin-only: when provided, a pencil shows on the cover to edit this ad. */
  onEdit?: (ad: PublicAnnouncement) => void;
}

/** One landing-page announcement (ADR-022) in the Jahez advertisement-card style. */
export const AdCard: React.FC<AdCardProps> = ({ ad, coverSrc, onEdit }) => {
  const navigate = useNavigate();
  const palette = getAdPalette(ad.color);
  const detailsPath = `/ads/${ad.id}`;
  const image = coverSrc !== undefined ? coverSrc ?? undefined : ad.cover_path ? publicApiUrl(ad.cover_path) : undefined;

  return (
    <NotchedProjectCard
      className={`jahez-ad-card jahez-ad-card--${ad.color}`}
      href={detailsPath}
      onNavigate={() => navigate(detailsPath)}
      title={ad.title}
      description={ad.description}
      image={image}
      imageAlt={ad.cover_alt || ad.title}
      badge={ad.badge_text ?? undefined}
      tags={ad.tags}
      surface="#ffffff"
      accent={palette.hex}
      accentForeground="#172033"
      focusRing={palette.focus}
      coverAspectRatio="16 / 9"
      fallbackGradient={`linear-gradient(135deg, ${palette.soft} 0%, #FFFFFF 100%)`}
      fallbackContent={<Megaphone aria-hidden="true" className="size-9" style={{ color: palette.ink }} />}
      overlay={
        onEdit ? (
          <button
            type="button"
            className="jahez-ad-card__edit"
            onClick={(e) => {
              e.preventDefault();
              e.stopPropagation();
              onEdit(ad);
            }}
            aria-label="تعديل الإعلان"
            title="تعديل الإعلان"
          >
            <Pencil aria-hidden="true" />
          </button>
        ) : undefined
      }
    />
  );
};
