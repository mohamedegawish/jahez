import React, { useState } from 'react';
import { AlertTriangle, Plus } from 'lucide-react';
import { api } from '../../api';
import type { FinancialPolicy, FinancialPolicyKind } from '../../api';
import { useAuth } from '../../auth/authContext';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDate } from '../../lib/format';
import { EFFECTIVE_STATUS, KIND_DECISIONS, KIND_LABELS, describeParameters } from '../../lib/financialPolicies';
import { decisionLabel } from '../../lib/labels';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { CardSkeleton } from '../ui/LoadingState';
import { QueryBoundary } from '../ui/QueryBoundary';
import { PolicyDetailModal } from './PolicyDetailModal';
import { PolicyFormModal } from './PolicyFormModal';

interface Props {
  kind: FinancialPolicyKind;
  description: string;
}

/**
 * The policies of one kind, by scope (ADR-023): the version in effect today and the one being
 * prepared. A kind with no approved version in effect keeps the operations that need it blocked,
 * and the panel says so; nothing is filled in by default.
 */
export const PolicyKindPanel: React.FC<Props> = ({ kind, description }) => {
  const policies = useApiQuery((signal) => api.financialPolicies.list({ filter: { kind }, per_page: 100, signal }), [kind]);
  const [openId, setOpenId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const { hasPermission } = useAuth();
  const canManage = hasPermission('financial_policies.manage');

  return (
    <Card
      title={KIND_LABELS[kind]}
      subtitle={description}
      accent="purple"
      action={canManage ? <Button variant="primary" size="sm" icon={Plus} onClick={() => setCreating(true)} data-action={`create-${kind}`}>سياسة جديدة</Button> : undefined}
    >
      <QueryBoundary
        query={policies}
        loading={<CardSkeleton />}
        isEmpty={(page) => page.data.length === 0}
        empty={
          <div className="space-y-3">
            <div className="p-3 rounded-xl bg-[#FEF5E7] border border-[#FDE5BE] text-xs text-[#A66F0B] flex items-start gap-2" role="alert" data-testid={`blocked-${kind}`}>
              <AlertTriangle className="w-4 h-4 shrink-0" />
              <span>
                لا توجد سياسة «{KIND_LABELS[kind]}» معتمدة، لذلك تبقى العمليات التي تحتاجها موقوفة. القرار المطلوب: {decisionLabel(KIND_DECISIONS[kind])} ({KIND_DECISIONS[kind]}).
              </span>
            </div>
            <EmptyState
              title="لا توجد سياسات بعد"
              description={canManage ? 'أنشئ سياسة بقيم يقرّها صاحب القرار، ثم أرسلها ليعتمدها مسؤول آخر.' : 'يعدّ السياسات مسؤول يملك صلاحية الإعداد، ويعتمدها مسؤول آخر.'}
            />
          </div>
        }
      >
        {(page) => (
          <ul className="space-y-2.5" data-testid={`policies-${kind}`}>
            {page.data.map((policy) => <PolicyRow key={policy.id} policy={policy} onOpen={() => setOpenId(policy.id)} />)}
          </ul>
        )}
      </QueryBoundary>

      {creating && (
        <PolicyFormModal
          mode={{ type: 'create', kind }}
          onClose={() => setCreating(false)}
          onSaved={(version) => {
            setCreating(false);
            policies.refetch();
            setOpenId(version.policy_id);
          }}
        />
      )}
      {openId !== null && <PolicyDetailModal policyId={openId} onClose={() => setOpenId(null)} onChanged={policies.refetch} />}
    </Card>
  );
};

const PolicyRow: React.FC<{ policy: FinancialPolicy; onOpen: () => void }> = ({ policy, onOpen }) => {
  const current = policy.current_version;
  const open = policy.open_version;
  return (
    <li>
      <button type="button" onClick={onOpen} className="w-full text-right p-3 rounded-xl border border-[#E6EAF0] bg-white hover:bg-[#F7F9FC] cursor-pointer text-xs space-y-2" data-policy-id={policy.id}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="font-bold text-[#172033]">{policy.name_ar}</span>
          <span className="text-[#667085]">{policy.scope.label_ar}{policy.scope.type !== 'global' ? `: ${policy.scope.name}` : ''}</span>
        </div>
        {current ? (
          <div className="flex flex-wrap items-center gap-2">
            <Badge size="sm" variant={EFFECTIVE_STATUS.active.tone}>سارية — الإصدار {current.version}</Badge>
            <span className="text-[#667085]">من {formatDate(current.effective_from)}{current.effective_to ? ` حتى ${formatDate(current.effective_to)}` : ''}</span>
            <span className="text-[#344054]">{describeParameters(policy.kind, current.parameters).slice(0, 2).map((row) => `${row.label}: ${row.value}`).join(' · ')}</span>
          </div>
        ) : (
          <div className="text-[#A66F0B] flex items-center gap-1.5"><AlertTriangle className="w-3.5 h-3.5" /> لا يوجد إصدار سارٍ اليوم؛ العمليات التي تحتاجها موقوفة.</div>
        )}
        {open && (
          <div className="flex items-center gap-2">
            <Badge size="sm" variant={EFFECTIVE_STATUS[open.effective_status].tone}>{EFFECTIVE_STATUS[open.effective_status].label} — الإصدار {open.version}</Badge>
            <span className="text-[#98A2B3]">يسري من {formatDate(open.effective_from)} عند اعتماده</span>
          </div>
        )}
      </button>
    </li>
  );
};
