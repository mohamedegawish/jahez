/**
 * A URL that came from the server (for example a payment gateway's `checkout_url`) is only ever linked
 * if it is https (or http on localhost, for development). Anything else - `javascript:`, `data:`, a
 * relative path - returns null so it is never placed in an href.
 */
export function safeExternalUrl(raw: string | null | undefined): string | null {
  if (!raw) return null;
  try {
    const url = new URL(raw);
    if (url.protocol === 'https:') return url.toString();
    if (url.protocol === 'http:' && (url.hostname === 'localhost' || url.hostname === '127.0.0.1')) return url.toString();
  } catch {
    // not a valid absolute URL
  }
  return null;
}
