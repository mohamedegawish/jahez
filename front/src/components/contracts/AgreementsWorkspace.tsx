import React, { useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Check, CheckCircle2, FilePlus2, MessageSquareCode, Receipt, ShieldAlert } from 'lucide-react';
import { api } from '../../api';
import type { Agreement, Contract } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { useSearchBox } from '../../hooks/useListParams';
import { formatDate, formatDateTime, formatMoney } from '../../lib/format';
import { ContractDraftModal } from './ContractDraftModal';
import { FinancialReadinessNotice } from '../financial/FinancialReadinessNotice';
import { ReviewStatusBadge } from '../marketplace/LifecycleBadge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { CardSkeleton } from '../ui/LoadingState';
import { Pagination } from '../ui/Pagination';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReasonModal } from '../ui/ReasonModal';
import { SearchInput } from '../ui/SearchInput';
import { ContractStatusBadge } from '../ui/StatusBadges';
import { Tabs } from '../ui/Tabs';
import { UnavailableNotice } from '../ui/UnavailableNotice';

interface AgreementsWorkspaceProps {
  mySide: 'factory' | 'provider';
}

const REVIEW_TABS = [
  { id: '', label: 'الكل' },
  { id: 'pending', label: 'بانتظار اعتماد المركز' },
  { id: 'approved', label: 'معتمدة' },
  { id: 'rejected', label: 'مرفوضة' },
];

/**
 * Agreements and their contract drafts, for either party. Four records are kept apart: the request and
 * its negotiation (the thread), the agreed proposal (the agreement, never changed), IMC's approval of it,
 * and the contract drafts that may follow the approval. No draft is binding and nothing is signed or
 * activated: those steps do not exist until the legal framework is decided (OQ-17).
 */
export const AgreementsWorkspace: React.FC<AgreementsWorkspaceProps> = ({ mySide }) => {
  const navigate = useNavigate();
  const { id } = useParams<{ id?: string }>();
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const review = params.get('review') ?? '';
  const searchText = params.get('search') ?? '';
  const base = mySide === 'factory' ? '/factory/contracts' : '/provider/contracts';
  const routeId = id && /^\d+$/.test(id) ? Number(id) : null;
  const legacyId = Number.parseInt(params.get('agreement') ?? '', 10) || null;

  const set = (changes: Record<string, string | null>) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        for (const [key, value] of Object.entries(changes)) {
          if (value === null || value === '') next.delete(key);
          else next.set(key, value);
        }
        return next;
      },
      { replace: true },
    );
  const search = useSearchBox(searchText, (value) => set({ search: value, page: null }));

  const agreements = useApiQuery(
    (signal) =>
      api.agreements.list({
        page,
        per_page: 10,
        search: searchText || undefined,
        filter: review ? { review_status: review as 'pending' | 'approved' | 'rejected' } : undefined,
        signal,
      }),
    [page, review, searchText],
  );
  const selectedId = routeId ?? legacyId ?? agreements.data?.data[0]?.id ?? null;

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">العقود</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          الاتفاقية هي العرض الذي قبله المصنع، ولا تُعد اشتراكًا معتمدًا قبل اعتماد مركز تحديث الصناعة. بعد الاعتماد يُعدّ أحد الطرفين مسودة عقد غير ملزمة قانونيًا.
        </p>
      </div>

      <Card className="p-4 space-y-3">
        <SearchInput value={search.value} onChange={search.onChange} placeholder={mySide === 'factory' ? 'ابحث باسم المزود أو الخدمة...' : 'ابحث باسم المصنع أو الخدمة...'} />
        <Tabs tabs={REVIEW_TABS} activeTab={review} onChange={(tab) => set({ review: tab, page: null })} />
      </Card>

      <QueryBoundary
        query={agreements}
        loading={<CardSkeleton />}
        isEmpty={(result) => result.data.length === 0 && selectedId === null}
        empty={
          <EmptyState
            title={page > 1 ? 'هذه الصفحة فارغة' : review || searchText ? 'لا توجد اتفاقيات مطابقة' : 'لا توجد اتفاقيات بعد'}
            description={
              page > 1
                ? 'رقم الصفحة المطلوب يتجاوز عدد الصفحات المتاحة.'
                : review || searchText
                  ? 'جرّبوا تغيير البحث أو التبويب.'
                  : mySide === 'factory'
                    ? 'تُنشأ الاتفاقية عند قبولكم لآخر نسخة من عرض مزود على أحد طلباتكم.'
                    : 'تُنشأ الاتفاقية عندما يقبل مصنع آخر نسخة من عرضكم.'
            }
            actionText={page > 1 || review || searchText ? 'عرض الكل' : undefined}
            onAction={page > 1 || review || searchText ? () => setParams(new URLSearchParams(), { replace: true }) : undefined}
          />
        }
      >
        {(result) => (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <div className="lg:col-span-4 space-y-3">
              <h3 className="text-sm font-bold text-[#172033] flex items-center justify-between">
                <span>الاتفاقيات</span>
                <span className="text-xs text-[#5146A5] bg-[#EEEAFE] px-2 py-0.5 rounded-full font-bold">{result.meta.total}</span>
              </h3>
              <div className="space-y-2">
                {result.data.map((agreement) => (
                  <div
                    key={agreement.id}
                    data-agreement-id={agreement.id}
                    onClick={() => navigate(`${base}/${agreement.id}${params.toString() ? `?${params.toString()}` : ''}`)}
                    className={`p-3.5 rounded-2xl border transition-all cursor-pointer ${
                      agreement.id === selectedId ? 'bg-white border-[#5146A5] shadow-md ring-2 ring-[#EEEAFE]' : 'bg-white border-[#E6EAF0] hover:border-[#CCD5E2]'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <h4 className="font-bold text-xs text-[#172033] line-clamp-1">{agreement.service?.name_ar}</h4>
                      <span className="font-mono text-[10px] font-bold text-[#5146A5]">#{agreement.id}</span>
                    </div>
                    <p className="text-[11px] text-[#667085] mt-1">{mySide === 'factory' ? agreement.provider?.name : agreement.factory?.name}</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                      <ReviewStatusBadge status={agreement.imc_review.status} />
                      {agreement.contract && <ContractStatusBadge status={agreement.contract.status} size="sm" />}
                    </div>
                    <div className="flex items-center justify-between mt-3 pt-2 border-t border-[#F1F4F9] text-[11px]">
                      <span className="font-bold text-[#35B779]">{formatMoney(agreement.price)}</span>
                      <span className="text-[#98A2B3]">{formatDate(agreement.concluded_at)}</span>
                    </div>
                  </div>
                ))}
              </div>
              <Pagination meta={result.meta} onPage={(n) => set({ page: String(n) })} />
            </div>

            <div className="lg:col-span-8">
              {selectedId !== null && <AgreementDetail key={selectedId} agreementId={selectedId} mySide={mySide} onChanged={agreements.refetch} />}
            </div>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
};

/** The path from request to contract draft, with the stages this agreement has reached. */
const Stages: React.FC<{ agreement: Agreement }> = ({ agreement }) => {
  const review = agreement.imc_review.status;
  const stages = [
    { label: 'طلب المصنع', done: true },
    { label: 'التفاوض والعروض', done: true },
    { label: 'الاتفاق على العرض', done: true },
    { label: review === 'rejected' ? 'رفض المركز' : 'اعتماد المركز', done: review !== 'pending', failed: review === 'rejected' },
    { label: 'مسودة عقد (غير ملزمة)', done: agreement.contract !== null },
  ];
  return (
    <ol className="flex flex-wrap items-center gap-2 text-[11px]">
      {stages.map((stage, index) => (
        <li key={stage.label} className="flex items-center gap-2">
          <span
            className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full font-semibold border ${
              stage.failed
                ? 'bg-[#FDECEE] border-[#F9C3C9] text-[#B82B3B]'
                : stage.done
                  ? 'bg-[#E7F8EE] border-[#C5F0D5] text-[#1D7E4C]'
                  : 'bg-[#F7F9FC] border-[#E6EAF0] text-[#98A2B3]'
            }`}
          >
            {stage.done && !stage.failed && <Check className="w-3 h-3" />}
            {stage.label}
          </span>
          {index < stages.length - 1 && <span className="text-[#CCD5E2]">←</span>}
        </li>
      ))}
    </ol>
  );
};

const AgreementDetail: React.FC<{ agreementId: number; mySide: 'factory' | 'provider'; onChanged: () => void }> = ({ agreementId, mySide, onChanged }) => {
  const navigate = useNavigate();
  const agreement = useApiQuery((signal) => api.agreements.get(agreementId, signal), [agreementId]);
  const contracts = useApiQuery((signal) => api.contracts.list({ filter: { agreement: agreementId }, per_page: 50, signal }), [agreementId]);
  const [drafting, setDrafting] = useState(false);
  const [cancelling, setCancelling] = useState<Contract | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const hasDraft = (contracts.data?.data ?? []).some((c) => c.status === 'draft');
  const invoicesPath = mySide === 'factory' ? '/factory/invoices' : '/provider/invoices';
  const requestPath = (a: Agreement) =>
    mySide === 'factory' ? `/factory/requests/${a.service_request_id}?thread=${a.provider_request_id}` : `/provider/requests/${a.provider_request_id}`;

  return (
    <QueryBoundary query={agreement} loading={<CardSkeleton />}>
      {(a) => {
        const approved = a.imc_review.status === 'approved' || !a.imc_review.required;
        return (
          <div className="space-y-5">
            {notice && (
              <div role="status" className="p-3.5 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center justify-between">
                <span className="flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4" />
                  {notice}
                </span>
                <button onClick={() => setNotice(null)} className="hover:underline cursor-pointer">إغلاق</button>
              </div>
            )}

            <Card
              title={`اتفاقية رقم ${a.id}`}
              subtitle={`${a.service?.name_ar ?? ''} · أُبرمت في ${formatDateTime(a.concluded_at)}${a.concluded_by ? ` بواسطة ${a.concluded_by.name}` : ''}`}
              action={
                <span className="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg bg-[#FEF5E7] text-[#A66F0B] border border-[#FDE5BE]">
                  <ShieldAlert className="w-3.5 h-3.5" /> غير ملزمة قانونيًا
                </span>
              }
              accent="purple"
            >
              <Stages agreement={a} />

              <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <Field label="المصنع" value={a.factory?.name} />
                <Field label="المزود" value={a.provider?.name} />
                <Field label="نوع العلاقة" value="اتفاقية خدمة بين مصنع ومزود · بإشراف المركز" />
                <Field label="الطلب" value={a.service_request ? `#${a.service_request.id} — ${a.service_request.title}` : `#${a.service_request_id}`} />
                <Field label="القيمة المتفق عليها" value={formatMoney(a.price)} strong />
                <Field label="مدة التنفيذ" value={a.terms ? `${a.terms.duration_days} يومًا` : '—'} />
              </div>

              <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <UnavailableNotice kind="decision" title="تاريخا البدء والانتهاء" decisionNeeded="OQ-17">
                  لا تُحدَّد تواريخ سريان للعقد قبل اعتماد إطاره القانوني؛ تُعرض مدة التنفيذ المتفق عليها فقط.
                </UnavailableNotice>
                <FinancialReadinessNotice agreementId={a.id} />
              </div>

              {a.terms && (
                <div className="mt-4 pt-4 border-t border-[#E6EAF0] space-y-3 text-xs">
                  <span className="font-bold text-[#172033] block">الشروط المتفق عليها (النسخة {a.terms.offer_version} من العرض)</span>
                  <div>
                    <span className="font-bold text-[#667085] block mb-0.5">نطاق العمل</span>
                    <p className="text-[#172033] leading-relaxed whitespace-pre-line" dir="auto">{a.terms.scope}</p>
                  </div>
                  <div>
                    <span className="font-bold text-[#667085] block mb-0.5">المخرجات</span>
                    <p className="text-[#172033] leading-relaxed whitespace-pre-line" dir="auto">{a.terms.deliverables}</p>
                  </div>
                </div>
              )}

              <div className="mt-4 pt-4 border-t border-[#E6EAF0] flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" icon={MessageSquareCode} onClick={() => navigate(requestPath(a))}>
                  الطلب وسجل التفاوض
                </Button>
                <Button variant="outline" size="sm" icon={Receipt} onClick={() => navigate(`${invoicesPath}?agreement=${a.id}`)}>
                  الفواتير
                </Button>
              </div>
            </Card>

            <Card title="اعتماد مركز تحديث الصناعة" subtitle="قرار المركز على الاتفاقية قبل أي مسودة عقد أو فاتورة" accent="gradient">
              <div className="space-y-2 text-xs">
                <div className="flex items-center gap-2">
                  <ReviewStatusBadge status={a.imc_review.status} size="md" />
                  {a.imc_review.decided_at && <span className="text-[#98A2B3]">في {formatDateTime(a.imc_review.decided_at)}{a.imc_review.reviewed_by ? ` · ${a.imc_review.reviewed_by.name}` : ''}</span>}
                </div>
                {a.imc_review.status === 'pending' && (
                  <p className="text-[#667085] leading-relaxed">الاتفاقية معروضة على المركز. لا تُعد اشتراكًا معتمدًا ولا يمكن إعداد مسودة عقد أو فاتورة لها قبل القرار.</p>
                )}
                {a.imc_review.reason && (
                  <p className="p-2.5 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-[#172033]" dir="auto">
                    سبب القرار: {a.imc_review.reason}
                  </p>
                )}
                {a.imc_review.status === 'rejected' && (
                  <UnavailableNotice kind="decision" title="ما بعد الرفض" decisionNeeded="OQ-43">
                    لم يُحدَّد بعد ما يترتب على رفض الاتفاقية بالنسبة للطلب وطرفيه. تبقى الاتفاقية وسجلها محفوظين.
                  </UnavailableNotice>
                )}
              </div>
            </Card>

            <Card
              title="مسودات العقد"
              subtitle="كل مسودة نسخة جديدة؛ تُحفظ الملغاة للسجل"
              accent="blue"
              action={
                <Button
                  variant="primary"
                  size="sm"
                  icon={FilePlus2}
                  data-action="draft-contract"
                  disabled={!approved || hasDraft || contracts.status !== 'success'}
                  title={!approved ? 'تتطلب مسودة العقد اعتماد المركز للاتفاقية أولًا' : undefined}
                  onClick={() => setDrafting(true)}
                >
                  إعداد مسودة عقد
                </Button>
              }
            >
              <div className="mb-4">
                <UnavailableNotice kind="decision" title="التوقيع والتفعيل والمستندات" decisionNeeded="OQ-17">
                  لا يوجد توقيع إلكتروني ولا تفعيل للعقد ولا مستندات عقد في الخادم؛ كل عقد مسودة غير ملزمة إلى أن تُعتمد الأطراف والقوالب والصلاحية القانونية.
                </UnavailableNotice>
              </div>
              {!approved && <p className="mb-3 text-[11px] text-[#A66F0B]">تُتاح مسودة العقد بعد اعتماد المركز للاتفاقية.</p>}
              {hasDraft && <p className="mb-3 text-[11px] text-[#98A2B3]">توجد مسودة سارية؛ ألغوها أولًا لإعداد نسخة جديدة.</p>}

              <QueryBoundary
                query={contracts}
                loading={<CardSkeleton />}
                isEmpty={(result) => result.data.length === 0}
                empty={<p className="text-xs text-[#667085] text-center py-6">لم تُعدّ أي مسودة عقد لهذه الاتفاقية بعد.</p>}
              >
                {(result) => (
                  <ul className="space-y-3">
                    {result.data.map((contract) => (
                      <ContractItem key={contract.id} contract={contract} onCancel={() => setCancelling(contract)} />
                    ))}
                  </ul>
                )}
              </QueryBoundary>
            </Card>

            {drafting && (
              <ContractDraftModal
                agreement={a}
                onClose={() => setDrafting(false)}
                onDrafted={(contract) => {
                  setDrafting(false);
                  setNotice(`تم حفظ مسودة العقد (النسخة ${contract.version}). وهي غير ملزمة قانونيًا.`);
                  contracts.refetch();
                  agreement.refetch();
                  onChanged();
                }}
              />
            )}
            {cancelling && (
              <ReasonModal
                title="إلغاء مسودة العقد"
                subtitle={`النسخة ${cancelling.version}`}
                description="تُحفظ المسودة الملغاة في السجل، ويمكن بعدها إعداد نسخة جديدة."
                confirmLabel="تأكيد الإلغاء"
                variant="danger"
                onConfirm={(reason) => api.contracts.cancel(cancelling.id, reason || null)}
                onDone={() => {
                  setCancelling(null);
                  setNotice('تم إلغاء مسودة العقد.');
                  contracts.refetch();
                  agreement.refetch();
                  onChanged();
                }}
                onClose={() => setCancelling(null)}
              />
            )}
          </div>
        );
      }}
    </QueryBoundary>
  );
};

const Field: React.FC<{ label: string; value: React.ReactNode; strong?: boolean }> = ({ label, value, strong = false }) => (
  <div className="p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
    <span className="text-[10px] text-[#98A2B3] block">{label}</span>
    <span className={`font-bold ${strong ? 'text-[#35B779]' : 'text-[#172033]'}`} dir="auto">{value ?? '—'}</span>
  </div>
);

const ContractItem: React.FC<{ contract: Contract; onCancel: () => void }> = ({ contract, onCancel }) => (
  <li className="p-4 rounded-xl border border-[#E6EAF0] bg-white text-xs space-y-2.5" data-contract-id={contract.id}>
    <div className="flex items-center justify-between gap-2">
      <span className="font-bold text-[#172033]">النسخة {contract.version}</span>
      <ContractStatusBadge status={contract.status} size="sm" />
    </div>
    <div className="text-[11px] text-[#98A2B3]">
      {contract.drafted_by ? `أعدّها ${contract.drafted_by.name} · ` : ''}
      {formatDateTime(contract.created_at)}
    </div>
    {contract.knowledge_transfer && (
      <div className="p-3 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] space-y-1.5">
        <div className="text-[#667085]">
          التزام نقل المعرفة: تدريب <strong className="text-[#172033]">{contract.knowledge_transfer.trainees}</strong> مهندسين من مركز تحديث الصناعة
        </div>
        <p className="text-[#172033] leading-relaxed whitespace-pre-line" dir="auto">{contract.knowledge_transfer.training_plan}</p>
        <div className="text-[11px] text-[#98A2B3]">مذكرة الالتزام: غير متاحة (بانتظار قرار {contract.knowledge_transfer.commitment_memo.decision_needed})</div>
      </div>
    )}
    {contract.notes && <p className="text-[#667085] whitespace-pre-line" dir="auto">ملاحظات: {contract.notes}</p>}
    <div className="text-[11px] text-[#667085]" data-testid="contract-template">
      {contract.policy_basis === 'legacy'
        ? 'مسودة سابقة لإدارة نماذج العقود (بلا لقطة نموذج).'
        : contract.template_version
          ? `مولّدة من نموذج العقد — الإصدار ${contract.template_version.version ?? contract.template_version.id}؛ لا تتغير بتعديل النموذج لاحقًا.`
          : 'لا يوجد نموذج عقد معتمد؛ أُعدّت المسودة دون بنود نموذجية.'}
    </div>
    {contract.terms_snapshot?.template && (
      <details className="p-3 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0]">
        <summary className="font-bold text-[#172033] cursor-pointer">{contract.terms_snapshot.template.title_ar} — {contract.terms_snapshot.template.clauses.length} بنود</summary>
        <ol className="mt-2 space-y-2 list-decimal pr-4">
          {contract.terms_snapshot.template.clauses.map((clause, i) => (
            <li key={i}>
              <span className="font-semibold text-[#172033]" dir="auto">{clause.heading_ar}</span>
              <p className="text-[#667085] whitespace-pre-line" dir="auto">{clause.body_ar}</p>
            </li>
          ))}
        </ol>
      </details>
    )}
    {contract.status === 'cancelled' && (
      <div className="text-[11px] text-[#98A2B3]" dir="auto">
        أُلغيت {contract.status_changed_at ? formatDateTime(contract.status_changed_at) : ''}
        {contract.status_reason ? ` — ${contract.status_reason}` : ''}
      </div>
    )}
    <div className="flex items-center justify-between pt-2 border-t border-[#F1F4F9] text-[11px] text-[#98A2B3]">
      <span>الحالة القانونية: مسودة غير ملزمة · التوقيع: غير متاح ({contract.signature.decision_needed})</span>
      {contract.status === 'draft' && (
        <Button size="sm" variant="outline" data-action="cancel-contract" onClick={onCancel}>
          إلغاء المسودة
        </Button>
      )}
    </div>
  </li>
);
