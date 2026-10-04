import React, { useState } from 'react';
import { Eye, FilePlus2, Lock, Rocket, RotateCcw, Save, Trash2 } from 'lucide-react';
import { api } from '../../api';
import { formatDateTime } from '../../lib/format';
import { ApiErrorState } from '../ui/ApiErrorState';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { ConfirmModal } from '../ui/ConfirmModal';
import { QuestionnairePreview } from './QuestionnairePreview';
import { ReviewChangesModal } from './ReviewChangesModal';
import type { ReadinessWorkspace } from './useReadinessWorkspace';

/**
 * What the editor tabs are working on, and the actions on it: save the draft, review its changes
 * and publish it, preview it as a factory sees it, delete it, or start a draft from the current version.
 */
export const WorkspaceBar: React.FC<{ workspace: ReadinessWorkspace }> = ({ workspace }) => {
  const [dialog, setDialog] = useState<'review' | 'delete' | 'preview' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const { draft, current } = workspace;

  const handleSave = async () => {
    setNotice(null);
    if (await workspace.save()) setNotice('حُفظت المسودة. لا تتأثر المصانع قبل النشر.');
  };

  return (
    <div className="jahez-card p-4 sticky top-2 z-10 space-y-3" data-testid="workspace-bar">
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div className="text-xs space-y-1">
          {draft ? (
            <>
              <div className="flex items-center gap-2 flex-wrap">
                <span className="font-bold text-sm text-[#172033]">مسودة الإصدار {draft.version}</span>
                <Badge size="sm" variant="warning">مسودة</Badge>
                {workspace.dirty && <Badge size="sm" variant="purple">تعديلات غير محفوظة</Badge>}
                {workspace.problems.length > 0 && <Badge size="sm" variant="error">{workspace.problems.length} ملاحظة تمنع النشر</Badge>}
              </div>
              <div className="text-[#667085]">
                أُنشئت {draft.created_by ? `بواسطة ${draft.created_by.name}` : ''} {formatDateTime(draft.created_at)}
                {draft.updated_by ? ` · آخر حفظ بواسطة ${draft.updated_by.name} ${formatDateTime(draft.updated_at ?? null)}` : ''}
              </div>
            </>
          ) : current ? (
            <>
              <div className="flex items-center gap-2 flex-wrap">
                <span className="font-bold text-sm text-[#172033]">الإصدار {current.version} (الحالي)</span>
                <Badge size="sm" variant="success">منشور</Badge>
              </div>
              <div className="text-[#667085] flex items-center gap-1">
                <Lock className="w-3.5 h-3.5" /> الإصدار المنشور لا يُعدّل. أنشئ مسودة منه لتغيير الصياغة أو الترتيب أو الحدود أو التوصيات.
              </div>
            </>
          ) : (
            <span className="text-[#667085]">لا يوجد إصدار حالي.</span>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {workspace.working && (
            <Button variant="outline" size="sm" icon={Eye} onClick={() => setDialog('preview')}>
              معاينة كما تراها المنشأة
            </Button>
          )}
          {draft && workspace.editable ? (
            <>
              {workspace.dirty && (
                <Button variant="ghost" size="sm" icon={RotateCcw} onClick={workspace.discard} disabled={workspace.saving}>
                  تجاهل التعديلات
                </Button>
              )}
              <Button variant="ghost" size="sm" icon={Trash2} onClick={() => setDialog('delete')}>
                حذف المسودة
              </Button>
              <Button variant="secondary" size="sm" icon={Save} onClick={handleSave} isLoading={workspace.saving} disabled={!workspace.dirty}>
                حفظ المسودة
              </Button>
              <Button variant="success" size="sm" icon={Rocket} onClick={() => setDialog('review')} disabled={workspace.saving}>
                مراجعة التغييرات والنشر
              </Button>
            </>
          ) : (
            current && (
              <Button variant="primary" size="sm" icon={FilePlus2} onClick={() => workspace.createDraft()} isLoading={workspace.creating}>
                إنشاء مسودة من الإصدار الحالي
              </Button>
            )
          )}
        </div>
      </div>

      {notice && !workspace.dirty && (
        <p role="status" className="text-xs font-semibold text-[#1D7E4C]">
          {notice}
        </p>
      )}
      {workspace.saveError !== null && <ApiErrorState compact error={workspace.saveError} />}
      {workspace.createError !== null && <ApiErrorState compact error={workspace.createError} />}

      {dialog === 'review' && (
        <ReviewChangesModal
          workspace={workspace}
          onClose={() => setDialog(null)}
          onPublished={() => {
            setDialog(null);
            setNotice(null);
            workspace.reload();
          }}
        />
      )}
      {dialog === 'preview' && workspace.working && (
        <QuestionnairePreview definition={workspace.working} versionLabel={draft ? `مسودة الإصدار ${draft.version}` : `الإصدار ${current?.version ?? ''}`} onClose={() => setDialog(null)} />
      )}
      {dialog === 'delete' && draft && (
        <ConfirmModal
          title={`حذف مسودة الإصدار ${draft.version}`}
          description="تُحذف المسودة وكل تعديلاتها. لا يتأثر الإصدار الحالي ولا أي تقييم مسجل."
          confirmLabel="حذف المسودة"
          variant="danger"
          onConfirm={() => api.readinessVersions.remove(draft.id)}
          onDone={() => {
            setDialog(null);
            workspace.reload();
          }}
          onClose={() => setDialog(null)}
        />
      )}
    </div>
  );
};
