import React, { useState } from 'react';
import { Check, XCircle } from 'lucide-react';
import { api } from '../../api';
import type { FactoryChangeRequest, LegalField } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import { formatDateTime } from '../../lib/format';
import { documentTypeLabel } from '../../lib/files';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { DocumentRow } from '../ui/PrivateFile';
import { ReasonModal } from '../ui/ReasonModal';

const LABELS: Record<LegalField, string> = {
  legal_name: 'الاسم القانوني',
  commercial_registration_number: 'رقم السجل التجاري',
  tax_registration_number: 'رقم التسجيل الضريبي',
};

/**
 * Pending factory requests to change recorded legal information (ADR-020). Approving applies the new
 * values and documents; rejecting keeps the recorded ones and tells the factory why.
 */
export const FactoryChangeQueue: React.FC<{
  /** Only this factory's pending request (its details page); otherwise IMC's whole queue. */
  factoryId?: number;
  /** Show an empty state instead of nothing when there is no pending request. */
  showEmpty?: boolean;
  onDecided?: () => void;
}> = ({ factoryId, showEmpty = false, onDecided }) => {
  const queue = useApiQuery(
    (signal) => (factoryId === undefined ? api.factoryChangeRequests.queue({ per_page: 20, signal }) : api.factoryChangeRequests.list(factoryId, { per_page: 20, signal })),
    [factoryId],
  );
  const [rejecting, setRejecting] = useState<FactoryChangeRequest | null>(null);
  const refresh = () => {
    queue.refetch();
    onDecided?.();
  };

  if (queue.status === 'error') return <ApiErrorState compact error={queue.error} onRetry={queue.refetch} />;
  const items = (queue.data?.data ?? []).filter((request) => request.status === 'pending');
  if (items.length === 0) {
    return showEmpty && queue.status === 'success' ? <p className="text-xs text-[#667085] p-4 jahez-card">لا توجد طلبات تعديل بيانات قانونية للمصانع بانتظار القرار.</p> : null;
  }

  return (
    <Card title={`طلبات تعديل البيانات القانونية للمصانع (${items.length})`} subtitle="لا تتغير بيانات المنشأة قبل قراركم" accent="purple">
      <div className="space-y-3">
        {items.map((request) => (
          <Item key={request.id} request={request} onApproved={refresh} onReject={() => setRejecting(request)} />
        ))}
      </div>
      {rejecting && (
        <ReasonModal
          title="رفض طلب التعديل"
          subtitle={rejecting.factory?.name}
          description="تبقى البيانات المسجلة كما هي، ويُبلَّغ المصنع بالسبب."
          confirmLabel="تأكيد الرفض"
          variant="danger"
          required
          onConfirm={(reason) => api.factoryChangeRequests.reject(rejecting.factory_id, rejecting.id, reason)}
          onDone={() => {
            setRejecting(null);
            refresh();
          }}
          onClose={() => setRejecting(null)}
        />
      )}
    </Card>
  );
};

const Item: React.FC<{ request: FactoryChangeRequest; onApproved: () => void; onReject: () => void }> = ({ request, onApproved, onReject }) => {
  const approve = useApiMutation(() => api.factoryChangeRequests.approve(request.factory_id, request.id));
  return (
    <div className="p-4 rounded-xl border border-[#E6EAF0] text-xs space-y-2" data-change-request-id={request.id}>
      <div className="flex items-center justify-between">
        <span className="font-bold text-[#172033]">{request.factory?.name}</span>
        <span className="text-[#98A2B3]">{formatDateTime(request.created_at)}{request.requested_by ? ` · ${request.requested_by.name}` : ''}</span>
      </div>
      <dl className="space-y-1">
        {(Object.keys(request.changes) as LegalField[]).map((field) => (
          <div key={field} className="flex flex-wrap gap-2">
            <dt className="text-[#667085]">{LABELS[field]}:</dt>
            <dd className="text-[#172033]" dir="auto">
              {request.factory?.[field] ?? '—'} ← <strong>{request.changes[field] ?? '— حذف —'}</strong>
            </dd>
          </div>
        ))}
      </dl>
      {(request.documents ?? []).map((document) => (
        <DocumentRow key={document.id} document={document} label={`${documentTypeLabel[document.type]} (جديد)`} load={() => api.factories.documentFile(request.factory_id, document.id)} />
      ))}
      {request.note && <p className="text-[#667085]" dir="auto">ملاحظة المصنع: {request.note}</p>}
      {approve.error !== null && <ApiErrorState compact error={approve.error} />}
      <div className="flex justify-end gap-2">
        <Button variant="outline" size="sm" icon={XCircle} onClick={onReject}>
          رفض
        </Button>
        <Button
          variant="success"
          size="sm"
          icon={Check}
          isLoading={approve.pending}
          onClick={async () => {
            const result = await approve.run();
            if (result.ok) onApproved();
          }}
        >
          اعتماد وتطبيق
        </Button>
      </div>
    </div>
  );
};
