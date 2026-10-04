import React from 'react';
import { useApiMutation } from '../../hooks/useApiMutation';
import { ApiErrorState } from './ApiErrorState';
import { Button } from './Button';
import { Modal } from './Modal';

interface ConfirmModalProps<T> {
  title: string;
  subtitle?: string;
  /** Spell out what the action changes, including consequences for others. */
  description: React.ReactNode;
  confirmLabel: string;
  variant?: 'primary' | 'danger' | 'success';
  onConfirm: () => Promise<T>;
  onDone: (result: T) => void;
  onClose: () => void;
}

/**
 * Confirmation for an action without free text. A 409/422/429 from the API stays inside the dialog
 * (nothing is reported as done unless the server accepted the action).
 */
export function ConfirmModal<T>({ title, subtitle, description, confirmLabel, variant = 'primary', onConfirm, onDone, onClose }: ConfirmModalProps<T>) {
  const run = useApiMutation(onConfirm);

  const handleConfirm = async () => {
    const result = await run.run();
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
          <Button variant={variant} size="sm" onClick={handleConfirm} isLoading={run.pending}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <div className="text-[#667085] leading-relaxed">{description}</div>
        {run.error !== null && <ApiErrorState compact error={run.error} />}
      </div>
    </Modal>
  );
}
