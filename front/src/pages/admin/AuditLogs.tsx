import React from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, fieldMessages } from '../../api';
import type { AuditLogQuery, AuditSubjectType } from '../../api';
import { useApiQuery } from '../../hooks/useApiQuery';
import { AUDIT_EVENTS, SUBJECT_TYPES, auditEventLabel, subjectTypeLabel } from '../../lib/audit';
import { formatDateTime } from '../../lib/format';
import { fieldErrorClass } from '../../lib/forms';
import { ApiErrorState } from '../../components/ui/ApiErrorState';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { EmptyState } from '../../components/ui/EmptyState';
import { FieldError } from '../../components/ui/FieldError';
import { TableSkeleton } from '../../components/ui/LoadingState';
import { CursorPagination } from '../../components/ui/Pagination';
import { QueryBoundary } from '../../components/ui/QueryBoundary';

const KEYS = ['event', 'actor_user_id', 'subject_type', 'subject_id', 'from', 'to', 'cursor'] as const;
const box = 'w-full py-2 px-3 text-xs bg-white border border-[#E6EAF0] rounded-xl text-[#172033] focus:outline-none focus:border-[#6EC8FF]';

/**
 * Read-only audit log (IMC only). The API uses cursor pagination and plain query parameters here
 * (`event`, `actor_user_id`, `subject_type` + `subject_id`, `from`, `to`), newest entries first.
 */
export const AuditLogs: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const get = (key: (typeof KEYS)[number]) => params.get(key) ?? '';
  const filterSig = KEYS.map(get).join('|');

  const set = (changes: Partial<Record<(typeof KEYS)[number], string>>) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        for (const [key, value] of Object.entries(changes)) {
          if (value === '' || value === undefined) next.delete(key);
          else next.set(key, value);
        }
        // Any filter change starts again from the newest entries.
        if (!('cursor' in changes)) next.delete('cursor');
        return next;
      },
      { replace: true },
    );

  // `subject_type` and `subject_id` are only valid together (the API answers 422 otherwise).
  const subjectComplete = get('subject_type') !== '' && get('subject_id') !== '';
  const query: AuditLogQuery = {
    per_page: 25,
    event: get('event') || undefined,
    actor_user_id: get('actor_user_id') ? Number(get('actor_user_id')) : undefined,
    ...(subjectComplete ? { subject_type: get('subject_type') as AuditSubjectType, subject_id: Number(get('subject_id')) } : {}),
    from: get('from') || undefined,
    to: get('to') || undefined,
    cursor: get('cursor') || undefined,
  };

  const logs = useApiQuery((signal) => api.auditLogs.list(query, signal), [filterSig]);
  const hasFilters = KEYS.some((k) => k !== 'cursor' && get(k) !== '');
  const filterErrors = logs.status === 'error' ? fieldMessages(logs.error, 'to').concat(fieldMessages(logs.error, 'from')) : [];

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl sm:text-2xl font-bold text-[#172033] tracking-tight">سجل التدقيق</h2>
        <p className="text-xs sm:text-sm text-[#667085] mt-0.5">
          سجل للأحداث الأمنية والإدارية لا يمكن تعديله أو حذفه. لا يحتوي نصوص الرسائل ولا شروط العروض ولا كلمات المرور.
        </p>
      </div>

      <Card className="p-4 space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <select aria-label="نوع الحدث" value={get('event')} onChange={(e) => set({ event: e.target.value })} className={box}>
            <option value="">كافة الأحداث</option>
            {Object.entries(AUDIT_EVENTS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
          <input aria-label="رقم المستخدم المنفّذ" type="number" min={1} placeholder="رقم المستخدم المنفّذ" value={get('actor_user_id')} onChange={(e) => set({ actor_user_id: e.target.value })} className={box} dir="ltr" />
          <select aria-label="نوع الجهة" value={get('subject_type')} onChange={(e) => set({ subject_type: e.target.value })} className={box}>
            <option value="">كل أنواع الجهات</option>
            {SUBJECT_TYPES.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </select>
          <input aria-label="رقم الجهة" type="number" min={1} placeholder="رقم الجهة (مع النوع)" value={get('subject_id')} onChange={(e) => set({ subject_id: e.target.value })} className={box} dir="ltr" />
          <label className="text-[11px] text-[#667085]">
            من تاريخ
            <input aria-label="من تاريخ" type="date" value={get('from')} onChange={(e) => set({ from: e.target.value })} className={`${box} mt-1 ${fieldErrorClass(false)}`} dir="ltr" />
          </label>
          <label className="text-[11px] text-[#667085]">
            إلى تاريخ
            <input aria-label="إلى تاريخ" type="date" value={get('to')} onChange={(e) => set({ to: e.target.value })} className={`${box} mt-1 ${fieldErrorClass(filterErrors.length > 0)}`} dir="ltr" />
          </label>
          <div className="flex items-end">
            <Button variant="outline" size="sm" onClick={() => setParams(new URLSearchParams(), { replace: true })} disabled={!hasFilters}>
              إعادة ضبط الفلاتر
            </Button>
          </div>
        </div>
        {(get('subject_type') !== '') !== (get('subject_id') !== '') && (
          <p className="text-[11px] text-[#A66F0B]">اختر نوع الجهة ورقمها معًا ليُطبَّق فلتر الجهة.</p>
        )}
        <FieldError messages={filterErrors} />
      </Card>

      {logs.status === 'error' && filterErrors.length > 0 ? (
        <ApiErrorState compact error={logs.error} onRetry={logs.refetch} />
      ) : (
        <QueryBoundary
          query={logs}
          loading={<TableSkeleton rows={8} cols={5} />}
          isEmpty={(page) => page.data.length === 0}
          empty={
            <EmptyState
              title={hasFilters ? 'لا توجد أحداث مطابقة' : 'السجل فارغ'}
              description={hasFilters ? 'جرّب تعديل الفلاتر.' : 'لم تُسجَّل أحداث بعد.'}
              actionText={hasFilters ? 'إعادة ضبط الفلاتر' : undefined}
              onAction={hasFilters ? () => setParams(new URLSearchParams(), { replace: true }) : undefined}
            />
          }
        >
          {(page) => (
            <div className="space-y-3">
              <div className="jahez-card overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="w-full text-right border-collapse text-xs">
                    <thead>
                      <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
                        <th className="py-3 px-4">الوقت</th>
                        <th className="py-3 px-4">الحدث</th>
                        <th className="py-3 px-4">المنفّذ</th>
                        <th className="py-3 px-4">الجهة</th>
                        <th className="py-3 px-4">العنوان / الطلب</th>
                        <th className="py-3 px-4">التفاصيل</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[#E6EAF0]">
                      {page.data.map((entry) => (
                        <tr key={entry.id} data-audit-id={entry.id} className="align-top hover:bg-[#F7F9FC]">
                          <td className="py-3 px-4 text-[#667085] whitespace-nowrap">{formatDateTime(entry.created_at)}</td>
                          <td className="py-3 px-4">
                            <div className="font-bold text-[#172033]">{auditEventLabel(entry.event)}</div>
                            <div className="font-mono text-[10px] text-[#98A2B3]" dir="ltr">{entry.event}</div>
                          </td>
                          <td className="py-3 px-4 text-[#667085]">
                            {entry.actor ? (
                              <>
                                <div className="font-semibold text-[#172033]">{entry.actor.name ?? '—'}</div>
                                <div className="text-[11px]" dir="ltr">{entry.actor.email} (#{entry.actor.id})</div>
                              </>
                            ) : (
                              <span className="text-[#98A2B3]">بلا مستخدم</span>
                            )}
                          </td>
                          <td className="py-3 px-4 text-[#667085]">{entry.subject ? `${subjectTypeLabel(entry.subject.type)} #${entry.subject.id}` : '—'}</td>
                          <td className="py-3 px-4 text-[#98A2B3] text-[11px]" dir="ltr">
                            <div>{entry.ip_address ?? '—'}</div>
                            <div className="font-mono truncate max-w-[160px]" title={entry.request_id ?? ''}>{entry.request_id ?? ''}</div>
                          </td>
                          <td className="py-3 px-4">
                            {entry.metadata && Object.keys(entry.metadata).length > 0 ? (
                              <details>
                                <summary className="cursor-pointer text-[#5146A5] font-semibold">عرض</summary>
                                <pre className="mt-1 p-2 rounded-lg bg-[#F7F9FC] border border-[#E6EAF0] text-[10px] leading-relaxed max-w-xs overflow-x-auto" dir="ltr">{JSON.stringify(entry.metadata, null, 2)}</pre>
                              </details>
                            ) : (
                              <span className="text-[#98A2B3]">—</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
              <CursorPagination
                hasPrev={page.meta.prev_cursor !== null}
                hasNext={page.meta.next_cursor !== null}
                onPrev={() => set({ cursor: page.meta.prev_cursor ?? '' })}
                onNext={() => set({ cursor: page.meta.next_cursor ?? '' })}
              />
            </div>
          )}
        </QueryBoundary>
      )}
    </div>
  );
};
