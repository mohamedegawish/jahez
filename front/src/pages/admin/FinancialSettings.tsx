import React, { useState } from 'react';
import { ShieldAlert } from 'lucide-react';
import { api } from '../../api';
import type { FinancialPolicy } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate } from '../../lib/format';
import { EFFECTIVE_STATUS, KIND_LABELS, SCOPE_LABELS } from '../../lib/financialPolicies';
import { PolicyDetailModal } from '../../components/financial/PolicyDetailModal';
import { PolicyKindPanel } from '../../components/financial/PolicyKindPanel';
import { PolicyResolverPanel } from '../../components/financial/PolicyResolverPanel';
import { Badge } from '../../components/ui/Badge';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { TableSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { Tabs } from '../../components/ui/Tabs';

const TABS = [
  { id: 'revenue_share', label: 'حصة الوزارة والعمولة' },
  { id: 'invoicing', label: 'سياسات الفواتير والسداد' },
  { id: 'tax', label: 'إعدادات الضرائب' },
  { id: 'contracts', label: 'نماذج العقود ودورة حياتها' },
  { id: 'versions', label: 'الإصدارات والاعتمادات وسجل التدقيق' },
];

/**
 * «الإعدادات المالية والتعاقدية» (ADR-023). Every value is an approved, versioned policy stored
 * by the API; this page holds no rate, tax, issuer or term of its own. Preparing and approving
 * are separate permissions held by different administrators, and the server enforces both.
 */
export const FinancialSettings: React.FC = () => {
  const [tab, setTab] = useState('revenue_share');
  const { hasPermission } = useAuth();

  return (
    <div className="space-y-6" dir="rtl">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">الإعدادات المالية والتعاقدية</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5 max-w-3xl leading-relaxed">
          سياسات مُصدَّرة بإصدارات وتواريخ سريان. يُعدّ المسودة مسؤول، ويعتمدها مسؤول آخر، ولا يُعدَّل أي إصدار بعد اعتماده. تحتفظ الاتفاقيات
          والعقود والفواتير بالإصدار الذي أُنشئت بموجبه. ما لم تُعتمد سياسة سارية تبقى العمليات التي تحتاجها موقوفة، ولا تُستخدم أي قيمة افتراضية.
        </p>
      </div>
      <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-[11px] text-[#667085] flex items-start gap-2">
        <ShieldAlert className="w-4 h-4 shrink-0 text-[#7A5AF8]" />
        <span>
          صلاحياتك: {hasPermission('financial_policies.manage') ? 'إعداد السياسات' : 'لا إعداد'} · {hasPermission('financial_policies.approve') ? 'اعتماد السياسات' : 'لا اعتماد'} ·{' '}
          {hasPermission('payments.record') ? 'تسجيل المدفوعات اليدوية' : 'لا تسجيل مدفوعات'}. تُمنح هذه الصلاحيات لأشخاص محددين من خادم المنصة، وليست لكل المسؤولين. اعتماد سياسة هنا لا
          يعني استيفاء المتطلبات القانونية أو الضريبية أو التعاقدية تلقائيًا.
        </span>
      </div>

      <Tabs tabs={TABS} activeTab={tab} onChange={setTab} />

      {tab === 'revenue_share' && (
        <PolicyKindPanel kind="revenue_share" description="نسبة مئوية من المجموع الفرعي قبل الضريبة، تظهر على الفواتير للاطلاع فقط؛ لا تُصرف أي مبالغ. يمكن تحديدها لكل خدمة أو قطاع أو مزود (OQ-15)." />
      )}
      {tab === 'invoicing' && (
        <div className="space-y-6">
          <PolicyKindPanel kind="invoicing" description="الجهة المُصدِرة والملزَمة بالسداد، صيغة الترقيم، أنواع الفواتير، والسماح بتسجيل مدفوعات يدوية (OQ-16). المسودة الداخلية ليست فاتورة رسمية حتى تُصدَر." />
          <PolicyKindPanel kind="payment_terms" description="عدد أيام الاستحقاق بعد الإصدار وقبول السداد الجزئي. التأخر يُحسب من تاريخ الاستحقاق ولا يُخزَّن (OQ-16)." />
        </div>
      )}
      {tab === 'tax' && (
        <PolicyKindPanel kind="tax" description="الضرائب والرسوم وهل الأسعار شاملة الضريبة. لا تُفترض أي نسبة؛ والقائمة الفارغة المعتمدة تعني عدم وجود ضريبة (OQ-16)." />
      )}
      {tab === 'contracts' && (
        <PolicyKindPanel kind="contract_template" description="بنود النموذج وأطرافه ومدته والحد الأدنى لنقل المعرفة. المسودات المولّدة غير ملزمة، ولا يتغير عقد سابق عند تعديل النموذج (OQ-17)." />
      )}
      {tab === 'versions' && (
        <div className="space-y-6">
          <AllPolicies />
          <PolicyResolverPanel />
        </div>
      )}
    </div>
  );
};

const AllPolicies: React.FC = () => {
  const policies = useApiQuery((signal) => api.financialPolicies.list({ per_page: 100, signal }), []);
  const [openId, setOpenId] = useState<number | null>(null);

  return (
    <Card title="كل السياسات وإصداراتها" subtitle="افتح سياسة لرؤية كل إصداراتها وتواريخ سريانها ومن أعدّها واعتمدها وسجل التدقيق" accent="gradient">
      <QueryBoundary
        query={policies}
        loading={<TableSkeleton rows={4} cols={5} />}
        isEmpty={(page) => page.data.length === 0}
        empty={<EmptyState title="لا توجد سياسات" description="لم تُنشأ أي سياسة مالية أو تعاقدية بعد؛ كل العمليات المالية التي تحتاجها موقوفة." />}
      >
        {(page) => (
          <div className="overflow-x-auto">
            <table className="w-full text-right text-xs border-collapse" data-testid="all-policies">
              <thead>
                <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085]">
                  <th className="py-2.5 px-3">النوع</th>
                  <th className="py-2.5 px-3">الاسم</th>
                  <th className="py-2.5 px-3">النطاق</th>
                  <th className="py-2.5 px-3">الساري اليوم</th>
                  <th className="py-2.5 px-3">قيد الإعداد</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#F1F4F9]">
                {page.data.map((policy: FinancialPolicy) => (
                  <tr key={policy.id} className="hover:bg-[#F7F9FC] cursor-pointer" onClick={() => setOpenId(policy.id)}>
                    <td className="py-2.5 px-3 font-semibold">{KIND_LABELS[policy.kind]}</td>
                    <td className="py-2.5 px-3">{policy.name_ar}</td>
                    <td className="py-2.5 px-3">{SCOPE_LABELS[policy.scope.type]}{policy.scope.type !== 'global' ? `: ${policy.scope.name}` : ''}</td>
                    <td className="py-2.5 px-3">
                      {policy.current_version ? `الإصدار ${policy.current_version.version} منذ ${formatDate(policy.current_version.effective_from)}` : <span className="text-[#A66F0B]">لا يوجد — موقوفة</span>}
                    </td>
                    <td className="py-2.5 px-3">
                      {policy.open_version ? <Badge size="sm" variant={EFFECTIVE_STATUS[policy.open_version.effective_status].tone}>{EFFECTIVE_STATUS[policy.open_version.effective_status].label}</Badge> : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </QueryBoundary>
      {openId !== null && <PolicyDetailModal policyId={openId} onClose={() => setOpenId(null)} onChanged={policies.refetch} />}
    </Card>
  );
};
