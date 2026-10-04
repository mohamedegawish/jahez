import { useCallback, useEffect, useRef, useState } from 'react';
import { isAbortError } from '../api/client';

export type QueryStatus = 'loading' | 'success' | 'error';

export interface ApiQuery<T> {
  status: QueryStatus;
  data: T | undefined;
  error: unknown;
  /** True while a refetch runs and the previous `data` is still being shown. */
  isRefreshing: boolean;
  /**
   * Why the latest REFETCH failed while earlier data is still being shown (a background refresh that
   * hit a 429 or a network blip). Initial-load failures use `status: 'error'` instead.
   */
  refreshError: unknown;
  refetch: () => void;
}

interface Options {
  /** When false nothing is fetched (status stays `loading`). Default true. */
  enabled?: boolean;
}

interface State<T> {
  status: QueryStatus;
  data: T | undefined;
  error: unknown;
  isRefreshing: boolean;
  refreshError: unknown;
}

/**
 * Run a GET and track loading / success / error. The request is aborted when the component
 * unmounts or `deps` change, so a stale response can never overwrite a newer one.
 *
 * A failed request is reported as `error`; it never falls back to empty or mock data.
 */
export function useApiQuery<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: readonly unknown[],
  options: Options = {},
): ApiQuery<T> {
  const enabled = options.enabled ?? true;
  const [state, setState] = useState<State<T>>({ status: 'loading', data: undefined, error: null, isRefreshing: false, refreshError: null });
  const [tick, setTick] = useState(0);
  const fetcherRef = useRef(fetcher);

  useEffect(() => {
    fetcherRef.current = fetcher;
  });

  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    // The effect synchronizes with the network; marking the request as started is part of that.
    // oxlint-disable-next-line react/set-state-in-effect
    setState((prev) =>
      prev.status === 'success'
        ? { ...prev, isRefreshing: true }
        : { status: 'loading', data: undefined, error: null, isRefreshing: false, refreshError: null },
    );
    fetcherRef.current(controller.signal).then(
      (data) => {
        if (!controller.signal.aborted) setState({ status: 'success', data, error: null, isRefreshing: false, refreshError: null });
      },
      (error: unknown) => {
        if (controller.signal.aborted || isAbortError(error)) return;
        // A failed REFRESH keeps the last good data on screen; only a failed first load is a full error.
        setState((prev) =>
          prev.status === 'success'
            ? { ...prev, isRefreshing: false, refreshError: error }
            : { status: 'error', data: undefined, error, isRefreshing: false, refreshError: null },
        );
      },
    );
    return () => controller.abort();
  // Caller-supplied deps are the request identity, so the array is intentionally dynamic.
  // oxlint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick, enabled]);

  const refetch = useCallback(() => setTick((n) => n + 1), []);

  return {
    status: state.status,
    data: state.data,
    error: state.error,
    isRefreshing: state.isRefreshing,
    refreshError: state.refreshError,
    refetch,
  };
}
