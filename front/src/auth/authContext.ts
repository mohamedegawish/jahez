import { createContext, useContext } from 'react';
import type { CurrentUser, Permission, Role } from '../api/types';

/**
 * `loading`       - a stored token is being checked with GET /me
 * `authenticated` - `user` is the server's answer from GET /me
 * `anonymous`     - no valid session
 * `error`         - GET /me could not be answered (network, 5xx): the token is kept, retry is offered
 */
export type AuthStatus = 'loading' | 'authenticated' | 'anonymous' | 'error';

export interface AuthContextValue {
  status: AuthStatus;
  /** Role, organization and permissions exactly as the API reports them. Never set from the UI. */
  user: CurrentUser | null;
  /** Why `status` is `error`. */
  error: unknown;
  /** True when a session that was active ended (401 or expiry), so the login page can say so. */
  sessionExpired: boolean;
  /** POST /auth/login -> store token -> GET /me. Throws ApiError (422 generic, 429 with Retry-After). */
  login: (email: string, password: string, remember: boolean) => Promise<CurrentUser>;
  /** POST /auth/logout, then clear the local session. Resolves false when the server could not be reached. */
  logout: () => Promise<boolean>;
  /** Re-run GET /me (after an `error`). */
  retry: () => void;
  /** Reload the current user from GET /me (for example after renaming the user's organization). */
  refresh: () => Promise<void>;
  hasPermission: (permission: Permission) => boolean;
}

export const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error('useAuth must be used within an AuthProvider');
  return value;
}

/** Landing page of each role's portal. */
export function portalFor(role: Role): string {
  switch (role) {
    case 'imc_admin':
      return '/admin/dashboard';
    case 'factory_member':
      return '/factory/dashboard';
    case 'provider_member':
      return '/provider/dashboard';
  }
}

const PORTAL_PREFIX: Record<Role, string> = {
  imc_admin: '/admin',
  factory_member: '/factory',
  provider_member: '/provider',
};

/**
 * Where to go after login. `from` (the page that required login) is honoured only when it is an
 * in-app path this role may open; otherwise the role's portal. This also blocks open redirects.
 */
export function postLoginPath(role: Role, from: unknown): string {
  if (typeof from !== 'string' || !from.startsWith('/') || from.startsWith('//')) return portalFor(role);
  const prefix = PORTAL_PREFIX[role];
  const inOwnPortal = from === prefix || from.startsWith(`${prefix}/`);
  const isPublic = from === '/' || from.startsWith('/ads/');
  return inOwnPortal || isPublic ? from : portalFor(role);
}
