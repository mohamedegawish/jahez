import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CheckCircle2, Send, FileText, Receipt, Compass, TrendingUp, Building2 } from 'lucide-react';
import { api } from '../../api';
import type { Factory } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyFactory } from '../../hooks/useMyOrganization';
import { formatMoneyOf } from '../../lib/format';
import { invoiceStatusLabel } from '../../lib/labels';
import { factoryCompletion } from '../../lib/profileCompletion';
import { KPICard, Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { FactoryApprovalNotice } from '../../components/factory/FactoryApprovalNotice';
import { OnboardingSteps } from '../../components/factory/OnboardingSteps';
import { PackageList } from '../../components/listings/PackageList';
import { ServiceListingCard } from '../../components/listings/ServiceListingCard';
import { RecentNotifications } from '../../components/notifications/RecentNotifications';

/** Every figure comes from the API: list totals and the marketplace report (last twelve months). */
async function loadCounts(signal: AbortSignal) {
  const [open, all, report, unread, listings, plans] = await Promise.all([
    api.serviceRequests.list({ per_page: 1, filter: { status: 'open' }, signal }),
    api.serviceRequests.list({ per_page: 1, signal }),
    api.reports.marketplace({ signal }),
    api.providerRequests.list({ per_page: 1, filter: { unread: true }, signal }),
    api.serviceListings.list({ per_page: 1, signal }),
    api.transformationPlans.list({ per_page: 1, signal }),
  ]);
  return {
    openRequests: open.meta.total,
    allRequests: all.meta.total,
    agreements: report.agreements.total,
    report,
    unreadThreads: unread.meta.total,
    availableServices: listings.meta.total,
    /** Plans IMC published for the factory (ADR-025); drafts are never listed for it. */
    publishedPlans: plans.meta.total,
  };
}

type Counts = Awaited<ReturnType<typeof loadCounts>>;

export const FactoryDashboard: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const factory = useMyFactory();
  const counts = useApiQuery(loadCounts, []);
  const c = counts.data;

  return (
    <QueryBoundary query={factory} loading={<CardSkeleton />}>
      {(f) => (
        <div className="space-y-6">
          <Banner factory={f} userName={user?.name ?? ''} sectorNames={f.sectors?.map((s) => s.name_ar) ?? []} />
          <FactoryApprovalNotice factory={f} />
          <OnboardingSteps factory={f} />
          <Journey assessed={Boolean(f.current_readiness)} counts={c} />

          {counts.status === 'error' ? (
            <ApiErrorState error={counts.error} onRetry={counts.refetch} />
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <KPICard
                title="مستوى الجاهزية الرقمية"
                value={f.readiness_level?.name_ar ?? '—'}
                icon={TrendingUp}
                accentColor="purple"
                description={
                  !f.readiness_level
                    ? 'لم يتم التقييم بعد'
                    : f.readiness_level.unlocked_by === 'plan_completion'
                      ? 'فُتح بإتمام خدمات المستوى السابق في خطة التحول'
                      : 'حسب آخر تقييم للجاهزية الرقمية'
                }
                onClick={() => navigate('/factory/assessment')}
              />
              <KPICard
                title="الخدمات المتاحة لمنشأتكم"
                value={c ? c.availableServices : '…'}
                icon={Compass}
                accentColor="blue"
                description="عروض مزودين معتمدين في قطاعاتكم"
                onClick={() => navigate('/factory/services')}
              />
              <KPICard
                title="طلباتي المفتوحة"
                value={c ? c.openRequests : '…'}
                icon={Send}
                accentColor="indigo"
                description={c ? `${c.report.requests.by_status.accepted} مفاوضة جارية · ${c.unreadThreads} برسائل جديدة` : 'طلبات مفتوحة'}
                onClick={() => navigate('/factory/requests?status=open')}
              />
              <KPICard
                title="اتفاقيات معتمدة من المركز"
                value={c ? c.report.agreements.by_review_status.approved : '…'}
                icon={FileText}
                accentColor="success"
                description={c ? `${c.report.agreements.by_review_status.pending} بانتظار الاعتماد · ${c.report.agreements.with_contract_draft} بمسودة عقد` : 'الاتفاقيات'}
                onClick={() => navigate('/factory/contracts')}
              />
            </div>
          )}

          <InvoiceSummary counts={c} />
          <Featured />
          <Recommended factory={f} />
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <ProfileCompletion factory={f} />
            <RecentNotifications />
          </div>
        </div>
      )}
    </QueryBoundary>
  );
};

const Banner: React.FC<{ factory: Factory; userName: string; sectorNames: string[] }> = ({ factory, userName, sectorNames }) => {
  const navigate = useNavigate();
  return (
    <div className="p-6 rounded-2xl bg-gradient-to-r from-[#5146A5] via-[#43388E] to-[#172033] text-white shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div>
        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/10 text-xs font-semibold text-[#DFF3FF] mb-2 backdrop-blur-xs">
          <Building2 className="w-3.5 h-3.5 text-[#6EC8FF]" />
          منشأة صناعية{sectorNames.length > 0 ? ` — ${sectorNames.join('، ')}` : ''}
        </div>
        <h2 className="text-xl sm:text-2xl font-bold tracking-tight">مرحباً بك مجدداً، {userName}</h2>
        <p className="text-[#DFF3FF]/80 text-xs sm:text-sm mt-0.5">{factory.name}</p>
      </div>

      <div className="flex items-center gap-3">
        <Button variant="secondary" size="md" icon={Compass} onClick={() => navigate('/factory/services')} className="bg-white text-[#5146A5] hover:bg-[#F7F9FC]">
          الخدمات المتاحة
        </Button>
        <Button
          variant="outline"
          size="md"
          onClick={() => navigate('/factory/assessment')}
          className="bg-transparent! border-white/40 text-white hover:bg-white/10"
        >
          {factory.current_readiness ? 'تقييم جديد للجاهزية' : 'بدء تقييم الجاهزية'}
        </Button>
      </div>
    </div>
  );
};

/** The journey is derived from real records: an assessment, a service request, an approved agreement. */
const Journey: React.FC<{ assessed: boolean; counts?: Counts }> = ({ assessed, counts }) => {
  const approved = (counts?.report.agreements.by_review_status.approved ?? 0) > 0;
  const done = [assessed, (counts?.publishedPlans ?? 0) > 0, (counts?.allRequests ?? 0) > 0, (counts?.agreements ?? 0) > 0, approved];
  const current = done.indexOf(false);
  const stages = [
    { title: '1. تقييم الجاهزية', desc: 'تحديد فئة الجاهزية الرقمية' },
    { title: '2. خطة التحول', desc: 'خطة تنشرها الوزارة لمنشأتكم', link: '/factory/roadmap' },
    { title: '3. طلب الخدمات', desc: 'طلب خدمة من مزودين مؤهلين' },
    { title: '4. التفاوض والاتفاق', desc: 'قبول عرض أحد المزودين' },
    { title: '5. اعتماد المركز', desc: 'اعتماد الاتفاقية ثم مسودة العقد' },
  ];

  return (
    <Card title="مسار رحلة التحول الصناعي الذكي لمنشأتكم" accent="blue">
      <div className="grid grid-cols-1 sm:grid-cols-5 gap-3">
        {stages.map((stage, idx) => (
          <div
            key={stage.title}
            className={`p-3.5 rounded-xl border text-xs transition-all ${
              idx === current
                ? 'bg-[#5146A5] text-white border-[#5146A5] shadow-xs'
                : done[idx]
                  ? 'bg-[#E7F8EE] text-[#1D7E4C] border-[#C5F0D5]'
                  : 'bg-[#F7F9FC] text-[#98A2B3] border-[#E6EAF0]'
            }`}
          >
            <div className="flex items-center justify-between mb-1">
              <span className="font-bold">{stage.title}</span>
              {done[idx] && <CheckCircle2 className="w-4 h-4 text-[#1D7E4C]" />}
            </div>
            <p className={`text-[11px] ${idx === current ? 'text-[#DFF3FF]' : 'text-[#667085]'}`}>{stage.desc}</p>
            {stage.link && (
              <Link to={stage.link} className={`text-[11px] font-bold underline ${idx === current ? 'text-white' : 'text-[#5146A5]'}`}>
                عرض الخطة
              </Link>
            )}
          </div>
        ))}
      </div>
    </Card>
  );
};

/** Invoices by status (from the report), linking to the filtered invoice center. */
const InvoiceSummary: React.FC<{ counts?: Counts }> = ({ counts }) => {
  const navigate = useNavigate();
  if (!counts) return null;
  const { by_status: byStatus, issued_totals: totals } = counts.report.invoices;
  return (
    <Card
      title="الفواتير"
      subtitle="حسب الحالة خلال 12 شهرًا · تاريخ الاستحقاق والتأخر يظهران في كل فاتورة"
      action={
        <Button variant="ghost" size="sm" icon={Receipt} onClick={() => navigate('/factory/invoices')}>
          مركز الفواتير
        </Button>
      }
    >
      <div className="flex flex-wrap gap-2 text-xs">
        {Object.entries(byStatus).map(([status, count]) => (
          <button
            key={status}
            type="button"
            onClick={() => navigate(`/factory/invoices?status=${status}`)}
            className="px-3 py-1.5 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] hover:border-[#9B8AFB] cursor-pointer"
          >
            {invoiceStatusLabel(status)}: <strong>{count}</strong>
          </button>
        ))}
      </div>
      {totals.length > 0 && (
        <ul className="mt-3 text-xs text-[#667085] space-y-1">
          {totals.map((row) => (
            <li key={`${row.status}-${row.currency}`}>
              {row.status === 'issued' ? 'صادرة بانتظار السداد' : invoiceStatusLabel(row.status)}:{' '}
              <strong className="text-[#172033]">{formatMoneyOf(row.amount, row.currency)}</strong>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
};

/** Listings IMC promotes («إعلان») among those the factory is eligible for. */
const Featured: React.FC = () => {
  const navigate = useNavigate();
  const featured = useApiQuery((signal) => api.serviceListings.list({ per_page: 3, filter: { promoted: true }, signal }), []);
  if (featured.status !== 'success' || (featured.data?.data.length ?? 0) === 0) return null;
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-base font-bold text-[#172033]">خدمات مميزة</h3>
          <p className="text-xs text-[#667085]">إعلانات يضعها مركز تحديث الصناعة على خدمات متاحة لمنشأتكم.</p>
        </div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/factory/services')}>
          كل الخدمات ←
        </Button>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
        {(featured.data?.data ?? []).map((listing) => (
          <ServiceListingCard
            key={listing.id}
            listing={listing}
            detailsPath={`/factory/services/${listing.service?.id}`}
            footer={<PackageList packages={listing.packages} compact />}
          />
        ))}
      </div>
    </div>
  );
};

const ProfileCompletion: React.FC<{ factory: Factory }> = ({ factory }) => {
  const navigate = useNavigate();
  const completion = factoryCompletion(factory);
  return (
    <Card
      title="اكتمال ملف المنشأة"
      action={
        <Button variant="ghost" size="sm" onClick={() => navigate('/factory/settings')}>
          الإعدادات
        </Button>
      }
    >
      <div className="text-xs space-y-2">
        <div className="flex items-center justify-between">
          <span className="text-[#667085]">الحقول المكتملة</span>
          <span className="font-bold text-[#172033]">
            {completion.filled} من {completion.total}
          </span>
        </div>
        <div className="h-2 rounded-full bg-[#F1F4F9] overflow-hidden">
          <div className="h-full bg-[#5146A5] rounded-full" style={{ width: `${completion.percent}%` }} />
        </div>
        {completion.missing.length > 0 && <p className="text-[11px] text-[#98A2B3]">ناقص: {completion.missing.join('، ')}</p>}
      </div>
    </Card>
  );
};

/** Services the factory's readiness roadmap recommends (`filter[recommended]`, available after an assessment). */
const Recommended: React.FC<{ factory: Factory }> = ({ factory }) => {
  const navigate = useNavigate();
  const assessed = Boolean(factory.current_readiness);
  const services = useApiQuery((signal) => api.catalog.services({ filter: { recommended: true }, signal }), [factory.id], {
    enabled: assessed,
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-base font-bold text-[#172033]">حلول مقترحة لمنشأتكم</h3>
          <p className="text-xs text-[#667085]">
            {assessed
              ? `موصى بها وفق خارطة طريق فئة الجاهزية: ${factory.current_readiness?.category?.name_ar ?? ''}`
              : 'تظهر الحلول الموصى بها بعد إكمال تقييم الجاهزية الرقمية.'}
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/factory/services?recommended=1')}>
          عرض الموصى بها ←
        </Button>
      </div>

      {!assessed ? (
        <div className="jahez-card p-6 text-center space-y-3">
          <p className="text-sm text-[#667085]">لم يُجرَ تقييم للجاهزية الرقمية لمنشأتكم بعد.</p>
          <Button variant="primary" size="md" onClick={() => navigate('/factory/assessment')}>
            بدء تقييم الجاهزية
          </Button>
        </div>
      ) : (
        <QueryBoundary
          query={services}
          loading={<CardSkeleton />}
          isEmpty={(list) => list.length === 0}
          empty={<div className="jahez-card p-6 text-center text-sm text-[#667085]">لا توجد في الكتالوج الحالي خدمات مقابلة لتوصيات فئتكم (OQ-42).</div>}
        >
          {(list) => (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {list.slice(0, 3).map((service) => (
                <div
                  key={service.id}
                  className="jahez-card p-5 flex flex-col justify-between hover:border-[#6EC8FF] transition-all cursor-pointer group"
                  onClick={() => navigate(`/factory/services/${service.id}`)}
                >
                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <span className="font-mono text-[10px] font-bold text-[#5146A5]" dir="ltr">{service.code}</span>
                      {service.category && (
                        <span className="text-[10px] px-2 py-0.5 rounded-full bg-[#EEEAFE] text-[#5146A5] font-semibold">{service.category.name_ar}</span>
                      )}
                    </div>
                    <h4 className="font-bold text-sm text-[#172033] group-hover:text-[#5146A5] transition-colors leading-snug">{service.name_ar}</h4>
                  </div>
                  <div className="mt-4 pt-3 border-t border-[#F1F4F9] flex justify-end">
                    <Button size="sm" variant="secondary">
                      عرض التفاصيل
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </QueryBoundary>
      )}
    </div>
  );
};
