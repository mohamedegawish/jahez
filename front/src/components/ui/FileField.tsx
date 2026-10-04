import React, { useId, useState } from 'react';
import { Paperclip, X } from 'lucide-react';
import { formatBytes, localFileProblem } from '../../lib/files';
import { FieldError } from './FieldError';

interface FileFieldProps {
  label: string;
  /** The `accept` attribute: extensions and MIME types. */
  accept: string;
  maxKb: number;
  value: File | null;
  onChange: (file: File | null) => void;
  /** Server (422) messages for this field. */
  messages?: string[];
  hint?: string;
  disabled?: boolean;
}

/** A single optional file input with a local type/size check; the API validates the content again. */
export const FileField: React.FC<FileFieldProps> = ({ label, accept, maxKb, value, onChange, messages = [], hint, disabled = false }) => {
  const id = useId();
  const [localError, setLocalError] = useState<string | null>(null);
  const extensions = accept.split(',').filter((entry) => entry.startsWith('.')).map((entry) => entry.slice(1).toUpperCase());

  const handle = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0] ?? null;
    event.target.value = '';
    if (file === null) return;
    const problem = localFileProblem(file, accept, maxKb);
    setLocalError(problem);
    onChange(problem === null ? file : null);
  };

  return (
    <div className="text-xs">
      <span className="font-bold text-[#172033] block mb-1">{label}</span>
      <div className={`flex items-center gap-2 p-2.5 rounded-xl border border-dashed ${messages.length > 0 || localError ? 'border-[#E45B6A]' : 'border-[#CCD5E2]'} bg-[#F7F9FC]`}>
        <label
          htmlFor={id}
          className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-[#E6EAF0] font-bold text-[#5146A5] ${disabled ? 'opacity-50' : 'cursor-pointer hover:bg-[#EEEAFE]'}`}
        >
          <Paperclip className="w-3.5 h-3.5" />
          اختيار ملف
        </label>
        <input id={id} type="file" accept={accept} className="sr-only" onChange={handle} disabled={disabled} />
        {value ? (
          <span className="flex items-center gap-1.5 min-w-0 text-[#172033]">
            <span className="truncate" dir="auto">{value.name}</span>
            <span className="text-[#98A2B3] shrink-0">({formatBytes(value.size)})</span>
            <button
              type="button"
              onClick={() => onChange(null)}
              disabled={disabled}
              className="p-0.5 rounded text-[#98A2B3] hover:text-[#B82B3B] cursor-pointer"
              aria-label={`إزالة الملف ${value.name}`}
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </span>
        ) : (
          <span className="text-[#98A2B3]">{extensions.join('، ')} — حتى {formatBytes(maxKb * 1024)}</span>
        )}
      </div>
      {hint && <p className="text-[11px] text-[#98A2B3] mt-1">{hint}</p>}
      <FieldError messages={localError ? [localError, ...messages] : messages} />
    </div>
  );
};
