import React, { useId } from 'react';
import { fieldErrorClass } from '../../lib/forms';
import { FieldError } from './FieldError';

interface TextFieldProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  /** Server (422) messages for this field. */
  messages?: string[];
  required?: boolean;
  type?: 'text' | 'email' | 'tel' | 'url' | 'number';
  dir?: 'ltr' | 'rtl' | 'auto';
  placeholder?: string;
  maxLength?: number;
  /** Render a multi-line textarea with this many rows. */
  rows?: number;
  hint?: string;
  disabled?: boolean;
  readOnly?: boolean;
  autoComplete?: string;
}

const inputClass = 'w-full p-2.5 rounded-xl border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF] disabled:bg-[#F7F9FC] read-only:bg-[#F7F9FC] read-only:text-[#475467]';

/** A labelled input with its server validation messages, shared by the registration and profile forms. */
export const TextField: React.FC<TextFieldProps> = ({
  label,
  value,
  onChange,
  messages = [],
  required = false,
  type = 'text',
  dir,
  placeholder,
  maxLength,
  rows,
  hint,
  disabled = false,
  readOnly = false,
  autoComplete,
}) => {
  const id = useId();
  const className = `${inputClass} ${fieldErrorClass(messages.length > 0)}`;
  const common = {
    id,
    value,
    disabled,
    readOnly,
    placeholder,
    maxLength,
    dir,
    required,
    'aria-invalid': messages.length > 0 || undefined,
    className,
  };

  return (
    <div className="text-xs">
      <label htmlFor={id} className="font-bold text-[#172033] block mb-1">
        {label}
        {required && <span className="text-[#E45B6A] mr-0.5" aria-hidden="true">*</span>}
      </label>
      {rows ? (
        <textarea {...common} rows={rows} onChange={(e) => onChange(e.target.value)} />
      ) : (
        <input {...common} type={type} autoComplete={autoComplete} onChange={(e) => onChange(e.target.value)} />
      )}
      {hint && <p className="text-[11px] text-[#98A2B3] mt-1">{hint}</p>}
      <FieldError messages={messages} />
    </div>
  );
};
