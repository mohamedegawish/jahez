'use client';

import * as React from 'react';
import { ArrowUpRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * NotchedProjectCard
 *
 * A card whose cover has a rounded notch bitten out of its bottom-right
 * corner, with the "open" arrow nested inside it. The cut is concentric with
 * the arrow disc, and filleted where it meets the cover's edges, so the
 * cover curves into it instead of ending on a point.
 *
 * The notch is drawn by three layers painted in the colour of the surface
 * BEHIND the card (`surface`, white by default). Put the card on a different
 * background and pass that colour, or the notch shows.
 *
 * Adapted for JAHEZ: RTL-safe copy, a fallback layer for failed/missing
 * covers, an optional footer + overlay slot (admin controls), and a very
 * light platform-tint focus ring drawn around the whole card.
 */

export interface NotchedProjectCardProps {
  href: string;
  title: string;
  description?: string;
  /** cover photograph */
  image?: string;
  imageAlt?: string;
  /** a pill at the top of the cover */
  badge?: string;
  tags?: string[];
  /** the colour behind the card; the notch is painted in it */
  surface?: string;
  /** the arrow disc's fill on hover (any CSS colour) */
  accent?: string;
  /** the arrow's colour on that fill */
  accentForeground?: string;
  /** the very light focus-ring colour drawn around the card */
  focusRing?: string;
  /** CSS aspect-ratio for the cover, e.g. "4 / 3" or "16 / 9" */
  coverAspectRatio?: string;
  /** painted behind the cover when the image is missing or fails to load */
  fallbackGradient?: string;
  /** shown centred inside the fallback cover */
  fallbackContent?: React.ReactNode;
  /** intercept navigation (prevents the default link jump) */
  onNavigate?: (e: React.MouseEvent<HTMLAnchorElement>) => void;
  /** extra content inside the link body (e.g. a progress bar) */
  children?: React.ReactNode;
  /** floating controls above the link (e.g. the admin pencil) */
  overlay?: React.ReactNode;
  /** pinned to the bottom of the card, outside the link */
  footer?: React.ReactNode;
  className?: string;
}

const DISC = 64; // the arrow disc, px
const BLOCK = 80; // the notch block, px (radius = BLOCK - DISC / 2)
const FILLET = 28; // the curve where the cut meets the cover's edges, px

export function NotchedProjectCard({
  href,
  title,
  description,
  image,
  imageAlt = '',
  badge,
  tags = [],
  surface = '#ffffff',
  accent,
  accentForeground = '#172033',
  focusRing = '#E6E2FE',
  coverAspectRatio = '4 / 3',
  fallbackGradient,
  fallbackContent,
  onNavigate,
  children,
  overlay,
  footer,
  className,
}: NotchedProjectCardProps) {
  const [failedImg, setFailedImg] = React.useState<string | null>(null);
  const showImage = Boolean(image) && failedImg !== image;

  const handleClick = (e: React.MouseEvent<HTMLAnchorElement>) => {
    if (!onNavigate) return;
    e.preventDefault();
    onNavigate(e);
  };

  return (
    <div
      className={cn('notched-card group relative flex flex-col rounded-[28px]', className)}
      style={
        {
          '--notched-focus': focusRing,
          '--notched-accent': accent,
          '--notched-accent-fg': accentForeground,
        } as React.CSSProperties
      }
    >
      <a
        href={href}
        className="notched-card__link flex flex-col outline-none"
        onClick={handleClick}
      >
        <div className="relative">
          {/* the cover */}
          <div
            className="relative overflow-hidden rounded-[28px] bg-[#F5F7FA]"
            style={{ aspectRatio: coverAspectRatio }}
          >
            {showImage && (
              <img
                src={image}
                alt={imageAlt}
                onError={() => setFailedImg(image ?? null)}
                className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
              />
            )}
            {!showImage && (
              <div
                aria-hidden
                className="absolute inset-0 flex items-center justify-center"
                style={{ background: fallbackGradient }}
              >
                {fallbackContent}
              </div>
            )}
            {badge && (
              <div className="pointer-events-none absolute inset-x-0 top-0 flex justify-center pt-4">
                <span className="notched-card__badge rounded-full border border-white/40 bg-black/30 px-2.5 py-1 text-[11px] font-medium text-white backdrop-blur-md">
                  {badge}
                </span>
              </div>
            )}
          </div>

          {/* the notch: a block with a concave corner, and a fillet at each
              end where the cut meets the cover's right and bottom edges */}
          <div
            aria-hidden
            className="absolute bottom-0 right-0"
            style={{ width: BLOCK, height: BLOCK, borderTopLeftRadius: BLOCK - DISC / 2, background: surface }}
          />
          {[
            { bottom: BLOCK, right: 0 },
            { bottom: 0, right: BLOCK },
          ].map((pos, i) => (
            <div
              key={i}
              aria-hidden
              className="absolute"
              style={{
                ...pos,
                width: FILLET,
                height: FILLET,
                background: `radial-gradient(circle at top left, transparent ${FILLET - 0.5}px, ${surface} ${FILLET}px)`,
              }}
            />
          ))}

          {/* the arrow, nested in the notch */}
          <span
            aria-hidden
            className="notched-card__arrow absolute bottom-0 right-0 flex items-center justify-center rounded-full border border-[#E6EAF0] bg-white text-[#172033] transition-[background-color,color,scale] duration-300 group-hover:scale-105 group-hover:bg-[var(--notched-accent)] group-hover:text-[var(--notched-accent-fg)]"
            style={{ width: DISC, height: DISC }}
          >
            <ArrowUpRight className="size-5 transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
          </span>
        </div>

        {/* body */}
        <div className="notched-card__body">
          <h3 className="notched-card__title mt-5 text-xl font-semibold tracking-tight text-[#172033]">
            {title}
          </h3>
          {description && (
            <p className="notched-card__desc mt-2 text-sm leading-relaxed text-[#5A6478]">
              {description}
            </p>
          )}
          {tags.length > 0 && (
            <ul className="notched-card__tags mt-4 flex flex-wrap gap-2">
              {tags.map((t, i) => (
                <li
                  key={`${t}-${i}`}
                  className="rounded-md bg-[#F1F3F7] px-2 py-1 text-[10px] font-semibold uppercase tracking-wider text-[#5A6478]"
                >
                  {t}
                </li>
              ))}
            </ul>
          )}
          {children}
        </div>
      </a>

      {footer && <footer className="notched-card__footer">{footer}</footer>}
      {overlay && (
        <div className="notched-card__overlay absolute left-2 top-2 z-10">{overlay}</div>
      )}
    </div>
  );
}

export default NotchedProjectCard;
