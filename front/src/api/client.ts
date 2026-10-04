import { ApiError, NetworkError, parseErrorBody } from './errors';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export type QueryScalar = string | number | boolean | null | undefined;
export type QueryValue = QueryScalar | QueryValue[] | { [key: string]: QueryValue };
export type Query = { [key: string]: QueryValue };

export interface StoredToken {
  accessToken: string;
  /** ISO 8601 UTC, from `expires_at` in the login response. */
  expiresAt: string;
}

/** Where the bearer token lives. The client only reads and clears it. */
export interface TokenStore {
  get(): StoredToken | null;
  clear(): void;
}

export interface RequestOptions {
  query?: Query;
  /** A plain object is sent as JSON; a `FormData` is sent as multipart (file uploads). */
  body?: unknown;
  headers?: Record<string, string>;
  signal?: AbortSignal;
  /** Default true. `false` sends no token and ignores 401 handling (used by login/forgot/reset). */
  auth?: boolean;
  /** `blob` returns the raw body of a successful response (a private file). Errors are still parsed as JSON. */
  responseType?: 'json' | 'blob';
}

export interface ApiClient {
  request<T>(method: HttpMethod, path: string, options?: RequestOptions): Promise<T>;
}

export interface ApiClientConfig {
  /** Includes the version prefix, for example `http://localhost:8000/api/v1`. */
  baseUrl: string;
  tokenStore: TokenStore;
  /** Called after a 401 (or a locally expired token) once the token has been cleared. */
  onUnauthenticated?: () => void;
  /** Send a fresh `X-Request-Id` on every request for server-log correlation. Default true. */
  sendRequestId?: boolean;
  fetchImpl?: typeof fetch;
}

/**
 * Serialise a query object the way Laravel reads it:
 *   { filter: { sector: 'food' }, page: 2 }  →  filter[sector]=food&page=2
 *   { ids: [1, 2] }                          →  ids[]=1&ids[]=2
 * `null`, `undefined` and empty strings are dropped; booleans become 1 / 0.
 */
export function buildQuery(query?: Query): string {
  if (!query) return '';
  const params = new URLSearchParams();

  const append = (key: string, value: QueryValue): void => {
    if (value === null || value === undefined || value === '') return;
    if (Array.isArray(value)) {
      value.forEach((item) => append(`${key}[]`, item));
    } else if (typeof value === 'object') {
      for (const [child, childValue] of Object.entries(value)) append(`${key}[${child}]`, childValue);
    } else if (typeof value === 'boolean') {
      params.append(key, value ? '1' : '0');
    } else {
      params.append(key, String(value));
    }
  };

  for (const [key, value] of Object.entries(query)) append(key, value);
  const out = params.toString();
  return out === '' ? '' : `?${out}`;
}

function parseRetryAfter(header: string | null): number | null {
  if (header === null) return null;
  const seconds = Number(header);
  if (Number.isFinite(seconds)) return Math.max(0, Math.ceil(seconds));
  const at = Date.parse(header);
  return Number.isNaN(at) ? null : Math.max(0, Math.ceil((at - Date.now()) / 1000));
}

export const isAbortError = (e: unknown): boolean =>
  (e instanceof DOMException && e.name === 'AbortError') || (e instanceof Error && e.name === 'AbortError');

function newRequestId(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `web-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export function createApiClient(config: ApiClientConfig): ApiClient {
  const baseUrl = config.baseUrl.replace(/\/+$/, '');
  const doFetch = config.fetchImpl ?? ((...args: Parameters<typeof fetch>) => fetch(...args));

  const unauthenticated = (requestId: string | null = null): ApiError => {
    config.tokenStore.clear();
    config.onUnauthenticated?.();
    return new ApiError({ status: 401, code: 'unauthenticated', message: 'Unauthenticated.', requestId });
  };

  async function request<T>(method: HttpMethod, path: string, options: RequestOptions = {}): Promise<T> {
    const useAuth = options.auth !== false;
    const headers: Record<string, string> = { Accept: 'application/json', ...options.headers };

    if (useAuth) {
      const token = config.tokenStore.get();
      if (token) {
        // No refresh tokens exist: an expired token can only be replaced by logging in again.
        if (Date.parse(token.expiresAt) <= Date.now()) throw unauthenticated();
        headers.Authorization = `Bearer ${token.accessToken}`;
      }
    }
    if (config.sendRequestId !== false) headers['X-Request-Id'] = newRequestId();

    let body: string | FormData | undefined;
    if (options.body instanceof FormData) {
      // The browser sets the multipart Content-Type with its boundary.
      body = options.body;
    } else if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    }

    const url = `${baseUrl}${path.startsWith('/') ? path : `/${path}`}${buildQuery(options.query)}`;

    let response: Response;
    try {
      response = await doFetch(url, { method, headers, body, signal: options.signal });
    } catch (error) {
      if (isAbortError(error)) throw error;
      throw new NetworkError(error instanceof Error ? error.message : undefined);
    }

    if (response.ok && options.responseType === 'blob') {
      return (await response.blob()) as T;
    }

    const text = await response.text();
    let json: unknown = null;
    if (text !== '') {
      try {
        json = JSON.parse(text);
      } catch {
        json = null;
      }
    }

    if (!response.ok) {
      const parsed = parseErrorBody(json);
      const requestId = parsed.request_id ?? response.headers.get('X-Request-Id');
      if (response.status === 401 && useAuth) throw unauthenticated(requestId);
      throw new ApiError({
        status: response.status,
        code: parsed.code ?? (response.status >= 500 ? 'server_error' : 'client_error'),
        message: parsed.message ?? response.statusText ?? 'Request failed',
        requestId,
        fieldErrors: parsed.errors,
        decisionNeeded: parsed.decision_needed,
        reasonAr: parsed.reason_ar,
        missingPolicies: parsed.missing_policies,
        retryAfter: parseRetryAfter(response.headers.get('Retry-After')),
      });
    }

    // 204 No Content (logout), or an empty body.
    return (response.status === 204 || json === null ? undefined : json) as T;
  }

  return { request };
}
