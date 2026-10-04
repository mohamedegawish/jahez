import React from 'react';
import type { ApiQuery } from '../../hooks/useApiQuery';
import { ApiErrorState } from './ApiErrorState';
import { CardSkeleton } from './LoadingState';

interface QueryBoundaryProps<T> {
  query: ApiQuery<T>;
  /** Shown while the first load runs. Defaults to a card skeleton. */
  loading?: React.ReactNode;
  /** Return true when the loaded data means "nothing to show" and `empty` should render. */
  isEmpty?: (data: T) => boolean;
  empty?: React.ReactNode;
  children: (data: T) => React.ReactNode;
}

/**
 * The four states of every API-backed view, in one place: loading -> error -> empty -> data.
 * A failed request renders `ApiErrorState` (with retry); it is never shown as an empty list.
 */
export function QueryBoundary<T>({ query, loading, isEmpty, empty, children }: QueryBoundaryProps<T>) {
  if (query.status === 'loading') return <>{loading ?? <CardSkeleton />}</>;
  if (query.status === 'error') return <ApiErrorState error={query.error} onRetry={query.refetch} />;

  const data = query.data as T;
  if (isEmpty?.(data)) return <>{empty}</>;
  return (
    <div className={query.isRefreshing ? 'opacity-60 transition-opacity' : 'transition-opacity'} aria-busy={query.isRefreshing}>
      {query.refreshError !== null && (
        // The last good data stays visible; the failed refresh is reported without discarding it.
        <div className="mb-3" data-testid="refresh-error">
          <ApiErrorState compact error={query.refreshError} onRetry={query.refetch} />
          <p className="mt-1 text-[11px] text-[#98A2B3]">البيانات المعروضة آخر نسخة محمّلة بنجاح وقد لا تكون محدّثة.</p>
        </div>
      )}
      {children(data)}
    </div>
  );
}
