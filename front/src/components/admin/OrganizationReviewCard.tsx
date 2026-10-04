import React from 'react';
import { Building2, CalendarDays, ChevronLeft, Factory as FactoryIcon } from 'lucide-react';
import type { ProviderApprovalStatus } from '../../api';
import { formatDate } from '../../lib/format';
import { ApprovalBadge } from '../ui/ApprovalBadge';
import { Button } from '../ui/Button';
import { PrivateImage } from '../ui/PrivateFile';

interface OrganizationReviewCardProps {
  kind: 'provider' | 'factory';
  id: number;
  name: string;
  /** Reads the private logo, when there is one. */
  loadLogo?: (signal: AbortSignal) => Promise<Blob>;
  logoVersion?: number;
  status: ProviderApprovalStatus;
  submittedAt: string | null;
  /** Short facts under the name: location, sector, experience. */
  facts: string[];
  /** Chips: services or sectors. */
  chips: { label: string; tone: 'blue' | 'purple' | 'warning' }[];
  /** Profile completeness guide (0–100) and what is missing. */
  completion: { percent: number; missing: string[] };
  /** An extra line under the chips (readiness, pending listings). */
  footnote?: React.ReactNode;
  onDetails: () => void;
}

const CHIP_TONES = {
  blue: 'bg-[#DFF3FF] text-[#0A6EB0]',
  purple: 'bg-[#EEEAFE] text-[#5146A5]',
  warning: 'bg-[#FEF5E7] text-[#A66F0B]',
} as const;

/**
 * A provider or factory in an IMC approval queue, in the Jahez card style (jahez-card, the soft
 * brand chips and the status badges used across the portals). The card summarises; every decision
 * is taken on the details page.
 */
export const OrganizationReviewCard: React.FC<OrganizationReviewCardProps> = ({
  kind,
  id,
  name,
  loadLogo,
  logoVersion,
  status,
  submittedAt,
  facts,
  chips,
  completion,
  footnote,
  onDetails,
}) => {
  const Fallback = kind === 'provider' ? Building2 : FactoryIcon;
  const fallback = (
    <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#EEEAFE] to-[#DFF3FF] text-[#5146A5] flex items-center justify-center">
      <Fallback className="w-6 h-6" />
    </div>
  );
  const barColor = completion.percent >= 80 ? 'bg-[#35B779]' : completion.percent >= 50 ? 'bg-[#F2B84B]' : 'bg-[#E45B6A]';
  const visibleChips = chips.slice(0, 4);

  return (
    <article
      className="jahez-card p-4 flex flex-col gap-3 transition-all hover:border-[#9B8AFB] hover:shadow-md"
      data-organization-kind={kind}
      data-organization-id={id}
    >
      <div className="flex items-start gap-3">
        {loadLogo && logoVersion !== undefined ? (
          <PrivateImage load={loadLogo} version={logoVersion} alt={name} className="w-14 h-14 rounded-2xl object-contain bg-white border border-[#E6EAF0]" fallback={fallback} />
        ) : (
          fallback
        )}
        <div className="flex-1 min-w-0">
          <div className="flex items-start justify-between gap-2">
            <h3 className="font-bold text-sm text-[#172033] leading-snug line-clamp-2">{name}</h3>
            <ApprovalBadge status={status} size="sm" />
          </div>
          {facts.length > 0 && <p className="text-[11px] text-[#667085] mt-1 line-clamp-1">{facts.join(' · ')}</p>}
          <p className="text-[10px] text-[#98A2B3] mt-1 flex items-center gap-1">
            <CalendarDays className="w-3 h-3" /> تاريخ التسجيل: {formatDate(submittedAt)}
          </p>
        </div>
      </div>

      <div className="flex flex-wrap gap-1 min-h-[22px]">
        {visibleChips.length === 0 ? (
          <span className="text-[11px] text-[#98A2B3]">{kind === 'provider' ? 'لم تُحدَّد خدمات' : 'لم تُحدَّد قطاعات'}</span>
        ) : (
          visibleChips.map((chip) => (
            <span key={chip.label} className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${CHIP_TONES[chip.tone]}`}>
              {chip.label}
            </span>
          ))
        )}
        {chips.length > visibleChips.length && <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[#F1F4F9] text-[#667085]">+{chips.length - visibleChips.length}</span>}
      </div>

      {footnote && <div className="text-[11px]">{footnote}</div>}

      <div className="mt-auto pt-3 border-t border-[#F1F4F9] space-y-2">
        <div className="flex items-center justify-between text-[11px]">
          <span className="text-[#667085]">اكتمال الملف</span>
          <span className="font-bold text-[#172033]">{completion.percent}%</span>
        </div>
        <div className="h-1.5 rounded-full bg-[#F1F4F9] overflow-hidden" title={completion.missing.length ? `ناقص: ${completion.missing.join('، ')}` : 'مكتمل'}>
          <div className={`h-full rounded-full ${barColor}`} style={{ width: `${completion.percent}%` }} />
        </div>
        <Button variant="secondary" size="sm" className="w-full" icon={ChevronLeft} onClick={onDetails} data-details-id={id}>
          تفاصيل
        </Button>
      </div>
    </article>
  );
};
