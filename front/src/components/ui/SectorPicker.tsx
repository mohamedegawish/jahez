import React from 'react';
import { Check } from 'lucide-react';
import type { Sector } from '../../api';
import { useSectors } from '../../hooks/useReference';
import { ApiErrorState } from './ApiErrorState';
import { Skeleton } from './LoadingState';

interface SectorPickerProps {
  /** Selected sector codes. */
  value: string[];
  onChange: (codes: string[]) => void;
  disabled?: boolean;
  className?: string;
}

/**
 * Sector checkboxes backed by `GET /reference/sectors` (no hard-coded list). The API limits how
 * many sectors one organization may have and says so with a 422 on `sectors`, shown by the caller.
 */
export const SectorPicker: React.FC<SectorPickerProps> = (props) => {
  const sectors = useSectors();

  if (sectors.status === 'loading') return <Skeleton className="h-9 w-full" />;
  if (sectors.status === 'error') return <ApiErrorState compact error={sectors.error} />;

  return <SectorChoice {...props} sectors={sectors.data ?? []} />;
};

/** The sector checkboxes for a list the caller already has (the public registration options). */
export const SectorChoice: React.FC<SectorPickerProps & { sectors: Sector[] }> = ({ value, onChange, sectors, disabled = false, className = '' }) => {
  const toggle = (code: string) => onChange(value.includes(code) ? value.filter((c) => c !== code) : [...value, code]);

  return (
    <div className={`flex flex-wrap gap-2 ${className}`} role="group" aria-label="القطاعات الصناعية">
      {sectors.map((sector) => {
        const selected = value.includes(sector.code);
        return (
          <button
            key={sector.code}
            type="button"
            disabled={disabled}
            aria-pressed={selected}
            data-sector={sector.code}
            onClick={() => toggle(sector.code)}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed ${
              selected
                ? 'bg-[#EEEAFE] border-[#5146A5] text-[#5146A5]'
                : 'bg-white border-[#E6EAF0] text-[#667085] hover:border-[#CCD5E2]'
            }`}
          >
            {selected && <Check className="w-3.5 h-3.5" />}
            {sector.name_ar}
          </button>
        );
      })}
    </div>
  );
};
