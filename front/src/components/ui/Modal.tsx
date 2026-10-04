import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '4xl';
  footer?: React.ReactNode;
}

export const Modal: React.FC<ModalProps> = ({
  isOpen,
  onClose,
  title,
  subtitle,
  children,
  maxWidth = 'lg',
  footer
}) => {
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    if (isOpen) {
      document.body.style.overflow = 'hidden';
      window.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.body.style.overflow = 'unset';
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const maxWidthStyles = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    '4xl': 'max-w-4xl'
  };

  // Rendered on <body>: a modal opened from inside a card (whose hover transform makes it the
  // containing block of `fixed` children) or from another modal would otherwise be clipped to it.
  return createPortal(
    <div className="fixed inset-0 z-50 overflow-y-auto">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-[#172033]/40 backdrop-blur-xs transition-opacity duration-200"
        onClick={onClose}
      />

      {/* relative z-10: the fixed backdrop (positioned, z-auto) otherwise paints
          ABOVE this static wrapper, swallowing every click inside the panel. */}
      <div className="relative z-10 flex min-h-full items-center justify-center p-4 text-center">
        <div
          className={`w-full ${maxWidthStyles[maxWidth]} transform overflow-hidden rounded-2xl bg-white text-right shadow-2xl transition-all border border-[#E6EAF0] my-8 animate-in fade-in zoom-in-95 duration-200`}
        >
          {/* Header */}
          <div className="flex items-center justify-between border-b border-[#E6EAF0] px-6 py-4">
            <div>
              <h3 className="text-lg font-bold text-[#172033]">{title}</h3>
              {subtitle && <p className="text-xs text-[#667085] mt-0.5">{subtitle}</p>}
            </div>
            <button
              onClick={onClose}
              className="rounded-lg p-1.5 text-[#667085] hover:bg-[#F1F4F9] hover:text-[#172033] transition-colors cursor-pointer"
            >
              <X className="w-5 h-5" />
            </button>
          </div>

          {/* Body */}
          <div className="px-6 py-5 max-h-[75vh] overflow-y-auto">
            {children}
          </div>

          {/* Footer */}
          {footer && (
            <div className="flex items-center justify-end gap-3 border-t border-[#E6EAF0] bg-[#F7F9FC] px-6 py-3.5 rounded-b-2xl">
              {footer}
            </div>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
};
