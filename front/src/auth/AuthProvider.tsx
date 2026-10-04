import React, { useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { api, isAbortError, isUnauthenticated, setUnauthenticatedHandler } from '../api';
import type { CurrentUser, Permission } from '../api';
import { AuthContext, type AuthContextValue, type AuthStatus } from './authContext';
import { clearReferenceCache } from '../lib/referenceCache';
import { tokenStore } from './tokenStore';

const DEVICE_NAME = 'jahez-web';
const TOKEN_KEY = 'jahez.auth.token';
/** setTimeout cannot wait longer than a signed 32-bit integer of milliseconds. */
const MAX_TIMER_MS = 2_147_483_647;

interface State {
  status: AuthStatus;
  user: CurrentUser | null;
  error: unknown;
  sessionExpired: boolean;
}

const ANONYMOUS: State = { status: 'anonymous', user: null, error: null, sessionExpired: false };

/**
 * Owns the session. The user, role and permissions always come from `GET /me`; the browser stores
 * only the bearer token. A 401 from any request, an expired token, or a logout in another tab
 * ends the session and the route guards send the visitor to /login.
 */
export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [state, setState] = useState<State>(() =>
    tokenStore.get() ? { status: 'loading', user: null, error: null, sessionExpired: false } : ANONYMOUS,
  );
  const [bootTick, setBootTick] = useState(0);

  const endSession = useCallback((expired: boolean) => {
    tokenStore.clear();
    clearReferenceCache();
    setState((prev) => ({ ...ANONYMOUS, sessionExpired: expired && prev.status === 'authenticated' }));
  }, []);

  // Any 401 (revoked token, deactivated account, expiry) ends the session. Registered in a layout
  // effect: those run before every passive effect, so no child's first request can see a missing handler.
  useLayoutEffect(() => {
    setUnauthenticatedHandler(() => endSession(true));
    return () => setUnauthenticatedHandler(null);
  }, [endSession]);

  // Restore the session on load: a stored token is only trusted once /me accepts it.
  useEffect(() => {
    if (!tokenStore.get()) {
      // The token vanished between the first render and now (expired, or cleared by another tab):
      // resolve to a definite state instead of staying in `loading`.
      // oxlint-disable-next-line react/set-state-in-effect
      setState((prev) => (prev.status === 'loading' ? ANONYMOUS : prev));
      return;
    }
    const controller = new AbortController();
    api.me(controller.signal).then(
      (user) => {
        if (!controller.signal.aborted) setState({ status: 'authenticated', user, error: null, sessionExpired: false });
      },
      (error: unknown) => {
        if (controller.signal.aborted || isAbortError(error)) return;
        if (isUnauthenticated(error)) return; // the client already cleared the token and notified us
        setState({ status: 'error', user: null, error, sessionExpired: false });
      },
    );
    return () => controller.abort();
  }, [bootTick]);

  // Expire the session at the token's `expires_at` (there are no refresh tokens).
  const authenticated = state.status === 'authenticated';
  useEffect(() => {
    if (!authenticated) return;
    const token = tokenStore.get();
    if (!token) return;
    const remaining = Date.parse(token.expiresAt) - Date.now();
    const timer = window.setTimeout(() => endSession(true), Math.min(Math.max(remaining, 0), MAX_TIMER_MS));
    return () => window.clearTimeout(timer);
  }, [authenticated, endSession]);

  // Logging out in another tab removes the shared token: follow it.
  useEffect(() => {
    const onStorage = (event: StorageEvent) => {
      if (event.key === TOKEN_KEY && event.newValue === null && !tokenStore.get()) endSession(false);
    };
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, [endSession]);

  const login = useCallback(async (email: string, password: string, remember: boolean): Promise<CurrentUser> => {
    const result = await api.auth.login({ email, password, device_name: DEVICE_NAME });
    tokenStore.set({ accessToken: result.access_token, expiresAt: result.expires_at }, remember);
    clearReferenceCache();
    try {
      const user = await api.me();
      setState({ status: 'authenticated', user, error: null, sessionExpired: false });
      return user;
    } catch (error) {
      tokenStore.clear();
      throw error;
    }
  }, []);

  const logout = useCallback(async (): Promise<boolean> => {
    let revoked = true;
    try {
      await api.auth.logout();
    } catch {
      revoked = false; // offline: the token is dropped locally but may stay valid on the server until it expires
    }
    tokenStore.clear();
    clearReferenceCache();
    setState(ANONYMOUS);
    return revoked;
  }, []);

  const retry = useCallback(() => {
    setState({ status: 'loading', user: null, error: null, sessionExpired: false });
    setBootTick((n) => n + 1);
  }, []);

  const refresh = useCallback(async (): Promise<void> => {
    try {
      const user = await api.me();
      setState((prev) => (prev.status === 'authenticated' ? { ...prev, user } : prev));
    } catch {
      // A 401 already ended the session through the shared handler; other failures keep the last known user.
    }
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      status: state.status,
      user: state.user,
      error: state.error,
      sessionExpired: state.sessionExpired,
      login,
      logout,
      retry,
      refresh,
      hasPermission: (permission: Permission) => state.user?.permissions.includes(permission) ?? false,
    }),
    [state, login, logout, retry, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
