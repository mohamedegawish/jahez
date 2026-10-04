import React from 'react';
import { ExternalLink } from 'lucide-react';
import { api } from '../../api';
import type { ProviderDirectoryEntry } from '../../api';
import { PrivateImage } from '../ui/PrivateFile';

interface ProviderCardProps {
  provider: ProviderDirectoryEntry;
  onOpen: (provider: ProviderDirectoryEntry) => void;
  /** Highlight a catalog service code the viewer is browsing. */
  highlightService?: string;
}

/** A provider as the directory exposes it: only public profile fields; contact details only if the API sends them (OQ-37). */
export const ProviderCard: React.FC<ProviderCardProps> = ({ provider, onOpen, highlightService }) => {
  const services = provider.services ?? [];
  const shown = services.slice(0, 4);

  return (
    <div
      data-provider-id={provider.id}
      onClick={() => onOpen(provider)}
      className="jahez-card p-5 flex flex-col justify-between hover:border-[#6EC8FF] transition-all cursor-pointer group"
    >
      <div>
        <div className="flex items-start justify-between gap-2 mb-2">
          <div className="flex items-center gap-2 min-w-0">
            {provider.has_logo && (
              <PrivateImage
                load={(signal) => api.directory.logo(provider.id, signal)}
                version={provider.id}
                alt=""
                className="w-9 h-9 rounded-lg border border-[#E6EAF0] object-contain bg-white shrink-0"
              />
            )}
            <h3 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors leading-snug">{provider.name}</h3>
          </div>
          <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-[#E7F8EE] text-[#1D7E4C] shrink-0">معتمد</span>
        </div>

        <div className="flex flex-wrap gap-1 mb-3">
          {(provider.sectors ?? []).map((sector) => (
            <span key={sector.code} className="px-2 py-0.5 rounded-full bg-[#DFF3FF] text-[#0A6EB0] text-[10px] font-semibold">
              {sector.name_ar}
            </span>
          ))}
        </div>

        {provider.description && <p className="text-[11px] text-[#667085] leading-relaxed line-clamp-2 mb-2" dir="auto">{provider.description}</p>}

        <div className="text-[11px] text-[#667085] space-y-1">
          <div className="flex items-center justify-between">
            <span>الخبرة في التحول الرقمي:</span>
            <span className="font-semibold text-[#172033]">{provider.dx_experience_years === null ? '—' : `${provider.dx_experience_years} سنة`}</span>
          </div>
          {provider.website && (
            <a
              href={provider.website}
              target="_blank"
              rel="noreferrer"
              onClick={(e) => e.stopPropagation()}
              className="inline-flex items-center gap-1 text-[#0A6EB0] hover:underline font-semibold"
              dir="ltr"
            >
              {provider.website.replace(/^https?:\/\//, '')} <ExternalLink className="w-3 h-3" />
            </a>
          )}
        </div>
      </div>

      <div className="mt-4 pt-3 border-t border-[#F1F4F9]">
        <span className="text-[10px] text-[#98A2B3] block mb-1.5">الخدمات المقدمة ({services.length})</span>
        <div className="flex flex-wrap gap-1">
          {shown.map((service) => (
            <span
              key={service.code}
              className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                service.code === highlightService ? 'bg-[#5146A5] text-white' : 'bg-[#EEEAFE] text-[#5146A5]'
              }`}
            >
              {service.name_ar}
            </span>
          ))}
          {services.length > shown.length && <span className="text-[10px] text-[#98A2B3] self-center">+{services.length - shown.length}</span>}
        </div>
      </div>
    </div>
  );
};
