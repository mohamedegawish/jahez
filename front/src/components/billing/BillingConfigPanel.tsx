import React from 'react';
import { CheckCircle2, Hourglass } from 'lucide-react';
import { api } from '../../api';
import type { BillingConfiguration } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { decisionLabel } from '../../lib/labels';
import { Card } from '../ui/Card';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';

const CAPABILITIES: { key: keyof BillingConfiguration; label: string }[] = [
  { key: 'invoice_drafting', label: 'إعداد مسودات الفواتير' },
  { key: 'invoice_issuing', label: 'إصدار الفواتير' },
  { key: 'payments', label: 'الدفع عبر البوابة' },
  { key: 'revenue_share', label: 'اقتسام الإيراد' },
  { key: 'contract_templates', label: 'نماذج العقود' },
  { key: 'refund_initiation', label: 'استرداد المبالغ' },
  { key: 'payouts', label: 'تحويل مستحقات المزودين' },
];

/**
 * Which policies are approved and in effect somewhere on the platform today (GET /billing/configuration,
 * ADR-023). Whether an operation is possible for one agreement also depends on the policy scope: see
 * the agreement's financial readiness. A blocked operation answers 409 `policy_not_configured`.
 */
export const BillingConfigPanel: React.FC = () => {
  const config = useApiQuery((signal) => api.billing.configuration(signal), []);

  return (
    <Card title="حالة السياسة المالية" subtitle="القواعد المالية التي اعتمدها مركز تحديث الصناعة حتى الآن" accent="blue">
      <QueryBoundary query={config} loading={<CardSkeleton />}>
        {(c) => (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" data-testid="billing-config">
            {CAPABILITIES.map(({ key, label }) => {
              const capability = c[key];
              return (
                <div
                  key={key}
                  data-capability={key}
                  data-available={capability.available}
                  className={`p-3 rounded-xl border text-xs ${capability.available ? 'bg-[#E7F8EE] border-[#C5F0D5]' : 'bg-[#F7F9FC] border-[#E6EAF0]'}`}
                >
                  <div className="flex items-center gap-1.5 font-bold text-[#172033]">
                    {capability.available ? <CheckCircle2 className="w-4 h-4 text-[#1D7E4C]" /> : <Hourglass className="w-4 h-4 text-[#98A2B3]" />}
                    {label}
                  </div>
                  <div className={`mt-1 ${capability.available ? 'text-[#1D7E4C]' : 'text-[#667085]'}`}>
                    {capability.available ? 'توجد سياسة معتمدة سارية' : `غير مُفعَّل — لا سياسة معتمدة سارية: ${decisionLabel(capability.decision_needed)}`}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </QueryBoundary>
    </Card>
  );
};
