import { useCallback, useMemo, useState } from 'react';
import { api } from '../../api';
import type { ReadinessQuestionnaireVersion } from '../../api';
import { useApiMutation } from '../../hooks/useApiMutation';
import { useApiQuery } from '../../hooks/useApiQuery';
import type { ApiQuery } from '../../hooks/useApiQuery';
import { definitionProblems, diffDefinitions, toDefinitionPayload } from '../../lib/readinessDefinition';
import type { DefinitionChange, DefinitionPayload } from '../../lib/readinessDefinition';

export interface ReadinessWorkspace {
  versions: ApiQuery<ReadinessQuestionnaireVersion[]>;
  /** The version factories answer now, with its definition. */
  current: ReadinessQuestionnaireVersion | null;
  /** The one draft, with its definition, or null. */
  draft: ReadinessQuestionnaireVersion | null;
  /** What the editor tabs show: the draft being edited, or the current version read-only. */
  working: DefinitionPayload | null;
  /** The published definition the draft is compared with before publishing. */
  baseline: DefinitionPayload | null;
  loading: boolean;
  loadError: unknown;
  editable: boolean;
  dirty: boolean;
  problems: string[];
  changes: DefinitionChange[];
  update: (change: (copy: DefinitionPayload) => void) => void;
  discard: () => void;
  save: () => Promise<boolean>;
  saving: boolean;
  saveError: unknown;
  createDraft: () => Promise<boolean>;
  creating: boolean;
  createError: unknown;
  reload: () => void;
}

/**
 * The questionnaire administration state shared by the editor tabs (jahez_api ADR-018 addenda): the
 * draft, if one exists, is edited in the browser and saved as a whole; a published version is only
 * shown. Every change is checked again by the API on save and on publish.
 */
export function useReadinessWorkspace(canManage: boolean): ReadinessWorkspace {
  const versions = useApiQuery((signal) => api.readinessVersions.list(signal), [], { enabled: canManage });
  const draftId = versions.data?.find((version) => version.status === 'draft')?.id ?? null;
  const currentId = versions.data?.find((version) => version.status === 'current')?.id ?? null;

  const draftQuery = useApiQuery((signal) => api.readinessVersions.get(draftId as number, signal), [draftId], { enabled: canManage && draftId !== null });
  const currentQuery = useApiQuery((signal) => api.readinessVersions.get(currentId as number, signal), [currentId], { enabled: canManage && currentId !== null });

  const draft = draftId !== null && draftQuery.data?.id === draftId ? draftQuery.data : null;
  const current = currentId !== null && currentQuery.data?.id === currentId ? currentQuery.data : null;

  // The local copy of the draft being edited. A freshly loaded draft (after creating, saving or
  // reloading) replaces it; React's pattern for adjusting state when its source changes.
  const [local, setLocal] = useState<{ source: ReadinessQuestionnaireVersion | null; payload: DefinitionPayload | null; dirty: boolean }>({ source: null, payload: null, dirty: false });
  if (local.source !== draft) {
    setLocal({ source: draft, payload: draft ? toDefinitionPayload(draft) : null, dirty: false });
  }
  const edited = local.source === draft ? local.payload : draft ? toDefinitionPayload(draft) : null;
  const dirty = local.source === draft && local.dirty;

  const baseline = useMemo(() => (current ? toDefinitionPayload(current) : null), [current]);
  const working = draft ? edited : baseline;

  const saveMutation = useApiMutation((payload: DefinitionPayload) => api.readinessVersions.update(draftId as number, payload));
  const createMutation = useApiMutation(() => api.readinessVersions.createDraft());

  const reload = useCallback(() => {
    versions.refetch();
    draftQuery.refetch();
    currentQuery.refetch();
  }, [versions, draftQuery, currentQuery]);

  const update = useCallback((change: (copy: DefinitionPayload) => void) => {
    setLocal((previous) => {
      if (!previous.payload) return previous;
      const copy: DefinitionPayload = JSON.parse(JSON.stringify(previous.payload));
      change(copy);
      return { ...previous, payload: copy, dirty: true };
    });
  }, []);

  const save = async () => {
    if (!edited || draftId === null) return false;
    const result = await saveMutation.run(edited);
    if (result.ok) {
      draftQuery.refetch();
      versions.refetch();
    }
    return result.ok;
  };

  const createDraft = async () => {
    const result = await createMutation.run();
    if (result.ok) versions.refetch();
    return result.ok;
  };

  const loading =
    canManage &&
    (versions.status === 'loading' || (draftId !== null && draftQuery.status === 'loading') || (currentId !== null && currentQuery.status === 'loading'));
  const loadError = versions.status === 'error' ? versions.error : draftQuery.status === 'error' ? draftQuery.error : currentQuery.status === 'error' ? currentQuery.error : null;

  return {
    versions,
    current,
    draft,
    working,
    baseline,
    loading,
    loadError,
    editable: canManage && draft !== null,
    dirty,
    problems: working ? definitionProblems(working) : [],
    changes: draft && edited && baseline ? diffDefinitions(baseline, edited) : [],
    update,
    discard: () => {
      setLocal({ source: draft, payload: draft ? toDefinitionPayload(draft) : null, dirty: false });
      saveMutation.reset();
    },
    save,
    saving: saveMutation.pending,
    saveError: saveMutation.error,
    createDraft,
    creating: createMutation.pending,
    createError: createMutation.error,
    reload,
  };
}
