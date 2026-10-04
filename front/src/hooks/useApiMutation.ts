import { useCallback, useEffect, useRef, useState } from 'react';
import { firstFieldErrors } from '../api/errors';

export type MutationResult<T> = { ok: true; data: T } | { ok: false; error: unknown };

export interface ApiMutation<A extends unknown[], T> {
  /** Run the request. Never throws: inspect the result. A second call while one is in flight is ignored. */
  run: (...args: A) => Promise<MutationResult<T>>;
  pending: boolean;
  /** The last failure, or null. Cleared by the next `run` or by `reset`. */
  error: unknown;
  /** First server message per Laravel field path (`sectors.0`, `price.amount`) for a 422. */
  fieldErrors: Record<string, string>;
  reset: () => void;
}

/**
 * Wrap a write request. It blocks duplicate submissions while pending and keeps the error so
 * the page can show field errors (422) or a typed message (403, 409, policy_not_configured, 429...).
 */
export function useApiMutation<A extends unknown[], T>(fn: (...args: A) => Promise<T>): ApiMutation<A, T> {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const inFlight = useRef(false);
  const mounted = useRef(true);
  const fnRef = useRef(fn);

  useEffect(() => {
    fnRef.current = fn;
  });
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const run = useCallback(async (...args: A): Promise<MutationResult<T>> => {
    if (inFlight.current) return { ok: false, error: null };
    inFlight.current = true;
    setPending(true);
    setError(null);
    try {
      const data = await fnRef.current(...args);
      return { ok: true, data };
    } catch (caught) {
      if (mounted.current) setError(caught);
      return { ok: false, error: caught };
    } finally {
      inFlight.current = false;
      if (mounted.current) setPending(false);
    }
  }, []);

  const reset = useCallback(() => setError(null), []);

  return { run, pending, error, fieldErrors: firstFieldErrors(error), reset };
}
