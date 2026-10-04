import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Building2, Layers, Megaphone, Sparkles } from 'lucide-react';
import { api } from '../../api';
import type { ServiceListing } from '../../api';
import { approvalLabel } from '../../lib/labels';
import { colorOptions } from '../admin/colorOptions';
import { NotchedProjectCard } from '../ui/notched-project-card';
import { PrivateImage } from '../ui/PrivateFile';

/** A stable palette per catalog category, from the existing advertisement palettes. */
function paletteFor(code: string | undefined) {
  const key = [...(code ?? '')].reduce((sum, ch) => sum + ch.charCodeAt(0), 0);
  return colorOptions[key % colorOptions.length];
}

/** The provider's logo in a circular avatar, read with the session token; initials when there is none. */
export const ProviderAvatar: React.FC<{ provider: ServiceListing['provider']; size?: 'sm' | 'lg' }> = ({ provider, size = 'sm' }) => {
  const box = size === 'lg' ? 'w-20 h-20' : 'w-9 h-9';
  const fallback = (
    <div className={`${box} rounded-full bg-white border border-[#E6EAF0] text-[#5146A5] flex items-center justify-center shadow-xs`}>
      <Building2 className={size === 'lg' ? 'w-8 h-8' : 'w-4 h-4'} />
    </div>
  );
  if (!provider?.logo_path) return fallback;
  const path = provider.logo_path;
  return (
    <PrivateImage
      load={(signal) => api.serviceListings.logo(path, signal)}
      version={path}
      alt={provider.name}
      className={`${box} rounded-full object-contain bg-white border border-[#E6EAF0] shadow-xs`}
      fallback={fallback}
    />
  );
};

interface ServiceListingCardProps {
  listing: ServiceListing;
  /** Where the card and its "details" action lead. */
  detailsPath: string;
  /** Extra actions under the card (request, manage). */
  footer?: React.ReactNode;
  /** Show the provider's approval status (the provider's own portal). */
  showApproval?: boolean;
}

/**
 * One provider's listing of a catalog service, in the Jahez advertisement-card style. The catalog part
 * (service name, category) is IMC's fixed catalog; the provider part is its own profile; a promotion is
 * IMC's paid placement and is always labelled «إعلان».
 */
export const ServiceListingCard: React.FC<ServiceListingCardProps> = ({ listing, detailsPath, footer, showApproval = false }) => {
  const navigate = useNavigate();
  const palette = paletteFor(listing.service?.category?.code);
  const tags = [
    listing.service?.category?.name_ar,
    listing.recommended ? 'موصى بها لمنشأتك' : undefined,
    listing.provider?.governorate ?? undefined,
  ].filter((tag): tag is string => Boolean(tag));

  return (
    <div className="relative" data-listing-id={listing.id} data-promoted={listing.promotion ? 'true' : 'false'}>
      <NotchedProjectCard
        className={`jahez-ad-card jahez-ad-card--grid jahez-ad-card--${palette.key}`}
        href={detailsPath}
        onNavigate={() => navigate(detailsPath)}
        title={listing.service?.name_ar ?? '—'}
        description={listing.promotion?.headline ?? listing.provider?.description ?? undefined}
        badge={listing.promotion ? listing.promotion.label : undefined}
        tags={tags}
        surface="#ffffff"
        accent={palette.hex}
        accentForeground="#172033"
        focusRing={palette.focus}
        coverAspectRatio="16 / 9"
        fallbackGradient={`linear-gradient(135deg, ${palette.soft} 0%, #FFFFFF 100%)`}
        fallbackContent={
          listing.provider?.has_logo ? <ProviderAvatar provider={listing.provider} size="lg" /> : <Layers aria-hidden="true" className="size-9" style={{ color: palette.ink }} />
        }
        overlay={
          listing.promotion ? (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-[#FEF5E7] border border-[#FDE5BE] text-[10px] font-bold text-[#A66F0B] shadow-xs" title="إعلان ممول من مركز تحديث الصناعة">
              <Megaphone className="w-3 h-3" /> {listing.promotion.label}
            </span>
          ) : undefined
        }
        footer={
          <div className="space-y-3 pt-3">
            <div className="flex items-center gap-2.5">
              <ProviderAvatar provider={listing.provider} />
              <div className="min-w-0">
                <div className="text-xs font-bold text-[#172033] truncate">{listing.provider?.name}</div>
                <div className="text-[10px] text-[#98A2B3]">
                  {listing.provider?.dx_experience_years != null ? `خبرة ${listing.provider.dx_experience_years} سنة في التحول الرقمي` : 'مزود خدمة'}
                </div>
              </div>
              {showApproval && listing.provider?.approval_status && (
                <span
                  className={`mr-auto text-[10px] font-bold px-2 py-0.5 rounded-full ${
                    listing.provider.approval_status === 'approved' ? 'bg-[#E7F8EE] text-[#1D7E4C]' : 'bg-[#FEF5E7] text-[#A66F0B]'
                  }`}
                >
                  {approvalLabel(listing.provider.approval_status)}
                </span>
              )}
              {listing.recommended && !showApproval && <Sparkles className="w-4 h-4 text-[#5146A5] mr-auto" aria-label="موصى بها" />}
            </div>
            {footer}
          </div>
        }
      />
    </div>
  );
};
