import React, { useState } from 'react';
import { Calculator, Search } from 'lucide-react';
import { api } from '../../api';
import type { FinancialPolicyKind, FinancialPolicyResolution, FinancialPreview } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useCatalogServices, useSectors } from '../../hooks/useReference';
import { formatDate, formatMoneyOf } from '../../lib/format';
import { AMOUNT, EFFECTIVE_STATUS, KIND_LABELS, businessToday, describeParameters } from '../../lib/financialPolicies';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';

/**
 * Which approved policy applies to a service, sector and provider on a day, and what an
 * invoice amount comes to under the policies that apply (ADR-023). Everything is calculated by
 * the server; nothing here is stored.
 */
export const PolicyResolverPanel: React.FC = () => {
  const sectors = useSectors();
  const services = useCatalogServices();
  const [kind, setKind] = useState<FinancialPolicyKind>('revenue_share');
  const [service, setService] = useState('');
  const [sector, setSector] = useState('');
  const [provider, setProvider] = useState('');
  const [date, setDate] = useState(businessToday());
  const [amount, setAmount] = useState('');
  const [resolution, setResolution] = useState<FinancialPolicyResolution | null>(null);
  const [preview, setPreview] = useState<FinancialPreview | null>(null);

  const context = () => ({
    ...(service ? { catalog_service: Number(service) } : {}),
    ...(sector ? { sectors: [sector] } : {}),
    ...(/^\d+$/.test(provider) ? { service_provider: Number(provider) } : {}),
    date,
  });
  const resolve = useApiMutation(() => api.financialPolicies.resolve({ kind, ...context() }));
  const calculate = useApiMutation(() => api.financialPolicies.preview({ amount: amount.trim(), ...context() }));

  const select = 'w-full p-2.5 rounded-xl border border-[#E6EAF0] bg-white';

  return (
    <Card title="التحقق من السياسة المطبّقة" subtitle="اختر الخدمة والقطاع والمزود والتاريخ لمعرفة الإصدار الذي ينطبق وسبب إيقاف أي عملية" accent="blue">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 text-xs">
        <label className="space-y-1"><span className="font-semibold text-[#344054]">الخدمة</span>
          <select value={service} onChange={(e) => setService(e.target.value)} className={select}>
            <option value="">أي خدمة</option>
            {(services.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name_ar}</option>)}
          </select>
        </label>
        <label className="space-y-1"><span className="font-semibold text-[#344054]">القطاع</span>
          <select value={sector} onChange={(e) => setSector(e.target.value)} className={select}>
            <option value="">أي قطاع</option>
            {(sectors.data ?? []).map((s) => <option key={s.code} value={s.code}>{s.name_ar}</option>)}
          </select>
        </label>
        <label className="space-y-1"><span className="font-semibold text-[#344054]">رقم مزود الخدمة</span>
          <input value={provider} onChange={(e) => setProvider(e.target.value)} dir="ltr" className={select} placeholder="اختياري" />
        </label>
        <label className="space-y-1"><span className="font-semibold text-[#344054]">التاريخ</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} dir="ltr" className={select} />
        </label>
      </div>

      <div className="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="space-y-3 p-3 rounded-xl border border-[#E6EAF0]">
          <div className="flex flex-wrap items-end gap-2 text-xs">
            <label className="space-y-1"><span className="font-semibold text-[#344054]">نوع السياسة</span>
              <select value={kind} onChange={(e) => setKind(e.target.value as FinancialPolicyKind)} className={select}>
                {(Object.keys(KIND_LABELS) as FinancialPolicyKind[]).map((k) => <option key={k} value={k}>{KIND_LABELS[k]}</option>)}
              </select>
            </label>
            <Button variant="secondary" size="sm" icon={Search} isLoading={resolve.pending} onClick={async () => { const r = await resolve.run(); if (r.ok) setResolution(r.data); }} data-action="resolve-policy">
              تحقّق
            </Button>
          </div>
          {resolve.error !== null && <ApiErrorState compact error={resolve.error} />}
          {resolution && (
            resolution.version ? (
              <div className="text-xs space-y-1.5" data-testid="resolution">
                <div className="flex items-center gap-2">
                  <Badge size="sm" variant={EFFECTIVE_STATUS[resolution.version.effective_status].tone}>{EFFECTIVE_STATUS[resolution.version.effective_status].label}</Badge>
                  <span className="font-bold text-[#172033]">{resolution.version.policy?.name_ar} — الإصدار {resolution.version.version}</span>
                </div>
                <div className="text-[#667085]">النطاق: {resolution.version.policy?.scope.label_ar} {resolution.version.policy?.scope.type !== 'global' ? resolution.version.policy?.scope.name : ''} · من {formatDate(resolution.version.effective_from)}</div>
                {describeParameters(resolution.kind, resolution.version.parameters).map((row) => <div key={row.label}>{row.label}: <b>{row.value}</b></div>)}
              </div>
            ) : (
              <p className="text-xs text-[#A66F0B] p-2.5 rounded-lg bg-[#FEF5E7] border border-[#FDE5BE]" role="alert" data-testid="resolution-missing">{resolution.reason_ar}</p>
            )
          )}
        </div>

        <div className="space-y-3 p-3 rounded-xl border border-[#E6EAF0]">
          <div className="flex flex-wrap items-end gap-2 text-xs">
            <label className="space-y-1"><span className="font-semibold text-[#344054]">مجموع فرعي تجريبي (ج.م)</span>
              <input value={amount} onChange={(e) => setAmount(e.target.value)} dir="ltr" inputMode="decimal" className={select} />
            </label>
            <Button variant="secondary" size="sm" icon={Calculator} isLoading={calculate.pending} disabled={!AMOUNT.test(amount.trim())} onClick={async () => { const r = await calculate.run(); if (r.ok) setPreview(r.data); }} data-action="preview-invoice">
              معاينة الفاتورة
            </Button>
          </div>
          {calculate.error !== null && <ApiErrorState compact error={calculate.error} />}
          {preview && (
            <div className="text-xs space-y-1.5" data-testid="invoice-preview">
              <Line label="صافي الخدمة" value={formatMoneyOf(preview.calculation.net_service_amount)} />
              {preview.calculation.fees.map((fee) => <Line key={fee.code} label={`رسم: ${fee.name_ar}`} value={formatMoneyOf(fee.amount)} />)}
              {preview.calculation.taxes.map((tax) => <Line key={tax.code} label={`${tax.name_ar} ${tax.rate_percent}%`} value={formatMoneyOf(tax.amount)} />)}
              <Line label="الإجمالي" value={formatMoneyOf(preview.calculation.total)} strong />
              <Line label="حصة الوزارة (للاطلاع)" value={preview.calculation.revenue_share ? formatMoneyOf(preview.calculation.revenue_share.amount) : 'لا تنطبق سياسة معتمدة'} />
              <Line label="تاريخ الاستحقاق" value={preview.due_date ? formatDate(preview.due_date) : 'غير محدد — لا توجد شروط سداد معتمدة'} />
              {preview.missing_ar.map((message) => <p key={message} className="text-[#A66F0B]">{message}</p>)}
            </div>
          )}
        </div>
      </div>
    </Card>
  );
};

const Line: React.FC<{ label: string; value: string; strong?: boolean }> = ({ label, value, strong }) => (
  <div className="flex items-center justify-between gap-3">
    <span className="text-[#667085]">{label}</span>
    <span className={strong ? 'font-extrabold text-[#35B779]' : 'font-semibold text-[#172033]'}>{value}</span>
  </div>
);
