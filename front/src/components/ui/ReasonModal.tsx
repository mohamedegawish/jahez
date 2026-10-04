import React, { useState } from 'react';
import { fieldMessages } from '../../api';
import { useApiMutation, type MutationResult } from '../../hooks/useApiMutation';
import { fieldErrorClass } from '../../lib/forms';
import { ApiErrorState } from './ApiErrorState';
import { Button } from './Button';
import { FieldError } from './FieldError';
import { Modal } from './Modal';

interface ReasonModalProps<T> {
  title: string;
  subtitle?: string;
  /** Explains what will happen, including side effects the user should know about. */
  description: string;
  confirmLabel: string;
  variant?: 'primary' | 'danger' | 'success';
  /** When true the confirm button stays disabled until a reason is written. */
  required?: boolean;
  label?: string;
  /** Runs the request with the (trimmed, possibly empty) reason. Errors are shown in the modal. */
  onConfirm: (reason: string) => Promise<T>;
  onDone: (result: T) => void;
  onClose: () => void;
  /** Extra inputs shown under the description (a date, for example). */
  children?: React.ReactNode;
}

/**
 * Confirmation dialog for actions that take an optional or required free-text reason (decline,
 * withdraw, cancel). A 409/422/429 from the API is shown inside the dialog and nothing is
 * reported as done unless the server accepted the action.
 */
export function ReasonModal<T>({
  title,
  subtitle,
  description,
  confirmLabel,
  variant = 'primary',
  required = false,
  label,
  onConfirm,
  onDone,
  onClose,
  children,
}: ReasonModalProps<T>) {
  const [reason, setReason] = useState('');
  const run = useApiMutation(() => onConfirm(reason.trim()));
  const reasonErrors = fieldMessages(run.error, 'reason');

  const handleConfirm = async () => {
    const result: MutationResult<T> = await run.run();
    if (result.ok) onDone(result.data);
  };

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={title}
      subtitle={subtitle}
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={run.pending}>
            إلغاء
          </Button>
          <Button variant={variant} size="sm" onClick={handleConfirm} isLoading={run.pending} disabled={required && reason.trim() === ''}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <p className="text-[#667085] leading-relaxed">{description}</p>
        {children}
        <label htmlFor="reason-text" className="font-bold text-[#172033] block">
          {label ?? (required ? 'السبب (مطلوب):' : 'السبب (اختياري):')}
        </label>
        <textarea
          id="reason-text"
          rows={4}
          value={reason}
          maxLength={2000}
          onChange={(e) => setReason(e.target.value)}
          className={`w-full p-3 rounded-xl border border-[#E6EAF0] text-xs focus:outline-none focus:border-[#6EC8FF] ${fieldErrorClass(reasonErrors.length > 0)}`}
        />
        <FieldError messages={reasonErrors} />
        {run.error !== null && reasonErrors.length === 0 && <ApiErrorState compact error={run.error} />}
      </div>
    </Modal>
  );
}
