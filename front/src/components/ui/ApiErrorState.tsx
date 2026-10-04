import React from 'react';
import { AlertTriangle, CloudOff, Info, Lock, RefreshCw, SearchX, ShieldAlert } from 'lucide-react';
import { describeError, isApiError, isForbidden, isNetworkError, isNotFound, isPolicyNotConfigured } from '../../api/errors';
import { Button } from './Button';

const TONES = {
  warning: 'bg-[#FEF5E7] border-[#FDE5BE] text-[#A66F0B]',
  error: 'bg-[#FDECEE] border-[#F9C3C9] text-[#B82B3B]',
  info: 'bg-[#DFF3FF] border-[#BDE5FD] text-[#0A6EB0]',
} as const;

function iconFor(error: unknown, className: string) {
  if (isNetworkError(error)) return <CloudOff className={className} />;
  if (isPolicyNotConfigured(error)) return <Info className={className} />;
  if (isForbidden(error)) return <Lock className={className} />;
  if (isNotFound(error)) return <SearchX className={className} />;
  if (isApiError(error) && error.status === 409) return <ShieldAlert className={className} />;
  return <AlertTriangle className={className} />;
}

interface ApiErrorStateProps {
  error: unknown;
  /** Shown as a "retry" button when the error can succeed later (network, 429, 5xx). */
  onRetry?: () => void;
  /** Inline banner (forms, action bars) instead of a full empty-area panel. */
  compact?: boolean;
  className?: string;
}

/**
 * One place that turns any API failure into a clear Arabic message: 401, 403, 404, 409,
 * 409 policy_not_configured (names the open question), 422, 429 (with Retry-After), 5xx
 * (with the request id) and network/CORS failures. Use it instead of an empty list.
 */
export const ApiErrorState: React.FC<ApiErrorStateProps> = ({ error, onRetry, compact = false, className = '' }) => {
  const info = describeError(error);

  if (compact) {
    return (
      <div role="alert" className={`flex items-start gap-2.5 p-3 rounded-xl border text-xs ${TONES[info.tone]} ${className}`}>
        {iconFor(error, "w-4 h-4 shrink-0 mt-0.5")}
        <div className="space-y-0.5 min-w-0">
          <div className="font-bold leading-relaxed">{info.title}</div>
          {info.detail && <div className="opacity-80 break-words" dir="auto">{info.detail}</div>}
          {info.retryable && onRetry && (
            <button type="button" onClick={onRetry} className="mt-1 inline-flex items-center gap-1 font-bold underline cursor-pointer">
              <RefreshCw className="w-3 h-3" /> إعادة المحاولة
            </button>
          )}
        </div>
      </div>
    );
  }

  return (
    <div
      role="alert"
      className={`flex flex-col items-center justify-center p-8 sm:p-12 text-center rounded-2xl border ${TONES[info.tone]} ${className}`}
    >
      <div className="w-14 h-14 rounded-2xl bg-white/70 flex items-center justify-center mb-4">
        {iconFor(error, "w-7 h-7")}
      </div>
      <h3 className="text-base font-bold mb-1 max-w-md leading-relaxed">{info.title}</h3>
      {info.detail && <p className="text-xs opacity-80 max-w-md mb-4 break-words" dir="auto">{info.detail}</p>}
      {info.retryable && onRetry && (
        <Button variant="outline" size="sm" icon={RefreshCw} onClick={onRetry}>
          إعادة المحاولة
        </Button>
      )}
    </div>
  );
};
