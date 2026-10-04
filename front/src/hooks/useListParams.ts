import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

export const DEFAULT_PER_PAGE = 15;

/**
 * List state kept in the URL (`?page=2&status=open&search=...`) so filters survive a refresh,
 * can be shared, and reset in one call. Changing a filter, the search or the sort returns to page 1.
 *
 * `filterKeys` are the URL names of the API's `filter[...]` keys; use `toApiQuery` to build the request.
 */
export function useListParams<K extends string>(filterKeys: readonly K[]) {
  const [params, setParams] = useSearchParams();

  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const requestedPerPage = Number.parseInt(params.get('per_page') ?? '', 10);
  // The API accepts 1..100; anything else falls back to the default instead of producing a 422.
  const perPage = requestedPerPage >= 1 && requestedPerPage <= 100 ? requestedPerPage : DEFAULT_PER_PAGE;
  const search = params.get('search') ?? '';
  const sort = params.get('sort') ?? '';

  const keysId = filterKeys.join('|');
  const filters = useMemo(() => {
    const out = {} as Record<K, string>;
    for (const key of keysId.split('|').filter(Boolean) as K[]) out[key] = params.get(key) ?? '';
    return out;
  }, [params, keysId]);

  const update = useCallback(
    (changes: Record<string, string | number | null>, resetPage = true) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev);
          for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === '') next.delete(key);
            else next.set(key, String(value));
          }
          if (resetPage) next.delete('page');
          return next;
        },
        { replace: true },
      );
    },
    [setParams],
  );

  const hasActiveFilters = search !== '' || sort !== '' || Object.values<string>(filters).some((value) => value !== '');

  return {
    page,
    perPage,
    search,
    sort,
    filters,
    hasActiveFilters,
    setPage: (n: number) => update({ page: n }, false),
    setPerPage: (n: number) => update({ per_page: n === DEFAULT_PER_PAGE ? null : n }),
    setSearch: (value: string) => update({ search: value }),
    setSort: (value: string) => update({ sort: value }),
    setFilter: (key: K, value: string) => update({ [key]: value }),
    reset: () => setParams(new URLSearchParams(), { replace: true }),
  };
}

/**
 * A search box that feels instant but reaches the URL (and so the API) only after a pause.
 * While the user types, `value` is their draft; afterwards it is the search held in the URL again,
 * so a "reset filters" that clears the URL also clears the box.
 */
export function useSearchBox(urlSearch: string, commit: (value: string) => void, delayMs = 350) {
  const [draft, setDraft] = useState<string | null>(null);
  const timer = useRef<number | undefined>(undefined);

  const onChange = useCallback(
    (value: string) => {
      setDraft(value);
      window.clearTimeout(timer.current);
      timer.current = window.setTimeout(() => {
        commit(value);
        setDraft(null);
      }, delayMs);
    },
    [commit, delayMs],
  );

  useEffect(() => () => window.clearTimeout(timer.current), []);

  return { value: draft ?? urlSearch, onChange };
}

/** Debounce a fast-changing value (a search box) before it reaches the URL and the API. */
export function useDebouncedValue<T>(value: T, delayMs = 350): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = window.setTimeout(() => setDebounced(value), delayMs);
    return () => window.clearTimeout(id);
  }, [value, delayMs]);
  return debounced;
}

/**
 * Build the API query from list params: `filter[key]` only for non-empty filters, plus
 * `search`, `sort`, `page` and `per_page`.
 */
export function toApiQuery<K extends string>(
  list: { page: number; perPage: number; search: string; sort: string; filters: Record<K, string> },
) {
  const filter: Record<string, string> = {};
  for (const [key, value] of Object.entries<string>(list.filters)) if (value !== '') filter[key] = value;
  return {
    page: list.page,
    per_page: list.perPage,
    search: list.search || undefined,
    sort: list.sort || undefined,
    filter: Object.keys(filter).length > 0 ? filter : undefined,
  };
}
