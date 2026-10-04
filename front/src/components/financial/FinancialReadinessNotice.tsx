import React from 'react';
import { CheckCircle2, CircleSlash } from 'lucide-react';
import { api } from '../../api';
import type { AgreementFinancialReadiness } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';

const OPERATIONS: { key: keyof AgreementFinancialReadiness; label: string }[] = [
  { key: 'contract_drafting', label: 'إعداد مسودة العقد' },
  { key: 'invoice_drafting', label: 'إعداد مسودة الفاتورة' },
  { key: 'invoice_issuing', label: 'إصدار الفاتورة' },
  { key: 'gateway_payment', label: 'الدفع الإلكتروني' },
  { key: 'contract_signature', label: 'توقيع العقد' },
  { key: 'payouts', label: 'تحويل المستحقات' },
];

/**
 * For one agreement: which financial operations are possible today and, for each blocked one,
 * every reason the server gives, in Arabic (ADR-023). Nothing here is decided by the browser.
 */
export const FinancialReadinessNotice: React.FC<{ agreementId: number }> = ({ agreementId }) => {
  const readiness = useApiQuery((signal) => api.agreements.financialReadiness(agreementId, signal), [agreementId]);

  return (
    <div className="p-3 rounded-xl border border-[#E6EAF0] bg-[#F7F9FC] text-xs space-y-2" data-testid="financial-readiness">
      <span className="font-bold text-[#172033] block">العمليات المالية لهذه الاتفاقية</span>
      <QueryBoundary query={readiness} loading={<CardSkeleton />}>
        {(data) => (
          <ul className="space-y-1.5">
            {OPERATIONS.map(({ key, label }) => {
              const operation = data[key];
              return (
                <li key={key} data-operation={key} data-available={operation.available}>
                  <div className={`flex items-center gap-1.5 font-semibold ${operation.available ? 'text-[#1D7E4C]' : 'text-[#A66F0B]'}`}>
                    {operation.available ? <CheckCircle2 className="w-3.5 h-3.5" /> : <CircleSlash className="w-3.5 h-3.5" />}
                    {label}: {operation.available ? 'متاحة' : 'موقوفة'}
                  </div>
                  {operation.reasons.map((reason) => (
                    <p key={reason.code} className="text-[11px] text-[#667085] pr-5">
                      {reason.message_ar}
                      {reason.decision_needed ? ` (${reason.decision_needed})` : ''}
                    </p>
                  ))}
                </li>
              );
            })}
          </ul>
        )}
      </QueryBoundary>
    </div>
  );
};
