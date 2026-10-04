import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowRight, Building2, CheckCircle2, ExternalLink, PauseCircle, Pencil, RotateCcw, XCircle } from 'lucide-react';
import { api } from '../../api';
import type { ProviderServiceListing, ServiceProvider } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate, formatDateTime } from '../../lib/format';
import { allowedApprovalDecisions, allowedListingDecisions, type ApprovalDecision } from '../../lib/provider';
import { providerCompletion } from '../../lib/profileCompletion';
import { ApprovalDecisionModal } from '../../components/admin/ApprovalDecisionModal';
import { ProviderFormModal } from '../../components/admin/ProviderFormModal';
import { ProviderLegalReview } from '../../components/admin/ProviderLegalReview';
import { EvaluationPanel } from '../../components/admin/ProviderEvaluationPanel';
import { ReviewHistory } from '../../components/admin/ReviewHistory';
import { ApprovalBadge, ListingStatusBadge } from '../../components/ui/ApprovalBadge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { PrivateImage } from '../../components/ui/PrivateFile';
import { QueryBoundary } from '../../components/ui/QueryBoundary';

/** API field names (OQ-36 configuration) in Arabic. */
const FIELD_LABELS: Record<string, string> = {
  legal_name: 'الاسم القانوني',
  description: 'نبذة عن الشركة',
  representative_name: 'اسم الممثل',
  job_title: 'المسمى الوظيفي',
  email: 'البريد الإلكتروني',
  phone: 'الهاتف',
  website: 'الموقع الإلكتروني',
  dx_experience_years: 'سنوات الخبرة',
  governorate: 'المحافظة',
  city: 'المدينة',
  address: 'العنوان',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
  sectors: 'القطاعات المستهدفة',
  services: 'الخدمات',
};

const DECISION_BUTTONS: Record<ApprovalDecision, { label: string; variant: 'success' | 'danger' | 'outline' | 'primary'; icon: typeof CheckCircle2 }> = {
  approved: { label: 'اعتماد المزود', variant: 'success', icon: CheckCircle2 },
  changes_requested: { label: 'طلب استكمال البيانات', variant: 'outline', icon: RotateCcw },
  rejected: { label: 'رفض الاعتماد', variant: 'danger', icon: XCircle },
  suspended: { label: 'إيقاف الاعتماد', variant: 'danger', icon: PauseCircle },
};

/**
 * Everything an IMC reviewer needs to decide on one provider (ADR-014, ADR-021): the submitted
 * business, contact and legal details, the private documents (read with the session token), each
 * listed service with its own decision, the DOC §6 evaluation, missing information and the review
 * history. Decisions are persisted by the API, audited and notified to the provider.
 */
export const ProviderApprovalDetail: React.FC = () => {
  const id = Number.parseInt(useParams().id ?? '', 10);
  const provider = useApiQuery((signal) => api.serviceProviders.get(id, signal), [id], { enabled: Number.isFinite(id) });
  const evaluations = useApiQuery((signal) => api.serviceProviders.evaluations.list(id, signal), [id], { enabled: Number.isFinite(id) });
  const [deciding, setDeciding] = useState<ApprovalDecision | null>(null);
  const [listingDecision, setListingDecision] = useState<{ listing: ProviderServiceListing; decision: ApprovalDecision } | null>(null);
  const [editing, setEditing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [historyKey, setHistoryKey] = useState(0);

  const changed = (message: string) => {
    setNotice(message);
    setHistoryKey((n) => n + 1);
    provider.refetch();
  };

  return (
    <div className="space-y-6">
      <Link to="/admin/approvals/providers" className="inline-flex items-center gap-1 text-xs font-semibold text-[#5146A5] hover:underline">
        <ArrowRight className="w-3.5 h-3.5" /> العودة إلى قائمة اعتماد المزودين
      </Link>

      <QueryBoundary query={provider} loading={<CardSkeleton />}>
        {(p) => {
          const completion = providerCompletion(p);
          const missingRequired = p.missing_required_fields ?? [];
          const listings = p.service_listings ?? [];
          const logo = p.documents?.logo ?? null;
          return (
            <>
              {notice && (
                <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center justify-between">
                  <span className="flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4" />
                    {notice}
                  </span>
                  <button onClick={() => setNotice(null)} className="hover:underline cursor-pointer">
                    إغلاق
                  </button>
                </div>
              )}

              <div className="jahez-card p-5 flex flex-col md:flex-row md:items-center gap-4 border-r-4 border-r-[#5146A5]">
                <div className="w-20 h-20 rounded-2xl border border-[#E6EAF0] bg-white flex items-center justify-center overflow-hidden shrink-0">
                  {logo ? (
                    <PrivateImage
                      load={(signal) => api.serviceProviders.documentFile(p.id, logo.id, signal)}
                      version={logo.id}
                      alt={p.name}
                      className="w-full h-full object-contain"
                      fallback={<Building2 className="w-8 h-8 text-[#5146A5]" />}
                    />
                  ) : (
                    <Building2 className="w-8 h-8 text-[#5146A5]" />
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-xl font-bold text-[#172033]">{p.name}</h2>
                    <ApprovalBadge status={p.approval.status} />
                  </div>
                  <p className="text-xs text-[#667085] mt-1" dir="auto">{p.legal_name ?? 'لم يُسجَّل الاسم القانوني'}</p>
                  <p className="text-[11px] text-[#98A2B3] mt-1">
                    مسجّل منذ {formatDate(p.created_at)} · آخر قرار {formatDateTime(p.approval.changed_at)}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2" data-testid="decision-bar">
                  {allowedApprovalDecisions(p.approval.status).map((decision) => {
                    const button = DECISION_BUTTONS[decision];
                    return (
                      <Button key={decision} variant={button.variant} size="sm" icon={button.icon} onClick={() => setDeciding(decision)} data-decision={decision}>
                        {button.label}
                      </Button>
                    );
                  })}
                  <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(true)}>
                    تعديل
                  </Button>
                </div>
              </div>

              {p.approval.reason && (
                <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-xs">
                  <span className="text-[10px] font-bold text-[#98A2B3] block mb-0.5">سبب آخر قرار</span>
                  <span className="text-[#172033] whitespace-pre-line" dir="auto">{p.approval.reason}</span>
                </div>
              )}

              {(missingRequired.length > 0 || completion.missing.length > 0) && (
                <div className="p-4 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs space-y-1.5" data-testid="missing-information">
                  <div className="font-bold text-[#A66F0B] flex items-center gap-2">
                    <AlertTriangle className="w-4 h-4" /> بيانات ناقصة
                  </div>
                  {missingRequired.length > 0 && (
                    <p className="text-[#8C5D08]">
                      حقول مطلوبة للاعتماد (يرفض الخادم الاعتماد قبل استكمالها): {missingRequired.map((field) => FIELD_LABELS[field] ?? field).join('، ')}.
                    </p>
                  )}
                  {completion.missing.length > 0 && (
                    <p className="text-[#8C5D08]">
                      حقول غير مكتملة في الملف ({completion.percent}%): {completion.missing.join('، ')}.
                    </p>
                  )}
                </div>
              )}

              <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <div className="lg:col-span-8 space-y-6">
                  <BusinessDetails provider={p} />

                  <Card title={`الخدمات المدرجة (${listings.length})`} subtitle="لكل خدمة قرار اعتماد مستقل؛ لا تصل الخدمة إلى المصانع إلا إذا اعتُمدت هي والمزود معًا" accent="purple">
                    {listings.length === 0 ? (
                      <p className="text-xs text-[#667085]">لم يُدرج المزود أي خدمة.</p>
                    ) : (
                      <ul className="divide-y divide-[#F1F4F9]" data-testid="service-listings">
                        {listings.map((listing) => (
                          <li key={listing.service.id} className="py-3 flex flex-col sm:flex-row sm:items-center gap-3" data-listing-service={listing.service.code}>
                            <div className="flex-1 min-w-0 text-xs">
                              <div className="flex flex-wrap items-center gap-2">
                                <span className="font-bold text-[#172033]">{listing.service.name_ar}</span>
                                <ListingStatusBadge status={listing.status} size="sm" />
                              </div>
                              <div className="text-[11px] text-[#98A2B3] mt-0.5">
                                {listing.service.category?.name_ar} · أُدرجت {formatDate(listing.submitted_at)}
                                {listing.changed_at ? ` · آخر قرار ${formatDate(listing.changed_at)}` : ''}
                              </div>
                              {listing.reason && <div className="text-[11px] text-[#667085] mt-1" dir="auto">السبب: {listing.reason}</div>}
                            </div>
                            <div className="flex flex-wrap gap-2 shrink-0">
                              {allowedListingDecisions(listing.status).map((decision) => (
                                <Button
                                  key={decision}
                                  size="sm"
                                  variant={decision === 'approved' ? 'success' : decision === 'rejected' ? 'danger' : 'outline'}
                                  onClick={() => setListingDecision({ listing, decision })}
                                  data-listing-decision={decision}
                                >
                                  {decision === 'approved' ? 'اعتماد' : decision === 'rejected' ? 'رفض' : 'إيقاف'}
                                </Button>
                              ))}
                            </div>
                          </li>
                        ))}
                      </ul>
                    )}
                  </Card>

                  <ProviderLegalReview provider={p} onChanged={changed} />

                  <EvaluationPanel
                    providerId={p.id}
                    evaluations={evaluations}
                    onRecorded={() => {
                      evaluations.refetch();
                      changed('تم تسجيل التقييم. التقييمات لا تُعدَّل، وأي إعادة تقييم تُضاف كسجل جديد.');
                    }}
                  />
                </div>

                <div className="lg:col-span-4 space-y-6">
                  <ReviewHistory subjectType="service_provider" subjectId={p.id} refreshKey={historyKey} />
                </div>
              </div>

              {deciding && (
                <ApprovalDecisionModal<ServiceProvider>
                  subject="provider"
                  decision={deciding}
                  subtitle={`المزود: ${p.name}`}
                  submit={(payload) => api.serviceProviders.decide(p.id, payload)}
                  onClose={() => setDeciding(null)}
                  onDone={() => {
                    setDeciding(null);
                    changed('تم تسجيل القرار وإبلاغ المزود.');
                  }}
                />
              )}
              {listingDecision && (
                <ApprovalDecisionModal<ServiceProvider>
                  subject="listing"
                  decision={listingDecision.decision}
                  subtitle={`${listingDecision.listing.service.name_ar} — ${p.name}`}
                  submit={(payload) =>
                    api.serviceProviders.reviewListing(p.id, listingDecision.listing.service.id, {
                      decision: payload.decision === 'changes_requested' ? 'rejected' : payload.decision,
                      reason: payload.reason,
                    })
                  }
                  onClose={() => setListingDecision(null)}
                  onDone={() => {
                    setListingDecision(null);
                    changed('تم تسجيل قرار الخدمة وإبلاغ المزود.');
                  }}
                />
              )}
              {editing && (
                <ProviderFormModal
                  provider={p}
                  onClose={() => setEditing(false)}
                  onSaved={() => {
                    setEditing(false);
                    changed('تم حفظ بيانات المزود.');
                  }}
                />
              )}
            </>
          );
        }}
      </QueryBoundary>
    </div>
  );
};

const Info: React.FC<{ label: string; value: React.ReactNode; ltr?: boolean }> = ({ label, value, ltr }) => (
  <div className="min-w-0">
    <span className="text-[#98A2B3] block text-[10px]">{label}</span>
    <span className="font-semibold text-[#172033] block break-words" dir={ltr ? 'ltr' : 'auto'}>
      {value === null || value === undefined || value === '' ? '—' : value}
    </span>
  </div>
);

const BusinessDetails: React.FC<{ provider: ServiceProvider }> = ({ provider: p }) => (
  <Card title="بيانات الشركة والتواصل" subtitle="كما قدّمها المزود؛ بيانات التواصل للمراجعين المخوّلين فقط" accent="blue">
    {p.description && <p className="text-xs text-[#172033] leading-relaxed mb-4 whitespace-pre-line" dir="auto">{p.description}</p>}
    <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs">
      <Info label="اسم الممثل" value={p.representative_name} />
      <Info label="المسمى الوظيفي" value={p.job_title} />
      <Info label="سنوات الخبرة في التحول الرقمي" value={p.dx_experience_years === null ? null : `${p.dx_experience_years} سنة`} />
      <Info label="البريد الإلكتروني" value={p.email} ltr />
      <Info label="الهاتف" value={p.phone} ltr />
      <Info
        label="الموقع الإلكتروني"
        value={
          p.website ? (
            <a href={p.website} target="_blank" rel="noreferrer" className="text-[#0A6EB0] hover:underline inline-flex items-center gap-1">
              {p.website} <ExternalLink className="w-3 h-3" />
            </a>
          ) : null
        }
        ltr
      />
      <Info label="المحافظة" value={p.governorate} />
      <Info label="المدينة" value={p.city} />
      <Info label="العنوان" value={p.address} />
    </div>
    <div className="mt-4 pt-3 border-t border-[#E6EAF0]">
      <span className="text-[#98A2B3] block text-[10px] mb-1">القطاعات المستهدفة</span>
      <div className="flex flex-wrap gap-1">
        {(p.sectors ?? []).length === 0 ? (
          <span className="text-xs text-[#98A2B3]">—</span>
        ) : (
          (p.sectors ?? []).map((sector) => (
            <span key={sector.code} className="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-[#DFF3FF] text-[#0A6EB0]">
              {sector.name_ar}
            </span>
          ))
        )}
      </div>
    </div>
  </Card>
);
