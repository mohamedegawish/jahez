import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../api';
import type { SiteSettingsMap } from '../api/types';
import type { SiteLang } from './siteSettings';
import { resolveSetting } from './siteSettings';
import { SiteSettingsContext } from './siteSettingsContext';

/** One fetch per page load, shared by every landing component. */
let cachedMap: SiteSettingsMap | undefined;
let inflight: Promise<SiteSettingsMap> | null = null;

function loadMap(signal: AbortSignal): Promise<SiteSettingsMap> {
  if (cachedMap) return Promise.resolve(cachedMap);
  if (!inflight) {
    inflight = api.siteSettings
      .publicMap(signal)
      .then((map) => {
        cachedMap = map;
        return map;
      })
      .catch((error: unknown) => {
        // A failed load must never blank the landing page: callers fall back.
        if (signal.aborted) throw error;
        return cachedMap ?? {};
      })
      .finally(() => {
        inflight = null;
      });
  }
  return inflight;
}

export const SiteSettingsProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [map, setMap] = useState<SiteSettingsMap | undefined>(cachedMap);
  // Session-only: browser storage belongs to the auth token alone (tests/noLocalEligibility).
  const [lang, setLang] = useState<SiteLang>('ar');

  useEffect(() => {
    if (cachedMap) return;
    const controller = new AbortController();
    loadMap(controller.signal).then(
      (loaded) => {
        if (!controller.signal.aborted) setMap(loaded);
      },
      () => {},
    );
    return () => controller.abort();
  }, []);

  const t = useMemo(
    () => (key: string, fallback: string) => resolveSetting(map, key, lang, fallback),
    [map, lang],
  );

  const value = useMemo(() => ({ map, lang, setLang, t }), [map, lang, t]);
  return <SiteSettingsContext.Provider value={value}>{children}</SiteSettingsContext.Provider>;
};
