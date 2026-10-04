import React from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertCircle, Building2, Factory, FileText, Send, Activity } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { api } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { auditEventLabel } from '../../lib/audit';
import { formatDateTime } from '../../lib/format';
import { approvalLabel, serviceRequestStatusLabel } from '../../lib/labels';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { Card, KPICard } from '../../components/ui/Card';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { ReadinessBadge } from '../../components/ui/ReadinessBadge';

const APPROVALS = ['approved', 'pending', 'changes_requested', 'rejected', 'suspended'] as const;
const REQUEST_STATUSES = ['open', 'awarded', 'cancelled'] as const;
const APPROVAL_COLORS = { approved: '#35B779', pending: '#F2B84B', changes_requested: '#9B8AFB', rejected: '#E45B6A', suspended: '#98A2B3' } as const;
const READINESS_COLORS: Record<string, string> = { b4_automation: '#98A2B3', basic: '#6EC8FF', advanced: '#9B8AFB', smart: '#35B779', none: '#E6EAF0' };

/** Every figure is an API pagination total (one-row pages), a read of the API's own lists or its readiness analytics; nothing is estimated. */
async function load(signal: AbortSignal) {
  const [readiness, summary, requests, agreements, invoices, audit] = await Promise.all([
    api.readiness.analytics({ signal }),
    api.reviewSummary(signal),
    Promise.all(REQUEST_STATUSES.map((s) => api.serviceRequests.list({ per_page: 1, filter: { status: s }, signal }))),
    api.agreements.list({ per_page: 1, signal }),
    api.invoices.list({ per_page: 1, signal }),
    api.auditLogs.list({ per_page: 6 }, signal),
  ]);
  const tally = <K extends string>(keys: readonly K[], res: { meta: { total: number } }[]) => Object.fromEntries(keys.map((k, i) => [k, res[i].meta.total])) as Record<K, number>;
  return {
    readiness,
    pendingFactories: summary.factories.pending,
    pendingListings: summary.listings.pending,
    approvals: Object.fromEntries(APPROVALS.map((s) => [s, summary.providers[s]])) as Record<(typeof APPROVALS)[number], number>,
    requests: tally(REQUEST_STATUSES, requests),
    agreements: agreements.meta.total,
    invoices: invoices.meta.total,
    audit: audit.data,
  };
}

export const AdminDashboard: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const q = useApiQuery(load, []);

  if (q.status === 'loading') return <CardSkeleton />;
  if (q.status === 'error') return <ApiErrorState error={q.error} onRetry={q.refetch} />;
  const d = q.data!;

  const pending = d.approvals.pending;
  const approvalChart = APPROVALS.map((s) => ({ name: approvalLabel(s), count: d.approvals[s], color: APPROVAL_COLORS[s] }));
  const requestChart = REQUEST_STATUSES.map((s) => ({ status: serviceRequestStatusLabel(s), count: d.requests[s] }));
  const requestTotal = REQUEST_STATUSES.reduce((n, s) => n + d.requests[s], 0);

  // Every factory's current readiness category, from the server's analytics over the stored assessments.
  const readinessChart = [
    ...d.readiness.current.by_category.map((c) => ({ code: c.code, name: c.name_ar, count: c.factories, color: READINESS_COLORS[c.code] ?? '#6EC8FF' })),
    { code: 'none', name: 'لم تُكمل التقييم', count: d.readiness.factories_not_assessed, color: READINESS_COLORS.none },
  ];
  const factoriesTotal = d.readiness.factories_total;

  return (
    <div className="space-y-6 animate-in fade-in duration-200">
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-[#5146A5] via-[#43388E] to-[#172033] p-6 sm:p-8 text-white shadow-md">
        <div className="absolute left-0 top-0 bottom-0 w-1/3 opacity-15 bg-[radial-gradient(#6EC8FF_2px,transparent_2px)] [background-size:16px_16px]" />
        <div className="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 text-xs font-semibold text-[#DFF3FF] mb-3 backdrop-blur-xs">
              منظومة جاهز للتحول الصناعي الذكي — مركز تحديث الصناعة
            </div>
            <h2 className="text-xl sm:text-2xl font-bold tracking-tight">مرحبًا، {user?.name}</h2>
            <p className="text-[#DFF3FF]/80 text-xs sm:text-sm mt-1 max-w-2xl leading-relaxed">مؤشرات المنظومة كما يعرضها الخادم الآن.</p>
          </div>
          <div className="flex items-center gap-3 shrink-0">
            <Button variant="secondary" size="md" onClick={() => navigate('/admin/approvals/providers')} className="bg-white text-[#5146A5] hover:bg-[#F7F9FC]">
              طلبات الاعتماد ({pending})
            </Button>
            <Button variant="outline" size="md" onClick={() => navigate('/admin/audit-logs')} className="bg-transparent! border-white/40 text-white hover:bg-white/10">
              سجل التدقيق
            </Button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <KPICard
          title="المنشآت الصناعية المسجلة"
          value={factoriesTotal}
          icon={Factory}
          accentColor="blue"
          description={`${d.readiness.completion_rate_percent ?? 0}% أكملت تقييم الجاهزية`}
          onClick={() => navigate('/admin/factories')}
        />
        <KPICard title="مزودو الخدمات المعتمدون" value={d.approvals.approved} icon={Building2} accentColor="purple" description={`${pending} بانتظار المراجعة`} onClick={() => navigate('/admin/providers')} />
        <KPICard title="طلبات الخدمة المفتوحة" value={d.requests.open} icon={Send} accentColor="indigo" description={`${requestTotal} طلبًا إجمالًا`} />
        <KPICard title="الاتفاقيات المبرمة" value={d.agreements} icon={FileText} accentColor="success" description={`${d.invoices} فاتورة`} onClick={() => navigate('/admin/contracts')} />
      </div>

      {pending + d.pendingFactories + d.pendingListings > 0 && (
        <div className="p-4 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] flex flex-col md:flex-row md:items-center justify-between gap-4" data-testid="pending-banner">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-[#F2B84B]/20 text-[#A66F0B] flex items-center justify-center shrink-0">
              <AlertCircle className="w-5 h-5" />
            </div>
            <div>
              <h4 className="text-sm font-bold text-[#A66F0B]">ملفات بانتظار مراجعتك</h4>
              <p className="text-xs text-[#8C5D08] mt-0.5">
                {pending} مزود خدمة · {d.pendingFactories} منشأة صناعية · {d.pendingListings} خدمة مزود — بانتظار قرار الاعتماد.
              </p>
            </div>
          </div>
          <div className="flex flex-wrap gap-2 shrink-0">
            {pending > 0 && (
              <Button size="sm" variant="primary" onClick={() => navigate('/admin/approvals/providers')} className="bg-[#A66F0B] hover:bg-[#8C5D08] text-white">
                المزودون
              </Button>
            )}
            {d.pendingFactories > 0 && (
              <Button size="sm" variant="primary" onClick={() => navigate('/admin/approvals/factories')} className="bg-[#A66F0B] hover:bg-[#8C5D08] text-white">
                المنشآت
              </Button>
            )}
            {d.pendingListings > 0 && (
              <Button size="sm" variant="primary" onClick={() => navigate('/admin/services')} className="bg-[#A66F0B] hover:bg-[#8C5D08] text-white">
                الخدمات
              </Button>
            )}
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card title="حالة اعتماد المزودين" subtitle="توزيع المزودين على قرارات الاعتماد" accent="blue" className="lg:col-span-1">
          <div className="h-56 flex items-center justify-center">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={approvalChart} cx="50%" cy="50%" innerRadius={55} outerRadius={80} paddingAngle={4} dataKey="count">
                  {approvalChart.map((entry) => (
                    <Cell key={entry.name} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip formatter={(val: unknown) => [`${val} مزود`, 'العدد']} contentStyle={{ direction: 'rtl', borderRadius: '8px', fontSize: '12px' }} />
              </PieChart>
            </ResponsiveContainer>
          </div>
          <div className="grid grid-cols-2 gap-2 mt-2 pt-3 border-t border-[#F1F4F9] text-xs">
            {approvalChart.map((m) => (
              <div key={m.name} className="flex items-center gap-2">
                <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: m.color }} />
                <span className="text-[#667085] truncate">{m.name}:</span>
                <span className="font-bold text-[#172033]">{m.count}</span>
              </div>
            ))}
          </div>
        </Card>

        <Card title="حالات طلبات الخدمة" subtitle="الطلبات المرسلة من المصانع إلى المزودين" accent="purple" className="lg:col-span-2">
          <div className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={requestChart} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E6EAF0" />
                <XAxis dataKey="status" tick={{ fontSize: 12, fill: '#667085' }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: '#667085' }} />
                <Tooltip formatter={(val: unknown) => [`${val} طلب`, 'العدد']} contentStyle={{ direction: 'rtl', borderRadius: '8px', fontSize: '12px' }} />
                <Bar dataKey="count" fill="#9B8AFB" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
          <div className="text-xs text-[#667085] mt-2 pt-3 border-t border-[#F1F4F9]">إجمالي الطلبات في المنظومة: {requestTotal}</div>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card
          title="أحدث الأنشطة عبر المنظومة"
          subtitle="من سجل التدقيق"
          action={
            <Button variant="ghost" size="sm" onClick={() => navigate('/admin/audit-logs')}>
              السجل الكامل
            </Button>
          }
        >
          <div className="space-y-3" data-testid="recent-activity">
            {d.audit.length === 0 && <p className="text-xs text-[#667085] text-center py-4">لا توجد أنشطة مسجلة.</p>}
            {d.audit.map((entry) => (
              <div key={entry.id} className="flex items-start gap-3 p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]/60">
                <div className="w-8 h-8 rounded-lg bg-[#EEEAFE] text-[#5146A5] flex items-center justify-center shrink-0">
                  <Activity className="w-4 h-4" />
                </div>
                <div className="flex-1 text-xs min-w-0">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-bold text-[#172033]">{auditEventLabel(entry.event)}</span>
                    <span className="text-[10px] text-[#98A2B3] shrink-0">{formatDateTime(entry.created_at)}</span>
                  </div>
                  <p className="text-[#667085] mt-0.5 truncate">{entry.actor ? (entry.actor.name ?? entry.actor.email) : 'بدون مستخدم (حدث مجهول)'}</p>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card title="توزيع جاهزية المنشآت" subtitle="فئات الجاهزية الرقمية كما صنّفها الخادم" accent="blue">
          <div className="space-y-2.5" data-testid="readiness-distribution">
            {readinessChart.map((r) => (
              <div key={r.code} className="flex items-center gap-3 text-xs">
                <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: r.color }} />
                {r.code === 'none' ? <span className="text-[#667085] w-40">{r.name}</span> : <span className="w-40"><ReadinessBadge category={{ code: r.code, name_ar: r.name }} size="sm" /></span>}
                <div className="flex-1 bg-[#F1F4F9] h-2 rounded-full overflow-hidden">
                  <div className="h-full rounded-full" style={{ width: `${(r.count / Math.max(factoriesTotal, 1)) * 100}%`, backgroundColor: r.color }} />
                </div>
                <span className="font-bold text-[#172033] w-6 text-left">{r.count}</span>
              </div>
            ))}
          </div>
          <div className="mt-4 pt-3 border-t border-[#F1F4F9] flex items-center justify-between text-xs text-[#667085]">
            <span>
              متوسط الدرجة الحالية: <strong className="text-[#172033]">{d.readiness.current.average_score ?? '—'}</strong> من 40 · كل المنشآت ({factoriesTotal})
            </span>
            <Button variant="ghost" size="sm" onClick={() => navigate('/admin/readiness')}>
              مؤشرات التقييم
            </Button>
          </div>
        </Card>
      </div>
    </div>
  );
};
