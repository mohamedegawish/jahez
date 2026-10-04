import React from 'react';
import { Ban, Hourglass } from 'lucide-react';
import { decisionLabel } from '../../lib/labels';

interface UnavailableNoticeProps {
  /**
   * `gap`      - the API has no endpoint for this feature (API GAP).
   * `decision` - the API supports it only once the owner decides a business rule (OQ).
   */
  kind: 'gap' | 'decision';
  title: string;
  children?: React.ReactNode;
  /** Open question(s) the feature waits for, for example "OQ-16" or "OQ-15, OQ-16". */
  decisionNeeded?: string | null;
  className?: string;
}

/**
 * Honest placeholder for a feature the backend does not support yet. It never pretends to
 * save or process anything; use it instead of a fake success.
 */
export const UnavailableNotice: React.FC<UnavailableNoticeProps> = ({ kind, title, children, decisionNeeded, className = '' }) => {
  const Icon = kind === 'gap' ? Ban : Hourglass;
  const tag = kind === 'gap' ? 'غير متاح حاليًا' : 'بانتظار قرار';
  const decision = decisionLabel(decisionNeeded);

  return (
    <div className={`flex items-start gap-3 p-4 rounded-xl border border-dashed border-[#CCD5E2] bg-[#F7F9FC] text-xs ${className}`}>
      <div className="w-8 h-8 rounded-lg bg-white border border-[#E6EAF0] text-[#667085] flex items-center justify-center shrink-0">
        <Icon className="w-4 h-4" />
      </div>
      <div className="space-y-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-bold text-[#172033]">{title}</span>
          <span className="px-2 py-0.5 rounded-md bg-[#F1F4F9] border border-[#E2E8F0] text-[10px] font-bold text-[#475467]">{tag}</span>
        </div>
        {children && <div className="text-[#667085] leading-relaxed">{children}</div>}
        {decision && <div className="text-[#0A6EB0] font-semibold">القرار المطلوب: {decision}</div>}
      </div>
    </div>
  );
};
