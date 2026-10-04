import React from 'react';
import { ArrowDown, ArrowUp } from 'lucide-react';

export const IconButton: React.FC<{ label: string; disabled?: boolean; onClick: () => void; children: React.ReactNode }> = ({ label, disabled, onClick, children }) => (
  <button
    type="button"
    aria-label={label}
    title={label}
    disabled={disabled}
    onClick={onClick}
    className="p-1.5 rounded-lg border border-[#E6EAF0] bg-white text-[#667085] hover:text-[#172033] disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
  >
    {children}
  </button>
);

/** Up / down buttons for reordering, hidden when the version is read-only. */
export const ReorderButtons: React.FC<{ editable: boolean; index: number; length: number; label: string; onMove: (offset: -1 | 1) => void }> = ({
  editable,
  index,
  length,
  label,
  onMove,
}) =>
  editable ? (
    <div className="flex items-center gap-1 shrink-0">
      <IconButton label={`تحريك ${label} للأعلى`} disabled={index === 0} onClick={() => onMove(-1)}>
        <ArrowUp className="w-3.5 h-3.5" />
      </IconButton>
      <IconButton label={`تحريك ${label} للأسفل`} disabled={index === length - 1} onClick={() => onMove(1)}>
        <ArrowDown className="w-3.5 h-3.5" />
      </IconButton>
    </div>
  ) : null;
