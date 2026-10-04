import React from 'react';
import { useNavigate } from 'react-router-dom';
import { FileText, Layers, MessageSquare, Receipt, Send, ShieldCheck } from 'lucide-react';
import { api } from '../../api';
import type { ServiceProvider } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyProvider } from '../../hooks/useMyOrganization';
import { formatDateTime, formatMoneyOf } from '../../lib/format';
import { invoiceStatusLabel } from '../../lib/labels';
import { providerCompletion } from '../../lib/profileCompletion';
import { LifecycleBadge } from '../../components/marketplace/LifecycleBadge';
import { RecentNotifications } from '../../components/notifications/RecentNotifications';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { ApprovalBadge } from '../../components/ui/ApprovalBadge';
import { Button } from '../../components/ui/Button';
import { Card, KPICard } from '../../components/ui/Card';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';

/**
 * Every figure comes from the API: pagination totals of the provider's own lists and the marketplace
 * report (last twelve months) computed from stored records.
 */
async function loadSummary(signal: AbortSignal) {
  const [report, unread, listings, promoted, inbox] = await Promise.all([
    api.reports.marketplace({ signal }),
    api.providerRequests.list({ per_page: 1, filter: { unread: true }, signal }),
    api.serviceListings.list({ per_page: 1, signal }),
    api.serviceListings.list({ per_page: 1, filter: { promoted: true }, signal }),
    api.providerRequests.list({ per_page: 5, filter: { status: 'pending' }, signal }),
  ]);
  return { report, unreadThreads: unread.meta.total, listings: listings.meta.total, promoted: promoted.meta.total, inbox };
}

export const ProviderDashboard: React.FC = () => {
  const navigate = useNavigate();
  const provider = useMyProvider();
  const summary = useApiQuery(loadSummary, []);
  const s = summary.data;

  return (
    <QueryBoundary query={provider} loading={<CardSkeleton />}>
      {(p) => (
        <div className="space-y-6">
          <Banner provider={p} />

          {summary.status === 'error' ? (
            <ApiErrorState error={summary.error} onRetry={summary.refetch} />
          ) : (
            <>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <KPICard
                  title="طلبات بانتظار ردكم"
                  value={s ? s.report.requests.by_status.pending : '…'}
                  icon={Send}
                  accentColor="purple"
                  description={s ? `من ${s.report.requests.total} طلبًا خلال 12 شهرًا` : 'طلبات المصانع'}
                  onClick={() => navigate('/provider/requests?status=pending')}
                />
                <KPICard
                  title="مفاوضات جارية"
                  value={s ? s.report.requests.by_status.accepted : '…'}
                  icon={MessageSquare}
                  accentColor="blue"
                  description={s ? `${s.unreadThreads} طلب برسائل غير مقروءة` : 'الرسائل والعروض'}
                  onClick={() => navigate(s && s.unreadThreads > 0 ? '/provider/requests?unread=1' : '/provider/requests?status=accepted')}
                />
                <KPICard
                  title="اتفاقيات بانتظار اعتماد المركز"
                  value={s ? s.report.agreements.by_review_status.pending : '…'}
                  icon={ShieldCheck}
                  accentColor="indigo"
                  description={s ? `${s.report.agreements.by_review_status.approved} معتمدة · ${s.report.agreements.with_contract_draft} بمسودة عقد` : 'الاتفاقيات'}
                  onClick={() => navigate('/provider/contracts?review=pending')}
                />
                <KPICard
                  title="خدماتي"
                  value={s ? s.listings : '…'}
                  icon={Layers}
                  accentColor="success"
                  description={s ? (s.promoted > 0 ? `${s.promoted} عليها إعلان من المركز` : 'من الكتالوج المعتمد') : 'الخدمات'}
                  onClick={() => navigate('/provider/services')}
                />
              </div>

              <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <div className="lg:col-span-7 space-y-6">
                  <Card
                    title="طلبات بانتظار ردكم"
                    subtitle="افتحوا الطلب لقبوله وبدء التفاوض أو الاعتذار عنه"
                    action={
                      <Button variant="ghost" size="sm" onClick={() => navigate('/provider/requests')}>
                        كل الطلبات
                      </Button>
                    }
                  >
                    {summary.status === 'loading' && <CardSkeleton />}
                    {s && s.inbox.data.length === 0 && <p className="text-xs text-[#667085] text-center py-6">لا توجد طلبات بانتظار ردكم حاليًا.</p>}
                    <div className="space-y-3">
                      {(s?.inbox.data ?? []).map((thread) => (
                        <button
                          key={thread.id}
                          type="button"
                          className="w-full text-right p-3.5 rounded-xl border border-[#E6EAF0] hover:border-[#9B8AFB] hover:bg-[#F7F9FC] transition-all cursor-pointer"
                          onClick={() => navigate(`/provider/requests/${thread.id}`)}
                        >
                          <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                              <span className="font-mono text-[10px] font-bold text-[#5146A5]">#{thread.id}</span>
                              <h4 className="font-bold text-xs text-[#172033] mt-0.5 truncate">{thread.service_request?.factory?.name}</h4>
                            </div>
                            <LifecycleBadge thread={thread} />
                          </div>
                          <p className="text-xs text-[#667085] mt-1.5 line-clamp-1">{thread.service_request?.title}</p>
                          <div className="flex items-center justify-between mt-2 pt-2 border-t border-[#F1F4F9] text-[11px] text-[#667085]">
                            <span>{thread.service_request?.service?.name_ar}</span>
                            <span>{formatDateTime(thread.created_at)}</span>
                          </div>
                        </button>
                      ))}
                    </div>
                  </Card>

                  <Card
                    title="الفواتير"
                    subtitle="حسب الحالة (12 شهرًا)"
                    action={
                      <Button variant="ghost" size="sm" icon={Receipt} onClick={() => navigate('/provider/invoices')}>
                        مركز الفواتير
                      </Button>
                    }
                  >
                    {s ? (
                      <div className="space-y-3 text-xs">
                        <div className="flex flex-wrap gap-2">
                          {Object.entries(s.report.invoices.by_status).map(([status, count]) => (
                            <button
                              key={status}
                              type="button"
                              onClick={() => navigate(`/provider/invoices?status=${status}`)}
                              className="px-3 py-1.5 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] hover:border-[#9B8AFB] cursor-pointer"
                            >
                              {invoiceStatusLabel(status)}: <strong>{count}</strong>
                            </button>
                          ))}
                        </div>
                        {s.report.invoices.issued_totals.length > 0 && (
                          <ul className="text-[#667085] space-y-1">
                            {s.report.invoices.issued_totals.map((row) => (
                              <li key={`${row.status}-${row.currency}`}>
                                إجمالي {invoiceStatusLabel(row.status)}: <strong className="text-[#172033]">{formatMoneyOf(row.amount, row.currency)}</strong>
                              </li>
                            ))}
                          </ul>
                        )}
                      </div>
                    ) : (
                      <CardSkeleton />
                    )}
                  </Card>
                </div>

                <div className="lg:col-span-5 space-y-6">
                  <ProfileCard provider={p} />
                  {s && (
                    <Card title="أداء شركتكم" subtitle="محسوب من سجلات آخر 12 شهرًا">
                      <ul className="text-xs space-y-2 text-[#667085]">
                        <li>
                          متوسط زمن الرد على الطلبات:{' '}
                          <strong className="text-[#172033]">{s.report.response_time.average_hours === null ? '—' : `${s.report.response_time.average_hours} ساعة`}</strong>
                        </li>
                        <li>
                          الطلبات التي انتهت باتفاق:{' '}
                          <strong className="text-[#172033]">
                            {s.report.conversion.agreed} من {s.report.conversion.requests}
                            {s.report.conversion.agreed_rate_percent !== null ? ` (${s.report.conversion.agreed_rate_percent}%)` : ''}
                          </strong>
                        </li>
                        <li>
                          اعتذاراتكم: <strong className="text-[#172033]">{s.report.requests.by_status.declined}</strong>
                        </li>
                      </ul>
                      <Button variant="outline" size="sm" icon={FileText} className="mt-3" onClick={() => navigate('/provider/reports')}>
                        التقارير التفصيلية
                      </Button>
                    </Card>
                  )}
                  <RecentNotifications />
                </div>
              </div>
            </>
          )}
        </div>
      )}
    </QueryBoundary>
  );
};

const ProfileCard: React.FC<{ provider: ServiceProvider }> = ({ provider: p }) => {
  const navigate = useNavigate();
  const completion = providerCompletion(p);
  return (
    <Card
      title="حالة الاعتماد واكتمال الملف"
      action={
        <Button variant="ghost" size="sm" onClick={() => navigate('/provider/settings')}>
          الإعدادات
        </Button>
      }
    >
      <div className="space-y-3 text-xs">
        <ApprovalBadge status={p.approval.status} />
        <p className="text-[#667085] leading-relaxed">
          {p.approval.status === 'approved'
            ? 'ملفكم معتمد ويظهر للمصانع المؤهلة.'
            : p.approval.status === 'pending'
              ? 'ملفكم قيد المراجعة؛ لا يظهر للمصانع قبل الاعتماد.'
              : p.approval.status === 'rejected'
                ? 'رُفض اعتماد ملفكم. عدّلوا البيانات واطلبوا مراجعة جديدة من الإعدادات.'
                : 'اعتماد ملفكم موقوف حاليًا وتتوقف طلباتكم المفتوحة مؤقتًا.'}
        </p>
        {p.approval.reason && <p className="p-2.5 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-[#172033]" dir="auto">{p.approval.reason}</p>}
        <div>
          <div className="flex items-center justify-between mb-1">
            <span className="text-[#667085]">اكتمال الملف</span>
            <span className="font-bold text-[#172033]">
              {completion.filled} من {completion.total} حقلًا
            </span>
          </div>
          <div className="h-2 rounded-full bg-[#F1F4F9] overflow-hidden">
            <div className="h-full bg-[#5146A5] rounded-full" style={{ width: `${completion.percent}%` }} />
          </div>
          {completion.missing.length > 0 && <p className="mt-1.5 text-[11px] text-[#98A2B3]">ناقص: {completion.missing.slice(0, 5).join('، ')}{completion.missing.length > 5 ? '…' : ''}</p>}
        </div>
      </div>
    </Card>
  );
};

const Banner: React.FC<{ provider: ServiceProvider }> = ({ provider }) => {
  const navigate = useNavigate();
  return (
    <div className="p-6 rounded-2xl bg-white border border-[#E6EAF0] shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div className="flex items-center gap-2 mb-1">
          <span className={`w-2 h-2 rounded-full ${provider.approval.status === 'approved' ? 'bg-[#35B779]' : 'bg-[#F2B84B]'}`} />
          <span className={`text-xs font-bold ${provider.approval.status === 'approved' ? 'text-[#1D7E4C]' : 'text-[#A66F0B]'}`}>
            {provider.approval.status === 'approved' ? 'شريك تكنولوجي معتمد' : 'ملف الشركة غير معتمد حاليًا'}
          </span>
        </div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">لوحة التحكم: {provider.name}</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">طلبات المصانع والتفاوض والاتفاقيات والفواتير في مكان واحد.</p>
      </div>
      <div className="flex items-center gap-3">
        <Button variant="primary" size="md" icon={Send} onClick={() => navigate('/provider/requests')}>
          طلبات المصانع
        </Button>
        <Button variant="outline" size="md" onClick={() => navigate('/provider/services')}>
          خدماتي
        </Button>
      </div>
    </div>
  );
};
