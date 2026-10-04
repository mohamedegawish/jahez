import React, { useState } from 'react';
import { AlertTriangle, Calculator, CheckCircle2, FilePlus2, History, Pencil, Send } from 'lucide-react';
import { api } from '../../api';
import type { FinancialPolicy, FinancialPolicyVersion, FinancialVersionPreview } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate, formatDateTime, formatMoneyOf } from '../../lib/format';
import { AMOUNT, EFFECTIVE_STATUS, businessToday, describeParameters, historyEventLabel } from '../../lib/financialPolicies';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { ConfirmModal } from '../ui/ConfirmModal';
import { CardSkeleton } from '../ui/LoadingState';
import { Modal } from '../ui/Modal';
import { QueryBoundary } from '../ui/QueryBoundary';
import { ReasonModal } from '../ui/ReasonModal';
import { PolicyFormModal, type PolicyFormMode } from './PolicyFormModal';

interface Props {
  policyId: number;
  onClose: () => void;
  onChanged: () => void;
}

type Dialog = 'submit' | 'approve' | 'reject' | 'archive' | 'end' | null;

/**
 * One policy and its versions (ADR-023). Values are read-only except in a draft. Each action
 * the API reports as allowed for the current administrator (`actions`) is offered, with the
 * effect explained before it is confirmed; the server refuses anything else.
 */
export const PolicyDetailModal: React.FC<Props> = ({ policyId, onClose, onChanged }) => {
  const policy = useApiQuery((signal) => api.financialPolicies.get(policyId, signal), [policyId]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [form, setForm] = useState<PolicyFormMode | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const { hasPermission } = useAuth();

  const reload = () => {
    policy.refetch();
    onChanged();
  };

  return (
    <Modal isOpen onClose={onClose} title="تفاصيل السياسة" subtitle="الإصدارات وتواريخ السريان والاعتمادات وسجل التدقيق" maxWidth="4xl">
      <QueryBoundary query={policy} loading={<CardSkeleton />}>
        {(p) => {
          const versions = p.versions ?? [];
          const shown = versions.find((v) => v.id === selectedId) ?? versions.find((v) => v.status === 'draft' || v.status === 'pending_approval') ?? versions[0];
          const hasOpen = versions.some((v) => v.status === 'draft' || v.status === 'pending_approval');
          return (
            <div className="space-y-4 text-right" dir="rtl">
              {notice && (
                <div role="status" className="p-3 rounded-xl bg-[#E7F8EE] border border-[#C5F0D5] text-xs font-semibold text-[#1D7E4C] flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4" /> {notice}
                </div>
              )}
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 rounded-xl bg-[#F7F9FC] border border-[#E6EAF0]">
                <div className="text-xs space-y-0.5">
                  <div className="font-bold text-[#172033] text-sm">{p.name_ar}</div>
                  <div className="text-[#667085]">{p.kind_label_ar} · النطاق: {p.scope.label_ar}{p.scope.type !== 'global' ? ` — ${p.scope.name}` : ''} · القرار المرجعي: {p.decision_needed}</div>
                </div>
                {hasPermission('financial_policies.manage') && (
                  <Button variant="secondary" size="sm" icon={FilePlus2} disabled={hasOpen} onClick={() => setForm({ type: 'version', policy: p, from: p.versions?.find((v) => v.effective_status === 'active') ?? versions[0] ?? null })} title={hasOpen ? 'أنهِ الإصدار المفتوح أولًا' : undefined}>
                    إصدار جديد
                  </Button>
                )}
              </div>
              {!p.current_version && (
                <div className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs text-[#A66F0B] flex items-start gap-2" role="alert">
                  <AlertTriangle className="w-4 h-4 shrink-0" />
                  لا يوجد إصدار معتمد سارٍ اليوم لهذه السياسة، لذلك تبقى العمليات التي تحتاجها موقوفة. لا تُستخدم أي قيمة افتراضية.
                </div>
              )}

              <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <ul className="space-y-2 lg:col-span-1" aria-label="الإصدارات">
                  {versions.map((v) => (
                    <li key={v.id}>
                      <button type="button" onClick={() => setSelectedId(v.id)} data-version-id={v.id} className={`w-full text-right p-2.5 rounded-xl border text-xs cursor-pointer ${shown?.id === v.id ? 'bg-[#EEEAFE] border-[#9B8AFB]' : 'bg-white border-[#E6EAF0] hover:bg-[#F7F9FC]'}`}>
                        <div className="flex items-center justify-between gap-2">
                          <span className="font-bold text-[#172033]">الإصدار {v.version}</span>
                          <Badge size="sm" variant={EFFECTIVE_STATUS[v.effective_status].tone}>{EFFECTIVE_STATUS[v.effective_status].label}</Badge>
                        </div>
                        <div className="text-[11px] text-[#667085] mt-1">{formatDate(v.effective_from)} — {v.effective_to ? formatDate(v.effective_to) : 'مفتوح'}</div>
                      </button>
                    </li>
                  ))}
                </ul>
                <div className="lg:col-span-3">
                  {shown && (
                    <VersionPanel
                      key={shown.id}
                      policy={p}
                      version={shown}
                      onEdit={() => setForm({ type: 'edit', policy: p, version: shown })}
                      onDone={(message) => {
                        setNotice(message);
                        reload();
                      }}
                    />
                  )}
                </div>
              </div>
              {form && (
                <PolicyFormModal
                  mode={form}
                  onClose={() => setForm(null)}
                  onSaved={(saved) => {
                    setForm(null);
                    setSelectedId(saved.id);
                    setNotice('حُفظت المسودة. أرسلها للاعتماد عندما تكتمل.');
                    reload();
                  }}
                />
              )}
            </div>
          );
        }}
      </QueryBoundary>
    </Modal>
  );
};

const VersionPanel: React.FC<{ policy: FinancialPolicy; version: FinancialPolicyVersion; onEdit: () => void; onDone: (message: string) => void }> = ({ policy, version, onEdit, onDone }) => {
  const [dialog, setDialog] = useState<Dialog>(null);
  const [endDate, setEndDate] = useState(businessToday());
  const actions = version.actions;
  const status = EFFECTIVE_STATUS[version.effective_status];
  const close = (message: string) => {
    setDialog(null);
    onDone(message);
  };

  return (
    <div className="space-y-4" data-testid="version-panel">
      <div className="p-4 rounded-xl border border-[#E6EAF0] bg-white space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h4 className="text-sm font-bold text-[#172033]">الإصدار {version.version}</h4>
          <div className="flex items-center gap-2">
            <Badge variant={status.tone}>{status.label}</Badge>
            <span className="text-[11px] text-[#98A2B3]">({version.status_label_ar})</span>
          </div>
        </div>
        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 text-xs">
          <Row label="يسري من" value={formatDate(version.effective_from)} />
          <Row label="يسري حتى" value={version.effective_to ? formatDate(version.effective_to) : 'مفتوح'} />
          {describeParameters(policy.kind, version.parameters).map((row) => <Row key={row.label} label={row.label} value={row.value} />)}
        </dl>
        <div className="text-[11px] text-[#667085] space-y-1 pt-2 border-t border-[#F1F4F9]">
          <div>سبب الإصدار: <span dir="auto">{version.change_reason}</span></div>
          <div>أعدّه: {version.created_by?.name ?? '—'} · {formatDateTime(version.created_at)}</div>
          {version.submitted_by && <div>أرسله للاعتماد: {version.submitted_by.name} · {formatDateTime(version.submitted_at)}</div>}
          {version.decided_by && <div>{version.status === 'rejected' ? 'رفضه' : 'اعتمده'}: {version.decided_by.name} · {formatDateTime(version.decided_at)}{version.decision_note ? ` — ${version.decision_note}` : ''}</div>}
          {version.status_reason && version.status !== 'rejected' && <div>آخر تغيير: <span dir="auto">{version.status_reason}</span></div>}
        </div>

        <div className="flex flex-wrap gap-2 pt-2 border-t border-[#F1F4F9]">
          {actions?.edit && <Button variant="outline" size="sm" icon={Pencil} onClick={onEdit}>تعديل المسودة</Button>}
          {actions?.submit && <Button variant="primary" size="sm" icon={Send} onClick={() => setDialog('submit')} data-action="submit-version">إرسال للاعتماد</Button>}
          {actions?.approve && <Button variant="success" size="sm" onClick={() => setDialog('approve')} data-action="approve-version">اعتماد</Button>}
          {actions?.reject && <Button variant="danger" size="sm" onClick={() => setDialog('reject')}>رفض</Button>}
          {actions?.end && <Button variant="outline" size="sm" onClick={() => setDialog('end')}>إنهاء مبكر</Button>}
          {actions?.archive && <Button variant="ghost" size="sm" onClick={() => setDialog('archive')}>أرشفة</Button>}
          {version.status === 'pending_approval' && !actions?.approve && (
            <p className="text-[11px] text-[#98A2B3]">يعتمده مسؤول يملك صلاحية الاعتماد ولم يُعدّ هذا الإصدار أو يرسله.</p>
          )}
        </div>
      </div>

      <VersionPreview version={version} kind={policy.kind} />
      <VersionHistory versionId={version.id} />

      {dialog === 'submit' && (
        <ConfirmModal
          title="إرسال الإصدار للاعتماد"
          description="بعد الإرسال لا يمكن تعديل القيم. يراجعه مسؤول آخر يملك صلاحية الاعتماد."
          confirmLabel="إرسال"
          onConfirm={() => api.financialPolicyVersions.submit(version.id)}
          onDone={() => close('أُرسل الإصدار للاعتماد.')}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog === 'approve' && (
        <ReasonModal
          title="اعتماد الإصدار"
          subtitle={`${policy.name_ar} — الإصدار ${version.version}`}
          description={`سيسري هذا الإصدار من ${formatDate(version.effective_from)}${version.effective_to ? ` حتى ${formatDate(version.effective_to)}` : ' دون تاريخ انتهاء'}. إن كان هناك إصدار سارٍ في ذلك التاريخ فسينتهي في اليوم السابق. لا يتغيّر أي سجل أُنشئ سابقًا؛ تحتفظ الاتفاقيات والعقود والفواتير بالإصدار الذي أُنشئت بموجبه. هذا الاعتماد لا يجعل القيم مستوفية للمتطلبات القانونية أو الضريبية تلقائيًا.`}
          confirmLabel="تأكيد الاعتماد"
          variant="success"
          required={false}
          label="ملاحظة الاعتماد (اختيارية)"
          onConfirm={(note) => api.financialPolicyVersions.approve(version.id, note || null)}
          onDone={() => close('اعتُمد الإصدار.')}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog === 'reject' && (
        <ReasonModal required title="رفض الإصدار" description="يبقى الإصدار المرفوض في السجل. يمكن إعداد إصدار جديد بعد أرشفته." confirmLabel="تأكيد الرفض" variant="danger" onConfirm={(reason) => api.financialPolicyVersions.reject(version.id, reason)} onDone={() => close('رُفض الإصدار.')} onClose={() => setDialog(null)} />
      )}
      {dialog === 'archive' && (
        <ReasonModal
          required
          title="أرشفة الإصدار"
          description={version.status === 'approved' ? 'سيُسحب هذا الإصدار المجدول قبل أن يسري، ولن يُطبَّق أبدًا.' : 'تُستبعد المسودة أو الإصدار المرفوض، ويبقى في السجل.'}
          confirmLabel="تأكيد الأرشفة"
          variant="danger"
          onConfirm={(reason) => api.financialPolicyVersions.archive(version.id, reason)}
          onDone={() => close('أُرشف الإصدار.')}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog === 'end' && (
        <ReasonModal
          required
          title="إنهاء الإصدار مبكرًا"
          description="بعد آخر يوم لن يُطبَّق هذا الإصدار، وتتوقف العمليات التي تحتاجه حتى يُعتمد إصدار آخر. السجلات السابقة لا تتغير."
          confirmLabel="تأكيد الإنهاء"
          variant="danger"
          onConfirm={(reason) => api.financialPolicyVersions.end(version.id, endDate, reason)}
          onDone={() => close('أُنهي الإصدار.')}
          onClose={() => setDialog(null)}
        >
          <label className="block text-xs space-y-1 mt-3">
            <span className="font-semibold text-[#344054]">آخر يوم للسريان</span>
            <input type="date" min={businessToday()} value={endDate} onChange={(e) => setEndDate(e.target.value)} className="w-full p-2.5 rounded-xl border border-[#E6EAF0]" dir="ltr" />
          </label>
        </ReasonModal>
      )}
    </div>
  );
};

/** The server's calculation for a sample amount under this version's values alone. */
const VersionPreview: React.FC<{ version: FinancialPolicyVersion; kind: FinancialPolicy['kind'] }> = ({ version, kind }) => {
  const [amount, setAmount] = useState('');
  const preview = useApiMutation((value: string) => api.financialPolicyVersions.preview(version.id, value));
  const [result, setResult] = useState<FinancialVersionPreview | null>(null);
  const needsAmount = kind === 'tax' || kind === 'revenue_share';

  const run = async (event: React.FormEvent) => {
    event.preventDefault();
    const response = await preview.run(needsAmount ? amount.trim() : '0');
    if (response.ok) setResult(response.data);
  };

  return (
    <form onSubmit={run} className="p-4 rounded-xl border border-[#E6EAF0] bg-white space-y-3" data-testid="version-preview">
      <h4 className="text-xs font-bold text-[#172033] flex items-center gap-1.5"><Calculator className="w-4 h-4" /> معاينة محسوبة على الخادم</h4>
      <div className="flex flex-wrap items-end gap-2">
        {needsAmount && (
          <label className="text-xs space-y-1">
            <span className="text-[#667085]">مجموع فرعي تجريبي (ج.م)</span>
            <input value={amount} onChange={(e) => setAmount(e.target.value)} dir="ltr" inputMode="decimal" className="p-2 rounded-lg border border-[#E6EAF0] w-40" />
          </label>
        )}
        <Button type="submit" variant="secondary" size="sm" isLoading={preview.pending} disabled={needsAmount && !AMOUNT.test(amount.trim())}>احسب</Button>
      </div>
      {preview.error !== null && <ApiErrorState compact error={preview.error} />}
      {result && <PreviewResult result={result} />}
    </form>
  );
};

const PreviewResult: React.FC<{ result: FinancialVersionPreview }> = ({ result }) => {
  if (result.calculation) {
    const c = result.calculation;
    return (
      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 text-xs" data-testid="preview-breakdown">
        <Row label="المجموع الفرعي" value={formatMoneyOf(c.subtotal)} />
        <Row label="صافي الخدمة" value={formatMoneyOf(c.net_service_amount)} />
        {c.fees.map((fee) => <Row key={fee.code} label={`رسم: ${fee.name_ar}`} value={formatMoneyOf(fee.amount)} />)}
        {c.taxes.map((tax) => <Row key={tax.code} label={`${tax.name_ar} ${tax.rate_percent}% على ${formatMoneyOf(tax.base)}`} value={formatMoneyOf(tax.amount)} />)}
        <Row label="الإجمالي" value={formatMoneyOf(c.total)} strong />
        {c.revenue_share && <Row label={`حصة الوزارة ${c.revenue_share.rate_percent}% (للاطلاع)`} value={formatMoneyOf(c.revenue_share.amount)} />}
      </dl>
    );
  }
  return (
    <dl className="text-xs space-y-1">
      {result.due_date_if_issued_today && <Row label="تاريخ الاستحقاق لو صدرت الفاتورة اليوم" value={formatDate(result.due_date_if_issued_today)} />}
      {result.first_number_example && <Row label="مثال لأول رقم" value={result.first_number_example} />}
      {result.clauses !== undefined && <Row label="عدد البنود" value={String(result.clauses)} />}
    </dl>
  );
};

const VersionHistory: React.FC<{ versionId: number }> = ({ versionId }) => {
  const history = useApiQuery((signal) => api.financialPolicyVersions.history(versionId, signal), [versionId]);
  return (
    <div className="p-4 rounded-xl border border-[#E6EAF0] bg-white space-y-2" data-testid="version-history">
      <h4 className="text-xs font-bold text-[#172033] flex items-center gap-1.5"><History className="w-4 h-4" /> سجل التدقيق</h4>
      <QueryBoundary query={history} loading={<CardSkeleton />} isEmpty={(entries) => entries.length === 0} empty={<p className="text-xs text-[#98A2B3]">لا توجد إدخالات.</p>}>
        {(entries) => (
          <ol className="space-y-2">
            {entries.map((entry) => (
              <li key={entry.id} className="text-[11px] border-r-2 border-[#9B8AFB] pr-2.5">
                <div className="font-semibold text-[#172033]">{historyEventLabel(entry.event)} · {entry.actor?.name ?? 'النظام'} · {formatDateTime(entry.created_at)}</div>
                {entry.metadata && <HistoryMetadata metadata={entry.metadata} />}
              </li>
            ))}
          </ol>
        )}
      </QueryBoundary>
    </div>
  );
};

const HistoryMetadata: React.FC<{ metadata: Record<string, unknown> }> = ({ metadata }) => {
  const reason = metadata.reason ?? metadata.change_reason ?? metadata.note;
  const changes = metadata.changes as Record<string, { from: unknown; to: unknown }> | undefined;
  return (
    <div className="text-[#667085] space-y-0.5 mt-0.5">
      {typeof reason === 'string' && <div dir="auto">السبب: {reason}</div>}
      {changes && Object.entries(changes).map(([field, change]) => (
        <div key={field} dir="ltr" className="text-left font-mono">{field}: {JSON.stringify(change.from)} → {JSON.stringify(change.to)}</div>
      ))}
      {typeof metadata.effective_from === 'string' && <div>يسري من {metadata.effective_from}</div>}
    </div>
  );
};

const Row: React.FC<{ label: string; value: string; strong?: boolean }> = ({ label, value, strong }) => (
  <div className="flex items-center justify-between gap-3">
    <dt className="text-[#667085]">{label}</dt>
    <dd className={strong ? 'font-extrabold text-[#35B779]' : 'font-semibold text-[#172033]'} dir="auto">{value}</dd>
  </div>
);

