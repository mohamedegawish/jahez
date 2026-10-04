import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CalendarDays, CheckCircle2, ClipboardCheck, Eye, Factory as FactoryIcon, FileSearch, PauseCircle, RotateCcw, Send, XCircle } from 'lucide-react';
import { api } from '../../api';
import type { FactorySummary } from '../../api';
import { approvalLabel } from '../../lib/labels';
import { formatDate } from '../../lib/format';
import { allowedApprovalDecisions, type ApprovalDecision } from '../../lib/provider';
import { colorOptions } from './colorOptions';
import { ApprovalBadge } from '../ui/ApprovalBadge';
import { Button } from '../ui/Button';
import { NotchedProjectCard } from '../ui/notched-project-card';
import { PrivateImage } from '../ui/PrivateFile';
import { ReadinessBadge } from '../ui/ReadinessBadge';

/** One palette per readiness level, from the advertisement palettes; neutral before the first assessment. */
const PALETTE_BY_LEVEL: Record<string, string> = { smart: 'green', advanced: 'purple', basic: 'blue', b4_automation: 'orange' };

const DECISIONS: Record<ApprovalDecision, { label: string; variant: 'success' | 'danger' | 'outline'; icon: typeof CheckCircle2 }> = {
  approved: { label: 'اعتماد', variant: 'success', icon: CheckCircle2 },
  changes_requested: { label: 'طلب استكمال', variant: 'outline', icon: RotateCcw },
  rejected: { label: 'رفض', variant: 'danger', icon: XCircle },
  suspended: { label: 'إيقاف', variant: 'danger', icon: PauseCircle },
};

/** The factory's logo, read with the session token; a factory icon when there is none. */
const FactoryLogo: React.FC<{ factory: FactorySummary; ink: string }> = ({ factory, ink }) => {
  const fallback = (
    <div className="w-20 h-20 rounded-2xl bg-white/80 border border-white flex items-center justify-center shadow-xs">
      <FactoryIcon className="w-9 h-9" style={{ color: ink }} />
    </div>
  );
  const logo = factory.logo;
  if (!logo) return fallback;
  return (
    <PrivateImage
      load={(signal) => api.factories.documentFile(factory.id, logo.id, signal)}
      version={logo.id}
      alt={factory.name}
      className="w-20 h-20 rounded-2xl object-contain bg-white border border-[#E6EAF0] shadow-xs"
      fallback={fallback}
    />
  );
};

interface FactoryCardProps {
  factory: FactorySummary;
  sizeName: string | null;
  /** The decisions the signed-in administrator may take (`factories.approve`); the API checks again. */
  canDecide: boolean;
  onDetails: () => void;
  onDecide: (decision: ApprovalDecision) => void;
}

/**
 * A factory in the IMC list, in the Jahez advertisement-card style: name, logo, sectors, place, the
 * account's approval status and, separately, its readiness level and score as the server computed them.
 * Legal details and documents are not on the card; they are on the review page.
 */
export const FactoryCard: React.FC<FactoryCardProps> = ({ factory, sizeName, canDecide, onDetails, onDecide }) => {
  const navigate = useNavigate();
  const reviewPath = `/admin/approvals/factories/${factory.id}`;
  const readiness = factory.current_readiness ?? null;
  const palette = colorOptions.find((option) => option.key === (PALETTE_BY_LEVEL[readiness?.category?.code ?? ''] ?? 'beige')) ?? colorOptions[0];
  const place = [factory.governorate, factory.city].filter(Boolean).join('، ');
  const tags = [...(factory.sectors ?? []).map((sector) => sector.name_ar), sizeName].filter((tag): tag is string => Boolean(tag));
  const decisions = canDecide ? allowedApprovalDecisions(factory.approval.status) : [];

  return (
    <div className="relative h-full" data-factory-id={factory.id} data-approval={factory.approval.status}>
      <NotchedProjectCard
        className={`jahez-ad-card jahez-ad-card--grid jahez-ad-card--${palette.key} h-full`}
        href={reviewPath}
        onNavigate={() => navigate(reviewPath)}
        title={factory.name}
        description={place || undefined}
        badge={approvalLabel(factory.approval.status)}
        tags={tags}
        surface="#ffffff"
        accent={palette.hex}
        accentForeground="#172033"
        focusRing={palette.focus}
        coverAspectRatio="16 / 8"
        fallbackGradient={`linear-gradient(135deg, ${palette.soft} 0%, #FFFFFF 100%)`}
        fallbackContent={<FactoryLogo factory={factory} ink={palette.ink} />}
        overlay={
          <span
            className="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-white/90 border border-[#E6EAF0] text-[10px] font-bold text-[#172033] shadow-xs"
            title="مستوى الجاهزية الرقمية الحالي كما حسبه الخادم"
          >
            {readiness ? `${readiness.total_score} / 40 · ${readiness.category?.name_ar ?? ''}` : 'لم يُقيَّم بعد'}
          </span>
        }
        footer={
          <div className="space-y-3 pt-3 text-xs">
            <div className="flex flex-wrap items-center gap-2">
              <ApprovalBadge status={factory.approval.status} size="sm" />
              <ReadinessBadge category={readiness?.category ?? null} size="sm" />
            </div>
            <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-[11px] text-[#667085]">
              <div className="flex items-center gap-1.5">
                <Send className="w-3.5 h-3.5 shrink-0" />
                <dt className="sr-only">طلبات الخدمة</dt>
                <dd>{factory.service_requests_count ?? 0} طلب خدمة</dd>
              </div>
              <div className="flex items-center gap-1.5">
                <CalendarDays className="w-3.5 h-3.5 shrink-0" />
                <dt className="sr-only">تاريخ التسجيل</dt>
                <dd>سُجّلت {formatDate(factory.created_at)}</dd>
              </div>
              <div className="flex items-center gap-1.5 col-span-2">
                <ClipboardCheck className="w-3.5 h-3.5 shrink-0" />
                <dt className="sr-only">آخر تقييم</dt>
                <dd>{readiness ? `آخر تقييم ${formatDate(readiness.completed_at)} (الإصدار ${readiness.questionnaire_version ?? '—'})` : 'لم تُكمل تقييم الجاهزية'}</dd>
              </div>
            </dl>
            {factory.approval.reason && factory.approval.status !== 'approved' && (
              <p className="p-2 rounded-lg bg-[#FEF5E7] text-[#A66F0B] leading-relaxed line-clamp-2" title={factory.approval.reason}>
                {factory.approval.reason}
              </p>
            )}
            <div className="flex flex-wrap gap-1.5 pt-2 border-t border-[#F1F4F9]">
              <Button size="sm" variant="secondary" icon={Eye} onClick={onDetails}>
                الملف الكامل
              </Button>
              <Link
                to={reviewPath}
                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-[#E6EAF0] text-[#344054] font-semibold hover:bg-[#F7F9FC]"
              >
                <FileSearch className="w-3.5 h-3.5" /> المراجعة والوثائق
              </Link>
              {readiness && (
                <Link
                  to={`/admin/readiness?tab=results&factory=${factory.id}`}
                  className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-[#E6EAF0] text-[#344054] font-semibold hover:bg-[#F7F9FC]"
                >
                  <ClipboardCheck className="w-3.5 h-3.5" /> نتائج التقييم
                </Link>
              )}
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
