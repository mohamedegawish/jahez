import React from 'react';

interface FieldErrorProps {
  /** One message, several, or nothing (renders nothing). Typically `fieldMessages(error, 'name')`. */
  messages?: string | string[] | null;
  className?: string;
}

/** Server (Laravel 422) validation message under a form field. */
export const FieldError: React.FC<FieldErrorProps> = ({ messages, className = '' }) => {
  const list = (Array.isArray(messages) ? messages : [messages]).filter((m): m is string => Boolean(m));
  if (list.length === 0) return null;
  return (
    <div role="alert" className={`mt-1 space-y-0.5 text-[11px] font-medium text-[#B82B3B] ${className}`} dir="auto">
      {list.map((message, i) => (
        <div key={i}>{message}</div>
      ))}
    </div>
  );
};

