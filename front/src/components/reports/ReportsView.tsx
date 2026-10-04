import React, { useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Clock, Download, FileText, Handshake, Send, ShieldCheck } from 'lucide-react';
import { api } from '../../api';
import type { MarketplaceReport } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useCatalogServices } from '../../hooks/useReference';
import { formatMoneyOf, today } from '../../lib/format';
import { invoiceStatusLabel, providerRequestStatusLabel } from '../../lib/labels';
import { Button } from '../ui/Button';
import { Card, KPICard } from '../ui/Card';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';
import { UnavailableNotice } from '../ui/UnavailableNotice';

const yearAgo = () => {
  const d = new Date();
  d.setFullYear(d.getFullYear() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** CSV of every table of the report, built from the API response itself (UTF-8 with BOM for Excel). */
function downloadCsv(report: MarketplaceReport, side: string) {
  const rows: (string | number)[][] = [
    ['الفترة', report.period.from, report.period.to],
    [],
    ['الطلبات حسب الحالة'],
    ...Object.entries(report.requests.by_status).map(([status, count]) => [providerRequestStatusLabel(status), count]),
    [],
    ['الطلبات حسب الشهر'],
    ...report.requests.by_month.map((row) => [row.month, row.count]),
    [],
    ['أكثر الخدمات طلبًا'],
    ...report.requests.top_services.map((row) => [row.name_ar, row.count]),
    [],
    ['التحويل', 'الطلبات', report.conversion.requests, 'المتفق عليها', report.conversion.agreed, 'المعتمدة', report.conversion.imc_approved],
    ['زمن الرد (ساعات)', 'المتوسط', report.response_time.average_hours ?? '', 'الوسيط', report.response_time.median_hours ?? ''],
    [],
    ['الفواتير حسب الحالة'],
    ...Object.entries(report.invoices.by_status).map(([status, count]) => [invoiceStatusLabel(status), count]),
    [],
    ['إجماليات الفواتير الصادرة', 'الحالة', 'العملة', 'المبلغ', 'العدد'],
    ...report.invoices.issued_totals.map((row) => ['', invoiceStatusLabel(row.status), row.currency, row.amount, row.count]),
  ];
  const csv = rows.map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob(['﻿', csv], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `jahez-${side}-report-${report.period.from}-${report.period.to}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}

/**
 * Marketplace report for the signed-in organization, computed by the API from stored records only.
 * Counts are counts; a rate is shown with its numerator and base, and only when the base is not zero.
 * Money appears only as totals of issued invoices, per currency.
 */
export const ReportsView: React.FC<{ side: 'provider' | 'factory' }> = ({ side }) => {
  const [from, setFrom] = useState(yearAgo());
  const [to, setTo] = useState(today());
  const [service, setService] = useState('');
  const services = useCatalogServices();
  const report = useApiQuery((signal) => api.reports.marketplace({ from, to, service: service || undefined, signal }), [from, to, service]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">التقارير</h2>
          <p className="text-xs sm:text-sm text-[#667085] mt-0.5">مؤشرات محسوبة من سجلات المنصة الفعلية لمنشأتكم فقط. الأيام بتوقيت UTC.</p>
        </div>
        <Button variant="outline" size="sm" icon={Download} disabled={!report.data} onClick={() => report.data && downloadCsv(report.data, side)}>
          تصدير CSV
        </Button>
      </div>

      <Card className="p-4">
        <div className="flex flex-col sm:flex-row flex-wrap gap-3 text-xs">
          <label className="inline-flex items-center gap-1.5 text-[#667085]">
            من
            <input type="date" value={from} max={to} onChange={(e) => e.target.value && setFrom(e.target.value)} className="py-1.5 px-2 border border-[#E6EAF0] rounded-lg" />
          </label>
          <label className="inline-flex items-center gap-1.5 text-[#667085]">
            إلى
            <input type="date" value={to} min={from} onChange={(e) => e.target.value && setTo(e.target.value)} className="py-1.5 px-2 border border-[#E6EAF0] rounded-lg" />
          </label>
          <select aria-label="الخدمة" value={service} onChange={(e) => setService(e.target.value)} className="py-2 px-3 bg-white border border-[#E6EAF0] rounded-xl text-[#172033] max-w-xs">
            <option value="">كل الخدمات</option>
            {(services.data ?? []).map((s) => (
              <option key={s.code} value={s.code}>
                {s.name_ar}
              </option>
            ))}
          </select>
        </div>
      </Card>

      <QueryBoundary query={report} loading={<CardSkeleton />}>
        {(r) => (
          <div className="space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <KPICard title="الطلبات في الفترة" value={r.requests.total} icon={Send} accentColor="purple" description="عدد" />
              <KPICard
                title="نسبة الاتفاق"
                value={r.conversion.agreed_rate_percent === null ? '—' : `${r.conversion.agreed_rate_percent}%`}
                icon={Handshake}
                accentColor="success"
                description={`${r.conversion.agreed} من ${r.conversion.requests} طلبًا`}
              />
              <KPICard
                title="نسبة الاعتماد من المركز"
                value={r.conversion.approved_rate_percent === null ? '—' : `${r.conversion.approved_rate_percent}%`}
                icon={ShieldCheck}
                accentColor="indigo"
                description={`${r.conversion.imc_approved} من ${r.conversion.requests} طلبًا`}
              />
              <KPICard
                title={side === 'provider' ? 'متوسط زمن الرد' : 'متوسط زمن رد المزودين'}
                value={r.response_time.average_hours === null ? '—' : `${r.response_time.average_hours} س`}
                icon={Clock}
                accentColor="blue"
                description={r.response_time.median_hours === null ? 'لا ردود في الفترة' : `الوسيط ${r.response_time.median_hours} س · ${r.response_time.answered_count} رد`}
              />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
              <div className="lg:col-span-8">
                <Card title="الطلبات حسب الشهر" subtitle="عدد الطلبات الواردة في كل شهر">
                  {r.requests.by_month.length === 0 ? (
                    <p className="text-xs text-[#98A2B3] text-center py-10">لا توجد طلبات في هذه الفترة.</p>
                  ) : (
                    <div className="h-64" dir="ltr">
                      <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={r.requests.by_month} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                          <CartesianGrid vertical={false} stroke="#F1F4F9" />
                          <XAxis dataKey="month" tick={{ fontSize: 11, fill: '#667085' }} axisLine={false} tickLine={false} />
                          <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#667085' }} axisLine={false} tickLine={false} />
                          <Tooltip cursor={{ fill: '#F7F9FC' }} formatter={(value) => [value, 'طلبات']} />
                          <Bar dataKey="count" fill="#5146A5" radius={[4, 4, 0, 0]} maxBarSize={36} />
                        </BarChart>
                      </ResponsiveContainer>
                    </div>
                  )}
                </Card>
              </div>
              <div className="lg:col-span-4">
                <Card title="الطلبات حسب الحالة" subtitle="أعداد">
                  <Table rows={Object.entries(r.requests.by_status).map(([status, count]) => [providerRequestStatusLabel(status), count])} />
                </Card>
              </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <Card title="أكثر الخدمات طلبًا" subtitle="أعلى 10 خدمات في الفترة">
                {r.requests.top_services.length === 0 ? (
                  <p className="text-xs text-[#98A2B3] text-center py-6">لا توجد بيانات.</p>
                ) : (
                  <Table rows={r.requests.top_services.map((row) => [row.name_ar, row.count])} />
                )}
              </Card>
              <Card title="الاتفاقيات" subtitle="حسب قرار المركز">
                <Table
                  rows={[
                    ['الإجمالي', r.agreements.total],
                    ['بانتظار الاعتماد', r.agreements.by_review_status.pending],
                    ['معتمدة', r.agreements.by_review_status.approved],
                    ['مرفوضة', r.agreements.by_review_status.rejected],
                    ['لها مسودة عقد سارية', r.agreements.with_contract_draft],
                  ]}
                />
                <div className="mt-3">
                  <UnavailableNotice kind="decision" title="العقود القريبة من الانتهاء" decisionNeeded={r.agreements.expiry.decision_needed}>
                    لا تُسجل تواريخ سريان للعقود بعد، فلا يمكن حساب ما يقترب من الانتهاء.
                  </UnavailableNotice>
                </div>
              </Card>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <Card title="الفواتير حسب الحالة" subtitle="أعداد الفواتير المنشأة في الفترة">
                <Table rows={Object.entries(r.invoices.by_status).map(([status, count]) => [invoiceStatusLabel(status), count])} />
              </Card>
              <Card title="إجماليات الفواتير الصادرة" subtitle="مبالغ الفواتير الصادرة في الفترة، لكل عملة">
                {r.invoices.issued_totals.length === 0 ? (
                  <p className="text-xs text-[#98A2B3] text-center py-6">لا توجد فواتير صادرة في الفترة.</p>
                ) : (
                  <Table rows={r.invoices.issued_totals.map((row) => [`${invoiceStatusLabel(row.status)} (${row.count})`, formatMoneyOf(row.amount, row.currency)])} />
                )}
                <p className="mt-3 text-[11px] text-[#98A2B3]">
                  لا تُعرض أرصدة مستحقة محسوبة: لا توجد تواريخ استحقاق أو شروط دفع معتمدة، ولا تُحسب حصة للمركز دون قاعدة معتمدة.
                </p>
              </Card>
            </div>

            <Card title={side === 'provider' ? 'نشاط شركتكم' : 'نشاط منشأتكم'} subtitle="الرسائل المرسلة ونسخ العروض حسب الشهر">
              {r.activity_by_month.length === 0 ? (
                <p className="text-xs text-[#98A2B3] text-center py-6">لا يوجد نشاط في هذه الفترة.</p>
              ) : (
                <Table
                  head={side === 'provider' ? ['الشهر', 'الرسائل', 'نسخ العروض'] : ['الشهر', 'الرسائل', 'نسخ العروض المستلمة']}
                  rows={r.activity_by_month.map((row) => [row.month, row.messages, row.offers])}
                />
              )}
            </Card>

            <p className="text-[11px] text-[#98A2B3] flex items-center gap-1.5">
              <FileText className="w-3.5 h-3.5" /> الفترة {r.period.from} – {r.period.to} ({r.period.timezone}).
            </p>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};

const Table: React.FC<{ rows: (string | number)[][]; head?: string[] }> = ({ rows, head }) => (
  <table className="w-full text-right text-xs border-collapse">
    {head && (
      <thead>
        <tr className="text-[#667085] border-b border-[#E6EAF0]">
          {head.map((cell) => (
            <th key={cell} className="py-2 font-semibold">
              {cell}
            </th>
          ))}
        </tr>
      </thead>
    )}
    <tbody className="divide-y divide-[#F1F4F9]">
      {rows.map((row, index) => (
        <tr key={index}>
          {row.map((cell, i) => (
            <td key={i} className={`py-2 ${i === 0 ? 'text-[#172033]' : 'font-bold text-[#172033] tabular-nums'}`}>
              {cell}
            </td>
          ))}
        </tr>
      ))}
    </tbody>
  </table>
);
