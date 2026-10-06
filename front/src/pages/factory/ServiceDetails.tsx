import React, { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowRight, Send, Sparkles, Users } from 'lucide-react';
import { api, isNotFound } from '../../api';
import type { ProviderDirectoryEntry } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyFactory } from '../../hooks/useMyOrganization';
import { RequestFormModal } from '../../components/factory/RequestFormModal';
import { DirectoryProfileModal } from '../../components/provider/DirectoryProfileModal';
import { ProviderCard } from '../../components/provider/ProviderCard';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';

export const ServiceDetails: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [requesting, setRequesting] = useState(false);
  const serviceId = /^\d+$/.test(id ?? '') ? Number(id) : null;

  const service = useApiQuery((signal) => api.catalog.service(serviceId as number, signal), [serviceId], { enabled: serviceId !== null });

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      <div>
        <Button variant="ghost" size="sm" icon={ArrowRight} onClick={() => navigate('/factory/catalog')}>
          العودة لكتالوج الخدمات
        </Button>
      </div>

      {serviceId === null ? (
        // A non-numeric id is a 404 on the API too; there is nothing to request.
        <EmptyState title="الخدمة غير موجودة" description="رابط الخدمة غير صالح." actionText="العودة للكتالوج" onAction={() => navigate('/factory/catalog')} />
      ) : service.status === 'error' && isNotFound(service.error) ? (
        // ADR-025/026: a service neither the factory's level nor a level below it makes available is reported as not found.
        <EmptyState
          title="هذه الخدمة غير متاحة لمستوى جاهزية منشأتكم"
          description="تحدد الوزارة الخدمات المتاحة لكل مستوى جاهزية رقمية، وتُفتح خدمات المستوى التالي بعد إتمام خدمات مستواكم في خطة التحول. تصفحوا الخدمات المتاحة لكم."
          actionText="الخدمات المتاحة"
          onAction={() => navigate('/factory/catalog')}
        />
      ) : (
        <QueryBoundary query={service} loading={<CardSkeleton />}>
          {(s) => (
            <>
              <div className="jahez-card p-6 sm:p-8 space-y-3">
                <div className="flex items-center gap-2">
                  <span className="font-mono text-xs font-bold px-2 py-0.5 rounded bg-[#EEEAFE] text-[#5146A5]" dir="ltr">{s.code}</span>
                  {s.category && <span className="text-xs font-semibold px-2 py-0.5 rounded bg-[#DFF3FF] text-[#0A6EB0]">{s.category.name_ar}</span>}
                  <RecommendedTag serviceId={s.id} />
                </div>
                <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                  <h1 className="text-xl sm:text-2xl font-bold text-[#172033] leading-snug" data-testid="service-name">{s.name_ar}</h1>
                  <Button variant="primary" size="md" icon={Send} onClick={() => setRequesting(true)} className="shrink-0">
                    طلب الخدمة من مزودين
                  </Button>
                </div>
                <p className="text-xs sm:text-sm text-[#667085] leading-relaxed pt-3 border-t border-[#F1F4F9]">
                  تفاصيل التنفيذ والسعر والمدة يحددها كل مزود في عرضه على طلبك؛ لا يحمل الكتالوج أسعارًا ثابتة. المزودون أدناه معتمدون من مركز
                  تحديث الصناعة ويستهدفون قطاعات منشأتك، والخدمة متاحة لمستوى جاهزيتك.
                </p>
              </div>

              <EligibleProviders serviceCode={s.code} />

              {requesting && (
                <RequestFormModal service={s} onClose={() => setRequesting(false)} onCreated={(created) => navigate(`/factory/requests/${created.id}`)} />
              )}
            </>
          )}
        </QueryBoundary>
      )}
    </div>
  );
};

/** Shown when the factory's readiness roadmap recommends this service (`filter[recommended]`). */
const RecommendedTag: React.FC<{ serviceId: number }> = ({ serviceId }) => {
  // The API answers 422 to filter[recommended] until the factory has an assessment, so only ask once it has one.
  const factory = useMyFactory();
  const recommended = useApiQuery((signal) => api.catalog.services({ filter: { recommended: true }, signal }), [], {
    enabled: Boolean(factory.data?.current_readiness),
  });
  if (recommended.status !== 'success' || !recommended.data?.some((s) => s.id === serviceId)) return null;
  return (
    <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded bg-[#E7F8EE] text-[#1D7E4C]">
      <Sparkles className="w-3 h-3" /> موصى بها لمنشأتك
    </span>
  );
};

const PER_PAGE = 9;

const EligibleProviders: React.FC<{ serviceCode: string }> = ({ serviceCode }) => {
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<ProviderDirectoryEntry | null>(null);
  const providers = useApiQuery(
    (signal) => api.directory.list({ page, per_page: PER_PAGE, filter: { service: serviceCode }, sort: 'name', signal }),
    [serviceCode, page],
  );

  return (
    <Card title="المزودون المؤهلون لمنشأتك" subtitle="معتمدون ويقدمون هذه الخدمة ويستهدفون قطاعات منشأتك" accent="blue">
      {providers.status === 'error' ? (
        <ApiErrorState error={providers.error} onRetry={providers.refetch} />
      ) : (
        <QueryBoundary
          query={providers}
          loading={<CardSkeleton />}
          isEmpty={(result) => result.data.length === 0}
          empty={
            <div className="text-center py-8 space-y-1">
              <Users className="w-8 h-8 text-[#98A2B3] mx-auto" />
              <p className="text-sm font-bold text-[#172033]">لا يوجد مزود مؤهل لهذه الخدمة حاليًا</p>
              <p className="text-xs text-[#667085]">لا يوجد مزود معتمد يقدمها ويستهدف قطاعات منشأتك. ستظهر الخيارات عند اعتماد مزودين مناسبين.</p>
            </div>
          }
        >
          {(result) => (
            <div className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {result.data.map((provider) => (
                  <ProviderCard key={provider.id} provider={provider} highlightService={serviceCode} onOpen={setOpen} />
                ))}
              </div>
              <Pagination meta={result.meta} onPage={setPage} />
            </div>
          )}
        </QueryBoundary>
      )}
      {open && <DirectoryProfileModal providerId={open.id} onClose={() => setOpen(null)} />}
    </Card>
  );
};
