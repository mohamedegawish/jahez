import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle2, Info, Pencil, RotateCcw, Save, X } from 'lucide-react';
import { api } from '../../api';
import type { ServiceListing, ServiceProvider } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useMyProvider } from '../../hooks/useMyOrganization';
import { ServiceListingCard } from '../../components/listings/ServiceListingCard';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { ApprovalBadge, ListingStatusBadge } from '../../components/ui/ApprovalBadge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { Pagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ReasonModal } from '../../components/ui/ReasonModal';
import { ServicePicker } from '../../components/ui/ServicePicker';

/**
 * The provider's own listings: one card per catalog service it offers. The service name and category
 * are IMC's fixed catalog; the description, logo and experience are the provider's profile; an «إعلان»
 * label is an IMC promotion. Visibility to factories follows IMC's approval of the provider. Prices are
 * not part of a listing: they are given in each offer.
 */
export const ProviderServices: React.FC = () => {
  const navigate = useNavigate();
  const provider = useMyProvider();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(false);
  const [saved, setSaved] = useState(false);
  const [resubmitting, setResubmitting] = useState<ServiceListing | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const listings = useApiQuery((signal) => api.serviceListings.list({ page, per_page: 12, signal }), [page]);

  return (
    <QueryBoundary query={provider} loading={<CardSkeleton />}>
      {(p) => (
        <div className="space-y-6">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">خدماتي</h2>
              <p className="text-xs sm:text-sm text-[#667085] mt-0.5">الخدمات التي تقدمها شركتكم من كتالوج الخدمات المعتمد، كما تظهر للمصانع المؤهلة.</p>
            </div>
            <div className="flex items-center gap-3">
              <ApprovalBadge status={p.approval.status} />
              <Button variant={editing ? 'outline' : 'primary'} size="sm" icon={editing ? X : Pencil} onClick={() => { setEditing(!editing); setSaved(false); }}>
                {editing ? 'إغلاق التعديل' : 'تعديل الخدمات المقدمة'}
              </Button>
            </div>
          </div>

          {p.approval.status !== 'approved' && (
            <div role="status" className="p-3.5 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs font-semibold text-[#A66F0B]">
              {p.approval.status === 'suspended'
                ? 'اعتماد شركتكم موقوف: لا تظهر خدماتكم للمصانع وتتوقف طلباتكم المفتوحة حتى إعادة الاعتماد.'
                : 'لا تظهر خدماتكم للمصانع قبل اعتماد ملف الشركة من مركز تحديث الصناعة.'}
            </div>
          )}
          {notice && (
            <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4" /> {notice}
            </div>
          )}
          {resubmitting?.service && (
            <ReasonModal
              title="إعادة تقديم الخدمة للمراجعة"
              subtitle={resubmitting.service.name_ar}
              description="صحّحوا بيانات ملف الشركة المطلوبة أولًا. تعود الخدمة بعد الإرسال إلى «بانتظار الاعتماد» ولا تظهر للمصانع قبل قرار المركز."
              label="ما الذي تم تصحيحه؟ (اختياري)"
              confirmLabel="إرسال للمراجعة"
              onConfirm={(note) => api.serviceProviders.resubmitListing(p.id, resubmitting.service!.id, note.trim() || null)}
              onDone={() => {
                setResubmitting(null);
                setNotice("أُرسلت الخدمة لمراجعة المركز من جديد.");
                listings.refetch();
              }}
              onClose={() => setResubmitting(null)}
            />
          )}
          {saved && (
            <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4" /> تم حفظ الخدمات التي تقدمها شركتكم.
            </div>
          )}

          {editing && (
            <ServicesEditor
              key={p.updated_at ?? p.id}
              provider={p}
              onSaved={() => {
                setSaved(true);
                setEditing(false);
                provider.refetch();
                listings.refetch();
              }}
            />
          )}

          <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-[11px] text-[#667085] flex items-start gap-2">
            <Info className="w-4 h-4 text-[#5146A5] shrink-0" />
            <span>
              اسم الخدمة وفئتها من الكتالوج الذي يديره المركز ولا يمكن تعديلهما. كل خدمة تضيفونها تُراجع وتُعتمد من المركز قبل أن تظهر للمصانع، ولا يغيّر تعديل
              قائمتكم حالة الخدمات المعتمدة سابقًا. الوصف والشعار وسنوات الخبرة من ملف شركتكم في الإعدادات. علامة «إعلان» تعني ترويجًا يضعه المركز، ولا يُطلب من هنا.
            </span>
          </div>

          <QueryBoundary
            query={listings}
            loading={<CardSkeleton />}
            isEmpty={(result) => result.data.length === 0}
            empty={
              <EmptyState
                title="لم تحددوا أي خدمة بعد"
                description="اختاروا من الكتالوج الخدمات التي تقدمها شركتكم لتظهر للمصانع المؤهلة بعد اعتماد ملفكم."
                actionText="تعديل الخدمات المقدمة"
                onAction={() => setEditing(true)}
              />
            }
          >
            {(result) => (
              <div className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
                  {result.data.map((listing) => (
                    <ServiceListingCard
                      key={listing.id}
                      listing={listing}
                      showApproval
                      detailsPath={`/provider/requests?service=${listing.service?.code ?? ''}`}
                      footer={
                        <div className="space-y-2">
                          {listing.review && (
                            <div className="text-[11px] space-y-1" data-listing-status={listing.review.status}>
                              <div className="flex items-center justify-between gap-2">
                                <span className="text-[#667085]">اعتماد المركز للخدمة:</span>
                                <ListingStatusBadge status={listing.review.status} size="sm" />
                              </div>
                              {listing.review.status !== 'approved' && (
                                <p className="text-[#A66F0B]">
                                  {listing.review.status === 'pending' ? 'لا تظهر هذه الخدمة للمصانع قبل اعتمادها.' : 'لا تظهر هذه الخدمة للمصانع.'}
                                </p>
                              )}
                              {listing.review.reason && <p className="text-[#667085]" dir="auto">السبب: {listing.review.reason}</p>}
                              {listing.review.status === 'rejected' && (
                                <Button variant="primary" size="sm" icon={RotateCcw} className="w-full" onClick={() => setResubmitting(listing)} data-action="resubmit">
                                  إعادة التقديم للمراجعة
                                </Button>
                              )}
                            </div>
                          )}
                          <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" className="flex-1" onClick={() => navigate(`/provider/requests?service=${listing.service?.code ?? ''}`)}>
                              طلبات هذه الخدمة
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => navigate('/provider/settings')}>
                              الملف التعريفي
                            </Button>
                          </div>
                        </div>
                      }
                    />
                  ))}
                </div>
                <Pagination meta={result.meta} onPage={setPage} />
              </div>
            )}
          </QueryBoundary>
        </div>
      )}
    </QueryBoundary>
  );
};

const ServicesEditor: React.FC<{ provider: ServiceProvider; onSaved: () => void }> = ({ provider, onSaved }) => {
  const initial = provider.services?.map((s) => s.code) ?? [];
  const [selected, setSelected] = useState<string[]>(initial);
  const save = useApiMutation(() => api.serviceProviders.update(provider.id, { services: selected }));
  const changed = selected.length !== initial.length || selected.some((code) => !initial.includes(code));

  return (
    <Card
      title="خدمات الكتالوج التي تقدمها شركتكم"
      subtitle={`${selected.length} خدمة محددة · الكتالوج ثابت ولا تُضاف إليه خدمات`}
      accent="blue"
      action={
        <Button
          variant="primary"
          size="sm"
          icon={Save}
          isLoading={save.pending}
          disabled={!changed}
          onClick={async () => {
            const result = await save.run();
            if (result.ok) onSaved();
          }}
        >
          حفظ الخدمات
        </Button>
      }
    >
      {save.error !== null && <div className="mb-3"><ApiErrorState compact error={save.error} /></div>}
      <ServicePicker value={selected} onChange={setSelected} disabled={save.pending} />
    </Card>
  );
};
