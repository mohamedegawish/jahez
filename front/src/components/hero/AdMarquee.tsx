import React from 'react';
import type { PublicAnnouncement } from '../../api';
import { AdCard } from './AdCard';

const MIN_GROUP_WIDTH = 2400; // px — one group must cover wide viewports
const CARD_PITCH = 316; // 300px card + 16px inline-end margin

/** The scrolling strip of live announcements, repeated so it loops seamlessly. */
export const AdMarquee: React.FC<{ ads: PublicAnnouncement[] }> = ({ ads }) => {
  if (ads.length === 0) return null;

  const reps = Math.max(2, Math.ceil(MIN_GROUP_WIDTH / (ads.length * CARD_PITCH)));
  const group: PublicAnnouncement[] = [];
  for (let i = 0; i < reps; i++) group.push(...ads);

  return (
    <div className="jahez-marquee w-full overflow-hidden" dir="rtl" aria-label="إعلانات المنصة">
      <div className="jahez-marquee-track py-1">
        {group.map((ad, i) => (
          <AdCard key={`g1-${ad.id}-${i}`} ad={ad} />
        ))}
        {group.map((ad, i) => (
          <AdCard key={`g2-${ad.id}-${i}`} ad={ad} />
        ))}
      </div>
    </div>
  );
};
