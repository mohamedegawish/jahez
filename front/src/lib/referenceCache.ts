const cache = new Map<string, Promise<unknown>>();

/**
 * Share one in-flight/finished request per key (reference data is bounded and static per
 * deployment). A failed request is never cached, so a retry refetches.
 */
export function cachedReference<T>(key: string, load: () => Promise<T>): Promise<T> {
  let hit = cache.get(key) as Promise<T> | undefined;
  if (!hit) {
    hit = load().catch((error: unknown) => {
      cache.delete(key);
      throw error;
    });
    cache.set(key, hit);
  }
  return hit;
}

/** Called whenever the session starts or ends, so one user's data is never reused for the next. */
export function clearReferenceCache(): void {
  cache.clear();
}
