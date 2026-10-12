import { createContext, useContext } from 'react';
import type { SiteSettingsMap } from '../api/types';
import type { SiteLang } from './siteSettings';
import { defaultText } from './siteSettings.ts';

export interface SiteSettingsContextValue {
  /** The public map (undefined until loaded; components render their built-in copy meanwhile). */
  map: SiteSettingsMap | undefined;
  lang: SiteLang;
  setLang: (lang: SiteLang) => void;
  /** Saved value in the current language, else the built-in copy, else the fallback. */
  t: (key: string, fallback?: string) => string;
}

export const SiteSettingsContext = createContext<SiteSettingsContextValue>({
  map: undefined,
  lang: 'ar',
  setLang: () => {},
  t: (key, fallback = '') => defaultText(key, 'ar') || fallback,
});

export function useSiteSettings(): SiteSettingsContextValue {
  return useContext(SiteSettingsContext);
}
