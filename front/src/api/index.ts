import { tokenStore } from '../auth/tokenStore';
import { createApiClient, type ApiClient, type RequestOptions, type HttpMethod } from './client';
import { createEndpoints } from './endpoints';
import { ConfigurationError } from './errors';

export * from './errors';
export * from './types';
export { buildQuery, isAbortError } from './client';
export type { ListQuery } from './endpoints';

let onUnauthenticated: (() => void) | null = null;

/** The auth layer registers what happens after a 401 (clear user state, go to /login). */
export function setUnauthenticatedHandler(handler: (() => void) | null): void {
  onUnauthenticated = handler;
}

let client: ApiClient | null = null;

function resolveClient(): ApiClient {
  if (client) return client;
  const baseUrl = import.meta.env.VITE_API_BASE_URL;
  if (!baseUrl) {
    // Deliberately no localhost fallback: a production build must never silently point at a dev API.
    throw new ConfigurationError(
      'VITE_API_BASE_URL is not set. Copy .env.example to .env and set it (for example http://localhost:8000/api/v1).',
    );
  }
  client = createApiClient({
    baseUrl,
    tokenStore,
    onUnauthenticated: () => onUnauthenticated?.(),
  });
  return client;
}

// Resolved lazily so a missing variable surfaces as a handled error on first use, not at import time.
const lazyClient: Pick<ApiClient, 'request'> = {
  request: <T>(method: HttpMethod, path: string, options?: RequestOptions) =>
    resolveClient().request<T>(method, path, options),
};

export const api = createEndpoints(lazyClient);

/**
 * The absolute URL of a public API resource (a live announcement's cover image), for an <img>
 * tag. Only for paths the API marks public: no token is attached.
 */
export function publicApiUrl(path: string): string {
  return `${String(import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/$/, '')}${path}`;
}
