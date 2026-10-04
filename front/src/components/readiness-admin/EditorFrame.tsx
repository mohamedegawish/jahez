import React from 'react';
import { ShieldAlert } from 'lucide-react';
import type { DefinitionPayload } from '../../lib/readinessDefinition';
import { ApiErrorState } from '../ui/ApiErrorState';
import { EmptyState } from '../ui/EmptyState';
import { CardSkeleton } from '../ui/LoadingState';
import type { ReadinessWorkspace } from './useReadinessWorkspace';
import { WorkspaceBar } from './WorkspaceBar';

/**
 * Loading, error and permission states shared by the editor tabs, with the workspace bar on top.
 * `children` gets the definition being shown and whether it may be edited.
 */
export const EditorFrame: React.FC<{
  workspace: ReadinessWorkspace;
  canManage: boolean;
  children: (definition: DefinitionPayload, editable: boolean) => React.ReactNode;
}> = ({ workspace, canManage, children }) => {
  if (!canManage) {
    return <EmptyState icon={ShieldAlert} title="لا تملك صلاحية إدارة الاستبيان" description="إدارة إصدارات الاستبيان تتطلب صلاحية readiness_questionnaires.manage." />;
  }
  if (workspace.loadError) return <ApiErrorState error={workspace.loadError} onRetry={workspace.reload} />;
  if (workspace.loading || !workspace.working) return <CardSkeleton />;

  return (
    <div className="space-y-5">
      <WorkspaceBar workspace={workspace} />
      {children(workspace.working, workspace.editable)}
    </div>
  );
};
