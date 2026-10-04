import type { StoredToken, TokenStore } from '../api/client';

// Only the bearer token and its expiry live in browser storage. User data and every piece of
// business state come from the API on each load (`GET /me`).
//
// "Remember session" (the login checkbox) chooses the storage area:
//   remembered → localStorage   (survives closing the browser)
//   otherwise  → sessionStorage (ends with the tab)
const KEY = 'jahez.auth.token';

function parse(raw: string | null): StoredToken | null {
  if (!raw) return null;
  try {
    const value = JSON.parse(raw) as Partial<StoredToken>;
    return typeof value.accessToken === 'string' && typeof value.expiresAt === 'string'
      ? { accessToken: value.accessToken, expiresAt: value.expiresAt }
      : null;
  } catch {
    return null;
  }
}

function read(area: () => Storage): StoredToken | null {
  try {
    return parse(area().getItem(KEY));
  } catch {
    return null; // storage blocked (private mode, disabled site data)
  }
}

export interface PersistentTokenStore extends TokenStore {
  set(token: StoredToken, remember: boolean): void;
}

export const tokenStore: PersistentTokenStore = {
  get: () => read(() => sessionStorage) ?? read(() => localStorage),

  set(token, remember) {
    this.clear();
    try {
      (remember ? localStorage : sessionStorage).setItem(KEY, JSON.stringify(token));
    } catch {
      // Without storage the session lasts only until the page reloads.
    }
  },

  clear() {
    for (const area of [() => sessionStorage, () => localStorage]) {
      try {
        area().removeItem(KEY);
      } catch {
        // ignore
      }
    }
  },
};
