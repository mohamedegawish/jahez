import type { SiteSettingsMap, SiteSettingsPayload } from '../api/types';
import { SITE_DEFAULTS } from './siteDefaults.ts';

/** Display language of the settings-driven landing content. The portal chrome stays Arabic. */
export type SiteLang = 'ar' | 'en';

/** Settings-page sections in display order. */
export const SECTION_ORDER = [
  'brand',
  'header',
  'footer',
  'hero',
  'join',
  'value',
  'categories',
  'about',
  'services',
  'steps',
] as const;

export const SECTION_TITLES: Record<string, string> = {
  brand: 'الشعار والعلامة',
  header: 'الترويسة',
  footer: 'التذييل',
  hero: 'الواجهة الرئيسية',
  join: 'قسم الانضمام',
  value: 'القيمة المقترحة',
  categories: 'التصنيفات',
  about: 'عن المنصة',
  services: 'خدمات المنصة',
  steps: 'خطوات الاستخدام',
};

const FIELD_WORDS: Record<string, string> = {
  title: 'العنوان',
  desc: 'الوصف',
  subtitle: 'العنوان الفرعي',
  kicker: 'الشارة العلوية',
  cta: 'نص الزر',
  button: 'نص الزر',
  value: 'القيمة',
  label: 'التسمية',
  hint: 'تلميح',
  intro: 'المقدمة',
  line: 'السطر',
  link: 'الرابط',
  alt: 'النص البديل',
  latin: 'اللاتيني',
  name: 'الاسم',
};

const GROUP_WORDS: Record<string, string> = {
  cat: 'التصنيف',
  svc: 'الخدمة',
  step: 'الخطوة',
  fact: 'الحقيقة',
  badge: 'الشارة',
};

const OWNER_WORDS: Record<string, string> = {
  factory: 'المصانع',
  provider: 'مزودي الخدمة',
  admin: 'المركز',
  join: 'الانضمام',
  login: 'الدخول',
};

const LINK_WORDS: Record<string, string> = {
  home: 'الرئيسية',
  register_factory: 'تسجيل مصنع',
  register_provider: 'تسجيل مزود',
  factory: 'تسجيل مصنع',
  provider: 'تسجيل مزود',
  login: 'الدخول',
};

/** Standalone fields whose label cannot be composed from fragments. */
const SINGULAR_LABELS: Record<string, string> = {
  'brand.logo_alt': 'النص البديل للشعار',
  'header.brand_ar': 'اسم العلامة بالعربية',
  'header.brand_en': 'اسم العلامة بالإنجليزية',
  'header.portal_button': 'زر الدخول للبوابة',
  'header.login_button': 'زر تسجيل الدخول',
  'hero.title_latin': 'العنوان باللاتينية',
  'hero.title_ar': 'العنوان بالعربية',
  'hero.subtitle_authority': 'الجهة المشرفة',
  'hero.join_factory_button': 'زر تسجيل المصنع',
  'hero.join_provider_button': 'زر تسجيل المزود',
  'hero.cta_catalog': 'زر استكشاف الكتالوج',
  'hero.cta_assessment': 'زر بدء التقييم',
  'hero.cta_login': 'زر تسجيل الدخول',
  'categories.browse_hint': 'تلميح التصفح',
  'categories.browse_cta': 'زر التصفح',
  'categories.assess_title': 'بطاقة التقييم — العنوان',
  'categories.assess_desc': 'بطاقة التقييم — الوصف',
  'categories.assess_hint': 'بطاقة التقييم — تلميح',
  'categories.assess_cta': 'بطاقة التقييم — الزر',
};

/**
 * Short settings-page label for a key (`hero.title_ar` → `العنوان بالعربية`).
 * Unknown keys fall back to the raw field part so a new key never breaks the form.
 */
export function labelFor(key: string): string {
  const singular = SINGULAR_LABELS[key];
  if (singular) return singular;

  const dot = key.indexOf('.');
  const rest = dot < 0 ? key : key.slice(dot + 1);

  const grouped = rest.match(/^(cat|svc|step|fact)(\d+)_(title|desc|cta|value|label|hint)$/);
  if (grouped) {
    const [, group, num, field] = grouped;
    return `${GROUP_WORDS[group] ?? group} ${num} — ${FIELD_WORDS[field] ?? field}`;
  }

  const badge = rest.match(/^badge(\d+)$/);
  if (badge) return `${GROUP_WORDS.badge} ${badge[1]}`;

  const portal = rest.match(/^(portal|card)_(factory|provider|admin|join|login)_(title|desc)$/);
  if (portal) {
    const [, kind, owner, field] = portal;
    const kindWord = kind === 'portal' ? 'بوابة' : 'بطاقة';
    return `${kindWord} ${OWNER_WORDS[owner] ?? owner} — ${FIELD_WORDS[field] ?? field}`;
  }

  const paragraph = rest.match(/^p([12])$/);
  if (paragraph) return `الفقرة ${paragraph[1]}`;

  const link = rest.match(/^(nav|link)_(.+)$/);
  if (link) {
    const [, , target] = link;
    return `رابط ${LINK_WORDS[target] ?? target}`;
  }

  return FIELD_WORDS[rest] ?? rest;
}

/**
 * The displayed text: the saved value in the current language, else the
 * built-in copy, else the caller's fallback.
 */
export function resolveSetting(
  map: SiteSettingsMap | undefined,
  key: string,
  lang: SiteLang,
  fallback: string,
): string {
  const saved = map?.[key]?.[lang];
  if (saved != null && saved !== '') return saved;
  const builtIn = defaultText(key, lang);
  return builtIn !== '' ? builtIn : fallback;
}

/**
 * The built-in copy of a key in a language ('' when the key is unknown).
 * The admin form pre-fills inputs with this when nothing is saved yet.
 */
export function defaultText(key: string, lang: SiteLang): string {
  const entry = SITE_DEFAULTS[key];
  if (!entry) return '';
  return lang === 'en' ? (entry.en ?? entry.ar) : entry.ar;
}

/** Absolute URL of the uploaded brand logo, or null until one is uploaded. */
export function logoSrc(map: SiteSettingsMap | undefined, apiBaseUrl: string): string | null {
  const path = map?.['brand.logo']?.image_url;
  return path ? `${apiBaseUrl.replace(/\/$/, '')}${path}` : null;
}

/** Alt text of the brand logo. */
export function logoAlt(map: SiteSettingsMap | undefined, fallback: string): string {
  return resolveSetting(map, 'brand.logo_alt', 'ar', fallback);
}

/**
 * Nest a flat `{ key: { ar, en } }` draft by section for PATCH /site-settings.
 * Keys without a section are skipped.
 */
export function buildSiteSettingsPayload(
  draft: Record<string, { ar: string; en: string }>,
): SiteSettingsPayload {
  const settings: SiteSettingsPayload['settings'] = {};
  for (const [key, values] of Object.entries(draft)) {
    const dot = key.indexOf('.');
    if (dot < 0) continue;
    const section = key.slice(0, dot);
    const name = key.slice(dot + 1);
    (settings[section] ??= {})[name] = { ar: values.ar || null, en: values.en || null };
  }
  return { settings };
}
