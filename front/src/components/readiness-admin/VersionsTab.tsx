import React from 'react';
import { History } from 'lucide-react';
import type { ReadinessQuestionnaireVersion } from '../../api';
import { formatDateTime } from '../../lib/format';
import { Badge } from '../ui/Badge';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { EditorFrame } from './EditorFrame';
import type { ReadinessWorkspace } from './useReadinessWorkspace';

const STATUS: Record<ReadinessQuestionnaireVersion['status'], { label: string; variant: 'success' | 'warning' | 'neutral' }> = {
  current: { label: 'الإصدار الحالي', variant: 'success' },
  draft: { label: 'مسودة', variant: 'warning' },
  retired: { label: 'إصدار سابق', variant: 'neutral' },
};

/**
 * «سجل الإصدارات والإحصائيات»: every questionnaire version with who drafted, changed and published it,
 * when, and how many assessments answered it. A published version never changes, so each result can be
 * reproduced from the version it answered.
 */
export const VersionsTab: React.FC<{ workspace: ReadinessWorkspace; canManage: boolean }> = ({ workspace, canManage }) => {
  return (
    <EditorFrame workspace={workspace} canManage={canManage}>
      {() => {
        const versions = workspace.versions.data ?? [];
        if (versions.length === 0) return <EmptyState icon={History} title="لا توجد إصدارات" description="لم يُحمَّل الاستبيان بعد." />;
        return (
          <Card title="سجل الإصدارات" subtitle="الأحدث أولًا. الإصدار الأول هو نص وثيقة الإطار كما وردت.">
            <div className="overflow-x-auto" data-testid="versions-tab">
              <table className="w-full text-right border-collapse min-w-[760px] text-xs">
                <thead>
                  <tr className="border-b border-[#E6EAF0] bg-[#F7F9FC] text-[#667085] font-semibold">
                    <th className="py-3 px-3">الإصدار</th>
                    <th className="py-3 px-3">الحالة</th>
                    <th className="py-3 px-3">الإنشاء</th>
                    <th className="py-3 px-3">آخر تعديل</th>
                    <th className="py-3 px-3">النشر</th>
                    <th className="py-3 px-3 text-center">التقييمات</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EAF0]">
                  {versions.map((version) => (
                    <tr key={version.id} data-version={version.version}>
                      <td className="py-3 px-3">
                        <div className="font-bold text-[#172033]">الإصدار {version.version}</div>
                        <div className="text-[#98A2B3]">{version.source_ref}</div>
                      </td>
                      <td className="py-3 px-3">
                        <Badge size="sm" variant={STATUS[version.status].variant}>
                          {STATUS[version.status].label}
                        </Badge>
                      </td>
                      <td className="py-3 px-3 text-[#667085]">
                        {formatDateTime(version.created_at)}
                        <div className="text-[#98A2B3]">{version.created_by?.name ?? 'وثيقة الإطار (بيانات مرجعية)'}</div>
                      </td>
                      <td className="py-3 px-3 text-[#667085]">
                        {version.updated_by ? (
                          <>
                            {formatDateTime(version.updated_at ?? null)}
                            <div className="text-[#98A2B3]">{version.updated_by.name}</div>
                          </>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="py-3 px-3 text-[#667085]">
                        {version.published_at ? (
                          <>
                            {formatDateTime(version.published_at)}
                            <div className="text-[#98A2B3]">{version.published_by?.name ?? 'وثيقة الإطار (بيانات مرجعية)'}</div>
                          </>
                        ) : (
                          'لم يُنشر'
                        )}
                      </td>
                      <td className="py-3 px-3 text-center">
                        <span className="font-extrabold text-[#5146A5]">{version.assessments_count ?? 0}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="mt-3 text-[11px] text-[#98A2B3]">السجل التفصيلي لكل تعديل محفوظ في سجل التدقيق (readiness_questionnaire.*).</p>
          </Card>
        );
      }}
    </EditorFrame>
  );
};
