import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Award, Building2, CalendarDays, CheckCircle2, FileSearch, Layers, PauseCircle, RotateCcw, XCircle } from 'lucide-react';
import { api } from '../../api';
import type { ServiceProvider } from '../../api';
import { formatDate } from '../../lib/format';
import { approvalLabel } from '../../lib/labels';
import { allowedApprovalDecisions, type ApprovalDecision } from '../../lib/provider';
import { colorOptions } from './colorOptions';
import { ApprovalBadge } from '../ui/ApprovalBadge';
import { Button } from '../ui/Button';
import { NotchedProjectCard } from '../ui/notched-project-card';
import { PrivateImage } from '../ui/PrivateFile';

/** One palette per approval status, from the advertisement palettes. */
const PALETTE_BY_STATUS: Record<string, string> = { approved: 'green', pending: 'orange', changes_requested: 'beige', rejected: 'red', suspended: 'purple' };

const DECISIONS: Record<ApprovalDecision, { label: string; variant: 'success' | 'danger' | 'outline'; icon: typeof CheckCircle2 }> = {
  approved: { label: 'اعتماد', variant: 'success', icon: CheckCircle2 },
  changes_requested: { label: 'طلب استكمال', variant: 'outline', icon: RotateCcw },
  rejected: { label: 'رفض', variant: 'danger', icon: XCircle },
  suspended: { label: 'إيقاف', variant: 'danger', icon: PauseCircle },
};

/** The provider's logo, read with the session token; a building icon when there is none. */
const ProviderLogo: React.FC<{ provider: ServiceProvider; ink: string }> = ({ provider, ink }) => {
  const fallback = (
    <div className="w-20 h-20 rounded-2xl bg-white/80 border border-white flex items-center justify-center shadow-xs">
      <Building2 className="w-9 h-9" style={{ color: ink }} />
    </div>
  );
  const logo = provider.documents?.logo ?? null;
  if (!logo) return fallback;
  return (
    <PrivateImage
      load={(signal) => api.serviceProviders.documentFile(provider.id, logo.id, signal)}
      version={logo.id}
      alt={provider.name}
      className="w-20 h-20 rounded-2xl object-contain bg-white border border-[#E6EAF0] shadow-xs"
      fallback={fallback}
    />
  );
};

interface ProviderAdminCardProps {
  provider: ServiceProvider;
  /** The signed-in administrator may decide on providers (`service_providers.approve`); the API checks again. */
  canDecide: boolean;
  onDecide: (decision: ApprovalDecision) => void;
}

/**
 * A service provider in the IMC list, in the Jahez advertisement-card style: name, logo, sectors, place,
 * experience, the account's approval status and how many of its listed services IMC approved. Legal
 * details, contact data and documents are not on the card; they are on the review page.
 */
export const ProviderAdminCard: React.FC<ProviderAdminCardProps> = ({ provider, canDecide, onDecide }) => {
  const navigate = useNavigate();
  const reviewPath = `/admin/approvals/providers/${provider.id}`;
  const palette = colorOptions.find((option) => option.key === PALETTE_BY_STATUS[provider.approval.status]) ?? colorOptions[0];
  const listings = provider.service_listings ?? [];
  const approvedListings = listings.filter((listing) => listing.status === 'approved').length;
  const pendingListings = listings.filter((listing) => listing.status === 'pending').length;
  const place = [provider.governorate, provider.city].filter(Boolean).join('، ');
  const decisions = canDecide ? allowedApprovalDecisions(provider.approval.status) : [];

  return (
    <div className="relative h-full" data-provider-id={provider.id} data-approval={provider.approval.status}>
      <NotchedProjectCard
        className={`jahez-ad-card jahez-ad-card--grid jahez-ad-card--${palette.key} h-full`}
        href={reviewPath}
        onNavigate={() => navigate(reviewPath)}
        title={provider.name}
        description={provider.description ?? (place || undefined)}
        badge={approvalLabel(provider.approval.status)}
        tags={(provider.sectors ?? []).map((sector) => sector.name_ar)}
        surface="#ffffff"
        accent={palette.hex}
        accentForeground="#172033"
        focusRing={palette.focus}
        coverAspectRatio="16 / 8"
        fallbackGradient={`linear-gradient(135deg, ${palette.soft} 0%, #FFFFFF 100%)`}
        fallbackContent={<ProviderLogo provider={provider} ink={palette.ink} />}
        overlay={
          <span
            className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-white/90 border border-[#E6EAF0] text-[10px] font-bold text-[#172033] shadow-xs"
            title="الخدمات التي اعتمدها المركز من خدمات المزود"
          >
            {approvedListings} / {listings.length} خدمة معتمدة
          </span>
        }
        footer={
          <div className="space-y-3 pt-3 text-xs">
            <div className="flex flex-wrap items-center gap-2">
              <ApprovalBadge status={provider.approval.status} size="sm" />
              {pendingListings > 0 && (
                <span className="px-2 py-0.5 rounded-full bg-[#FEF5E7] text-[#A66F0B] text-[10px] font-bold">{pendingListings} خدمة بانتظار الاعتماد</span>
              )}
            </div>
            <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-[11px] text-[#667085]">
              <div className="flex items-center gap-1.5">
                <Award className="w-3.5 h-3.5 shrink-0" />
                <dt className="sr-only">الخبرة</dt>
                <dd>{provider.dx_experience_years === null ? 'الخبرة غير محددة' : `خبرة ${provider.dx_experience_years} سنة`}</dd>
              </div>
              <div className="flex items-center gap-1.5">
                <CalendarDays className="w-3.5 h-3.5 shrink-0" />
                <dt className="sr-only">تاريخ التسجيل</dt>
                <dd>سُجّل {formatDate(provider.created_at)}</dd>
              </div>
              {place && (
                <div className="flex items-center gap-1.5 col-span-2">
                  <Building2 className="w-3.5 h-3.5 shrink-0" />
                  <dt className="sr-only">المقر</dt>
                  <dd>{place}</dd>
                </div>
              )}
            </dl>
            {provider.approval.reason && provider.approval.status !== 'approved' && (
              <p className="p-2 rounded-lg bg-[#FEF5E7] text-[#A66F0B] leading-relaxed line-clamp-2" title={provider.approval.reason}>
                {provider.approval.reason}
              </p>
            )}
            <div className="flex flex-wrap gap-1.5 pt-2 border-t border-[#F1F4F9]">
              <Link
                to={reviewPath}
                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-[#EEEAFE] text-[#5146A5] font-semibold hover:bg-[#E2DCFD]"
              >
                <FileSearch className="w-3.5 h-3.5" /> الملف الكامل والمراجعة
              </Link>
              <Link
                to={`/admin/services?listing_status=all&search=${encodeURIComponent(provider.name)}`}
                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-[#E6EAF0] text-[#344054] font-semibold hover:bg-[#F7F9FC]"
              >
                <Layers className="w-3.5 h-3.5" /> خدماته
              </Link>
            </div>
            {decisions.length > 0 && (
              <div className="flex flex-wrap gap-1.5" aria-label="قرارات الاعتماد">
                {decisions.map((decision) => {
                  const button = DECISIONS[decision];
                  return (
                    <Button key={decision} size="sm" variant={button.variant} icon={button.icon} onClick={() => onDecide(decision)} data-decision={decision}>
                      {button.label}
                    </Button>
                  );
                })}
              </div>
            )}
          </div>
        }
      />
    </div>
  );
};
