import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowRight, CheckCircle2, Eye, Factory as FactoryIcon, Info as InfoIcon, PauseCircle, Pencil, RotateCcw, XCircle } from 'lucide-react';
import { api } from '../../api';
import type { Factory } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { nameOf, useFactorySizes } from '../../hooks/useReference';
import { documentTypeLabel } from '../../lib/files';
import { formatDate, formatDateTime } from '../../lib/format';
import { allowedApprovalDecisions, type ApprovalDecision } from '../../lib/provider';
import { factoryCompletion } from '../../lib/profileCompletion';
import { ApprovalDecisionModal } from '../../components/admin/ApprovalDecisionModal';
import { AssessmentDetailModal } from '../../components/admin/AssessmentDetailModal';
import { FactoryChangeQueue } from '../../components/admin/FactoryChangeQueue';
import { FactoryFormModal } from '../../components/admin/FactoryFormModal';
import { ReviewHistory } from '../../components/admin/ReviewHistory';
import { FactoryPlanCard } from '../../components/roadmap-admin/FactoryPlanCard';
import { ApprovalBadge } from '../../components/ui/ApprovalBadge';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { CardSkeleton } from '../../components/ui/LoadingState';
import { DocumentRow, PrivateImage } from '../../components/ui/PrivateFile';
import { QueryBoundary } from '../../components/ui/QueryBoundary';
import { ReadinessBadge } from '../../components/ui/ReadinessBadge';

const FIELD_LABELS: Record<string, string> = {
  legal_name: 'الاسم القانوني',
  contact_name: 'اسم مسؤول التواصل',
  contact_email: 'البريد الإلكتروني',
  contact_phone: 'الهاتف',
  governorate: 'المحافظة',
  city: 'المدينة',
  address: 'العنوان',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
  sectors: 'القطاعات',
};

const DECISION_BUTTONS: Record<ApprovalDecision, { label: string; variant: 'success' | 'danger' | 'outline'; icon: typeof CheckCircle2 }> = {
  approved: { label: 'اعتماد المنشأة', variant: 'success', icon: CheckCircle2 },
  changes_requested: { label: 'طلب استكمال البيانات', variant: 'outline', icon: RotateCcw },
  rejected: { label: 'رفض الاعتماد', variant: 'danger', icon: XCircle },
  suspended: { label: 'إيقاف الاعتماد', variant: 'danger', icon: PauseCircle },
};

/**
 * Everything an IMC reviewer needs to decide on one factory account (ADR-021): the submitted profile,
 * private documents, legal change requests, the readiness assessment summary and history, missing
 * information and the review history. The approval never changes an assessment, its score or its
 * category: those are computed by the server from the answers (ADR-018).
 */
export const FactoryApprovalDetail: React.FC = () => {
  const id = Number.parseInt(useParams().id ?? '', 10);
  const factory = useApiQuery((signal) => api.factories.get(id, signal), [id], { enabled: Number.isFinite(id) });
  const assessments = useApiQuery((signal) => api.readiness.list(id, { per_page: 20, signal }), [id], { enabled: Number.isFinite(id) });
  const sizes = useFactorySizes();
  const [deciding, setDeciding] = useState<ApprovalDecision | null>(null);
  const [viewing, setViewing] = useState<number | null>(null);
  const [editing, setEditing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [historyKey, setHistoryKey] = useState(0);

  const changed = (message: string) => {
    setNotice(message);
    setHistoryKey((n) => n + 1);
    factory.refetch();
  };

  return (
    <div className="space-y-6">
      <Link to="/admin/approvals/factories" className="inline-flex items-center gap-1 text-xs font-semibold text-[#5146A5] hover:underline">
        <ArrowRight className="w-3.5 h-3.5" /> العودة إلى قائمة اعتماد المنشآت
      </Link>

      <QueryBoundary query={factory} loading={<CardSkeleton />}>
        {(f) => {
          const completion = factoryCompletion(f);
          const missingRequired = f.onboarding?.missing_profile_fields ?? [];
          const logo = f.documents?.logo ?? null;
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

              <div className="jahez-card p-5 flex flex-col md:flex-row md:items-center gap-4 border-r-4 border-r-[#6EC8FF]">
                <div className="w-20 h-20 rounded-2xl border border-[#E6EAF0] bg-white flex items-center justify-center overflow-hidden shrink-0">
                  {logo ? (
                    <PrivateImage
                      load={(signal) => api.factories.documentFile(f.id, logo.id, signal)}
                      version={logo.id}
                      alt={f.name}
                      className="w-full h-full object-contain"
                      fallback={<FactoryIcon className="w-8 h-8 text-[#0A6EB0]" />}
                    />
                  ) : (
                    <FactoryIcon className="w-8 h-8 text-[#0A6EB0]" />
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-xl font-bold text-[#172033]">{f.name}</h2>
                    <ApprovalBadge status={f.approval.status} />
                  </div>
                  <p className="text-xs text-[#667085] mt-1" dir="auto">{f.legal_name ?? 'لم يُسجَّل الاسم القانوني'}</p>
                  <p className="text-[11px] text-[#98A2B3] mt-1">
                    مسجّلة منذ {formatDate(f.created_at)} · آخر قرار {formatDateTime(f.approval.changed_at)}
                    {!f.approval.may_send_requests && ' · لا تستطيع إرسال طلبات خدمة قبل الاعتماد'}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2" data-testid="decision-bar">
                  {allowedApprovalDecisions(f.approval.status).map((decision) => {
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

              {f.approval.reason && (
                <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-xs">
                  <span className="text-[10px] font-bold text-[#98A2B3] block mb-0.5">سبب آخر قرار</span>
                  <span className="text-[#172033] whitespace-pre-line" dir="auto">{f.approval.reason}</span>
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
                  <Card title="بيانات المنشأة والتواصل" subtitle="كما قدّمتها المنشأة؛ للمراجعين المخوّلين فقط" accent="blue">
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs">
                      <Info label="الحجم" value={f.size ? nameOf(sizes.data, f.size) : null} />
                      <Info label="اسم مسؤول التواصل" value={f.contact_name} />
                      <Info label="المسمى الوظيفي" value={f.contact_job_title} />
                      <Info label="البريد الإلكتروني" value={f.contact_email} ltr />
                      <Info label="الهاتف" value={f.contact_phone} ltr />
                      <Info label="الموقع الإلكتروني" value={f.website} ltr />
                      <Info label="المحافظة" value={f.governorate} />
                      <Info label="المدينة" value={f.city} />
                      <Info label="العنوان" value={f.address} />
                      <Info label="رقم السجل التجاري" value={f.commercial_registration_number} />
                      <Info label="رقم التسجيل الضريبي" value={f.tax_registration_number} />
                    </div>
                    <div className="mt-4 pt-3 border-t border-[#E6EAF0]">
                      <span className="text-[#98A2B3] block text-[10px] mb-1">القطاعات</span>
                      <div className="flex flex-wrap gap-1">
                        {(f.sectors ?? []).length === 0 ? (
                          <span className="text-xs text-[#98A2B3]">—</span>
                        ) : (
                          (f.sectors ?? []).map((sector) => (
                            <span key={sector.code} className="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-[#DFF3FF] text-[#0A6EB0]">
                              {sector.name_ar}
                            </span>
                          ))
                        )}
                      </div>
                    </div>
                  </Card>

                  <Card title="المستندات" subtitle="ملفات خاصة تُقرأ بجلسة المراجع، ولا تُتاح عبر رابط عام" accent="blue">
                    <div className="space-y-2">
                      {(['commercial_registration', 'tax_registration'] as const).map((type) => {
                        const document = f.documents?.[type] ?? null;
                        return document ? (
                          <DocumentRow key={type} document={document} label={documentTypeLabel[type]} load={() => api.factories.documentFile(f.id, document.id)} />
                        ) : (
                          <p key={type} className="text-xs text-[#A66F0B]">
                            {documentTypeLabel[type]}: لم يُرفع بعد.
                          </p>
                        );
                      })}
                    </div>
                  </Card>

                  <FactoryChangeQueue factoryId={f.id} onDecided={() => changed('تم تسجيل القرار على طلب التعديل.')} />

                  <Card title="تقييم الجاهزية الرقمية" subtitle="يحسبه الخادم من الإجابات؛ لا يغيّره قرار الاعتماد" accent="purple">
                    <div className="p-3 mb-4 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0] text-[11px] text-[#667085] flex gap-2">
                      <InfoIcon className="w-4 h-4 text-[#5146A5] shrink-0" />
                      الفئة ناتجة عن مجموع نقاط الإجابات (10–40) وفق حدود الإصدار المُجاب عنه، ولا يوجد تعديل يدوي للفئة أو الدرجة.
                    </div>
                    {f.current_readiness ? (
                      <div className="flex flex-wrap items-center gap-4 text-xs mb-4">
                        <ReadinessBadge category={f.current_readiness.category} />
                        <span>
                          الدرجة: <strong className="text-[#172033] text-base">{f.current_readiness.total_score}</strong> / 40
                        </span>
                        <span className="text-[#98A2B3]">
                          الإصدار {f.current_readiness.questionnaire_version} · {formatDateTime(f.current_readiness.completed_at)}
                        </span>
                        {f.readiness_level?.unlocked_by === 'plan_completion' && (
                          <span className="flex items-center gap-2">
                            المستوى المفتوح: <ReadinessBadge category={f.readiness_level} size="sm" />
                            <span className="text-[#98A2B3]">بإتمام خدمات خطة التحول · {f.readiness_level.unlocked_at ? formatDateTime(f.readiness_level.unlocked_at) : ''}</span>
                          </span>
                        )}
                      </div>
                    ) : (
                      <p className="text-xs text-[#A66F0B] font-semibold mb-4">لم تُكمل المنشأة تقييم الجاهزية الرقمية بعد.</p>
                    )}
                    <QueryBoundary
                      query={assessments}
                      loading={<CardSkeleton />}
                      isEmpty={(page) => page.data.length === 0}
                      empty={<span />}
                    >
                      {(page) => (
                        <ul className="divide-y divide-[#F1F4F9] text-xs">
                          {page.data.map((assessment) => (
                            <li key={assessment.id} className="py-2.5 flex items-center gap-3">
                              <span className="text-[#667085] w-36">{formatDateTime(assessment.completed_at)}</span>
                              <span className="font-bold text-[#172033] w-10">{assessment.total_score}</span>
                              <ReadinessBadge category={assessment.category} size="sm" />
                              <span className="text-[#98A2B3]">إصدار {assessment.questionnaire_version}</span>
                              <Button variant="ghost" size="sm" icon={Eye} className="mr-auto" onClick={() => setViewing(assessment.id)}>
                                الإجابات
                              </Button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </QueryBoundary>
                  </Card>

                  <FactoryPlanCard factory={f} />
                </div>

                <div className="lg:col-span-4 space-y-6">
                  <ReviewHistory subjectType="factory" subjectId={f.id} refreshKey={historyKey} />
                </div>
              </div>

              {deciding && (
                <ApprovalDecisionModal<Factory>
                  subject="factory"
                  decision={deciding}
                  subtitle={`المنشأة: ${f.name}`}
                  submit={(payload) => api.factories.decide(f.id, payload)}
                  onClose={() => setDeciding(null)}
                  onDone={() => {
                    setDeciding(null);
                    changed('تم تسجيل القرار وإبلاغ المنشأة.');
                  }}
                />
              )}
              {viewing !== null && <AssessmentDetailModal factoryId={f.id} assessmentId={viewing} factoryName={f.name} onClose={() => setViewing(null)} />}
              {editing && (
                <FactoryFormModal
                  factory={f}
                  onClose={() => setEditing(false)}
                  onSaved={() => {
                    setEditing(false);
                    changed('تم حفظ بيانات المنشأة.');
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

const Info: React.FC<{ label: string; value: string | null | undefined; ltr?: boolean }> = ({ label, value, ltr }) => (
  <div className="min-w-0">
    <span className="text-[#98A2B3] block text-[10px]">{label}</span>
    <span className="font-semibold text-[#172033] block break-words" dir={ltr ? 'ltr' : 'auto'}>
      {value === null || value === undefined || value === '' ? '—' : value}
    </span>
  </div>
);
